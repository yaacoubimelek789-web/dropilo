<?php
declare(strict_types=1);

/**
 * For a given shop, match order line items to products by Title and optionally SKU.
 */
class OrderProductMatch
{
    /** @var array key: normalized title -> product id; key sku_$sku -> product id */
    public static function buildProductIndex(PDO $pdo, int $shopId): array
    {
        $st = $pdo->prepare('SELECT id, title, variant_sku FROM products WHERE shop_id = ?');
        $st->execute([$shopId]);
        $index = [];
        while ($row = $st->fetch()) {
            $norm = self::normalize($row['title']);
            if ($norm !== '') {
                $index[$norm] = (int) $row['id'];
            }
            if ($row['variant_sku'] !== null && trim($row['variant_sku']) !== '') {
                $index['sku_' . trim($row['variant_sku'])] = (int) $row['id'];
            }
        }
        return $index;
    }

    public static function findProductId(array $index, string $lineItemName, ?string $lineItemSku): ?int
    {
        $norm = self::normalize($lineItemName);
        if ($norm !== '' && isset($index[$norm])) {
            return $index[$norm];
        }
        if ($lineItemSku !== null && trim($lineItemSku) !== '' && isset($index['sku_' . trim($lineItemSku)])) {
            return $index['sku_' . trim($lineItemSku)];
        }
        return null;
    }

    private static function normalize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }
}
