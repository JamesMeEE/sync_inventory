<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Bangkok'); // เร็วและชัดเจน

require __DIR__ . '/vendor/autoload.php';

$shipstationApiKey = 'xpRrHahO/dZFI6gN3TvtV1tPQbtfNPJochIJUSCejZY';
$magentoBaseUrl = 'https://fb.frankandbeans.com.au';
$magentoToken = '7y0evnf0z400fu4ffynzgw2howtanwys';
$bundleMappingFile = __DIR__ . '/bundle-mapping.json';

$startTime = microtime(true);
$inventoryMap = [];
$countProcessed = 0;
$limit = 10;

function getShipstationInventoryPage($url, $apiKey) {
    static $headers;
    if (!$headers) {
        $headers = [
            "api-key: $apiKey",
            "Content-Type: application/json"
        ];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function updateMagentoProductStock($baseUrl, $token, $sku, $qty) {
    $url = "$baseUrl/rest/V1/products/$sku/stockItems/1";
    $data = ['stockItem' => ['qty' => $qty, 'is_in_stock' => $qty > 0]];
    $headers = [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    curl_exec($ch);
    curl_close($ch);
}

try {
    $bundleMap = is_file($bundleMappingFile) ? json_decode(file_get_contents($bundleMappingFile), true) : [];
    $url = "https://api.shipstation.com/v2/inventory";

    do {
        $response = getShipstationInventoryPage($url, $shipstationApiKey);
        if (!isset($response['inventory'])) break;

        foreach ($response['inventory'] as $item) {
            if ($countProcessed >= $limit) break 2;
            $sku = $item['sku'];
            $qty = $item['on_hand'];
            updateMagentoProductStock($magentoBaseUrl, $magentoToken, $sku, $qty);
            $inventoryMap[$sku] = $qty;
            $countProcessed++;
        }

        $url = $response['links']['next']['href'] ?? null;
    } while ($url && $countProcessed < $limit);

    foreach ($bundleMap as $bundleSku => $data) {
        $minQty = PHP_INT_MAX;
        foreach ($data['components'] as $componentSku => $requiredQty) {
            if (empty($inventoryMap[$componentSku]) || !is_numeric($inventoryMap[$componentSku])) {
                $minQty = 0;
                break;
            }
            $minQty = min($minQty, (int)($inventoryMap[$componentSku] / $requiredQty));
        }
        $bundleQty = $minQty > 0 ? $minQty : 0;
        updateMagentoProductStock($magentoBaseUrl, $magentoToken, $bundleSku, $bundleQty);
        $inventoryMap[$bundleSku] = $bundleQty;
    }

    // SAVE JSON
    $publicDir = __DIR__ . '/public';
    if (!is_dir($publicDir)) mkdir($publicDir, 0755, true);

    $jsonPath = $publicDir . '/latest.json';
    $jsonArray = [['generated_at' => date('Y-m-d H:i:s')]];
    $i = 1;
    foreach ($inventoryMap as $sku => $qty) {
        $jsonArray[] = ['no' => $i++, 'sku' => $sku, 'qty' => $qty];
    }
    file_put_contents($jsonPath, json_encode($jsonArray, JSON_UNESCAPED_UNICODE));

    // UPLOAD TO GITHUB
    require_once __DIR__ . '/upload_to_github.php';

} catch (Exception $e) {
    // Silent fail
}

// DONE TIME
$endTime = microtime(true);
$elapsed = $endTime - $startTime;
$minutes = floor($elapsed / 60);
$seconds = round($elapsed % 60);
echo "Done in {$minutes} minute(s) {$seconds} second(s)\n";
