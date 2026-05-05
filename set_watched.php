<?php
session_start();
require_once 'config.php';

// Grab the real visitor IP, accounting for Cloudflare / Pangolin / proxies
if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
} elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
    $ip = trim($_SERVER['HTTP_X_REAL_IP']);
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim($parts[0]);
} else {
    $ip = $_SERVER['REMOTE_ADDR'];
}

// --- UPDATE existing record's watch_time ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && isset($_POST['watch_time'])) {
    $id = (int)$_POST['id'];
    $watchTime = (int)$_POST['watch_time'];

    $stmt = $conn->prepare("UPDATE completions SET watch_time = ? WHERE id = ?");
    $stmt->bind_param("ii", $watchTime, $id);
    $stmt->execute();
    http_response_code(204);
    exit;
}

// --- MARK record as redirected ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && isset($_POST['redirected'])) {
    $id = (int)$_POST['id'];
    $stmt = $conn->prepare("UPDATE completions SET redirected = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    http_response_code(204);
    exit;
}

// --- CREATE new completion record ---
$slug = $conn->real_escape_string($_GET['slug'] ?? '');
if (!$slug) {
    http_response_code(400);
    exit('Missing slug');
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Resolve country from Cloudflare header or ip-api.com fallback
$country = null;
if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $country = substr($_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2);
} else {
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $geo = curl_exec($ch);
    curl_close($ch);
    if ($geo) {
        $geoData = json_decode($geo, true);
        if (!empty($geoData['countryCode'])) {
            $country = substr($geoData['countryCode'], 0, 2);
        }
    }
}

$stmt = $conn->prepare(
    "INSERT INTO completions (slug, ip_address, user_agent, country, watch_time) VALUES (?, ?, ?, ?, 0)"
);
$stmt->bind_param("ssss", $slug, $ip, $userAgent, $country);
if (! $stmt->execute()) {
    http_response_code(500);
    error_log("Completion log failed: " . $stmt->error);
    exit('DB error');
}

// Return the new record ID so the client can update watch_time later
header('Content-Type: application/json');
echo json_encode(['id' => $stmt->insert_id]);
