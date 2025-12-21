<?php
/**
 * Helper: callGeminiAPI
 * Enhanced version that properly parses Gemini API responses
 */

if (!function_exists('callGeminiAPI')) {
    function callGeminiAPI($prompt) {
        $apiUrl = getenv('GEMINI_API_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent';
        $apiKey = getenv('GEMINI_API_KEY');
        
        // Fallback if no API key is configured
        if (!$apiKey) {
            return "👋 **Welcome to PHPue AI Assistant!**\n\nTo enable Gemini AI responses:\n\n1. Copy `backend/env.example.php` to `backend/env.php`\n2. Add your Gemini API key from [Google AI Studio](https://makersuite.google.com/app/apikey)\n3. Restart the chat\n\nIn the meantime, here's a PHPue tip: Use `@require` to include components from the `components/` folder in your views!";
        }

        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey
        ];
        
        // If the calling code included a short 'context' override via env or supports includeDocs,
        // allow trimming and safe injection to the prompt. We detect a special marker: "__PHPUE_INCLUDE_DOCS__"
        // which should be appended in the prompt when includeDocs is requested.
        $injectedContext = '';
        if (strpos($prompt, '__PHPUE_INCLUDE_DOCS__') !== false) {
            // Remove marker and replace with a concise context block
            $prompt = str_replace('__PHPUE_INCLUDE_DOCS__', '', $prompt);

            $docsContext = "Reference material (short):\n- PHPue docs: https://phpue.co.uk/docs\n- PHPue AI: https://ai.phpue.co.uk\n\nWhen answering, prefer official documentation and include brief citations (URL or short path). If you quote docs, show the short path and link.";

            // Ensure the injected context doesn't exceed a safe size (e.g., 1500 chars)
            if (strlen($docsContext) > 1500) {
                $docsContext = substr($docsContext, 0, 1500) . '...';
            }

            $injectedContext = "----BEGIN PHPUE CONTEXT----\n" . $docsContext . "\n----END PHPUE CONTEXT----\n\n";

            // Prepend the context to the user's prompt
            $prompt = $injectedContext . $prompt;

            // Log the injected prompt for debugging (do not log API key)
            @file_put_contents(sys_get_temp_dir() . '/phpue_gemini_debug.log', "[" . date('Y-m-d H:i:s') . "] Injected PHPUE context into prompt.\nPromptSnippet: " . substr($prompt, 0, 800) . "\n\n", FILE_APPEND | LOCK_EX);
        }

        // Gemini 1.5+ format
        $body = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 800,
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
        $ch = curl_init($apiUrl . '?key=' . $apiKey); // Alternative format
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log the raw response for debugging
        @file_put_contents(sys_get_temp_dir() . '/phpue_gemini_debug.log', 
            "[" . date('Y-m-d H:i:s') . "]\n" .
            "Prompt: $prompt\n" .
            "HTTP Code: $httpCode\n" .
            "Response: $resp\n" .
            "Error: $err\n\n", 
            FILE_APPEND | LOCK_EX
        );
        
        if ($err) {
            return "⚠️ **Network Error**\n\nUnable to connect to Gemini API: " . $err;
        }
        
        if ($httpCode !== 200) {
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
            // Log the structure for debugging
            $structure = json_encode($decoded, JSON_PRETTY_PRINT);
            @file_put_contents(sys_get_temp_dir() . '/phpue_gemini_structure.log', 
                "[" . date('Y-m-d H:i:s') . "] Unrecognized structure:\n$structure\n\n", 
                FILE_APPEND | LOCK_EX
            );
            
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