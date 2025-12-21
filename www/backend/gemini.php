<?php
/**
 * Helper: callGeminiAPI
 * Centralized so AJAX endpoints can always include it.
 */
if (!function_exists('callGeminiAPI')) {
    function callGeminiAPI($prompt) {
        $apiUrl = getenv('GEMINI_API_URL');
        $apiKey = getenv('GEMINI_API_KEY');

        // Fallback local reply if no API configured
        if (!$apiUrl || !$apiKey) {
            return "(Gemini not configured) I can't reach Gemini API. Try setting GEMINI_API_URL and GEMINI_API_KEY. Meanwhile: here is a helpful tip about PHPue — start with your app's entry file and look for 'App.pvue' to bootstrap components.";
        }

        $isGoogle = str_contains($apiUrl, 'generativelanguage.googleapis.com');

        if ($isGoogle) {
            // Use x-goog-api-key header (preferred) and support both generateContent and generateText endpoints
            $headers = [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey
            ];

            if (str_contains($apiUrl, ':generateContent') || str_contains($apiUrl, 'generateContent')) {
                // Modern content-based payload
                $body = json_encode([
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ]
                ]);
            } elseif (str_contains($apiUrl, ':generateText') || str_contains($apiUrl, 'generateText') || str_contains($apiUrl, 'text-bison')) {
                // Older generateText payload
                $body = json_encode([
                    'prompt' => ['text' => $prompt],
                    'maxOutputTokens' => 400
                ]);
            } else {
                // Default to content-style payload (most flexible)
                $body = json_encode([
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ]
                ]);
            }

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        } else {
            // Generic provider: standard prompt + Bearer token header
            $payload = json_encode(['prompt' => $prompt, 'max_tokens' => 400]);

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        }

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return "(Gemini error) " . $err;
        }

        $decoded = json_decode($resp, true);

        // If Google returned an error object, surface it clearly
        if (is_array($decoded) && isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            return "(Gemini) " . $msg;
        }

        // Try to extract response in several known shapes
        if (is_array($decoded)) {
            // Google generateContent candidates
            if (isset($decoded['candidates'][0]['content'])) {
                $c = $decoded['candidates'][0]['content'];
                return is_string($c) ? $c : json_encode($c);
            }

            // Google older outputs shape
            if (isset($decoded['outputs'][0]['content'][0]['parts'][0]['text'])) {
                return $decoded['outputs'][0]['content'][0]['parts'][0]['text'];
            }

            // Some shapes use 'candidates'[0]['output']
            if (isset($decoded['candidates'][0]['output'])) {
                return is_string($decoded['candidates'][0]['output']) ? $decoded['candidates'][0]['output'] : json_encode($decoded['candidates'][0]['output']);
            }

            // Common shapes
            if (isset($decoded['reply'])) return $decoded['reply'];
            if (isset($decoded['choices'][0]['text'])) return $decoded['choices'][0]['text'];
            if (isset($decoded['result'])) return json_encode($decoded['result']);
        }

        // Log raw unexpected responses (avoid returning raw JSON to UI)
        @file_put_contents(sys_get_temp_dir() . '/phpue_gemini.log', date('Y-m-d H:i:s') . " - Unexpected raw response: " . $resp . "\n", FILE_APPEND | LOCK_EX);
        return "(Gemini) Unexpected response. The server returned an unknown format — check server logs (/tmp/phpue_gemini.log) for details.";
    }
}
