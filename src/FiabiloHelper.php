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

    /**
     * Send order to Fiabilo (Add API).
     * Returns ['tracking_code' => '...'] on success or ['error' => '...'].
     */
    public static function sendOrder(string $addToken, array $order): array
    {
        $body = [
            'token' => $addToken,
            'prix' => $order['total'] ?? 0,
            'nom' => $order['billing_name'] ?? $order['shipping_name'] ?? '',
            'tel' => $order['billing_phone'] ?? $order['phone'] ?? '',
            'adresse' => $order['billing_address'] ?? $order['shipping_address'] ?? '',
            'gouvernerat' => $order['billing_country'] ?? '',
            'ville' => $order['billing_city'] ?? $order['shipping_city'] ?? '',
            'cp' => $order['billing_zip'] ?? $order['shipping_zip'] ?? '',
            'designation' => $order['designation'] ?? '',
            'nb_article' => (int) ($order['nb_article'] ?? 1),
            'msg' => $order['notes'] ?? '',
            'ouvrir' => isset($order['ouvrir']) ? (int)$order['ouvrir'] : 0,
        ];
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
