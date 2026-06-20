<?php
include 'vendor/autoload.php';
$app = include 'bootstrap.php';
$st = $app->pdo->query("SELECT tracking_token_encrypted FROM user_integrations WHERE provider = 'fiabilo' LIMIT 1");
$token = FiabiloHelper::decrypt($st->fetchColumn(), $app->app['encryption_key']);
$code = '147476970111';
$body = ['token' => $token, 'code' => $code, 'historique' => 1];
$urls = [
    'https://www.fiabilo.tn/api/v1/post.php',
    'https://www.fiabilo.tn/api/v1/suivi.php',
    'https://www.fiabilo.tn/api/v1/tracking.php',
    'https://www.fiabilo.tn/api/v1/historique.php',
    'https://www.fiabilo.tn/api/v1/get_historique.php'
];

$results = [];
foreach ($urls as $url) {
    // Try POST
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $results["POST " . $url] = $resp;
    curl_close($ch);

    // Try GET
    $urlGet = $url . '?' . http_build_query($body);
    $ch = curl_init($urlGet);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $results["GET " . $urlGet] = $resp;
    curl_close($ch);
}
file_put_contents('debug_resp.json', json_encode($results, JSON_PRETTY_PRINT));
echo "Saved results to debug_resp.json\n";
