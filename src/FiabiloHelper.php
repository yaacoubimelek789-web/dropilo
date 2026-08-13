<?php
declare(strict_types=1);

/**
 * Fiabilo API: Add Token (create shipments), Tracking Token (check status).
 * Tokens are encrypted with AES-256-CBC before storage.
 */
class FiabiloHelper
{
    private const API_URL = 'https://www.fiabilo.tn/api/v1/post.php';
    private const CIPHER = 'aes-256-cbc';
    private const IV_LEN = 16;

    /** Encrypt plaintext for DB storage (returns base64: iv + ciphertext). */
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

    /** Decrypt stored value (base64: iv + ciphertext). */
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

    private static function keyBytes(string $key): string
    {
        if (strlen($key) === 32) {
            return $key;
        }
        return hash('sha256', $key, true);
    }

    public static function governorates(): array
    {
        return [
            'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès', 'Gafsa', 'Jendouba',
            'Kairouan', 'Kasserine', 'Kébili', 'La Manouba', 'Le Kef', 'Mahdia',
            'Médenine', 'Monastir', 'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana',
            'Sousse', 'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
        ];
    }

    public static function guessGovernorate(?string ...$parts): string
    {
        $hay = mb_strtolower(trim(implode(' ', array_filter($parts))), 'UTF-8');
        if ($hay === '') {
            return '';
        }
        $aliases = [
            'manouba' => 'La Manouba', 'la manouba' => 'La Manouba',
            'kef' => 'Le Kef', 'le kef' => 'Le Kef',
            'kebili' => 'Kébili', 'kébili' => 'Kébili',
            'medenine' => 'Médenine', 'médenine' => 'Médenine', 'mednine' => 'Médenine',
            'beja' => 'Béja', 'béja' => 'Béja',
            'gabes' => 'Gabès', 'gabès' => 'Gabès',
            'tataouine' => 'Tataouine', 'tatawin' => 'Tataouine',
            'nabeul' => 'Nabeul', 'hammamet' => 'Nabeul',
            'ben arous' => 'Ben Arous', 'benarous' => 'Ben Arous', 'ezzahra' => 'Ben Arous', 'rades' => 'Ben Arous',
            'ariana' => 'Ariana', 'la soukra' => 'Ariana',
            'tunis' => 'Tunis', 'lac' => 'Tunis',
            'sousse' => 'Sousse', 'msaken' => 'Sousse',
            'sfax' => 'Sfax', 'monastir' => 'Monastir', 'mahdia' => 'Mahdia',
            'bizerte' => 'Bizerte', 'kairouan' => 'Kairouan', 'gafsa' => 'Gafsa',
            'jendouba' => 'Jendouba', 'kasserine' => 'Kasserine', 'siliana' => 'Siliana',
            'zaghouan' => 'Zaghouan', 'tozeur' => 'Tozeur', 'sidi bouzid' => 'Sidi Bouzid',
        ];
        foreach ($aliases as $needle => $gov) {
            if (str_contains($hay, $needle)) {
                return $gov;
            }
        }
        foreach (self::governorates() as $gov) {
            if (str_contains($hay, mb_strtolower($gov, 'UTF-8'))) {
                return $gov;
            }
        }
        return '';
    }

    public static function resolveAddToken(PDO $pdo, int $userId, string $encryptionKey): string
    {
        $st = $pdo->prepare('SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$userId, 'fiabilo']);
        $row = $st->fetch();
        if ($row && !empty($row['add_token_encrypted'])) {
            $token = self::decrypt($row['add_token_encrypted'], $encryptionKey);
            if ($token !== '') {
                return $token;
            }
        }
        return trim((string) (getenv('FIABILO_ADD_TOKEN') ?: ''));
    }

    public static function saveAddToken(PDO $pdo, int $userId, string $token, string $encryptionKey): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $addEnc = self::encrypt($token, $encryptionKey);
        $st = $pdo->prepare('SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$userId, 'fiabilo']);
        $existing = $st->fetch();
        $trackEnc = $existing['tracking_token_encrypted'] ?? '';
        $pdo->prepare('INSERT INTO user_integrations (user_id, provider, add_token_encrypted, tracking_token_encrypted) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE add_token_encrypted = VALUES(add_token_encrypted)')
            ->execute([$userId, 'fiabilo', $addEnc, $trackEnc]);
    }

    public static function resolveTrackingToken(PDO $pdo, int $userId, string $encryptionKey): string
    {
        $st = $pdo->prepare('SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$userId, 'fiabilo']);
        $row = $st->fetch();
        if ($row && !empty($row['tracking_token_encrypted'])) {
            $token = self::decrypt($row['tracking_token_encrypted'], $encryptionKey);
            if ($token !== '') {
                return $token;
            }
        }
        return trim((string) (getenv('FIABILO_TRACKING_TOKEN') ?: ''));
    }

    public static function saveTrackingToken(PDO $pdo, int $userId, string $token, string $encryptionKey): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $trackEnc = self::encrypt($token, $encryptionKey);
        $st = $pdo->prepare('SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$userId, 'fiabilo']);
        $existing = $st->fetch();
        $addEnc = $existing['add_token_encrypted'] ?? '';
        $pdo->prepare('INSERT INTO user_integrations (user_id, provider, add_token_encrypted, tracking_token_encrypted) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE tracking_token_encrypted = VALUES(tracking_token_encrypted)')
            ->execute([$userId, 'fiabilo', $addEnc, $trackEnc]);
    }

    /** Normalize Fiabilo status for comparisons (accents, case, punctuation). */
    public static function normalizeStatus(?string $status): string
    {
        $s = mb_strtolower(trim((string) $status), 'UTF-8');
        if ($s === '') {
            return '';
        }
        $map = [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ô' => 'o', 'ö' => 'o',
            'î' => 'i', 'ï' => 'i',
            'ç' => 'c',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    /**
     * Classify a Fiabilo etat into: delivered|returned|shipping|pending|warehouse|deleted|unknown
     * Handles typos like "enlever", "enleve", "enlevement", "livrer", "retour expediteur", etc.
     */
    public static function classifyStatus(?string $status): string
    {
        $s = self::normalizeStatus($status);
        if ($s === '') {
            return 'unknown';
        }

        if (str_contains($s, 'supprim') || $s === 'delete' || $s === 'deleted') {
            return 'deleted';
        }

        // Returns / refusals / cancelled FIRST (before "expedi" matches Retour Expediteur)
        if (
            str_contains($s, 'retour')
            || str_contains($s, 'rtn')
            || str_contains($s, 'refus')
            || str_contains($s, 'annul')
            || str_contains($s, 'cancel')
            || str_contains($s, 'echou')
            || str_contains($s, 'a verifier')
            || str_contains($s, 'verifier')
            || str_contains($s, 'lost')
            || str_contains($s, 'perdu')
        ) {
            return 'returned';
        }

        // Out for delivery / in transit BEFORE generic "livr*" (avoids matching "livraison")
        if (
            str_contains($s, 'livraison')
            || str_contains($s, 'cours')
            || (str_contains($s, 'expedi') && !str_contains($s, 'expediteur'))
            || str_contains($s, 'ship')
            || str_contains($s, 'transit')
        ) {
            return 'shipping';
        }

        // Delivered — avoid matching "non livre" / "pas livre"
        if (
            preg_match('/\b(livre|livrer|livres|livree|livres?)\b/u', $s)
            || str_contains($s, 'delivered')
            || $s === 'recu'
            || str_contains($s, 'recue')
        ) {
            return 'delivered';
        }

        // Warehouse / hub
        if (
            str_contains($s, 'magasin')
            || str_contains($s, 'entrepot')
            || str_contains($s, 'depot')
            || str_contains($s, 'warehouse')
            || str_contains($s, 'centre')
            || str_contains($s, 'transfert')
            || str_contains($s, 'arame')
        ) {
            return 'warehouse';
        }

        // Pickup / assigned / waiting — includes "enlever", "enleve", "enlevement"
        if (
            str_contains($s, 'enlev')
            || str_contains($s, 'attente')
            || str_contains($s, 'assign')
            || str_contains($s, 'pending')
            || str_contains($s, 'pickup')
            || str_contains($s, 'ramass')
        ) {
            return 'pending';
        }

        return 'unknown';
    }

    public static function isTerminalStatus(?string $status): bool
    {
        $class = self::classifyStatus($status);
        return in_array($class, ['delivered', 'returned', 'deleted'], true);
    }

    /**
     * Persist a Fiabilo status onto an order row (timestamps + last_sync).
     * Returns true when the stored status text changed.
     */
    public static function applyStatusUpdate(PDO $pdo, int $orderId, string $status, ?string $previous = null): bool
    {
        $status = trim($status);
        if ($status === '') {
            return false;
        }

        $class = self::classifyStatus($status);
        $changed = $previous === null || trim((string) $previous) !== $status;

        $sql = 'UPDATE orders SET fiabilo_status = ?, fiabilo_last_sync = NOW()';
        $params = [$status];

        if ($class === 'delivered') {
            $sql .= ', delivered_at = COALESCE(delivered_at, NOW()), status = ?';
            $params[] = 'shipping';
        } elseif ($class === 'returned' || $class === 'deleted') {
            $sql .= ', returned_at = COALESCE(returned_at, NOW()), status = ?';
            $params[] = 'shipping';
        } elseif ($class === 'shipping' || $class === 'warehouse') {
            $sql .= ', shipped_at = COALESCE(shipped_at, NOW()), status = ?';
            $params[] = 'shipping';
        } elseif ($class === 'pending') {
            $sql .= ', status = ?';
            $params[] = 'shipping';
        }

        $sql .= ' WHERE id = ?';
        $params[] = $orderId;
        $pdo->prepare($sql)->execute($params);

        return $changed;
    }

    /**
     * Sync non-terminal Fiabilo shipments for a user.
     * Oldest / never-synced first so stuck "En attente" / "Enlever" don't stay forever.
     *
     * @return array{checked:int,updated:int,errors:array<int,string>}
     */
    public static function syncUserShipments(PDO $pdo, int $userId, string $encryptionKey, int $limit = 80, bool $force = false): array
    {
        $result = ['checked' => 0, 'updated' => 0, 'errors' => []];
        $token = self::resolveTrackingToken($pdo, $userId, $encryptionKey);
        if ($token === '') {
            $result['errors'][] = 'Missing FIABILO tracking token';
            return $result;
        }

        $limit = max(1, min(200, $limit));
        $staleSql = $force
            ? ''
            : ' AND (o.fiabilo_last_sync IS NULL OR o.fiabilo_last_sync < DATE_SUB(NOW(), INTERVAL 2 MINUTE))';

        // Pull candidates; filter terminal in PHP with fuzzy classifier (exact SQL lists miss "Retour Expediteur", "Enlever", etc.)
        $sql = "SELECT o.id, o.fiabilo_tracking_code, o.fiabilo_status
            FROM orders o
            JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ?
              AND o.fiabilo_tracking_code IS NOT NULL
              AND o.fiabilo_tracking_code != ''
              $staleSql
            ORDER BY (o.fiabilo_last_sync IS NULL) DESC, o.fiabilo_last_sync ASC, o.id DESC
            LIMIT " . (int) ($limit * 3);

        $st = $pdo->prepare($sql);
        $st->execute([$userId]);
        $candidates = $st->fetchAll();

        $toSync = [];
        foreach ($candidates as $row) {
            if (self::isTerminalStatus($row['fiabilo_status'] ?? null)) {
                // Still refresh terminal occasionally if never synced recently and force/stale
                continue;
            }
            $toSync[] = $row;
            if (count($toSync) >= $limit) {
                break;
            }
        }

        foreach ($toSync as $order) {
            $result['checked']++;
            $code = (string) $order['fiabilo_tracking_code'];
            $statusRes = self::getStatus($token, $code);
            if (isset($statusRes['error'])) {
                // Mark sync attempt so one bad code doesn't block the queue forever
                $pdo->prepare('UPDATE orders SET fiabilo_last_sync = NOW() WHERE id = ?')->execute([(int) $order['id']]);
                $result['errors'][] = $code . ': ' . $statusRes['error'];
                continue;
            }
            if (!isset($statusRes['etat'])) {
                $pdo->prepare('UPDATE orders SET fiabilo_last_sync = NOW() WHERE id = ?')->execute([(int) $order['id']]);
                continue;
            }
            $etat = (string) $statusRes['etat'];
            if (self::applyStatusUpdate($pdo, (int) $order['id'], $etat, $order['fiabilo_status'] ?? null)) {
                $result['updated']++;
            } else {
                // Same text — still bump last_sync so we rotate to older stuck packages
                $pdo->prepare('UPDATE orders SET fiabilo_last_sync = NOW() WHERE id = ?')->execute([(int) $order['id']]);
            }
        }

        return $result;
    }

    /**
     * Send order to Fiabilo (Add API).
     * Returns ['tracking_code' => '...'] on success or ['error' => '...'].
     */
    public static function sendOrder(string $addToken, array $order): array
    {
        $ouvrirRaw = $order['ouvrir'] ?? 0;
        if ($ouvrirRaw === 'Oui' || $ouvrirRaw === 'Non') {
            $ouvrir = $ouvrirRaw;
        } else {
            $ouvrir = ((int) $ouvrirRaw === 1) ? 'Oui' : 'Non';
        }

        $gouvernorat = trim((string) ($order['gouvernerat'] ?? $order['gouvernorat'] ?? ''));
        if ($gouvernorat === '' || !in_array($gouvernorat, self::governorates(), true)) {
            $gouvernorat = self::guessGovernorate(
                $order['billing_city'] ?? '',
                $order['shipping_city'] ?? '',
                $order['billing_address'] ?? '',
                $order['shipping_address'] ?? '',
                $order['billing_country'] ?? '',
                $order['ville'] ?? ''
            );
        }

        $ville = trim((string) ($order['ville'] ?? $order['billing_city'] ?? $order['shipping_city'] ?? ''));
        $adresse = trim((string) ($order['adresse'] ?? $order['billing_address'] ?? $order['shipping_address'] ?? ''));
        $localite = trim((string) ($order['localite'] ?? $order['billing_zip'] ?? $order['shipping_zip'] ?? ''));
        if ($localite !== '' && $adresse !== '' && !str_contains(mb_strtolower($adresse, 'UTF-8'), mb_strtolower($localite, 'UTF-8'))) {
            $adresse = trim($adresse . ', ' . $localite);
        }

        $prix = $order['prix'] ?? $order['total'] ?? 0;
        if (!is_numeric($prix)) {
            $prix = 0;
        }

        $body = [
            'token' => $addToken,
            'prix' => (string) $prix,
            'nom' => trim((string) ($order['nom'] ?? $order['billing_name'] ?? $order['shipping_name'] ?? '')),
            'tel' => trim((string) ($order['tel'] ?? $order['billing_phone'] ?? $order['phone'] ?? '')),
            'tel2' => trim((string) ($order['tel2'] ?? '')),
            'adresse' => $adresse,
            'gouvernerat' => $gouvernorat,
            'ville' => $ville,
            'cp' => trim((string) ($order['cp'] ?? $order['billing_zip'] ?? $order['shipping_zip'] ?? '')),
            'designation' => trim((string) ($order['designation'] ?? '')),
            'nb_article' => max(1, (int) ($order['nb_article'] ?? 1)),
            'nb_colis' => max(1, (int) ($order['nb_colis'] ?? 1)),
            'msg' => trim((string) ($order['msg'] ?? $order['notes'] ?? '')),
            'echange' => trim((string) ($order['echange'] ?? '')),
            'article' => trim((string) ($order['article'] ?? '')),
            'nb_echange' => trim((string) ($order['nb_echange'] ?? '')),
            'ouvrir' => $ouvrir,
        ];

        if ($body['nom'] === '' || $body['tel'] === '' || $body['adresse'] === '' || $body['gouvernerat'] === '') {
            return ['error' => 'Missing customer name, phone, address, or governorate'];
        }

        $resp = self::post(self::API_URL, $body);
        if (isset($resp['error'])) {
            return $resp;
        }
        $status = (int) ($resp['status'] ?? 0);
        if ($status !== 1) {
            return ['error' => $resp['status_message'] ?? 'API error'];
        }
        $code = $resp['status_message'] ?? null;
        if (!empty($resp['lien']) && preg_match('/[?&]code=([^&]+)/', $resp['lien'], $m)) {
            $code = $m[1];
        }
        return $code ? ['tracking_code' => $code] : ['error' => 'No tracking code in response'];
    }

    /**
     * Get shipment status (Tracking API).
     * Returns ['etat' => '...'] or ['error' => '...'].
     */
    public static function getStatus(string $trackingToken, string $code): array
    {
        $body = ['token' => $trackingToken, 'code' => $code];
        $resp = self::post(self::API_URL, $body);
        if (isset($resp['error'])) {
            return $resp;
        }
        $status = (int) ($resp['status'] ?? 0);
        if ($status !== 1) {
            $msg = $resp['status_message'] ?? $resp['etat'] ?? 'Token invalide';
            return ['error' => $msg];
        }
        return [
            'etat' => $resp['etat'] ?? 'Inconnu',
            'historique' => $resp['historique'] ?? [],
            'livreur' => $resp['livreur'] ?? null,
            'livreur_tel' => $resp['livreur_tel'] ?? null,
        ];
    }

    /**
     * Test connection: verify tracking token with a dummy code.
     * Returns ['ok' => true] or ['error' => '...'].
     */
    public static function testConnection(string $addToken, string $trackingToken): array
    {
        $body = ['token' => $trackingToken, 'code' => 'TEST'];
        $resp = self::post(self::API_URL, $body);
        if (isset($resp['error'])) {
            if (strpos($resp['error'], 'invalide') !== false || strpos($resp['error'], 'Token') !== false) {
                return ['error' => 'Tracking token invalid. Check that you used the Tracking token in the Tracking field.'];
            }
            return $resp;
        }
        $status = (int) ($resp['status'] ?? 0);
        if ($status === 1) {
            return ['ok' => true, 'message' => 'Connection OK. Tracking token accepted.'];
        }
        $msg = $resp['status_message'] ?? $resp['etat'] ?? '';
        if (stripos($msg, 'invalide') !== false || stripos($msg, 'token') !== false) {
            return ['error' => 'Invalid tracking token. Use the token from your shipping account (Tracking).'];
        }
        return ['ok' => true, 'message' => 'Connection OK.'];
    }

    private static function post(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            return ['error' => 'Connection error: ' . $err];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['error' => 'Invalid response from shipping service'];
        }
        return $decoded;
    }
}
