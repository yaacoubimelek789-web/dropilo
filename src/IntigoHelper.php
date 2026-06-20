<?php
declare(strict_types=1);

/**
 * Intigo Shipping API Helper.
 */
class IntigoHelper
{
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

    private const BASE_URL = 'https://external-api.intigo.tn/secure-api';
    private const DEV_BASE_URL = 'https://dev-external-api.intigo.tn/secure-api';

    // Status Constants from Documentation
    public const STATUS_ASSIGNED = 2;
    public const STATUS_DELIVERED = 6;
    public const STATUS_CANCELLED_ADMIN = 8;
    public const STATUS_SHIPPING = 10;
    public const STATUS_RETURN_PROVISIONAL = 15;
    public const STATUS_RETURN_DEFINITIVE = 16;
    public const STATUS_RETURN_SELLER = 17;
    public const STATUS_TRANSFER_HUB = 20;
    public const STATUS_LOST = 21;
    public const STATUS_IN_HUB = 25;
    public const STATUS_IN_TRANSIT = 27;
    public const STATUS_VERIFICATION = 28;
    public const STATUS_RELAUNCH = 29;

    /**
     * Map numerical status to human readable label.
     */
    public static function getStatusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_ASSIGNED => 'Assigné au livreur',
            self::STATUS_DELIVERED => 'Livré',
            self::STATUS_CANCELLED_ADMIN => 'Annulé par admin',
            self::STATUS_SHIPPING => 'En cours de livraison',
            self::STATUS_RETURN_PROVISIONAL => 'En retour provisoire',
            self::STATUS_RETURN_DEFINITIVE => 'En retour définitif',
            self::STATUS_RETURN_SELLER => 'En retour vendeur',
            self::STATUS_TRANSFER_HUB => 'En transfert vers centre',
            self::STATUS_LOST => 'Colis perdu',
            self::STATUS_IN_HUB => 'Entré au centre',
            self::STATUS_IN_TRANSIT => 'En transfert',
            self::STATUS_VERIFICATION => 'Vérification',
            self::STATUS_RELAUNCH => 'Relance',
            default => 'Statut inconnu (' . $status . ')',
        };
    }

    /**
     * Test the connection.
     */
    public static function testConnection(string $apiKey, string $merchantId, bool $sandbox = false): array
    {
        $response = self::request('GET', '/parcels/parcel?nid=0', $apiKey, $merchantId, null, $sandbox);
        
        // If we get a 401, it's definitely an auth failure.
        if (isset($response['http_code']) && $response['http_code'] === 401) {
            return ['success' => false, 'error' => 'Invalid API Key or Merchant ID (Unauthorized)'];
        }
        
        if (isset($response['error'])) {
            return ['success' => false, 'error' => $response['error']];
        }

        // If we get 400 or 404, but NOT 401, it means auth passed!
        return ['success' => true];
    }

    /**
     * Create a new parcel.
     */
    public static function addOrder(array $data, string $apiKey, string $merchantId, bool $sandbox = false): array
    {
        return self::request('POST', '/parcels', $apiKey, $merchantId, $data, $sandbox);
    }

    /**
     * Get tracking status.
     */
    public static function getTrackingStatus(string $trackingCode, string $apiKey, string $merchantId, bool $sandbox = false): array
    {
        return self::request('GET', '/parcels/parcel?nid=' . urlencode($trackingCode), $apiKey, $merchantId, null, $sandbox);
    }

    /**
     * Private request helper.
     */
    private static function request(string $method, string $path, string $apiKey, string $merchantId, ?array $body = null, bool $sandbox = false): array
    {
        $baseUrl = $sandbox ? self::DEV_BASE_URL : self::BASE_URL;
        $url = $baseUrl . $path;
        $ch = curl_init($url);
        
        // The doc shows: Authorization: { "apiKey": "API_KEY" }
        // We added idClient inside as well, as many Intigo-like APIs require merchant identification in header.
        $headers = [
            'Content-Type: application/json',
            'Authorization: { "apiKey": "' . $apiKey . '", "idClient": "' . $merchantId . '" }'
        ];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
 // For debugging connection issues

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'CURL Error: ' . $curlError, 'http_code' => $httpCode];
        }

        $data = json_decode($response, true) ?? [];
        $data['http_code'] = $httpCode;
        return $data;
    }
}
