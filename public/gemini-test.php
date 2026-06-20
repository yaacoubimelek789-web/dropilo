<?php
/**
 * Standalone Gemini API test - NO database required
 * Open directly: http://localhost/dropilo/public/gemini-test.php
 * Helps diagnose Gemini connection when DB is unavailable.
 */
header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../config/app.php';
$apiKey = $config['gemini_api_key'] ?? '';

if (empty($apiKey)) {
    die("ERROR: Gemini API key not set in config/app.php\n");
}

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
$body = json_encode([
    'contents' => [
        ['parts' => [['text' => 'Say hello in 5 words']]]
    ],
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Curl Error: " . ($curlErr ?: 'none') . "\n";
echo "Response length: " . strlen($response) . "\n\n";

if ($response === false) {
    die("FAILED: Could not connect. Check: PHP curl extension, firewall, SSL.\n");
}

$data = json_decode($response, true);
if ($httpCode === 200 && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    echo "SUCCESS! Gemini says: " . $data['candidates'][0]['content']['parts'][0]['text'] . "\n";
} else {
    echo "API Error:\n";
    echo json_encode($data ?: $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
