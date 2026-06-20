<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import orders from a Dropilo-native Excel file (.dropilo).
 * Reads order data from the 'Orders' sheet and product images from the hidden '_dropilo_data' sheet.
 */
class DropiloImport
{
    /**
     * Parse and import a .dropilo (XLSX) file into a target shop.
     *
     * @param PDO $pdo
     * @param string $filePath Path to the uploaded .dropilo file
     * @param int $shopId Target shop ID
     * @param string $uploadsDir Absolute path to the uploads directory for saving images
     * @return array ['imported' => int, 'skipped' => int, 'errors' => string[]]
     */
    public static function import(PDO $pdo, string $filePath, int $shopId, string $uploadsDir): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Could not read the file: ' . $e->getMessage();
            return $result;
        }

        // Read main orders sheet
        $sheet = $spreadsheet->getSheetByName('Orders');
        if ($sheet === null) {
            $sheet = $spreadsheet->getSheet(0);
        }

        // Read hidden data sheet for images
        $imageMap = [];
        $dataSheet = $spreadsheet->getSheetByName('_dropilo_data');
        if ($dataSheet !== null) {
            $dataHighRow = $dataSheet->getHighestRow();
            for ($r = 4; $r <= $dataHighRow; $r++) {
                $orderName = trim((string) $dataSheet->getCell('A' . $r)->getValue());
                $prodTitle = trim((string) $dataSheet->getCell('B' . $r)->getValue());
                $imgSrc = trim((string) $dataSheet->getCell('C' . $r)->getValue());
                $imgMime = trim((string) $dataSheet->getCell('D' . $r)->getValue());
                $imgB64 = trim((string) $dataSheet->getCell('E' . $r)->getValue());

                if ($orderName !== '' && $prodTitle !== '') {
                    $key = $orderName . '||' . $prodTitle;
                    $imageMap[$key] = [
                        'image_src' => $imgSrc,
                        'image_mime' => $imgMime ?: 'image/jpeg',
                        'image_base64' => $imgB64,
                    ];
                }
            }
        }

        // Ensure uploads directory exists
        $shopImgDir = rtrim($uploadsDir, '/\\') . '/products';
        if (!is_dir($shopImgDir)) {
            @mkdir($shopImgDir, 0755, true);
        }

        // Build product index for this shop
        $productIndex = OrderProductMatch::buildProductIndex($pdo, $shopId);

        $orderIns = $pdo->prepare('
            INSERT INTO orders (shop_id, name, order_created_at, financial_status, fulfillment_status, total, currency, shipping_method,
            billing_name, billing_phone, billing_address, billing_city, billing_zip, billing_country,
            shipping_name, shipping_address, shipping_city, shipping_zip, notes, phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new")
        ');

        $lineIns = $pdo->prepare('
            INSERT INTO order_line_items (order_id, lineitem_name, lineitem_sku, lineitem_price, lineitem_quantity, vendor, fulfillment_status, product_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $checkDup = $pdo->prepare('SELECT id FROM orders WHERE shop_id = ? AND name = ?');

        // Parse rows — each row is one line item, grouped by Order Name (col B)
        $highRow = $sheet->getHighestRow();
        $orders = [];

        for ($r = 2; $r <= $highRow; $r++) {
            $orderName = trim((string) $sheet->getCell('B' . $r)->getValue());
            if ($orderName === '') {
                continue; // Continuation row without order name - belongs to previous order
            }

            // Start of a new order group
            $orders[$orderName] = [
                'name' => $orderName,
                'billing_name' => trim((string) $sheet->getCell('C' . $r)->getValue()),
                'billing_phone' => trim((string) $sheet->getCell('D' . $r)->getValue()),
                'billing_address' => trim((string) $sheet->getCell('E' . $r)->getValue()),
                'billing_city' => trim((string) $sheet->getCell('F' . $r)->getValue()),
                'billing_zip' => trim((string) $sheet->getCell('G' . $r)->getValue()),
                'billing_country' => trim((string) $sheet->getCell('H' . $r)->getValue()),
                'shipping_address' => trim((string) $sheet->getCell('I' . $r)->getValue()),
                'shipping_city' => trim((string) $sheet->getCell('J' . $r)->getValue()),
                'shipping_zip' => trim((string) $sheet->getCell('K' . $r)->getValue()),
                'total' => self::parseDecimal($sheet->getCell('L' . $r)->getValue()),
                'currency' => trim((string) $sheet->getCell('M' . $r)->getValue()) ?: 'TND',
                'status' => trim((string) $sheet->getCell('N' . $r)->getValue()),
                'notes' => trim((string) $sheet->getCell('O' . $r)->getValue()),
                'order_created_at' => trim((string) $sheet->getCell('P' . $r)->getValue()) ?: null,
                'line_items' => [],
            ];
        }

        // Second pass: collect all line items for each order
        $currentOrderName = '';
        for ($r = 2; $r <= $highRow; $r++) {
            $cellOrderName = trim((string) $sheet->getCell('B' . $r)->getValue());
            if ($cellOrderName !== '') {
                $currentOrderName = $cellOrderName;
            }
            if ($currentOrderName === '' || !isset($orders[$currentOrderName])) {
                continue;
            }

            $productName = trim((string) $sheet->getCell('Q' . $r)->getValue());
            if ($productName === '') {
                continue;
            }

            $orders[$currentOrderName]['line_items'][] = [
                'lineitem_name' => $productName,
                'lineitem_sku' => trim((string) $sheet->getCell('R' . $r)->getValue()),
                'lineitem_quantity' => (int) ($sheet->getCell('S' . $r)->getValue() ?: 1),
                'lineitem_price' => self::parseDecimal($sheet->getCell('T' . $r)->getValue()),
            ];
        }

        // Insert orders into database
        foreach ($orders as $order) {
            $orderName = trim($order['name']);
            if ($orderName === '') {
                $result['skipped']++;
                continue;
            }

            $checkDup->execute([$shopId, $orderName]);
            if ($checkDup->fetch()) {
                $result['skipped']++;
                continue;
            }

            try {
                $orderIns->execute([
                    $shopId,
                    $order['name'],
                    $order['order_created_at'],
                    null, // financial_status
                    null, // fulfillment_status
                    $order['total'],
                    $order['currency'],
                    null, // shipping_method
                    $order['billing_name'],
                    $order['billing_phone'],
                    $order['billing_address'],
                    $order['billing_city'],
                    $order['billing_zip'],
                    $order['billing_country'],
                    null, // shipping_name
                    $order['shipping_address'],
                    $order['shipping_city'],
                    $order['shipping_zip'],
                    $order['notes'],
                    $order['billing_phone'], // phone fallback
                ]);
                $orderId = (int) $pdo->lastInsertId();

                foreach ($order['line_items'] as $li) {
                    $liName = $li['lineitem_name'];
                    $liSku = $li['lineitem_sku'] ?: null;

                    // Try to match existing product
                    $productId = OrderProductMatch::findProductId($productIndex, $liName, $liSku);

                    // If no match, try to create product from image data
                    if ($productId === null) {
                        $imgKey = $orderName . '||' . $liName;
                        $imgInfo = $imageMap[$imgKey] ?? null;
                        $productId = self::createProductFromImport($pdo, $shopId, $liName, $liSku, $li['lineitem_price'], $imgInfo, $shopImgDir);
                        if ($productId !== null) {
                            // Update index
                            $norm = preg_replace('/\s+/u', ' ', trim($liName));
                            if ($norm !== '') $productIndex[$norm] = $productId;
                            if ($liSku !== null && trim($liSku) !== '') $productIndex['sku_' . trim($liSku)] = $productId;
                        }
                    }

                    $lineIns->execute([
                        $orderId,
                        $liName,
                        $liSku,
                        $li['lineitem_price'],
                        $li['lineitem_quantity'],
                        null, // vendor
                        null, // fulfillment_status
                        $productId,
                    ]);
                }

                $result['imported']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'Order ' . $orderName . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Create a product from import data, saving the embedded image if present.
     */
    private static function createProductFromImport(PDO $pdo, int $shopId, string $title, ?string $sku, $price, ?array $imgInfo, string $imgDir): ?int
    {
        if (trim($title) === '') return null;

        // Check if product already exists
        $st = $pdo->prepare('SELECT id FROM products WHERE shop_id = ? AND title = ?');
        $st->execute([$shopId, $title]);
        $existing = $st->fetch();
        if ($existing) return (int) $existing['id'];

        $imageSrc = null;

        // Save embedded base64 image
        if ($imgInfo !== null && !empty($imgInfo['image_base64'])) {
            $imageData = base64_decode($imgInfo['image_base64'], true);
            if ($imageData !== false) {
                $ext = self::mimeToExt($imgInfo['image_mime']);
                $filename = 'dropilo_' . $shopId . '_' . md5($title . time()) . '.' . $ext;
                $savePath = rtrim($imgDir, '/\\') . '/' . $filename;
                if (@file_put_contents($savePath, $imageData) !== false) {
                    $imageSrc = 'public/uploads/products/' . $filename;
                }
            }
        }

        // Fallback to original URL
        if ($imageSrc === null && $imgInfo !== null && !empty($imgInfo['image_src'])) {
            $imageSrc = $imgInfo['image_src'];
        }

        $ins = $pdo->prepare('INSERT INTO products (shop_id, title, variant_sku, variant_price, image_src, status) VALUES (?, ?, ?, ?, ?, "active")');
        $ins->execute([$shopId, $title, $sku, $price, $imageSrc]);
        return (int) $pdo->lastInsertId();
    }

    private static function parseDecimal($v): ?float
    {
        if ($v === null || $v === '') return null;
        $v = str_replace(',', '.', (string) $v);
        return is_numeric($v) ? (float) $v : null;
    }

    private static function mimeToExt(string $mime): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'jpg';
    }
}
