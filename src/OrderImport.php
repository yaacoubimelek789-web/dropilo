<?php
declare(strict_types=1);

/**
 * Parse Shopify orders export CSV. One order = multiple rows (one per line item).
 */
class OrderImport
{
    public static function parse(string $path): array
    {
        $rows = CsvParser::read($path);
        $orders = [];
        foreach ($rows as $row) {
            $name = isset($row['Name']) ? trim((string) $row['Name']) : '';
            if ($name === '') {
                continue;
            }
            $lineItem = [
                'lineitem_quantity' => isset($row['Lineitem quantity']) ? (int) $row['Lineitem quantity'] : 1,
                'lineitem_name' => isset($row['Lineitem name']) ? trim((string) $row['Lineitem name']) : '',
                'lineitem_price' => self::parseDecimal($row['Lineitem price'] ?? null),
                'lineitem_sku' => isset($row['Lineitem sku']) ? trim((string) $row['Lineitem sku']) : null,
                'vendor' => isset($row['Vendor']) ? trim((string) $row['Vendor']) : null,
                'fulfillment_status' => isset($row['Lineitem fulfillment status']) ? trim((string) $row['Lineitem fulfillment status']) : null,
            ];
            if (!isset($orders[$name])) {
                $orders[$name] = [
                    'name' => $name,
                    'order_created_at' => self::parseDate($row['Created at'] ?? null),
                    'financial_status' => isset($row['Financial Status']) ? trim((string) $row['Financial Status']) : null,
                    'fulfillment_status' => isset($row['Fulfillment Status']) ? trim((string) $row['Fulfillment Status']) : null,
                    'total' => self::parseDecimal($row['Total'] ?? null),
                    'currency' => isset($row['Currency']) ? trim((string) $row['Currency']) : null,
                    'shipping_method' => isset($row['Shipping Method']) ? trim((string) $row['Shipping Method']) : null,
                    'billing_name' => isset($row['Billing Name']) ? trim((string) $row['Billing Name']) : null,
                    'billing_phone' => isset($row['Billing Phone']) ? trim((string) $row['Billing Phone']) : null,
                    'billing_address' => isset($row['Billing Address1']) ? trim((string) $row['Billing Address1']) : null,
                    'billing_city' => isset($row['Billing City']) ? trim((string) $row['Billing City']) : null,
                    'billing_zip' => isset($row['Billing Zip']) ? trim((string) $row['Billing Zip']) : null,
                    'billing_country' => isset($row['Billing Country']) ? trim((string) $row['Billing Country']) : null,
                    'shipping_name' => isset($row['Shipping Name']) ? trim((string) $row['Shipping Name']) : null,
                    'shipping_address' => isset($row['Shipping Address1']) ? trim((string) $row['Shipping Address1']) : null,
                    'shipping_city' => isset($row['Shipping City']) ? trim((string) $row['Shipping City']) : null,
                    'shipping_zip' => isset($row['Shipping Zip']) ? trim((string) $row['Shipping Zip']) : null,
                    'notes' => isset($row['Notes']) ? trim((string) $row['Notes']) : null,
                    'phone' => isset($row['Phone']) ? trim((string) $row['Phone']) : null,
                    'line_items' => [],
                ];
            }
            $orders[$name]['line_items'][] = $lineItem;
        }
        return array_values($orders);
    }

    private static function parseDecimal($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '.', (string) $v);
        return is_numeric($v) ? (float) $v : null;
    }

    private static function parseDate($v): ?string
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        $ts = strtotime((string) $v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
