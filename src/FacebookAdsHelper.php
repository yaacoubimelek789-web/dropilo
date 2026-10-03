<?php
declare(strict_types=1);

/**
 * Meta (Facebook) Ads Insights sync via Graph API.
 * Stores a long-lived user/system token + ad account id; caches daily spend metrics.
 */
class FacebookAdsHelper
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    public static function encrypt(string $plaintext, string $key): string
    {
        return FiabiloHelper::encrypt($plaintext, $key);
    }

    public static function decrypt(string $stored, string $key): string
    {
        return FiabiloHelper::decrypt($stored, $key);
    }

    public static function resolveToken(PDO $pdo, int $userId, string $encryptionKey): string
    {
        $st = $pdo->prepare("SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = 'meta_ads'");
        $st->execute([$userId]);
        $row = $st->fetch();
        if ($row && !empty($row['add_token_encrypted'])) {
            $token = self::decrypt($row['add_token_encrypted'], $encryptionKey);
            if ($token !== '') {
                return $token;
            }
        }
        return trim((string) (getenv('META_ADS_ACCESS_TOKEN') ?: ''));
    }

    public static function saveCredentials(PDO $pdo, int $userId, string $accessToken, string $adAccountId, string $encryptionKey): void
    {
        $accessToken = trim($accessToken);
        $adAccountId = self::normalizeAdAccountId($adAccountId);
        if ($accessToken === '') {
            return;
        }
        $enc = self::encrypt($accessToken, $encryptionKey);
        $st = $pdo->prepare("SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = 'meta_ads'");
        $st->execute([$userId]);
        $existing = $st->fetch();
        $trackEnc = $adAccountId !== ''
            ? self::encrypt($adAccountId, $encryptionKey)
            : ($existing['tracking_token_encrypted'] ?? '');
        $pdo->prepare("INSERT INTO user_integrations (user_id, provider, add_token_encrypted, tracking_token_encrypted)
            VALUES (?, 'meta_ads', ?, ?)
            ON DUPLICATE KEY UPDATE
                add_token_encrypted = VALUES(add_token_encrypted),
                tracking_token_encrypted = IF(VALUES(tracking_token_encrypted) = '', tracking_token_encrypted, VALUES(tracking_token_encrypted))")
            ->execute([$userId, $enc, $trackEnc]);

        if ($adAccountId !== '') {
            CashflowHelper::saveSettings($pdo, $userId, array_merge(
                CashflowHelper::getSettings($pdo, $userId),
                ['meta_ad_account_id' => $adAccountId]
            ));
        }
    }

    public static function deleteCredentials(PDO $pdo, int $userId): void
    {
        $pdo->prepare("DELETE FROM user_integrations WHERE user_id = ? AND provider = 'meta_ads'")->execute([$userId]);
    }

    public static function hasCredentials(PDO $pdo, int $userId, string $encryptionKey): bool
    {
        return self::resolveToken($pdo, $userId, $encryptionKey) !== ''
            && self::resolveAdAccountId($pdo, $userId, $encryptionKey) !== '';
    }

    public static function resolveAdAccountId(PDO $pdo, int $userId, string $encryptionKey): string
    {
        $settings = CashflowHelper::getSettings($pdo, $userId);
        if (!empty($settings['meta_ad_account_id'])) {
            return self::normalizeAdAccountId($settings['meta_ad_account_id']);
        }
        $st = $pdo->prepare("SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = 'meta_ads'");
        $st->execute([$userId]);
        $row = $st->fetch();
        if ($row && !empty($row['tracking_token_encrypted'])) {
            $id = self::decrypt($row['tracking_token_encrypted'], $encryptionKey);
            if ($id !== '') {
                return self::normalizeAdAccountId($id);
            }
        }
        return self::normalizeAdAccountId((string) (getenv('META_ADS_ACCOUNT_ID') ?: ''));
    }

    public static function normalizeAdAccountId(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        $id = preg_replace('/^act_/i', '', $id) ?? $id;
        return preg_replace('/\D+/', '', $id) ?? '';
    }

    /**
     * Sync daily insights into ad_insights_daily.
     * @return array{ok?:bool,days?:int,error?:string}
     */
    public static function syncInsights(PDO $pdo, int $userId, string $encryptionKey, string $since, string $until): array
    {
        CashflowHelper::ensureSchema($pdo);
        $token = self::resolveToken($pdo, $userId, $encryptionKey);
        $accountId = self::resolveAdAccountId($pdo, $userId, $encryptionKey);
        if ($token === '' || $accountId === '') {
            return ['error' => 'Connect Meta Ads: access token + ad account ID required'];
        }

        $fields = 'spend,impressions,clicks,reach,cpc,cpm,ctr,actions,action_values';
        $url = self::GRAPH . '/act_' . rawurlencode($accountId) . '/insights?' . http_build_query([
            'fields' => $fields,
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'level' => 'account',
            'access_token' => $token,
        ]);

        $resp = self::get($url);
        if (isset($resp['error'])) {
            $msg = is_array($resp['error']) ? ($resp['error']['message'] ?? 'Meta API error') : (string) $resp['error'];
            return ['error' => $msg];
        }

        $rows = $resp['data'] ?? [];
        $upsert = $pdo->prepare("INSERT INTO ad_insights_daily
            (user_id, insight_date, spend, impressions, clicks, reach, cpc, cpm, ctr, fb_purchases, fb_purchase_value, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                spend = VALUES(spend),
                impressions = VALUES(impressions),
                clicks = VALUES(clicks),
                reach = VALUES(reach),
                cpc = VALUES(cpc),
                cpm = VALUES(cpm),
                ctr = VALUES(ctr),
                fb_purchases = VALUES(fb_purchases),
                fb_purchase_value = VALUES(fb_purchase_value),
                synced_at = NOW()");

        $days = 0;
        foreach ($rows as $row) {
            $date = $row['date_start'] ?? null;
            if (!$date) {
                continue;
            }
            $purchases = 0;
            $purchaseValue = 0.0;
            foreach (($row['actions'] ?? []) as $action) {
                if (($action['action_type'] ?? '') === 'purchase' || ($action['action_type'] ?? '') === 'omni_purchase') {
                    $purchases = max($purchases, (int) ($action['value'] ?? 0));
                }
            }
            foreach (($row['action_values'] ?? []) as $action) {
                if (($action['action_type'] ?? '') === 'purchase' || ($action['action_type'] ?? '') === 'omni_purchase') {
                    $purchaseValue = max($purchaseValue, (float) ($action['value'] ?? 0));
                }
            }
            $upsert->execute([
                $userId,
                $date,
                (float) ($row['spend'] ?? 0),
                (int) ($row['impressions'] ?? 0),
                (int) ($row['clicks'] ?? 0),
                (int) ($row['reach'] ?? 0),
                isset($row['cpc']) ? (float) $row['cpc'] : null,
                isset($row['cpm']) ? (float) $row['cpm'] : null,
                isset($row['ctr']) ? (float) $row['ctr'] : null,
                $purchases,
                $purchaseValue,
            ]);
            $days++;
        }

        return ['ok' => true, 'days' => $days];
    }

    public static function testConnection(string $token, string $adAccountId): array
    {
        $adAccountId = self::normalizeAdAccountId($adAccountId);
        if ($token === '' || $adAccountId === '') {
            return ['error' => 'Token and Ad Account ID are required'];
        }
        $url = self::GRAPH . '/act_' . rawurlencode($adAccountId) . '?' . http_build_query([
            'fields' => 'name,account_id,currency,amount_spent',
            'access_token' => $token,
        ]);
        $resp = self::get($url);
        if (isset($resp['error'])) {
            $msg = is_array($resp['error']) ? ($resp['error']['message'] ?? 'Meta API error') : (string) $resp['error'];
            return ['error' => $msg];
        }
        return [
            'ok' => true,
            'name' => $resp['name'] ?? 'Ad Account',
            'currency' => $resp['currency'] ?? '',
            'account_id' => $resp['account_id'] ?? $adAccountId,
        ];
    }

    private static function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            return ['error' => 'Connection error: ' . $err];
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return ['error' => 'Invalid response from Meta'];
        }
        return $decoded;
    }
}
