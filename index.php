<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

// Session timeout: 30 minutes
$timeout = 1800;
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $timeout) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}
$_SESSION['last_activity'] = time();

// Handle settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $refreshSecs = max(1, min(3600, (int)($_POST['meta_refresh_seconds'] ?? 5)));
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('meta_refresh_seconds', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $refreshSecs, $refreshSecs);
    $stmt->execute();
    header("Location: index.php?settings_saved=1");
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password']) && !isset($_POST['save_gallery'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $stmt = $conn->prepare("SELECT id, password_hash, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 1) {
        $stmt->bind_result($id, $hash, $role);
        $stmt->fetch();
        if (password_verify($_POST['password'], $hash)) {
            if ($role !== 'admin') {
                $loginError = "Access denied. Admins only.";
            } else {
                $_SESSION['user_id'] = $id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $role;
                header("Location: index.php");
                exit();
            }
        } else {
            $loginError = "Invalid credentials.";
        }
    } else {
        $loginError = "Invalid credentials.";
    }
}

// If not logged in, show login form
if (!isset($_SESSION['user_id'])) {
    include 'login_form.php';
    exit();
}

// Handle delete gallery
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];

    // Get all media files for this gallery
    $result = $conn->query("SELECT file_path FROM gallery_media WHERE gallery_id = $deleteId");
    while ($row = $result->fetch_assoc()) {
        if (file_exists($row['file_path'])) {
            unlink($row['file_path']);
        }
    }

    // Get meta_image
    $result = $conn->query("SELECT meta_image FROM galleries WHERE id = $deleteId");
    if ($row = $result->fetch_assoc()) {
        if (!empty($row['meta_image']) && file_exists($row['meta_image'])) {
            unlink($row['meta_image']);
        }
    }

    // Delete gallery (cascade deletes gallery_media)
    $conn->query("DELETE FROM galleries WHERE id = $deleteId");
    header("Location: index.php");
    exit();
}

// Handle edit - fetch gallery data
$editGallery = null;
$editMedia = [];
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $result = $conn->query("SELECT * FROM galleries WHERE id = $editId");
    $editGallery = $result->fetch_assoc();

    if ($editGallery) {
        $mediaResult = $conn->query("SELECT * FROM gallery_media WHERE gallery_id = $editId ORDER BY created_at");
        while ($row = $mediaResult->fetch_assoc()) {
            $editMedia[] = $row;
        }
    }
}

// Handle form submission (create/update gallery)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gallery'])) {
    $youtubeUrl  = $conn->real_escape_string($_POST['youtube_url']);
    $title       = $conn->real_escape_string($_POST['title'] ?? '');
    $description = $conn->real_escape_string($_POST['description'] ?? '');
    $content     = $conn->real_escape_string($_POST['content'] ?? '');
    $galleryId   = (int)($_POST['gallery_id'] ?? 0);

    // Handle meta image upload
    $metaImage = '';
    if (!empty($_FILES['meta_image']['tmp_name'])) {
        if (!is_dir(THUMB_DIR)) {
            mkdir(THUMB_DIR, 0755, true);
        }
        $ext = strtolower(pathinfo($_FILES['meta_image']['name'], PATHINFO_EXTENSION));
        $thumbFilename = uniqid() . '_' . time() . '.' . $ext;
        $thumbPath = THUMB_DIR . $thumbFilename;
        if (move_uploaded_file($_FILES['meta_image']['tmp_name'], $thumbPath)) {
            $metaImage = $conn->real_escape_string($thumbPath);
        }
    }

    if ($galleryId > 0) {
        $updates = ["youtube_url = '$youtubeUrl'", "title = '$title'", "description = '$description'", "content = '$content'"];
        if ($metaImage) $updates[] = "meta_image = '$metaImage'";
        $conn->query("UPDATE galleries SET " . implode(', ', $updates) . " WHERE id = $galleryId");
    } else {
        $slug = uniqid();
        $conn->query("INSERT INTO galleries (slug, youtube_url, title, description, meta_image, content) VALUES ('$slug', '$youtubeUrl', '$title', '$description', '$metaImage', '$content')");
        $galleryId = $conn->insert_id;
    }

    header("Location: index.php?edit=" . $galleryId . "&saved=1");
    exit();
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalResult = $conn->query("SELECT COUNT(*) as total FROM galleries");
$total = $totalResult->fetch_assoc()['total'];
$totalPages = ceil($total / $perPage);

// Current settings values
$currentRefreshSeconds = (int)getSetting($conn, 'meta_refresh_seconds', 5);

// Fetch galleries
$galleries = [];
$result = $conn->query("SELECT g.*, (SELECT COUNT(*) FROM gallery_media WHERE gallery_id = g.id) as media_count FROM galleries g ORDER BY created_at DESC LIMIT $offset, $perPage");
while ($row = $result->fetch_assoc()) {
    $galleries[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gallery Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <style>
        /* Quill dark theme overrides */
        .ql-toolbar { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.1) !important; border-radius: 8px 8px 0 0; position: relative; z-index: 2; }
        .ql-container { background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1) !important; border-radius: 0 0 8px 8px; min-height: 200px; max-height: 400px; overflow-y: auto; position: relative; z-index: 1; }
        .ql-editor { color: #fff; min-height: 200px; font-family: 'Inter', sans-serif; font-size: 0.95rem; }
        .ql-editor.ql-blank::before { color: rgba(255,255,255,0.3); font-style: normal; }
        .ql-toolbar .ql-stroke { stroke: rgba(255,255,255,0.65); }
        .ql-toolbar .ql-fill  { fill:  rgba(255,255,255,0.65); }
        .ql-toolbar .ql-picker { color: rgba(255,255,255,0.65); }
        .ql-toolbar button:hover .ql-stroke, .ql-toolbar button.ql-active .ql-stroke { stroke: #fff; }
        .ql-toolbar button:hover .ql-fill,  .ql-toolbar button.ql-active .ql-fill  { fill:  #fff; }
        .ql-toolbar .ql-picker-options { background: #1e1e30; border-color: rgba(255,255,255,0.1); }
        .quill-wrapper { position: relative; z-index: 1; margin-bottom: 20px; }
    </style>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-bg: rgba(30, 30, 40, 0.8);
            --border-color: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f0f1a 100%);
            min-height: 100vh;
        }

        [data-bs-theme="light"] body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
        }

        .navbar-brand-custom {
            font-weight: 700;
            font-size: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            padding: 1rem 1.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.8);
        }

        .dropzone {
            border: 2px dashed rgba(102, 126, 234, 0.5);
            border-radius: 16px;
            padding: 50px 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(102, 126, 234, 0.05);
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.15);
            transform: scale(1.01);
        }

        .dropzone p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 1.1rem;
        }

        .media-preview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .media-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 12px;
            overflow: hidden;
            background: #1a1a2e;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .media-item:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }

        .media-item img, .media-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .media-item:hover img, .media-item:hover video {
            transform: scale(1.1);
        }

        .media-item .delete-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: linear-gradient(135deg, #ff6b6b, #ee5a5a);
            border: none;
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(238, 90, 90, 0.5);
        }

        .media-item:hover .delete-btn {
            opacity: 1;
        }

        .media-item .media-type {
            position: absolute;
            bottom: 8px;
            left: 8px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            backdrop-filter: blur(5px);
        }

        .upload-progress {
            margin-top: 15px;
        }

        .gallery-thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .table {
            --bs-table-bg: transparent;
        }

        .table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.5);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem;
        }

        .table td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr {
            transition: all 0.2s ease;
        }

        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }

        .badge.bg-info {
            background: linear-gradient(135deg, #667eea, #764ba2) !important;
        }

        .btn-outline-primary {
            border-color: #667eea;
            color: #667eea;
        }

        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
        }

        .btn-outline-danger:hover {
            background: linear-gradient(135deg, #ff6b6b, #ee5a5a);
            border-color: transparent;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: #5fdd6c;
            border-radius: 12px;
        }

        .spinner-border-sm {
            color: #667eea;
        }

        .pagination .page-link {
            background: transparent;
            border-color: var(--border-color);
            color: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            margin: 0 3px;
        }

        .pagination .page-item.active .page-link {
            background: var(--primary-gradient);
            border-color: transparent;
        }

        /* Header styling */
        .admin-header {
            padding: 1.5rem 0;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .stats-badge {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="admin-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2 class="navbar-brand-custom mb-1">Gallery Admin</h2>
            <p class="text-muted mb-0 small">Manage your exclusive content galleries</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="logs.php" class="btn btn-outline-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                    <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4"/>
                </svg>
                Logs
            </a>
            <button id="themeToggle" class="btn btn-outline-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M6 .278a.77.77 0 0 1 .08.858 7.2 7.2 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.79.79 0 0 1 .81.316.73.73 0 0 1-.031.893A8.35 8.35 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.75.75 0 0 1 6 .278"/>
                </svg>
            </button>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Gallery saved successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['settings_saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Settings saved successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Settings -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Settings</span>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row align-items-end g-3">
                    <div class="col-md-4">
                        <label class="form-label">Segundos de página en blanco antes del refresh</label>
                        <input type="number" name="meta_refresh_seconds" class="form-control"
                               min="1" max="3600"
                               value="<?= $currentRefreshSeconds ?>"
                               placeholder="5">
                        <div class="form-text text-muted">
                            Tiempo que el visitante verá la página en blanco antes de ser redirigido al contenido.
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="save_settings" class="btn btn-primary w-100">Guardar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Gallery Form -->
    <div class="card mb-4">
        <div class="card-header">
            <?= $editGallery ? 'Edit Gallery' : 'Create New Gallery' ?>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="galleryForm">
                <input type="hidden" name="gallery_id" value="<?= $editGallery['id'] ?? '' ?>">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">URL del contenido</label>
                        <input type="text" name="youtube_url" class="form-control"
                               value="<?= htmlspecialchars($editGallery['youtube_url'] ?? '') ?>"
                               placeholder="YouTube, Vimeo, .mp4, o cualquier enlace">
                        <div class="form-text text-muted">Soporta: YouTube, Vimeo, video directo (mp4/webm) o cualquier URL</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control"
                               value="<?= htmlspecialchars($editGallery['title'] ?? '') ?>"
                               placeholder="Gallery title">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Optional description"><?= htmlspecialchars($editGallery['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Social Preview Image</label>
                        <input type="file" name="meta_image" class="form-control" accept="image/*">
                        <?php if (!empty($editGallery['meta_image'])): ?>
                            <small class="text-muted">Current: <?= basename($editGallery['meta_image']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">Contenido (Blog)</label>
                        <div class="quill-wrapper">
                            <div id="quillEditor"></div>
                            <textarea id="htmlEditor" style="display:none;width:100%;min-height:200px;max-height:400px;background:rgba(255,255,255,0.04);color:#fff;border:1px solid rgba(255,255,255,0.1);border-top:none;border-radius:0 0 8px 8px;padding:14px;font-family:monospace;font-size:0.88rem;resize:vertical;outline:none;"></textarea>
                        </div>
                        <input type="hidden" name="content" id="contentInput">
                    </div>
                </div>

                <div style="position:relative; z-index:10;">
                <button type="submit" name="save_gallery" class="btn btn-primary">
                    <?= $editGallery ? 'Update Gallery' : 'Create Gallery' ?>
                </button>
                <?php if ($editGallery): ?>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                </div>
            </form>

            <?php if ($editGallery): ?>
                <!-- Media Upload Section (only shown when editing) -->
                <hr class="my-4">
                <h5>Gallery Media</h5>
                <p class="text-muted small">Upload images (jpg, png, gif, webp) and videos (mp4, webm, mov)</p>

                <div class="dropzone" id="dropzone">
                    <input type="file" id="mediaFiles" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mov" style="display:none">
                    <p class="mb-0">Drag & drop files here or click to select</p>
                </div>

                <div class="upload-progress" id="uploadProgress"></div>

                <div class="media-preview" id="mediaPreview">
                    <?php foreach ($editMedia as $media): ?>
                        <div class="media-item" data-id="<?= $media['id'] ?>">
                            <?php if ($media['media_type'] === 'image'): ?>
                                <img src="<?= htmlspecialchars($media['file_path']) ?>" alt="">
                            <?php else: ?>
                                <video src="<?= htmlspecialchars($media['file_path']) ?>" muted></video>
                            <?php endif; ?>
                            <button class="delete-btn" onclick="deleteMedia(<?= $media['id'] ?>)">&times;</button>
                            <span class="media-type"><?= strtoupper($media['media_type']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Galleries List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>All Galleries</span>
            <span class="badge bg-secondary"><?= $total ?> total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Title / Slug</th>
                        <th>YouTube</th>
                        <th>Media</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($galleries)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No galleries yet. Create one above.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($galleries as $g): ?>
                        <tr>
                            <td>
                                <?php if ($g['meta_image']): ?>
                                    <img src="<?= htmlspecialchars($g['meta_image']) ?>" class="gallery-thumb" alt="">
                                <?php else: ?>
                                    <div class="gallery-thumb bg-secondary d-flex align-items-center justify-content-center">
                                        <span class="text-white-50">?</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($g['title'] ?: 'Untitled') ?></strong><br>
                                <small class="text-muted">
                                    <a href="watch.php?slug=<?= $g['slug'] ?>" target="_blank"><?= $g['slug'] ?></a>
                                </small>
                            </td>
                            <td><small class="text-truncate d-inline-block" style="max-width:150px"><?= htmlspecialchars($g['youtube_url']) ?></small></td>
                            <td><span class="badge bg-info"><?= $g['media_count'] ?> files</span></td>
                            <td><small><?= date('M j, Y', strtotime($g['created_at'])) ?></small></td>
                            <td>
                                <a href="?edit=<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="?delete=<?= $g['id'] ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this gallery and all its media?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const icons = Quill.import('ui/icons');
icons['html-source'] = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M14.6 16.6l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4zm-5.2 0L4.8 12l4.6-4.6L8 6 2 12l6 6 1.4-1.4z"/></svg>';

let htmlMode = false;
const contentInput = document.getElementById('contentInput');
const htmlEditor   = document.getElementById('htmlEditor');
const quillEl      = document.getElementById('quillEditor');

const quill = new Quill('#quillEditor', {
    theme: 'snow',
    placeholder: 'Escribe el contenido del blog...',
    modules: {
        toolbar: {
            container: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['link', 'blockquote'],
                ['clean'],
                ['html-source']
            ],
            handlers: {
                'html-source': function() {
                    htmlMode = !htmlMode;
                    if (htmlMode) {
                        // Visual → HTML: export Quill content to textarea
                        htmlEditor.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
                        contentInput.value = htmlEditor.value;
                        quillEl.style.display = 'none';
                        htmlEditor.style.display = 'block';
                    } else {
                        // HTML → Visual: load textarea into Quill for display only
                        // contentInput already holds the raw HTML from the textarea
                        quill.root.innerHTML = htmlEditor.value;
                        htmlEditor.style.display = 'none';
                        quillEl.style.display = '';
                    }
                }
            }
        }
    }
});

<?php if (!empty($editGallery['content'])): ?>
quill.root.innerHTML = <?= json_encode($editGallery['content']) ?>;
<?php endif; ?>

// In visual mode: sync Quill → contentInput
quill.on('text-change', function() {
    if (!htmlMode) {
        contentInput.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
    }
});

// In HTML mode: sync textarea → contentInput live
htmlEditor.addEventListener('input', function() {
    contentInput.value = htmlEditor.value;
});

// On submit: ensure contentInput has the correct value from active mode
document.getElementById('galleryForm').addEventListener('submit', function() {
    contentInput.value = htmlMode
        ? htmlEditor.value
        : (quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML);
});
</script>
<script>
const galleryId = <?= $editGallery['id'] ?? 0 ?>;
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('mediaFiles');
const progressDiv = document.getElementById('uploadProgress');
const previewDiv = document.getElementById('mediaPreview');

// Theme toggle
document.getElementById('themeToggle').addEventListener('click', () => {
    const html = document.documentElement;
    html.dataset.bsTheme = html.dataset.bsTheme === 'dark' ? 'light' : 'dark';
});

if (dropzone) {
    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(e => {
        dropzone.addEventListener(e, ev => { ev.preventDefault(); ev.stopPropagation(); });
    });

    // Highlight on drag
    ['dragenter', 'dragover'].forEach(e => {
        dropzone.addEventListener(e, () => dropzone.classList.add('dragover'));
    });
    ['dragleave', 'drop'].forEach(e => {
        dropzone.addEventListener(e, () => dropzone.classList.remove('dragover'));
    });

    // Handle drop
    dropzone.addEventListener('drop', e => handleFiles(e.dataTransfer.files));

    // Click to select
    dropzone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => handleFiles(fileInput.files));
}

async function handleFiles(files) {
    if (!galleryId) {
        alert('Please save the gallery first before uploading media.');
        return;
    }

    for (const file of files) {
        await uploadFile(file);
    }
}

async function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('gallery_id', galleryId);

    // Show progress
    const progressId = 'progress_' + Date.now();
    progressDiv.innerHTML += `
        <div id="${progressId}" class="d-flex align-items-center gap-2 mb-1">
            <div class="spinner-border spinner-border-sm"></div>
            <span>Uploading ${file.name}...</span>
        </div>
    `;

    try {
        const response = await fetch('upload_media.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        document.getElementById(progressId).remove();

        if (data.success) {
            addMediaPreview(data);
        } else {
            alert('Upload failed: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        document.getElementById(progressId).remove();
        alert('Upload error: ' + err.message);
    }
}

function addMediaPreview(media) {
    const div = document.createElement('div');
    div.className = 'media-item';
    div.dataset.id = media.id;

    if (media.type === 'image') {
        div.innerHTML = `
            <img src="${media.path}" alt="">
            <button class="delete-btn" onclick="deleteMedia(${media.id})">&times;</button>
            <span class="media-type">IMAGE</span>
        `;
    } else {
        div.innerHTML = `
            <video src="${media.path}" muted></video>
            <button class="delete-btn" onclick="deleteMedia(${media.id})">&times;</button>
            <span class="media-type">VIDEO</span>
        `;
    }

    previewDiv.appendChild(div);
}

async function deleteMedia(id) {
    if (!confirm('Delete this file?')) return;

    try {
        const formData = new FormData();
        formData.append('id', id);

        const response = await fetch('delete_media.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            document.querySelector(`.media-item[data-id="${id}"]`).remove();
        } else {
            alert('Delete failed: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Delete error: ' + err.message);
    }
}
</script>
</body>
</html>
