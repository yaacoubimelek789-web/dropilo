<?php
declare(strict_types=1);

/**
 * Keep order and product prices intact across devices.
 * Saving/confirming an order must never write a blank/zero total
 * when line items or the product catalog still have a real price.
 */
class OrderPricing
{
    public static function lineTotal(PDO $pdo, int $orderId): float
    {
        $st = $pdo->prepare('
            SELECT COALESCE(SUM(
                COALESCE(NULLIF(li.lineitem_price, 0), p.variant_price, 0)
                * COALESCE(li.lineitem_quantity, 1)
            ), 0)
            FROM order_line_items li
            LEFT JOIN products p ON li.product_id = p.id
            WHERE li.order_id = ?
        ');
        $st->execute([$orderId]);
        return (float) $st->fetchColumn();
    }

    public static function backfillMissingLinePrices(PDO $pdo, int $orderId): void
    {
        $pdo->prepare('
            UPDATE order_line_items li
            INNER JOIN products p ON li.product_id = p.id
            SET li.lineitem_price = p.variant_price
            WHERE li.order_id = ?
              AND p.variant_price IS NOT NULL
              AND p.variant_price > 0
              AND (li.lineitem_price IS NULL OR li.lineitem_price = 0)
        ')->execute([$orderId]);
    }

    /**
     * Restore a missing/zero order total from line items.
     * When $onlyIfMissing is false, always rewrite total from the current lines.
     */
    public static function syncOrderTotal(PDO $pdo, int $orderId, bool $onlyIfMissing = true): float
    {
        self::backfillMissingLinePrices($pdo, $orderId);
        $sum = self::lineTotal($pdo, $orderId);
        if ($sum <= 0) {
            return $sum;
        }
        if ($onlyIfMissing) {
            $pdo->prepare('UPDATE orders SET total = ? WHERE id = ? AND (total IS NULL OR total = 0)')
                ->execute([$sum, $orderId]);
        } else {
            $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([$sum, $orderId]);
        }
        return $sum;
    }

    public static function parseMoney($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace(',', '.', (string) $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * If a product price was wiped to 0, restore it from past order line items.
     */
    public static function restoreProductPricesFromOrders(PDO $pdo, int $userId): void
    {
        $pdo->prepare('
            UPDATE products p
            INNER JOIN shops s ON p.shop_id = s.id
            INNER JOIN (
                SELECT li.product_id, MAX(li.lineitem_price) AS price
                FROM order_line_items li
                INNER JOIN orders o ON li.order_id = o.id
                INNER JOIN shops s2 ON o.shop_id = s2.id
                WHERE s2.user_id = ?
                  AND li.product_id IS NOT NULL
                  AND li.lineitem_price IS NOT NULL
                  AND li.lineitem_price > 0
                GROUP BY li.product_id
            ) src ON src.product_id = p.id
            SET p.variant_price = src.price
            WHERE s.user_id = ?
              AND (p.variant_price IS NULL OR p.variant_price = 0)
        ')->execute([$userId, $userId]);
    }

    public static function restoreZeroOrderTotals(PDO $pdo, int $userId): void
    {
        $pdo->prepare('
            UPDATE orders o
            INNER JOIN shops s ON o.shop_id = s.id
            INNER JOIN (
                SELECT li.order_id,
                       SUM(
                           COALESCE(NULLIF(li.lineitem_price, 0), p.variant_price, 0)
                           * COALESCE(li.lineitem_quantity, 1)
                       ) AS sum_total
                FROM order_line_items li
                LEFT JOIN products p ON li.product_id = p.id
                GROUP BY li.order_id
            ) src ON src.order_id = o.id
            SET o.total = src.sum_total
            WHERE s.user_id = ?
              AND (o.total IS NULL OR o.total = 0)
              AND src.sum_total > 0
        ')->execute([$userId]);
    }
}
