<?php
/**
 * Helper: callGeminiAPI
 * Enhanced version that properly parses Gemini API responses
 */

if (!function_exists('geminiWelcomeMessage')) {
    /** First message of every chat (rendered without starting a session). */
    function geminiWelcomeMessage(): array {
        return [
            'role' => 'assistant',
            'user' => 'Gemini',
            'text' => "👋 Hello! I'm Gemini, your PHPue AI assistant. My answers are grounded on the PHPue 0.0.3 knowledge base (ai.phpue.co.uk/llms-full.txt). Ask me about components, routing, @AJAX, deployment or anything else PHPue!",
            'timestamp' => date('H:i:s'),
            'type' => 'welcome'
        ];
    }
}

if (!function_exists('geminiSystemPreamble')) {
    /** Instructions shared by the server chat and the browser-only "your own key" mode. */
    function geminiSystemPreamble(): string {
        return "You are PHPue AI, the assistant on ai.phpue.co.uk. Answer questions about PHPue, a server-side PHP framework "
            . "that compiles Vue-like .pvue files to PHP. PHPue is NOT Vue: never suggest v-if, v-model, p-else, p-show, p-model, "
            . "props, slots or client-side reactivity, and never write {{ " . '$obj.prop' . " }}. Base every answer on the knowledge base below; "
            . "it is the source of truth for PHPue 0.0.3. If it does not cover something, say so and suggest plain PHP or "
            . "https://phpue.co.uk/docs. Keep answers concise, use fenced code blocks for code, and link the relevant topic URL "
            . "(https://ai.phpue.co.uk/docs?topic=<slug>) when it helps.";
    }
}

if (!function_exists('geminiSystemInstruction')) {
    /** Grounds every answer on the knowledge base in backend/knowledge/content. */
    function geminiSystemInstruction(): string {
        $knowledge = class_exists('PHPueKnowledge') ? PHPueKnowledge::llmsFullTxt() : '';
        return geminiSystemPreamble() . "\n\n=== PHPUE KNOWLEDGE BASE ===\n" . $knowledge;
    }
}

if (!function_exists('callGeminiAPI')) {
    function callGeminiAPI($prompt) {
        $apiUrl = getenv('GEMINI_API_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent';
        $apiKey = getenv('GEMINI_API_KEY');
        
        // Fallback if no API key is configured
        if (!$apiKey) {
            return "👋 **Welcome to PHPue AI Assistant!**\n\nTo enable Gemini AI responses:\n\n1. Create `backend/env.php` (git-ignored, auto-loaded by PHPue)\n2. Add `putenv('GEMINI_API_KEY=...');` with a key from Google AI Studio\n3. Restart the chat\n\nIn the meantime, here's a PHPue tip: Use `@require` to include components from the `components/` folder in your views!";
        }

        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey
        ];
        
        // Gemini 1.5+ format; system_instruction grounds the answer on the PHPue knowledge base
        $body = json_encode([
            'system_instruction' => [
                'parts' => [
                    ['text' => geminiSystemInstruction()]
                ]
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048,
                'topP' => 0.8,
                'topK' => 40
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ]);        
        // The key travels only in the x-goog-api-key header, never in the URL (URLs end up in logs and errors)
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log metadata only (private folder, never /tmp). Prompts and answers are personal data:
        // they are only included, shortened, when PHPUE_DEBUG=1 on a development machine.
        if (PHPuePrivate::debug()) {
            PHPuePrivate::log('gemini', 'debug', ['status' => $httpCode, 'prompt' => mb_substr($prompt, 0, 300), 'response' => mb_substr((string) $resp, 0, 300)]);
        }

        if ($err) {
            PHPuePrivate::log('gemini', 'network error', ['curl' => $err]);
            return "⚠️ **Network Error**\n\nThe AI service could not be reached. Please try again in a moment.";
        }

        if ($httpCode !== 200) {
            $apiError = json_decode((string) $resp, true)['error']['message'] ?? '';
            PHPuePrivate::log('gemini', 'api error', ['status' => $httpCode, 'error' => mb_substr($apiError, 0, 300)]);
            return "⚠️ **API Error (HTTP $httpCode)**\n\nPlease check your API key and endpoint configuration.";
        }
        
        $decoded = json_decode($resp, true);
        
        if (!$decoded) {
            return "⚠️ **Invalid Response**\n\nGemini API returned an invalid JSON response.";
        }
        
        // Check for API errors
        if (isset($decoded['error'])) {
            $errorMsg = $decoded['error']['message'] ?? 'Unknown API error';
            return "⚠️ **Gemini API Error**\n\n" . $errorMsg;
        }
        
        // Extract text from Gemini response - FIXED HERE!
        $text = '';
        
        // Try multiple response formats
        if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            $text = $decoded['candidates'][0]['content']['parts'][0]['text'];
        } elseif (isset($decoded['candidates'][0]['content']['parts'])) {
            foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        } elseif (isset($decoded['candidates'][0]['content']['text'])) {
            $text = $decoded['candidates'][0]['content']['text'];
        } elseif (isset($decoded['candidates'][0]['output'])) {
            $text = $decoded['candidates'][0]['output'];
        } elseif (isset($decoded['choices'][0]['text'])) {
            $text = $decoded['choices'][0]['text'];
        } elseif (isset($decoded['text'])) {
            $text = $decoded['text'];
        } else {
            // Log the shape of the response (keys and finish reason), not its content
            PHPuePrivate::log('gemini', 'unrecognized response', [
                'keys' => array_keys($decoded),
                'finishReason' => $decoded['candidates'][0]['finishReason'] ?? null,
            ]);
            
            return "⚠️ **Response Format Error**\n\nGemini API returned an unexpected response format. Please check server logs for details.";
        }
        
        // Clean up the response
        $text = trim($text);
        
        // Add context if it's a generic response
        if (empty($text) || strlen($text) < 10) {
            return "👋 **Hello!**\n\nI'm Gemini, your PHPue AI assistant. It seems I received your message but my response was too brief. Could you rephrase or ask something specific about PHPue? I can help with components, deployment, routing, and more!";
        }
        
        return $text;
    }
}