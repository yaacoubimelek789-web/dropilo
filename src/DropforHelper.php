<?php
declare(strict_types=1);

/**
 * Dropfor shipping API (https://www.dropfor.app/api/v1).
 * Auth: Authorization: Bearer <token>
 */
class DropforHelper
{
    private const CIPHER = 'aes-256-cbc';
    private const IV_LEN = 16;
    private const BASE_URL = 'https://www.dropfor.app/api/v1';

    /** Governorates accepted by POST /orders city. Spellings match the Dropfor API docs. */
    private const CITIES = [
        'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabes', 'Gafsa', 'Jendouba',
        'Kairouan', 'Kasserine', 'Kebili', 'El Kef', 'Mahdia', 'Manouba', 'Medenine',
        'Monastir', 'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse', 'Tataouine',
        'Tozeur', 'Tunis', 'Zaghouan',
    ];

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $existing = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN);
        $needed = [
            'dropfor_tracking_code' => 'VARCHAR(100) DEFAULT NULL',
            'dropfor_status' => 'VARCHAR(50) DEFAULT NULL',
            'dropfor_payment' => 'VARCHAR(30) DEFAULT NULL',
            'dropfor_sent_at' => 'DATETIME DEFAULT NULL',
            'dropfor_last_sync' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($needed as $name => $def) {
            if (!in_array($name, $existing, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN `$name` $def");
            }
        }
        $done = true;
    }

    public static function encrypt(string $plaintext, string $key): string
    {
        $key = self::keyBytes($key);
        $iv = random_bytes(self::IV_LEN);
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $stored, string $key): string
    {
        $key = self::keyBytes($key);
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < self::IV_LEN) {
            return '';
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $ciphertext = substr($raw, self::IV_LEN);
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    public static function saveToken(PDO $pdo, int $userId, string $token, string $encryptionKey): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $enc = self::encrypt($token, $encryptionKey);
        $pdo->prepare('INSERT INTO user_integrations (user_id, provider, add_token_encrypted, tracking_token_encrypted) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE add_token_encrypted = VALUES(add_token_encrypted)')
            ->execute([$userId, 'dropfor', $enc, '']);
    }

    public static function resolveToken(PDO $pdo, int $userId, string $encryptionKey): string
    {
        $st = $pdo->prepare('SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$userId, 'dropfor']);
        $row = $st->fetch();
        if ($row && !empty($row['add_token_encrypted'])) {
            return self::decrypt((string) $row['add_token_encrypted'], $encryptionKey);
        }
        return '';
    }

    public static function testConnection(string $token): array
    {
        $response = self::request('GET', '/status?page=1', $token);
        if (($response['http_code'] ?? 0) === 401) {
            return ['success' => false, 'error' => 'Invalid API key'];
        }
        if (!empty($response['error']) && !isset($response['success'])) {
            return ['success' => false, 'error' => (string) $response['error']];
        }
        if (($response['http_code'] ?? 0) >= 200 && ($response['http_code'] ?? 0) < 300) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => $response['message'] ?? $response['error'] ?? 'Connection failed'];
    }

    /**
     * Create a Dropfor order from a local order row plus line-item summary.
     * Expects designation, nb_article on $order when available.
     */
    public static function addOrder(array $order, string $token): array
    {
        $city = self::normalizeCity((string) ($order['billing_city'] ?? $order['shipping_city'] ?? ''));
        if ($city === '') {
            return ['error' => 'City must be a Dropfor governorate (Tunis, Ariana, Sfax, …)'];
        }
        $phone = self::phone((string) ($order['billing_phone'] ?? $order['phone'] ?? ''));
        if ($phone === '') {
            return ['error' => 'Phone is required'];
        }
        $name = trim((string) ($order['billing_name'] ?? $order['shipping_name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Customer name is required'];
        }
        $address = trim((string) ($order['billing_address'] ?? $order['shipping_address'] ?? ''));
        if ($address === '') {
            $address = $city;
        }
        $phone2raw = trim((string) ($order['phone'] ?? ''));
        $phone2 = self::phone($phone2raw);
        if ($phone2 === $phone) {
            $phone2 = '';
        }

        $payload = [
            'name' => $name,
            'phone' => $phone,
            'phone2' => $phone2,
            'city' => $city,
            'address' => $address,
            'total' => (float) ($order['total'] ?? 0),
            'quantity' => max(1, (int) ($order['nb_article'] ?? 1)),
            'content' => trim((string) ($order['designation'] ?? 'Order')) ?: 'Order',
            'note' => trim((string) ($order['notes'] ?? '')),
            'exchange' => false,
        ];

        $response = self::request('POST', '/orders', $token, $payload);
        $data = $response['data'] ?? null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (($response['success'] ?? false) && $id !== null && $id !== '') {
            return [
                'id' => (string) $id,
                'status' => self::normalizeStatus((string) ($data['status'] ?? 'en_attente')),
                'payment' => (string) ($data['payment'] ?? ''),
            ];
        }
        $err = $response['error'] ?? $response['message'] ?? null;
        if (is_array($err)) {
            $err = json_encode($err);
        }
        return ['error' => $err ?: ('Dropfor rejected the order (HTTP ' . (int) ($response['http_code'] ?? 0) . ')')];
    }

    public static function getStatus(string $orderId, string $token): array
    {
        $response = self::request('GET', '/status/' . rawurlencode($orderId), $token);
        if (!($response['success'] ?? false)) {
            return ['error' => $response['error'] ?? $response['message'] ?? 'Status lookup failed'];
        }
        return [
            'status' => self::normalizeStatus((string) ($response['status'] ?? '')),
            'payment' => (string) ($response['payment'] ?? ''),
            'order_id' => (string) ($response['order_id'] ?? $orderId),
        ];
    }

    public static function applyStatusUpdate(PDO $pdo, int $orderId, string $status, ?string $previous, string $payment = ''): void
    {
        if ($status === '' || $status === $previous) {
            if ($payment !== '') {
                $pdo->prepare('UPDATE orders SET dropfor_payment = ?, dropfor_last_sync = NOW() WHERE id = ?')->execute([$payment, $orderId]);
            }
            return;
        }
        $sql = 'UPDATE orders SET dropfor_status = ?, dropfor_payment = ?, dropfor_last_sync = NOW()';
        $lower = mb_strtolower($status);
        if (str_contains($lower, 'livr')) {
            $sql .= ', delivered_at = COALESCE(delivered_at, NOW())';
        } elseif (str_contains($lower, 'retour')) {
            $sql .= ', returned_at = COALESCE(returned_at, NOW())';
        }
        $sql .= ' WHERE id = ?';
        $pdo->prepare($sql)->execute([$status, $payment, $orderId]);
    }

    /** Refresh non-terminal Dropfor shipments. Returns how many rows changed. */
    public static function syncUserShipments(PDO $pdo, int $userId, string $encryptionKey, int $limit = 40): int
    {
        self::ensureSchema($pdo);
        $token = self::resolveToken($pdo, $userId, $encryptionKey);
        if ($token === '') {
            return 0;
        }
        $st = $pdo->prepare("SELECT o.id, o.dropfor_tracking_code, o.dropfor_status
            FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ? AND o.dropfor_tracking_code IS NOT NULL
              AND (o.dropfor_status IS NULL OR LOWER(o.dropfor_status) NOT IN ('livré', 'livre', 'livree', 'retour', 'retourné'))
            ORDER BY o.dropfor_sent_at DESC LIMIT " . (int) $limit);
        $st->execute([$userId]);
        $updated = 0;
        foreach ($st->fetchAll() as $order) {
            $res = self::getStatus((string) $order['dropfor_tracking_code'], $token);
            if (!isset($res['status'])) {
                continue;
            }
            if ($res['status'] !== ($order['dropfor_status'] ?? '')) {
                self::applyStatusUpdate($pdo, (int) $order['id'], $res['status'], $order['dropfor_status'] ?? null, $res['payment'] ?? '');
                $updated++;
            }
        }
        return $updated;
    }

    public static function normalizeStatus(string $raw): string
    {
        $s = self::fold($raw);
        if ($s === '') {
            return 'En attente';
        }
        if (str_contains($s, 'cours')) {
            return 'En cours';
        }
        if (str_contains($s, 'retour')) {
            return 'Retour';
        }
        if (in_array($s, ['livre', 'livres', 'livrer', 'livree', 'delivered', 'recu'], true) || str_starts_with($s, 'livre ')) {
            return 'Livré';
        }
        if (str_contains($s, 'depot')) {
            return 'Au dépôt';
        }
        if (str_contains($s, 'pickup')) {
            return 'Pickup demandé';
        }
        if (str_contains($s, 'verif')) {
            return 'À vérifier';
        }
        if (str_contains($s, 'relance')) {
            return 'À relance';
        }
        if (str_contains($s, 'attente')) {
            return 'En attente';
        }
        return trim($raw) !== '' ? trim($raw) : 'En attente';
    }

    public static function normalizeCity(string $city): string
    {
        $fold = self::fold($city);
        if ($fold === '') {
            return '';
        }
        if ($fold === 'kef' || $fold === 'le kef' || $fold === 'el kef' || str_contains($fold, 'kef')) {
            return 'El Kef';
        }
        foreach (self::CITIES as $name) {
            $nf = self::fold($name);
            if ($fold === $nf || str_starts_with($fold, $nf . ' ') || str_ends_with($fold, ' ' . $nf)) {
                return $name;
            }
        }
        return '';
    }

    public static function phone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '216') && strlen($digits) > 8) {
            $digits = substr($digits, 3);
        }
        if (strlen($digits) > 8) {
            $digits = substr($digits, -8);
        }
        return strlen($digits) >= 8 ? $digits : '';
    }

    private static function request(string $method, string $path, string $token, ?array $body = null): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $ca = dirname(__DIR__) . '/config/cacert.pem';
        if (is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return ['error' => 'CURL Error: ' . $curlError, 'http_code' => $httpCode];
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['error' => 'Unexpected response', 'http_code' => $httpCode];
        }
        $data['http_code'] = $httpCode;
        return $data;
    }

    private static function keyBytes(string $key): string
    {
        if (strlen($key) === 32) {
            return $key;
        }
        return hash('sha256', $key, true);
    }

    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);
        $map = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'];
        return trim(preg_replace('/\s+/', ' ', strtr($value, $map)) ?? '');
    }
}
