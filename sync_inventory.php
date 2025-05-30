<?php

require __DIR__ . '/vendor/autoload.php';

// CONFIGURATION
$shipstationApiKey = 'xpRrHahO/dZFI6gN3TvtV1tPQbtfNPJochIJUSCejZY';
$magentoBaseUrl = 'https://fb.frankandbeans.com.au';
$magentoToken = '7y0evnf0z400fu4ffynzgw2howtanwys';
$bundleMappingFile = __DIR__ . '/bundle-mapping.json';

// Helpers
function logMessage($message, $echo = true, $clean = false) {
    global $logFile;
    $line = $clean ? "$message\n" : "[" . date('Y-m-d H:i:s') . "] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    if ($echo) {
        echo $line;
    }
}

function cleanOldLogs($logDir, $maxFiles = 24) {
    $files = glob("$logDir/log_*.txt");
    if (count($files) > $maxFiles) {
        usort($files, function($a, $b) {
            return filectime($a) - filectime($b);
        });
        $filesToDelete = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($filesToDelete as $file) {
            @unlink($file);
        }
    }
}

function getShipstationInventoryPage($url, $apiKey) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "api-key: $apiKey",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        throw new Exception('ShipStation cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new Exception("ShipStation API responded with HTTP code $httpCode");
    }
    return json_decode($response, true);
}

function updateMagentoProductStock($magentoBaseUrl, $accessToken, $sku, $qty) {
    $url = "$magentoBaseUrl/rest/V1/products/$sku/stockItems/1";
    $data = ["stockItem" => ["qty" => $qty, "is_in_stock" => $qty > 0]];
    $headers = [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        curl_close($ch);
        return ['success' => false, 'message' => 'cURL error: ' . curl_error($ch)];
    }
    curl_close($ch);

    if ($httpCode === 200) return ['success' => true];
    elseif ($httpCode === 404) return ['success' => false, 'not_found' => true];
    else return ['success' => false, 'message' => "HTTP $httpCode. Response: $result"];
}

// SCRIPT START
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/log_' . date('Ymd_His') . '.txt';
cleanOldLogs($logDir, 24);

$totalUpdated = 0;
$totalSkipped = 0;
$totalFailed = 0;
$totalItems = 0;
$totalBundleUpdated = 0;

$inventoryMap = [];

try {
    $bundleMap = file_exists($bundleMappingFile) ? json_decode(file_get_contents($bundleMappingFile), true) : [];

    $url = "https://api.shipstation.com/v2/inventory";
    do {
        $response = getShipstationInventoryPage($url, $shipstationApiKey);
        if (!isset($response['inventory'])) {
            throw new Exception('Malformed ShipStation response: missing inventory field.');
        }
        $totalItems += count($response['inventory']);

        foreach ($response['inventory'] as $item) {
            $sku = $item['sku'];
            $qty = $item['on_hand'];
            $inventoryMap[$sku] = $qty;

            $updateResult = updateMagentoProductStock($magentoBaseUrl, $magentoToken, $sku, $qty);

            if ($updateResult['success']) {
                logMessage("SKU $sku with qty $qty", true, true);
                $totalUpdated++;
            } elseif (!empty($updateResult['not_found'])) {
                $totalSkipped++;
            } else {
                $totalFailed++;
            }
        }

        $url = $response['links']['next']['href'] ?? null;

    } while ($url);

    // Handle bundle SKUs
    foreach ($bundleMap as $bundleSku => $data) {
        $minQty = PHP_INT_MAX;
        foreach ($data['components'] as $componentSku => $requiredQty) {
            if (!isset($inventoryMap[$componentSku])) {
                $minQty = 0;
                break;
            }
            $available = floor($inventoryMap[$componentSku] / $requiredQty);
            $minQty = min($minQty, $available);
        }

        $bundleQty = max(0, $minQty);
        $updateResult = updateMagentoProductStock($magentoBaseUrl, $magentoToken, $bundleSku, $bundleQty);

        if ($updateResult['success']) {
            logMessage("SKU $bundleSku with qty $bundleQty", true, true);
            $totalUpdated++;
            $totalBundleUpdated++;
        } elseif (!empty($updateResult['not_found'])) {
            $totalSkipped++;
        } else {
            $totalFailed++;
        }
    }

} catch (Exception $e) {
    // ไม่แสดง error เพื่อให้ Google Script อ่านง่าย
    file_put_contents($logFile, "❌ Script error: " . $e->getMessage() . "\n", FILE_APPEND);
}
