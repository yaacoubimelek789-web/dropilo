<?php
/**
 * Application config (encryption key for integrations).
 * In production use an env var or a long random string stored securely.
 */
return [
    'encryption_key' => getenv('APP_ENCRYPTION_KEY') ?: hash('sha256', 'shopify-made-easy-fiabilo-secret-key-2025', true),
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: 'AIzaSyDP7O9Q4aAIRDv5FGFXdBsBQ7wQG6gzimU', // Set your Gemini API key here or via env var
];
