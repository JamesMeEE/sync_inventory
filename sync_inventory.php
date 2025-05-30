<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Bangkok');

require __DIR__ . '/vendor/autoload.php';

$shipstationApiKey = 'xpRrHahO/dZFI6gN3TvtV1tPQbtfNPJochIJUSCejZY';
$magentoBaseUrl = 'https://fb.frankandbeans.com.au';
$magentoToken = '7y0evnf0z400fu4ffynzgw2howtanwys';
$bundleMappingFile = __DIR__ . '/bundle-mapping.json';

$startTime = microtime(true);
$inventoryMap = [];

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

function multiUpdateMagentoStocks($baseUrl, $token, $stockArray) {
    $multiHandle = curl_multi_init();
    $curlHandles = [];

    foreach ($stockArray as $sku => $qty) {
        $url = "$baseUrl/rest/V1/products/$sku/stockItems/1";
        $data = ['stockItem' => ['qty' => $qty, 'is_in_stock' => is_numeric($qty) && $qty > 0]];
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
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);

    foreach ($curlHandles as $ch) {
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    curl_multi_close($multiHandle);
}

try {
    $bundleMap = is_file($bundleMappingFile) ? json_decode(file_get_contents($bundleMappingFile), true) : [];
    $url = "https://api.shipstation.com/v2/inventory";
    $stockToUpdate = [];

    do {
        $response = getShipstationInventoryPage($url, $shipstationApiKey);
        if (!isset($response['inventory'])) break;

        foreach ($response['inventory'] as $item) {
            $sku = $item['sku'] ?? null;
            $qty = isset($item['on_hand']) && is_numeric($item['on_hand']) ? $item['on_hand'] : "ERROR";
            if (!$sku) continue;
            $inventoryMap[$sku] = $qty;
            $stockToUpdate[$sku] = $qty;
        }

        $url = $response['links']['next']['href'] ?? null;
    } while ($url);

    multiUpdateMagentoStocks($magentoBaseUrl, $magentoToken, $stockToUpdate);

    $bundleStock = [];
    foreach ($bundleMap as $bundleSku => $data) {
        $minQty = PHP_INT_MAX;
        $error = false;
        foreach ($data['components'] as $componentSku => $requiredQty) {
            $compQty = $inventoryMap[$componentSku] ?? null;
            if (!is_numeric($compQty)) {
                $error = true;
                break;
            }
            $available = floor($compQty / $requiredQty);
            $minQty = min($minQty, $available);
        }
        if ($error) {
            $inventoryMap[$bundleSku] = "ERROR";
        } else {
            $bundleQty = max(0, $minQty);
            $bundleStock[$bundleSku] = $bundleQty;
            $inventoryMap[$bundleSku] = $bundleQty;
        }
    }

    multiUpdateMagentoStocks($magentoBaseUrl, $magentoToken, $bundleStock);

    $publicDir = __DIR__ . '/public';
    if (!is_dir($publicDir)) mkdir($publicDir, 0755, true);

    $jsonPath = $publicDir . '/latest.json';
    $jsonArray = [['generated_at' => date('Y-m-d H:i:s')]];
    $i = 1;
    foreach ($inventoryMap as $sku => $qty) {
        $jsonArray[] = ['no' => $i++, 'sku' => $sku, 'qty' => $qty];
    }
    file_put_contents($jsonPath, json_encode($jsonArray, JSON_UNESCAPED_UNICODE));

    require_once __DIR__ . '/upload_to_github.php';

} catch (Exception $e) {
    // silent
}

$endTime = microtime(true);
$elapsed = $endTime - $startTime;
$minutes = floor($elapsed / 60);
$seconds = round($elapsed % 60);
echo "Done in {$minutes} minute(s) {$seconds} second(s)\n";
