<?php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$uid = (int) $_SESSION['user_id'];
$types = $_GET['types'] ?? '';
if (empty($types)) {
    die('No types selected');
}
$typeArray = explode(',', $types);

$deliveredStatuses = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
$deliveredPlaceholder = implode(',', array_fill(0, count($deliveredStatuses), '?'));

$params = [$uid];
$subClauses = [];

if (in_array('customer', $typeArray)) {
    $subClauses[] = "(LOWER(o.fiabilo_status) IN ($deliveredPlaceholder) OR LOWER(o.intigo_status) IN ($deliveredPlaceholder))";
    $params = array_merge($params, $deliveredStatuses, $deliveredStatuses);
}
if (in_array('qualified', $typeArray)) {
    // Qualified = Confirmed AND NOT Delivered
    $subClauses[] = "(o.confirmed = 1 AND (LOWER(o.fiabilo_status) NOT IN ($deliveredPlaceholder) OR o.fiabilo_status IS NULL) AND (LOWER(o.intigo_status) NOT IN ($deliveredPlaceholder) OR o.intigo_status IS NULL))";
    $params = array_merge($params, $deliveredStatuses, $deliveredStatuses);
}

if (empty($subClauses)) {
    die('Invalid selection');
}

$whereSql = " AND (" . implode(' OR ', $subClauses) . ")";

$st = $app->pdo->prepare("
    SELECT o.billing_name, o.billing_phone, o.phone
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ? $whereSql
");
$st->execute($params);
$leads = $st->fetchAll();

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator('Dropilou')->setTitle('Leads Export');
$sheet = $spreadsheet->getActiveSheet();

// Header
$sheet->setCellValue('A1', 'Customer');
$sheet->setCellValue('B1', 'Phone');

// Style header
$sheet->getStyle('A1:B1')->getFont()->setBold(true);

$rowNum = 2;
foreach ($leads as $lead) {
    $name = $lead['billing_name'] ?: 'Guest';
    $phone = $lead['billing_phone'] ?: $lead['phone'] ?: '';
    $sheet->setCellValue('A' . $rowNum, $name);
    $sheet->setCellValue('B' . $rowNum, $phone);
    $rowNum++;
}

foreach (range('A', 'B') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="dropilou_leads_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
