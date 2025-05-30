<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

// CONFIG
$shipstationApiKey = 'xpRrHahO/dZFI6gN3TvtV1tPQbtfNPJochIJUSCejZY';
$magentoBaseUrl = 'https://fb.frankandbeans.com.au';
$magentoToken = '7y0evnf0z400fu4ffynzgw2howtanwys';
$bundleMappingFile = __DIR__ . '/bundle-mapping.json';

// START TIMER
$startTime = microtime(true);

// MAIN VARIABLES
$inventoryMap = [];
$countProcessed = 0;

function getShipstationInventoryPage($url, $apiKey) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "api-key: $apiKey",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function updateMagentoProductStock($baseUrl, $token, $sku, $qty) {
    $url = "$baseUrl/rest/V1/products/$sku/stockItems/1";
    $data = ["stockItem" => ["qty" => $qty, "is_in_stock" => $qty > 0]];
    $headers = [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$limit = 20;

try {
    $bundleMap = file_exists($bundleMappingFile) ? json_decode(file_get_contents($bundleMappingFile), true) : [];

    $url = "https://api.shipstation.com/v2/inventory";
    do {
        $response = getShipstationInventoryPage($url, $shipstationApiKey);
        if (!isset($response['inventory'])) break;

        foreach ($response['inventory'] as $item) {
            if ($countProcessed >= $limit) break 2;

            $sku = $item['sku'];
            $qty = $item['on_hand'];
            $code = updateMagentoProductStock($magentoBaseUrl, $magentoToken, $sku, $qty);
            $inventoryMap[$sku] = ($code === 200) ? $qty : "N/A";
            $countProcessed++;
        }
        $url = $response['links']['next']['href'] ?? null;
    } while ($url);

    foreach ($bundleMap as $bundleSku => $data) {
        $minQty = PHP_INT_MAX;
        foreach ($data['components'] as $componentSku => $requiredQty) {
            if (!isset($inventoryMap[$componentSku]) || !is_numeric($inventoryMap[$componentSku])) {
                $minQty = 0;
                break;
            }
            $available = floor($inventoryMap[$componentSku] / $requiredQty);
            $minQty = min($minQty, $available);
        }
        $bundleQty = max(0, $minQty);
        $code = updateMagentoProductStock($magentoBaseUrl, $magentoToken, $bundleSku, $bundleQty);
        $inventoryMap[$bundleSku] = ($code === 200) ? $bundleQty : "N/A";
    }
} catch (Exception $e) {
    // Silent fail
}

// SAVE JSON
$publicDir = __DIR__ . '/public';
if (!is_dir($publicDir)) mkdir($publicDir, 0755, true);

$jsonPath = $publicDir . '/latest.json';
$jsonArray = [['generated_at' => date('Y-m-d H:i:s')]];
$index = 1;
foreach ($inventoryMap as $sku => $qty) {
    $jsonArray[] = ['no' => $index++, 'sku' => $sku, 'qty' => $qty];
}
file_put_contents($jsonPath, json_encode($jsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// DONE MESSAGE
$endTime = microtime(true);
$elapsedSeconds = $endTime - $startTime;
$minutes = floor($elapsedSeconds / 60);
$seconds = round($elapsedSeconds % 60);
echo "Done in {$minutes} minute(s) {$seconds} second(s)\n";
