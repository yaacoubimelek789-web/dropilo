<?php
declare(strict_types=1);

/**
 * Cashflow / true-profit engine for COD dropshipping.
 * Uses delivered revenue, product COGS, courier fees, Meta ads spend, and manual charges/cashback.
 */
class CashflowHelper
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_settings (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            courier_fee_delivered DECIMAL(10,2) NOT NULL DEFAULT 8.00,
            courier_fee_return DECIMAL(10,2) NOT NULL DEFAULT 8.00,
            handling_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 3.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'TND',
            meta_ad_account_id VARCHAR(64) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_entries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            entry_type VARCHAR(32) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            label VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_finance_user_date (user_id, entry_date),
            INDEX idx_finance_type (user_id, entry_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ad_insights_daily (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            insight_date DATE NOT NULL,
            spend DECIMAL(12,2) NOT NULL DEFAULT 0,
            impressions INT UNSIGNED NOT NULL DEFAULT 0,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            reach INT UNSIGNED NOT NULL DEFAULT 0,
            cpc DECIMAL(12,4) DEFAULT NULL,
            cpm DECIMAL(12,4) DEFAULT NULL,
            ctr DECIMAL(12,4) DEFAULT NULL,
            fb_purchases INT UNSIGNED NOT NULL DEFAULT 0,
            fb_purchase_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            synced_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_user_day (user_id, insight_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function getSettings(PDO $pdo, int $userId): array
    {
        self::ensureSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM finance_settings WHERE user_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO finance_settings (user_id) VALUES (?)')->execute([$userId]);
            $st->execute([$userId]);
            $row = $st->fetch() ?: [];
        }
        return [
            'courier_fee_delivered' => (float) ($row['courier_fee_delivered'] ?? 8),
            'courier_fee_return' => (float) ($row['courier_fee_return'] ?? 8),
            'handling_fee_pct' => (float) ($row['handling_fee_pct'] ?? 3),
            'currency' => (string) ($row['currency'] ?? 'TND'),
            'meta_ad_account_id' => (string) ($row['meta_ad_account_id'] ?? ''),
        ];
    }

    public static function saveSettings(PDO $pdo, int $userId, array $data): void
    {
        self::ensureSchema($pdo);
        $pdo->prepare('INSERT INTO finance_settings (user_id, courier_fee_delivered, courier_fee_return, handling_fee_pct, currency, meta_ad_account_id)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                courier_fee_delivered = VALUES(courier_fee_delivered),
                courier_fee_return = VALUES(courier_fee_return),
                handling_fee_pct = VALUES(handling_fee_pct),
                currency = VALUES(currency),
                meta_ad_account_id = VALUES(meta_ad_account_id)')
            ->execute([
                $userId,
                max(0, (float) ($data['courier_fee_delivered'] ?? 8)),
                max(0, (float) ($data['courier_fee_return'] ?? 8)),
                max(0, min(50, (float) ($data['handling_fee_pct'] ?? 3))),
                substr(trim((string) ($data['currency'] ?? 'TND')), 0, 8) ?: 'TND',
                trim((string) ($data['meta_ad_account_id'] ?? '')) ?: null,
            ]);
    }

    public static function addEntry(PDO $pdo, int $userId, string $date, string $type, float $amount, string $label = '', string $notes = ''): void
    {
        self::ensureSchema($pdo);
        $allowed = ['charge', 'cashback', 'ad_spend', 'other_income', 'other_expense'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Invalid finance entry type');
        }
        $pdo->prepare('INSERT INTO finance_entries (user_id, entry_date, entry_type, amount, label, notes) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $date, $type, abs($amount), $label !== '' ? $label : null, $notes !== '' ? $notes : null]);
    }

    public static function deleteEntry(PDO $pdo, int $userId, int $entryId): void
    {
        $pdo->prepare('DELETE FROM finance_entries WHERE id = ? AND user_id = ?')->execute([$entryId, $userId]);
    }

    public static function listEntries(PDO $pdo, int $userId, string $from, string $to, int $limit = 50): array
    {
        self::ensureSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM finance_entries WHERE user_id = ? AND entry_date >= ? AND entry_date <= ? ORDER BY entry_date DESC, id DESC LIMIT ' . (int) $limit);
        $st->execute([$userId, $from, $to]);
        return $st->fetchAll();
    }

    /** Order pipeline + money stats for a date window. */
    public static function compute(PDO $pdo, int $userId, string $from, string $to, ?array $settings = null): array
    {
        self::ensureSchema($pdo);
        $settings = $settings ?? self::getSettings($pdo, $userId);
        $feeDel = (float) $settings['courier_fee_delivered'];
        $feeRet = (float) $settings['courier_fee_return'];
        $handlingPct = (float) $settings['handling_fee_pct'];

        // Delivered
        $stDel = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(o.total), 0) AS revenue
            FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ?
              AND o.delivered_at IS NOT NULL
              AND DATE(o.delivered_at) >= ? AND DATE(o.delivered_at) <= ?
              AND (
                (LOWER(o.fiabilo_status) LIKE '%livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%non livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%livraison%')
                OR LOWER(o.fiabilo_status) IN ('delivered','recu','reçu','livree')
                OR (o.fiabilo_status IS NULL AND o.delivered_at IS NOT NULL)
              )
        ");
        $stDel->execute([$userId, $from, $to]);
        $del = $stDel->fetch() ?: ['cnt' => 0, 'revenue' => 0];
        $deliveredCount = (int) $del['cnt'];
        $deliveredRevenue = (float) $del['revenue'];

        // Returned
        $stRet = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(o.total), 0) AS lost_value
            FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ?
              AND DATE(COALESCE(o.returned_at, o.fiabilo_last_sync, o.created_at)) >= ?
              AND DATE(COALESCE(o.returned_at, o.fiabilo_last_sync, o.created_at)) <= ?
              AND (
                LOWER(o.fiabilo_status) LIKE '%retour%'
                OR LOWER(o.fiabilo_status) LIKE '%rtn%'
                OR LOWER(o.fiabilo_status) LIKE '%refus%'
                OR LOWER(o.fiabilo_status) LIKE '%annul%'
                OR LOWER(o.fiabilo_status) LIKE '%supprim%'
              )
        ");
        $stRet->execute([$userId, $from, $to]);
        $ret = $stRet->fetch() ?: ['cnt' => 0, 'lost_value' => 0];
        $returnedCount = (int) $ret['cnt'];
        $returnedValue = (float) $ret['lost_value'];

        // Confirmed / follow-up created or marked in range
        $stConf = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ? AND (o.confirmed = 1 OR o.status = 'confirmed')
              AND DATE(COALESCE(o.confirmed_at, o.created_at)) >= ? AND DATE(COALESCE(o.confirmed_at, o.created_at)) <= ?");
        $stConf->execute([$userId, $from, $to]);
        $confirmedCount = (int) $stConf->fetchColumn();

        $stFu = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ? AND (o.follow_up = 1 OR o.status = 'followup')
              AND DATE(COALESCE(o.followup_at, o.created_at)) >= ? AND DATE(COALESCE(o.followup_at, o.created_at)) <= ?");
        $stFu->execute([$userId, $from, $to]);
        $followupCount = (int) $stFu->fetchColumn();

        $stNew = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(o.total),0) FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ? AND DATE(COALESCE(o.order_created_at, o.created_at)) >= ? AND DATE(COALESCE(o.order_created_at, o.created_at)) <= ?");
        $stNew->execute([$userId, $from, $to]);
        $rowNew = $stNew->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $ordersCreated = (int) $rowNew[0];
        $ordersCreatedValue = (float) $rowNew[1];

        // COGS on delivered
        $stCost = $pdo->prepare("
            SELECT COALESCE(SUM(li.lineitem_quantity * COALESCE(p.cost, 0)), 0)
            FROM order_line_items li
            JOIN orders o ON li.order_id = o.id
            JOIN shops s ON o.shop_id = s.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE s.user_id = ?
              AND o.delivered_at IS NOT NULL
              AND DATE(o.delivered_at) >= ? AND DATE(o.delivered_at) <= ?
              AND (
                (LOWER(o.fiabilo_status) LIKE '%livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%non livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%livraison%')
                OR LOWER(o.fiabilo_status) IN ('delivered','recu','reçu','livree')
                OR (o.fiabilo_status IS NULL AND o.delivered_at IS NOT NULL)
              )
        ");
        $stCost->execute([$userId, $from, $to]);
        $cogs = (float) $stCost->fetchColumn();

        // COGS tied to returns (lost product cost)
        $stCostRet = $pdo->prepare("
            SELECT COALESCE(SUM(li.lineitem_quantity * COALESCE(p.cost, 0)), 0)
            FROM order_line_items li
            JOIN orders o ON li.order_id = o.id
            JOIN shops s ON o.shop_id = s.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE s.user_id = ?
              AND DATE(COALESCE(o.returned_at, o.fiabilo_last_sync, o.created_at)) >= ?
              AND DATE(COALESCE(o.returned_at, o.fiabilo_last_sync, o.created_at)) <= ?
              AND (
                LOWER(o.fiabilo_status) LIKE '%retour%'
                OR LOWER(o.fiabilo_status) LIKE '%rtn%'
                OR LOWER(o.fiabilo_status) LIKE '%refus%'
                OR LOWER(o.fiabilo_status) LIKE '%annul%'
                OR LOWER(o.fiabilo_status) LIKE '%supprim%'
              )
        ");
        $stCostRet->execute([$userId, $from, $to]);
        $returnCogs = (float) $stCostRet->fetchColumn();

        // Manual finance entries
        $stEnt = $pdo->prepare("
            SELECT entry_type, COALESCE(SUM(amount),0) AS total
            FROM finance_entries
            WHERE user_id = ? AND entry_date >= ? AND entry_date <= ?
            GROUP BY entry_type
        ");
        $stEnt->execute([$userId, $from, $to]);
        $entryTotals = [
            'charge' => 0.0,
            'cashback' => 0.0,
            'ad_spend' => 0.0,
            'other_income' => 0.0,
            'other_expense' => 0.0,
        ];
        foreach ($stEnt->fetchAll() as $e) {
            $entryTotals[$e['entry_type']] = (float) $e['total'];
        }

        // Meta ads cached insights
        $stAds = $pdo->prepare("
            SELECT
                COALESCE(SUM(spend),0) AS spend,
                COALESCE(SUM(impressions),0) AS impressions,
                COALESCE(SUM(clicks),0) AS clicks,
                COALESCE(SUM(reach),0) AS reach,
                COALESCE(SUM(fb_purchases),0) AS fb_purchases,
                COALESCE(SUM(fb_purchase_value),0) AS fb_purchase_value
            FROM ad_insights_daily
            WHERE user_id = ? AND insight_date >= ? AND insight_date <= ?
        ");
        $stAds->execute([$userId, $from, $to]);
        $ads = $stAds->fetch() ?: [];
        $metaSpend = (float) ($ads['spend'] ?? 0);
        $impressions = (int) ($ads['impressions'] ?? 0);
        $clicks = (int) ($ads['clicks'] ?? 0);
        $reach = (int) ($ads['reach'] ?? 0);
        $fbPurchases = (int) ($ads['fb_purchases'] ?? 0);
        $fbPurchaseValue = (float) ($ads['fb_purchase_value'] ?? 0);

        $manualAdSpend = $entryTotals['ad_spend'];
        $totalAdSpend = $metaSpend + $manualAdSpend;

        $courierDeliveredFees = $deliveredCount * $feeDel;
        $courierReturnFees = $returnedCount * $feeRet;
        $afterCourierFees = max(0, $deliveredRevenue - $courierDeliveredFees);
        $handlingFees = $afterCourierFees * ($handlingPct / 100);
        $courierPayout = $afterCourierFees - $handlingFees;

        $charges = $entryTotals['charge'] + $entryTotals['other_expense'];
        $cashback = $entryTotals['cashback'] + $entryTotals['other_income'];

        // True economics (COD Tunisia style) — same stack shown in the UI
        $grossProfit = $deliveredRevenue - $cogs;
        $cashFlow = $deliveredRevenue
            - $cogs
            - $courierDeliveredFees
            - $handlingFees
            - $courierReturnFees
            - $returnCogs
            - $totalAdSpend
            - $charges
            + $cashback;
        $netAfterCourier = $courierPayout - $cogs - $courierReturnFees - $returnCogs;
        $netProfit = $cashFlow;

        $deliveryDenom = $deliveredCount + $returnedCount;
        $deliveryRate = $deliveryDenom > 0 ? ($deliveredCount / $deliveryDenom) * 100 : 0;

        $realRoas = $totalAdSpend > 0 ? ($deliveredRevenue / $totalAdSpend) : null;
        $profitRoas = $totalAdSpend > 0 ? ($netProfit / $totalAdSpend) : null;
        $cpp = $deliveredCount > 0 ? ($totalAdSpend / $deliveredCount) : null;
        $cpc = $clicks > 0 ? ($metaSpend / $clicks) : null;
        $cpm = $impressions > 0 ? (($metaSpend / $impressions) * 1000) : null;
        $ctr = $impressions > 0 ? (($clicks / $impressions) * 100) : null;
        $aov = $deliveredCount > 0 ? ($deliveredRevenue / $deliveredCount) : null;
        $profitPerOrder = $deliveredCount > 0 ? ($netProfit / $deliveredCount) : null;

        return [
            'from' => $from,
            'to' => $to,
            'currency' => $settings['currency'],
            'settings' => $settings,
            'pipeline' => [
                'orders_created' => $ordersCreated,
                'orders_created_value' => $ordersCreatedValue,
                'confirmed' => $confirmedCount,
                'followup' => $followupCount,
                'delivered' => $deliveredCount,
                'returned' => $returnedCount,
                'delivery_rate' => $deliveryRate,
            ],
            'revenue' => [
                'delivered' => $deliveredRevenue,
                'returned_value' => $returnedValue,
                'aov' => $aov,
            ],
            'costs' => [
                'cogs' => $cogs,
                'return_cogs' => $returnCogs,
                'courier_delivered' => $courierDeliveredFees,
                'courier_return' => $courierReturnFees,
                'handling_fees' => $handlingFees,
                'ad_spend_meta' => $metaSpend,
                'ad_spend_manual' => $manualAdSpend,
                'ad_spend_total' => $totalAdSpend,
                'charges' => $charges,
            ],
            'income' => [
                'courier_payout' => $courierPayout,
                'cashback' => $cashback,
            ],
            'ads' => [
                'spend' => $totalAdSpend,
                'meta_spend' => $metaSpend,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'reach' => $reach,
                'cpc' => $cpc,
                'cpm' => $cpm,
                'ctr' => $ctr,
                'fb_purchases' => $fbPurchases,
                'fb_purchase_value' => $fbPurchaseValue,
                'real_roas' => $realRoas,
                'profit_roas' => $profitRoas,
                'cpp' => $cpp,
            ],
            'result' => [
                'gross_profit' => $grossProfit,
                'net_after_courier' => $netAfterCourier,
                'cash_flow' => $cashFlow,
                'net_profit' => $netProfit,
                'profit_per_order' => $profitPerOrder,
            ],
            'entries' => $entryTotals,
        ];
    }

    public static function dailySeries(PDO $pdo, int $userId, string $from, string $to): array
    {
        self::ensureSchema($pdo);
        $labels = [];
        $delivered = [];
        $returned = [];
        $spend = [];
        $revenue = [];

        $period = new DatePeriod(new DateTime($from), new DateInterval('P1D'), (new DateTime($to))->modify('+1 day'));
        foreach ($period as $d) {
            $labels[] = $d->format('M d');
            $key = $d->format('Y-m-d');
            $delivered[$key] = 0;
            $returned[$key] = 0;
            $spend[$key] = 0.0;
            $revenue[$key] = 0.0;
        }

        $st = $pdo->prepare("
            SELECT DATE(o.delivered_at) d, COUNT(*) c, COALESCE(SUM(o.total),0) rev
            FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ? AND o.delivered_at IS NOT NULL
              AND DATE(o.delivered_at) >= ? AND DATE(o.delivered_at) <= ?
              AND (
                (LOWER(o.fiabilo_status) LIKE '%livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%livraison%')
                OR LOWER(o.fiabilo_status) IN ('delivered','recu','reçu','livree')
                OR o.fiabilo_status IS NULL
              )
            GROUP BY DATE(o.delivered_at)
        ");
        $st->execute([$userId, $from, $to]);
        foreach ($st->fetchAll() as $r) {
            if (isset($delivered[$r['d']])) {
                $delivered[$r['d']] = (int) $r['c'];
                $revenue[$r['d']] = (float) $r['rev'];
            }
        }

        $stR = $pdo->prepare("
            SELECT DATE(COALESCE(o.returned_at, o.created_at)) d, COUNT(*) c
            FROM orders o JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ?
              AND DATE(COALESCE(o.returned_at, o.created_at)) >= ?
              AND DATE(COALESCE(o.returned_at, o.created_at)) <= ?
              AND (LOWER(o.fiabilo_status) LIKE '%retour%' OR LOWER(o.fiabilo_status) LIKE '%rtn%' OR LOWER(o.fiabilo_status) LIKE '%refus%')
            GROUP BY DATE(COALESCE(o.returned_at, o.created_at))
        ");
        $stR->execute([$userId, $from, $to]);
        foreach ($stR->fetchAll() as $r) {
            if (isset($returned[$r['d']])) {
                $returned[$r['d']] = (int) $r['c'];
            }
        }

        $stA = $pdo->prepare("SELECT insight_date d, spend FROM ad_insights_daily WHERE user_id = ? AND insight_date >= ? AND insight_date <= ?");
        $stA->execute([$userId, $from, $to]);
        foreach ($stA->fetchAll() as $r) {
            if (isset($spend[$r['d']])) {
                $spend[$r['d']] = (float) $r['spend'];
            }
        }

        return [
            'labels' => $labels,
            'delivered' => array_values($delivered),
            'returned' => array_values($returned),
            'spend' => array_values($spend),
            'revenue' => array_values($revenue),
        ];
    }
}
