<?php
// === CONFIG ===
$repo = 'JamesMeEEE/sync_inventory'; // GitHub repo
$branch = 'main'; // หรือ master ตามที่คุณใช้จริง
$token = getenv('GITHUB_TOKEN'); // ต้องตั้งค่า ENV ใน Render ชื่อ GITHUB_TOKEN
$localFile = __DIR__ . '/public/latest.json';
$remotePath = 'public/latest.json'; // ตำแหน่งใน repo

// === FUNCTION ===
function uploadToGitHub($token, $repo, $branch, $localFile, $remotePath) {
    $apiBase = "https://api.github.com";
    $headers = [
        "Authorization: token $token",
        "User-Agent: GitUploader",
        "Accept: application/vnd.github.v3+json"
    ];

    if (!file_exists($localFile)) {
        echo "❌ Local file not found: $localFile\n";
        return;
    }

    $content = file_get_contents($localFile);
    $base64Content = base64_encode($content);

    // Step 1: Get the current file SHA (if it exists)
    $url = "$apiBase/repos/$repo/contents/$remotePath?ref=$branch";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $data = json_decode($result, true);
    $sha = $data['sha'] ?? null;

    // Step 2: PUT new content
    $putData = [
        "message" => "🤖 Auto update latest.json",
        "content" => $base64Content,
        "branch" => $branch
    ];
    if ($sha) $putData['sha'] = $sha;

    $ch = curl_init("$apiBase/repos/$repo/contents/$remotePath");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($putData));

    $putResult = curl_exec($ch);
    $putInfo = curl_getinfo($ch);
    $httpCode = $putInfo['http_code'];
    curl_close($ch);

    if ($httpCode === 201 || $httpCode === 200) {
        echo "✅ Uploaded latest.json to GitHub ($remotePath)\n";
    } else {
        echo "❌ Failed to upload. HTTP $httpCode\n";
        echo "Response: $putResult\n";
    }
}

// === EXECUTE ===
uploadToGitHub($token, $repo, $branch, $localFile, $remotePath);
