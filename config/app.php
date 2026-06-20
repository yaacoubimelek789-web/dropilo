<?php
/**
 * Application config. Set secrets in .env (never commit .env).
 */
$encryptionKey = getenv('APP_ENCRYPTION_KEY') ?: '';
if ($encryptionKey === '') {
    $encryptionKey = hash('sha256', 'shopify-made-easy-fiabilo-secret-key-2025', true);
}

return [
    'encryption_key' => $encryptionKey,
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
];
