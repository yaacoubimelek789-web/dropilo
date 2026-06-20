<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Export orders as an Excel (.dropilo) file.
 * Product images are embedded in cells and also stored as base64 in a hidden sheet for reimport.
 */
class DropiloExport
{
    /**
     * Build and stream an Excel file for the given order IDs.
     *
     * @param PDO $pdo
     * @param array $orderIds Array of integer order IDs (already verified to belong to the user)
     * @return string Temporary file path of the generated XLSX
     */
    public static function generate(PDO $pdo, array $orderIds): string
    {
        if (empty($orderIds)) {
            throw new \RuntimeException('No orders to export.');
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        // Fetch orders
        $st = $pdo->prepare("
            SELECT o.*, s.name AS shop_name
            FROM orders o
            JOIN shops s ON o.shop_id = s.id
            WHERE o.id IN ($placeholders)
            ORDER BY o.created_at DESC
        ");
        $st->execute($orderIds);
        $orders = $st->fetchAll();

        // Fetch line items with product info
        $stLines = $pdo->prepare("
            SELECT li.*, p.image_src, p.title AS product_title, p.variant_sku AS product_sku,
                   p.variant_price AS product_price, p.handle AS product_handle
            FROM order_line_items li
            LEFT JOIN products p ON li.product_id = p.id
            WHERE li.order_id IN ($placeholders)
            ORDER BY li.order_id, li.id
        ");
        $stLines->execute($orderIds);
        $allLines = $stLines->fetchAll();

        // Group line items by order_id
        $linesByOrder = [];
        foreach ($allLines as $li) {
            $linesByOrder[(int) $li['order_id']][] = $li;
        }

        // Create spreadsheet
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Dropilo')
            ->setTitle('Dropilo Orders Export')
            ->setDescription('Exported orders from Dropilo');

        // === ORDERS SHEET ===
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Orders');

        // Header row
        $headers = ['#', 'Order Name', 'Customer', 'Phone', 'Address', 'City', 'Zip', 'Country',
            'Shipping Address', 'Shipping City', 'Shipping Zip', 'Total', 'Currency', 'Status',
            'Notes', 'Created At', 'Product', 'SKU', 'Qty', 'Price', 'Product Image'];

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6366F1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '4F46E5']]],
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }
        $sheet->getStyle('A1:U1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Column widths
        $colWidths = [5, 15, 20, 15, 30, 15, 10, 12, 30, 15, 10, 12, 8, 12, 25, 18, 25, 12, 6, 10, 15];
        foreach ($colWidths as $i => $w) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($colLetter)->setWidth($w);
        }

        // Temp dir for images
        $tmpDir = sys_get_temp_dir() . '/dropilo_export_' . uniqid();
        @mkdir($tmpDir, 0755, true);

        $row = 2;
        $imageDataForHiddenSheet = [];

        foreach ($orders as $o) {
            $oid = (int) $o['id'];
            $lines = $linesByOrder[$oid] ?? [];

            if (empty($lines)) {
                // Order with no line items
                self::writeOrderRow($sheet, $row, $o, null, null);
                $row++;
                continue;
            }

            foreach ($lines as $liIdx => $li) {
                // Only write order info on the first line item row
                $orderData = $liIdx === 0 ? $o : null;
                self::writeOrderRow($sheet, $row, $orderData, $li, null);

                // Handle product image
                if (!empty($li['image_src'])) {
                    $imgFile = self::downloadImage($li['image_src'], $tmpDir);
                    if ($imgFile !== null) {
                        try {
                            $drawing = new Drawing();
                            $drawing->setName('Product');
                            $drawing->setDescription($li['product_title'] ?? 'Product');
                            $drawing->setPath($imgFile);
                            $drawing->setWidth(60);
                            $drawing->setHeight(60);
                            $drawing->setCoordinates('U' . $row);
                            $drawing->setOffsetX(5);
                            $drawing->setOffsetY(5);
                            $drawing->setWorksheet($sheet);
                            $sheet->getRowDimension($row)->setRowHeight(50);

                            // Store base64 for hidden sheet
                            $imgData = @file_get_contents($imgFile);
                            if ($imgData !== false) {
                                $finfo = new finfo(FILEINFO_MIME_TYPE);
                                $mime = $finfo->buffer($imgData);
                                $imageDataForHiddenSheet[] = [
                                    'row' => $row,
                                    'order_name' => $o['name'],
                                    'product_title' => $li['product_title'] ?? $li['lineitem_name'],
                                    'image_src' => $li['image_src'],
                                    'image_base64' => base64_encode($imgData),
                                    'image_mime' => $mime ?: 'image/jpeg',
                                ];
                            }
                        } catch (\Throwable $e) {
                            // Skip image if it fails
                        }
                    }
                }

                $row++;
            }
        }

        // === HIDDEN DATA SHEET (for reimport) ===
        $dataSheet = $spreadsheet->createSheet();
        $dataSheet->setTitle('_dropilo_data');
        $dataSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // Metadata row
        $dataSheet->setCellValue('A1', 'dropilo_version');
        $dataSheet->setCellValue('B1', '1');
        $dataSheet->setCellValue('C1', 'exported_at');
        $dataSheet->setCellValue('D1', date('c'));

        // Image data headers
        $dataSheet->setCellValue('A3', 'order_name');
        $dataSheet->setCellValue('B3', 'product_title');
        $dataSheet->setCellValue('C3', 'image_src');
        $dataSheet->setCellValue('D3', 'image_mime');
        $dataSheet->setCellValue('E3', 'image_base64');

        $dataRow = 4;
        foreach ($imageDataForHiddenSheet as $imgEntry) {
            $dataSheet->setCellValue('A' . $dataRow, $imgEntry['order_name']);
            $dataSheet->setCellValue('B' . $dataRow, $imgEntry['product_title']);
            $dataSheet->setCellValue('C' . $dataRow, $imgEntry['image_src']);
            $dataSheet->setCellValue('D' . $dataRow, $imgEntry['image_mime']);
            $dataSheet->setCellValue('E' . $dataRow, $imgEntry['image_base64']);
            $dataRow++;
        }

        // Set active sheet back to Orders
        $spreadsheet->setActiveSheetIndex(0);

        // Write to temp file
        $tmpFile = $tmpDir . '/export.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);

        return $tmpFile;
    }

    /**
     * Write one row of order + line item data.
     */
    private static function writeOrderRow($sheet, int $row, ?array $order, ?array $lineItem, ?string $imgCell): void
    {
        $altBg = ($row % 2 === 0) ? 'F8FAFC' : 'FFFFFF';
        $rowStyle = [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $altBg]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];

        if ($order !== null) {
            $values = [
                $row - 1,
                $order['name'],
                $order['billing_name'],
                $order['billing_phone'] ?? $order['phone'],
                $order['billing_address'],
                $order['billing_city'],
                $order['billing_zip'],
                $order['billing_country'],
                $order['shipping_address'],
                $order['shipping_city'],
                $order['shipping_zip'],
                $order['total'],
                $order['currency'] ?? 'TND',
                $order['status'] ?? 'new',
                $order['notes'],
                $order['order_created_at'] ?? $order['created_at'] ?? '',
            ];
            foreach ($values as $col => $val) {
                $sheet->setCellValue([$col + 1, $row], $val);
            }
        }

        if ($lineItem !== null) {
            $sheet->setCellValue([17, $row], $lineItem['product_title'] ?? $lineItem['lineitem_name']);
            $sheet->setCellValue([18, $row], $lineItem['lineitem_sku'] ?? $lineItem['product_sku'] ?? '');
            $sheet->setCellValue([19, $row], (int) $lineItem['lineitem_quantity']);
            $sheet->setCellValue([20, $row], $lineItem['lineitem_price'] ?? $lineItem['product_price'] ?? '');
        }

        $sheet->getStyle('A' . $row . ':U' . $row)->applyFromArray($rowStyle);
    }

    /**
     * Download an image to a temp directory. Returns local file path or null.
     */
    private static function downloadImage(string $url, string $tmpDir): ?string
    {
        if (empty($url) || strpos($url, 'placehold') !== false) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 5, 'user_agent' => 'Dropilo/1.0'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        try {
            $data = @file_get_contents($url, false, $ctx);
            if ($data === false || strlen($data) < 100) {
                return null;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($data);
            if (!$mime || strpos($mime, 'image/') !== 0) {
                return null;
            }

            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'jpg';
            $filename = md5($url) . '.' . $ext;
            $filepath = $tmpDir . '/' . $filename;
            file_put_contents($filepath, $data);
            return $filepath;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Clean up temp directory after export.
     */
    public static function cleanup(string $tmpFile): void
    {
        $dir = dirname($tmpFile);
        if (is_dir($dir) && strpos($dir, 'dropilo_export_') !== false) {
            $files = glob($dir . '/*');
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
