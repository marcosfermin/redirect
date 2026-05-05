<?php
date_default_timezone_set('America/New_York');

// Database configuration
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'gated';
$dbuser = getenv('DB_USER') ?: 'root';
$dbpass = getenv('DB_PASS') ?: '';

$conn = new mysqli($host, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$conn->query("SET time_zone = '-05:00'");

// Upload configuration
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_VIDEO_TYPES', ['mp4', 'webm', 'mov']);
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
define('UPLOAD_DIR', 'uploads/gallery/');
define('THUMB_DIR', 'uploads/thumbs/');

// Ensure content column exists on galleries (MySQL 8.0 compatible)
$colCheck = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'galleries' AND COLUMN_NAME = 'content'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE galleries ADD COLUMN content LONGTEXT DEFAULT NULL");
}

// Ensure redirected column exists on completions
$colCheck = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'completions' AND COLUMN_NAME = 'redirected'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE completions ADD COLUMN redirected TINYINT(1) NOT NULL DEFAULT 0");
}

// Ensure settings table exists with defaults
$conn->query("CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('meta_refresh_seconds', '5')");

function getSetting($conn, $key, $default = null) {
    $k = $conn->real_escape_string($key);
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '$k'");
    if ($r && $r->num_rows > 0) return $r->fetch_assoc()['setting_value'];
    return $default;
}

// Valid MIME types
define('VALID_MIMES', [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime'
]);
