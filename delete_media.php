<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Admin auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$mediaId = (int)($_POST['id'] ?? 0);
if (!$mediaId) {
    http_response_code(400);
    exit(json_encode(['error' => 'Media ID required']));
}

// Get file path before deleting
$stmt = $conn->prepare("SELECT file_path FROM gallery_media WHERE id = ?");
$stmt->bind_param("i", $mediaId);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Delete physical file
    if (file_exists($row['file_path'])) {
        unlink($row['file_path']);
    }

    // Delete from database
    $stmt = $conn->prepare("DELETE FROM gallery_media WHERE id = ?");
    $stmt->bind_param("i", $mediaId);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Media not found']);
}
