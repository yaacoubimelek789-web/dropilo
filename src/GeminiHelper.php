<?php
/**
 * Gemini API Helper for IMO Bot integration
 */
class GeminiHelper
{
    private $apiKey;
    private $models = [
        'gemini-2.0-flash',
        'gemini-1.5-flash',
        'gemini-1.5-pro',
    ];

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Generate a response from Gemini with user context
     * @param string $userMessage The user's message
     * @param string $userContext The formatted user data context
     * @param array $conversationHistory Array of messages with 'role' and 'content'
     * @return array ['success' => bool, 'message' => string, 'error' => string|null]
     */
    public function generateResponse($userMessage, $userContext, $conversationHistory = [])
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'Gemini API key not configured'];
        }

        // Build the prompt with user context
        $systemPrompt = $this->buildSystemPrompt($userContext);
        
        // Build full prompt: system + history + current message (simple single-turn format)
        $fullPrompt = $systemPrompt . "\n\n---\n\n";
        foreach ($conversationHistory as $msg) {
            $label = $msg['role'] === 'user' ? 'User' : 'Bot';
            $fullPrompt .= $label . ": " . $msg['content'] . "\n\n";
        }
        $fullPrompt .= "User: " . $userMessage;

        // Simple format matching working curl - single content block
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $fullPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048,
            ],
        ];

        $lastError = null;
        foreach ($this->models as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($this->apiKey);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $lastError = 'Connection failed: ' . ($curlError ?: 'Unknown curl error');
                continue;
            }

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    return [
                        'success' => true,
                        'message' => $data['candidates'][0]['content']['parts'][0]['text']
                    ];
                }
            }

            $errorData = json_decode($response, true);
            $apiMsg = $errorData['error']['message'] ?? $errorData['error']['status'] ?? null;
            $lastError = $apiMsg ?: ('HTTP ' . $httpCode);

            $isQuotaError = $apiMsg && (stripos($apiMsg, 'quota') !== false || stripos($apiMsg, 'RESOURCE_EXHAUSTED') !== false);
            if (!$isQuotaError) {
                break;
            }
        }

        $userFriendlyError = $lastError;
        if (stripos($lastError, 'quota') !== false) {
            $userFriendlyError = 'API quota exceeded. Please try again in a few minutes, or check your Google AI Studio billing.';
        }

        return [
            'success' => false,
            'error' => $userFriendlyError
        ];
    }

    /**
     * Build system prompt with user's Dropilou data context
     */
    private function buildSystemPrompt($userContext)
    {
        $prompt = "You are IMO, a bot for Dropilou — an order and logistics management system. You are NOT an AI assistant; you are a fast, action-oriented bot. Help users get quick answers and insights about their business data.\n\n";
        $prompt .= "IMPORTANT: You are speaking with a specific user. Only use the data provided below for this user. Do not make up or assume data from other users.\n\n";
        $prompt .= "USER'S DROPILOU DATA:\n";
        $prompt .= $userContext;
        $prompt .= "\n\n";
        $prompt .= "Instructions:\n";
        $prompt .= "- Answer questions about orders, shops, products, shipping, revenue, and analytics using ONLY the data provided above.\n";
        $prompt .= "- Be concise, helpful, and professional.\n";
        $prompt .= "- If asked about data not available in the context, say you don't have that information for this user.\n";
        $prompt .= "- Use natural language and avoid technical jargon when possible.\n";
        $prompt .= "- When mentioning numbers, be specific and accurate.\n";
        
        return $prompt;
    }
}
