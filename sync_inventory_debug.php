<?php

// 🔇 ปิดคำเตือน Deprecated + Notice
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

// CONFIGURATION
$shipstationApiKey = 'xpRrHahO/dZFI6gN3TvtV1tPQbtfNPJochIJUSCejZY';
$magentoBaseUrl = 'https://fb.frankandbeans.com.au';
$magentoToken = '7y0evnf0z400fu4ffynzgw2howtanwys';
$bundleMappingFile = __DIR__ . '/bundle-mapping.json';

// LOG CONFIGURATION
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/log_' . date('Ymd_His') . '.txt';
$latestLogFile = $logDir . '/latest.txt';
$logLines = [];

function logMessage($message, $echo = true) {
    global $logLines;
    $timestamp = date('[Y-m-d H:i:s]');
    $line = "$timestamp $message";
    $logLines[] = $line;
    if ($echo) echo $line . "\n";
}

function cleanOldLogs($logDir, $maxFiles = 100) {
    $files = glob("$logDir/log_*.txt");
    if (count($files) > $maxFiles) {
        usort($files, function($a, $b) {
            return filectime($a) - filectime($b);
        });
        @unlink($files[0]);
    }
}
cleanOldLogs($logDir, 100);

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

// === SCRIPT START ===
$totalUpdated = 0;
$totalSkipped = 0;
$totalFailed = 0;
$totalBundleUpdated = 0;
$inventoryMap = [];
$limit = 10;
$countProcessed = 0;

try {
    $bundleMap = file_exists($bundleMappingFile) ? json_decode(file_get_contents($bundleMappingFile), true) : [];

    $url = "https://api.shipstation.com/v2/inventory";
    do {
        $response = getShipstationInventoryPage($url, $shipstationApiKey);
        if (!isset($response['inventory'])) {
            throw new Exception('Malformed ShipStation response: missing inventory field.');
        }

        foreach ($response['inventory'] as $item) {
            if ($countProcessed >= $limit) break 2;

            $sku = $item['sku'];
            $qty = $item['on_hand'];
            $inventoryMap[$sku] = $qty;

            $updateResult = updateMagentoProductStock($magentoBaseUrl, $magentoToken, $sku, $qty);

            if ($updateResult['success']) {
                logMessage("SKU $sku with qty $qty");
                $totalUpdated++;
            } elseif (!empty($updateResult['not_found'])) {
                $totalSkipped++;
            } else {
                $totalFailed++;
            }

            $countProcessed++;
        }

        $url = $response['links']['next']['href'] ?? null;
    } while ($url && $countProcessed < $limit);

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
            logMessage("SKU $bundleSku with qty $bundleQty");
            $totalUpdated++;
            $totalBundleUpdated++;
        } elseif (!empty($updateResult['not_found'])) {
            $totalSkipped++;
        } else {
            $totalFailed++;
        }
    }
} catch (Exception $e) {
    logMessage("❌ Script error: " . $e->getMessage());
}

// 🔹 เขียน log ลงไฟล์
file_put_contents($logFile, implode("\n", $logLines));
file_put_contents($latestLogFile, implode("\n", $logLines));

// ✅ เขียน JSON แบบเรียบง่ายลง public/latest.json
$publicDir = __DIR__ . '/public';
if (!is_dir($publicDir)) mkdir($publicDir, 0755, true);

$jsonPath = $publicDir . '/latest.json';
$jsonArray = [];
$index = 1;
foreach ($inventoryMap as $sku => $qty) {
    $jsonArray[] = [
        'no' => $index++,
        'sku' => $sku,
        'qty' => $qty
    ];
}

logMessage("📂 JSON Path = $jsonPath");
logMessage("📌 Writable = " . (is_writable(dirname($jsonPath)) ? "YES" : "NO"));
logMessage("📤 JSON content:\n" . json_encode($jsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if (file_put_contents($jsonPath, json_encode($jsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
    logMessage("✅ JSON saved to $jsonPath");
} else {
    logMessage("❌ Failed to write JSON to $jsonPath");
}
