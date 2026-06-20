<?php
/**
 * Chat API endpoint for IMO Bot
 * Handles POST (send message) and GET (fetch history/conversations)
 */
header('Content-Type: application/json');

$base = dirname(__DIR__);
$app = require $base . '/bootstrap.php';
require_once $base . '/src/GeminiHelper.php';
require_once $base . '/src/UserDataContext.php';

$uid = (int) $_SESSION['user_id'] ?? null;

if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = $app->pdo;

// Lazy migration: create chat tables if they don't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `chat_conversations` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int unsigned NOT NULL,
        `title` varchar(255) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `user_updated` (`user_id`, `updated_at`),
        CONSTRAINT `chat_conversations_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `chat_messages` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `conversation_id` int unsigned NOT NULL,
        `role` ENUM('user', 'assistant') NOT NULL,
        `content` text NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `conversation_id` (`conversation_id`),
        KEY `conversation_created` (`conversation_id`, `created_at`),
        CONSTRAINT `chat_messages_conversation_fk` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Tables might already exist, continue
}

if ($method === 'POST') {
    // Send a message
    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');
    $conversationId = isset($input['conversation_id']) ? (int) $input['conversation_id'] : null;

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        exit;
    }

    // Get or create conversation
    if ($conversationId) {
        // Verify conversation belongs to user
        $convStmt = $pdo->prepare("SELECT id FROM chat_conversations WHERE id = ? AND user_id = ?");
        $convStmt->execute([$conversationId, $uid]);
        $conversation = $convStmt->fetch();
        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }
        $conversationId = $conversation['id'];
    } else {
        // Create new conversation with title from first message
        $title = mb_substr($message, 0, 50);
        if (mb_strlen($message) > 50) $title .= '...';
        $convStmt = $pdo->prepare("INSERT INTO chat_conversations (user_id, title) VALUES (?, ?)");
        $convStmt->execute([$uid, $title]);
        $conversationId = $pdo->lastInsertId();
    }

    // Save user message
    $msgStmt = $pdo->prepare("INSERT INTO chat_messages (conversation_id, role, content) VALUES (?, 'user', ?)");
    $msgStmt->execute([$conversationId, $message]);

    // Update conversation timestamp
    $pdo->prepare("UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);

    // Fetch conversation history (last 20 messages for context)
    $historyStmt = $pdo->prepare("
        SELECT role, content 
        FROM chat_messages 
        WHERE conversation_id = ? 
        ORDER BY created_at ASC 
        LIMIT 20
    ");
    $historyStmt->execute([$conversationId]);
    $history = $historyStmt->fetchAll();

    // Build user data context
    $contextBuilder = new UserDataContext($pdo, $uid);
    $userContext = $contextBuilder->build();

    // Get Gemini API key
    $geminiApiKey = $app->app['gemini_api_key'] ?? '';
    if (empty($geminiApiKey)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gemini API key not configured. Please set GEMINI_API_KEY in config/app.php']);
        exit;
    }

    // Generate response from Gemini
    $gemini = new GeminiHelper($geminiApiKey);
    
    // Prepare history (exclude current message which is already saved)
    $conversationHistory = [];
    foreach ($history as $msg) {
        if ($msg['role'] === 'user' && $msg['content'] === $message && count($conversationHistory) === count($history) - 1) {
            // Skip the current message we just added
            continue;
        }
        $conversationHistory[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }

    $response = $gemini->generateResponse($message, $userContext, $conversationHistory);

    if (!$response['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $response['error']]);
        exit;
    }

    // Save assistant response
    $assistantMsg = $response['message'];
    $msgStmt = $pdo->prepare("INSERT INTO chat_messages (conversation_id, role, content) VALUES (?, 'assistant', ?)");
    $msgStmt->execute([$conversationId, $assistantMsg]);

    echo json_encode([
        'success' => true,
        'message' => $assistantMsg,
        'conversation_id' => $conversationId
    ]);

} elseif ($method === 'GET') {
    // Fetch conversations or messages
    $action = $_GET['action'] ?? 'conversations';
    
    if ($action === 'conversations') {
        // List all conversations for user
        $stmt = $pdo->prepare("
            SELECT 
                c.id, c.title, c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = c.id) as message_count
            FROM chat_conversations c
            WHERE c.user_id = ?
            ORDER BY c.updated_at DESC
            LIMIT 50
        ");
        $stmt->execute([$uid]);
        $conversations = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'conversations' => $conversations]);
        
    } elseif ($action === 'messages') {
        // Get messages for a conversation
        $conversationId = (int) ($_GET['conversation_id'] ?? 0);
        
        if (!$conversationId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'conversation_id is required']);
            exit;
        }
        
        // Verify conversation belongs to user
        $convStmt = $pdo->prepare("SELECT id FROM chat_conversations WHERE id = ? AND user_id = ?");
        $convStmt->execute([$conversationId, $uid]);
        $conversation = $convStmt->fetch();
        
        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT id, role, content, created_at
            FROM chat_messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
