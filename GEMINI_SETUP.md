# Gemini API Setup for ASK IMO

## Prerequisites

1. Get a Gemini API key from [Google AI Studio](https://makersuite.google.com/app/apikey)
2. The API key should have access to the Gemini 1.5 Flash model

## Configuration

### Option 1: Environment Variable (Recommended for Production)

Set the `GEMINI_API_KEY` environment variable:

```bash
export GEMINI_API_KEY="your-api-key-here"
```

### Option 2: Direct Configuration

Edit `config/app.php` and set your API key directly:

```php
return [
    'encryption_key' => getenv('APP_ENCRYPTION_KEY') ?: hash('sha256', 'shopify-made-easy-fiabilo-secret-key-2025', true),
    'gemini_api_key' => 'your-api-key-here', // Replace with your actual API key
];
```

## Database Setup

Run the SQL migration to create the chat tables:

```sql
-- Run the SQL file: sql/add_chat_tables.sql
-- Or the tables will be created automatically on first use (lazy migration)
```

The chat tables will be created automatically when you first use the chat feature, but you can also run `sql/add_chat_tables.sql` manually if preferred.

## Features

- **User-specific chat**: Each user has their own conversations and chat history
- **Data-aware responses**: Gemini has access to all user's Dropilou data (orders, shops, products, analytics)
- **Conversation history**: Users can view and switch between past conversations
- **Persistent storage**: All messages are stored in the database

## How It Works

1. When a user sends a message, the system:
   - Fetches all relevant user data (shops, orders, products, analytics)
   - Formats it as context for Gemini
   - Sends the message with context to Gemini API
   - Stores both user message and assistant response in the database

2. Each user's data is isolated - Gemini only sees data for the authenticated user

3. Conversation history is maintained per user, allowing context-aware follow-up questions

## Testing

1. Navigate to "ASK IMO" in the sidebar
2. Type a question like "What is my total revenue?"
3. IMO will respond based on your actual Dropilou data

## Troubleshooting

- **"Gemini API key not configured"**: Make sure you've set the API key in `config/app.php` or as an environment variable
- **API errors**: Check that your API key is valid and has access to Gemini 1.5 Flash
- **No data in responses**: Verify that the user has shops, orders, or products in the database
