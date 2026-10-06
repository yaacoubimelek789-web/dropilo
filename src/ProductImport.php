<?php
declare(strict_types=1);

/**
 * Parse Shopify products export CSV and return rows grouped by product (Handle).
 */
class ProductImport
{
    public static function parse(string $path): array
    {
        $rows = CsvParser::read($path);
        $products = [];
        foreach ($rows as $row) {
            $handle = isset($row['Handle']) ? trim((string) $row['Handle']) : '';
            if ($handle === '') {
                continue;
            }
            $title = isset($row['Title']) ? trim((string) $row['Title']) : null;
            $cost = self::firstDecimal($row, ['Cost per item', 'Variant Cost', 'Cost']);
            $price = self::parseDecimal($row['Variant Price'] ?? null);
            if (!isset($products[$handle])) {
                $products[$handle] = [
                    'handle' => $handle,
                    'title' => $title ?? '',
                    'body_html' => isset($row['Body (HTML)']) ? (string) $row['Body (HTML)'] : null,
                    'vendor' => isset($row['Vendor']) ? trim((string) $row['Vendor']) : null,
                    'type' => isset($row['Type']) ? trim((string) $row['Type']) : null,
                    'variant_sku' => isset($row['Variant SKU']) ? trim((string) $row['Variant SKU']) : null,
                    'variant_price' => $price,
                    'cost' => $cost,
                    'images' => [],
                    'status' => isset($row['Status']) ? trim((string) $row['Status']) : 'active',
                ];
            } else {
                if ($products[$handle]['cost'] === null && $cost !== null) {
                    $products[$handle]['cost'] = $cost;
                }
                if ($products[$handle]['variant_price'] === null && $price !== null) {
                    $products[$handle]['variant_price'] = $price;
                }
            }
            if (isset($row['Image Src']) && trim((string) $row['Image Src']) !== '') {
                $pos = isset($row['Image Position']) ? (int) $row['Image Position'] : 999;
                $products[$handle]['images'][$pos] = trim((string) $row['Image Src']);
            }
            if ($title !== null && $title !== '' && $products[$handle]['title'] === '') {
                $products[$handle]['title'] = $title;
            }
        }

        foreach ($products as &$p) {
            ksort($p['images']);
            $p['image_src'] = count($p['images']) > 0 ? (string) array_values($p['images'])[0] : null;
            unset($p['images']);
        }
        return array_values($products);
    }

    /** First numeric value among the given CSV columns. */
    private static function firstDecimal(array $row, array $columns): ?float
    {
        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $value = self::parseDecimal($row[$column]);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private static function parseDecimal($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '.', (string) $v);
        return is_numeric($v) ? (float) $v : null;
    }
}
