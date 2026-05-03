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

$galleryId = (int)($_POST['gallery_id'] ?? 0);
if (!$galleryId) {
    http_response_code(400);
    exit(json_encode(['error' => 'Gallery ID required']));
}

// Verify gallery exists
$stmt = $conn->prepare("SELECT id FROM galleries WHERE id = ?");
$stmt->bind_param("i", $galleryId);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(404);
    exit(json_encode(['error' => 'Gallery not found']));
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit(json_encode(['error' => 'No file uploaded or upload error']));
}

$file = $_FILES['file'];

// Check file size
if ($file['size'] > MAX_UPLOAD_SIZE) {
    http_response_code(400);
    exit(json_encode(['error' => 'File too large (max 100MB)']));
}

// Validate file type
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$isImage = in_array($ext, ALLOWED_IMAGE_TYPES);
$isVideo = in_array($ext, ALLOWED_VIDEO_TYPES);

if (!$isImage && !$isVideo) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp, mp4, webm, mov']));
}

// Verify MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (isset(VALID_MIMES[$ext]) && VALID_MIMES[$ext] !== $mimeType) {
    // Allow some flexibility for video types
    $allowedVideoMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v'];
    if (!($isVideo && in_array($mimeType, $allowedVideoMimes))) {
        http_response_code(400);
        exit(json_encode(['error' => 'File type mismatch']));
    }
}

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Generate unique filename
$filename = uniqid() . '_' . time() . '.' . $ext;
$uploadPath = UPLOAD_DIR . $filename;

if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
    $mediaType = $isImage ? 'image' : 'video';
    $originalFilename = $file['name'];
    $fileSize = $file['size'];

    $stmt = $conn->prepare(
        "INSERT INTO gallery_media (gallery_id, media_type, file_path, original_filename, file_size, mime_type)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isssis", $galleryId, $mediaType, $uploadPath, $originalFilename, $fileSize, $mimeType);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'id' => $conn->insert_id,
            'path' => $uploadPath,
            'type' => $mediaType,
            'filename' => $originalFilename
        ]);
    } else {
        unlink($uploadPath);
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Upload failed']);
}
