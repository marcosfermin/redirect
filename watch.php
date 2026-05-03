<?php
session_start();
require_once 'config.php';

$slug = $conn->real_escape_string($_GET['slug'] ?? '');
if (!$slug) {
    die("Missing link.");
}

// Fetch gallery early to get the redirect URL
$result = $conn->query("SELECT youtube_url FROM galleries WHERE slug = '$slug'");
if (!$result || $result->num_rows === 0) die("Invalid link.");
$redirectUrl = htmlspecialchars($result->fetch_assoc()['youtube_url'], ENT_QUOTES);

// Show purple blank page that redirects to the stored link
$refreshSeconds = max(1, (int)getSetting($conn, 'meta_refresh_seconds', 5));
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
echo '<meta http-equiv="refresh" content="' . $refreshSeconds . ';url=' . $redirectUrl . '">';
echo '</head><body style="margin:0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;"></body></html>';
exit();

// Fetch gallery
$result = $conn->query("SELECT * FROM galleries WHERE slug = '$slug'");
if (!$result || $result->num_rows === 0) {
    die("Invalid link.");
}
$gallery = $result->fetch_assoc();

// Fetch media in RANDOM order
$mediaResult = $conn->query("SELECT * FROM gallery_media WHERE gallery_id = {$gallery['id']} ORDER BY RAND()");
$mediaItems = [];
while ($row = $mediaResult->fetch_assoc()) {
    $mediaItems[] = $row;
}

// Meta data
$metaTitle = htmlspecialchars($gallery['title'] ?: 'Exclusive Content');
$metaDesc = htmlspecialchars($gallery['description'] ?: 'Play video to unlock exclusive content.');
$metaImage = htmlspecialchars($gallery['meta_image'] ?: '');

// Build absolute URL for meta image if local
if ($metaImage && !filter_var($metaImage, FILTER_VALIDATE_URL) && file_exists($gallery['meta_image'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $metaImage = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($gallery['meta_image'], '/');
}

function detectUrlType($url) {
    if (preg_match('/(?:youtube\.com\/(?:watch|embed|shorts)|youtu\.be\/)/', $url)) return 'youtube';
    if (preg_match('/vimeo\.com\/\d/', $url)) return 'vimeo';
    if (preg_match('/\.(mp4|webm|mov|ogv)(\?|#|$)/i', $url)) return 'video';
    return 'link';
}

function extractYouTubeId($url) {
    $parsed = parse_url($url);
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $params);
        if (!empty($params['v'])) return $params['v'];
    }
    if (!empty($parsed['path'])) {
        $parts = explode('/', trim($parsed['path'], '/'));
        return end($parts);
    }
    return null;
}

$urlType  = detectUrlType($gallery['youtube_url']);
$videoId  = null;
$vimeoId  = null;
$contentUrl = htmlspecialchars($gallery['youtube_url'], ENT_QUOTES);

if ($urlType === 'youtube') {
    $videoId = extractYouTubeId($gallery['youtube_url']);
    if (!$videoId) die("Invalid YouTube link.");
} elseif ($urlType === 'vimeo') {
    preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $gallery['youtube_url'], $m);
    $vimeoId = $m[1] ?? null;
    if (!$vimeoId) die("Invalid Vimeo link.");
}

$lockMessage = ($urlType === 'link')
    ? 'Visitar el enlace para ver contenido exclusivo'
    : 'Reproducir para ver contenido exclusivo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $metaTitle ?></title>
    <meta name="description" content="<?= $metaDesc ?>">
    <meta property="og:title" content="<?= $metaTitle ?>">
    <meta property="og:description" content="<?= $metaDesc ?>">
    <?php if ($metaImage): ?>
    <meta property="og:image" content="<?= $metaImage ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $metaTitle ?>">
    <meta name="twitter:description" content="<?= $metaDesc ?>">
    <?php if ($metaImage): ?>
    <meta name="twitter:image" content="<?= $metaImage ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($urlType === 'youtube'): ?>
    <script src="https://www.youtube.com/iframe_api"></script>
    <?php elseif ($urlType === 'vimeo'): ?>
    <script src="https://player.vimeo.com/api/player.js"></script>
    <?php endif; ?>
    <style>
        :root {
            --blur-amount: 25px;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --glow-color: rgba(102, 126, 234, 0.5);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a2e 50%, #0f0f1a 100%);
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Animated background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(102, 126, 234, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(118, 75, 162, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .player-section {
            position: relative;
            z-index: 1;
            padding: 60px 0 50px;
            background: linear-gradient(180deg, rgba(26, 26, 46, 0.8) 0%, transparent 100%);
        }

        .player-wrapper {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .player-container {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            box-shadow:
                0 20px 60px rgba(0, 0, 0, 0.5),
                0 0 100px rgba(102, 126, 234, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        #player {
            width: 100%;
            aspect-ratio: 16/9;
            display: block;
        }

        .unlock-message {
            text-align: center;
            margin-top: 30px;
            padding: 18px 35px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(20px);
            font-weight: 500;
            font-size: 1.1rem;
            transition: all 0.5s ease;
            animation: pulse 2s infinite;
            cursor: pointer;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.4); }
            50% { box-shadow: 0 0 0 15px rgba(102, 126, 234, 0); }
        }

        .unlock-message .icon {
            font-size: 1.4rem;
        }

        .unlock-message.unlocked {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.3), rgba(32, 201, 151, 0.3));
            border-color: rgba(40, 167, 69, 0.5);
            color: #5fdd6c;
            animation: none;
            box-shadow: 0 0 30px rgba(40, 167, 69, 0.3);
        }

        /* Gallery Section */
        .gallery-section {
            position: relative;
            z-index: 1;
            padding: 60px 0 80px;
        }

        .section-title {
            text-align: center;
            margin-bottom: 40px;
        }

        .section-title h2 {
            font-size: 2rem;
            font-weight: 700;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }

        .section-title p {
            color: rgba(255, 255, 255, 0.5);
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            padding: 0 20px;
        }

        .gallery-item {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            background: rgba(26, 26, 46, 0.8);
            transition: all 0.6s cubic-bezier(0.23, 1, 0.32, 1);
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .gallery-item.locked {
            filter: blur(var(--blur-amount)) saturate(0.5);
            pointer-events: none;
            user-select: none;
            transform: scale(0.98);
        }

        .gallery-item.unlocked {
            filter: none;
            pointer-events: auto;
        }

        .gallery-item.unlocked:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow:
                0 20px 40px rgba(0, 0, 0, 0.4),
                0 0 60px rgba(102, 126, 234, 0.2);
            border-color: rgba(102, 126, 234, 0.3);
        }

        .gallery-item img {
            width: 100%;
            height: 320px;
            object-fit: cover;
            display: block;
            transition: transform 0.6s ease;
        }

        .gallery-item video {
            width: 100%;
            height: 320px;
            object-fit: cover;
            display: block;
            background: #000;
            transition: transform 0.6s ease;
        }

        .gallery-item.unlocked:hover img,
        .gallery-item.unlocked:hover video {
            transform: scale(1.1);
        }

        .media-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0, 0, 0, 0.7);
            color: #fff;
            padding: 6px 14px;
            border-radius: 25px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .media-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #667eea;
            border-radius: 50%;
            animation: blink 1.5s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .gallery-item .overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 100px;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .gallery-item.unlocked:hover .overlay {
            opacity: 1;
        }

        /* Empty State */
        .empty-gallery {
            text-align: center;
            padding: 80px 20px;
            color: rgba(255, 255, 255, 0.4);
        }

        .empty-gallery .icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        /* Lightbox */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.97);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .lightbox.active {
            display: flex;
        }

        .lightbox-content {
            max-width: 95vw;
            max-height: 95vh;
            animation: zoomIn 0.3s ease;
        }

        @keyframes zoomIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .lightbox-content img,
        .lightbox-content video {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        .lightbox-close {
            position: absolute;
            top: 25px;
            right: 35px;
            font-size: 50px;
            color: #fff;
            cursor: pointer;
            z-index: 1001;
            opacity: 0.7;
            transition: all 0.3s ease;
            line-height: 1;
        }

        .lightbox-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        /* Lock indicator for locked items */
        .gallery-item.locked::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            backdrop-filter: blur(5px);
        }

        /* Counter */
        .media-counter {
            text-align: center;
            margin-bottom: 30px;
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.9rem;
        }

        .media-counter span {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
            }

            .gallery-item img,
            .gallery-item video {
                height: 250px;
            }

            .unlock-message {
                font-size: 0.95rem;
                padding: 14px 25px;
            }

            .player-section {
                padding: 40px 0 30px;
            }
        }
    </style>
</head>
<body>
    <!-- Player Section -->
    <div class="player-section">
        <div class="container">
            <div class="player-wrapper">
                <div class="player-container">
                    <?php if ($urlType === 'youtube'): ?>
                        <div id="player"></div>
                    <?php elseif ($urlType === 'vimeo'): ?>
                        <iframe id="vimeo-player"
                            src="https://player.vimeo.com/video/<?= $vimeoId ?>?autoplay=1&muted=1&playsinline=1&dnt=1"
                            frameborder="0"
                            allow="autoplay; fullscreen; picture-in-picture"
                            style="width:100%;aspect-ratio:16/9;display:block;"></iframe>
                    <?php elseif ($urlType === 'video'): ?>
                        <video id="html5-player" controls autoplay muted playsinline
                            style="width:100%;aspect-ratio:16/9;display:block;background:#000;">
                            <source src="<?= $contentUrl ?>">
                        </video>
                    <?php else: ?>
                        <div id="link-gate" style="display:flex;align-items:center;justify-content:center;aspect-ratio:16/9;background:rgba(0,0,0,0.3);border-radius:12px;">
                            <a href="<?= $contentUrl ?>" target="_blank" rel="noopener" id="visit-btn"
                               onclick="onLinkVisit()"
                               style="display:inline-flex;align-items:center;gap:12px;padding:20px 40px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;text-decoration:none;border-radius:50px;font-size:1.1rem;font-weight:600;box-shadow:0 8px 30px rgba(102,126,234,0.5);">
                                <span style="font-size:1.4rem;">&#128279;</span>
                                Visitar enlace para desbloquear
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-center">
                    <div class="unlock-message" id="unlockMessage">
                        <span class="icon">&#9654;</span>
                        <?= htmlspecialchars($lockMessage) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gallery Section -->
    <div class="gallery-section">
        <div class="container">
            <?php if (empty($mediaItems)): ?>
                <div class="empty-gallery">
                    <div class="icon">&#128247;</div>
                    <p>No hay contenido disponible.</p>
                </div>
            <?php else: ?>
                <div class="gallery-grid">
                    <?php foreach ($mediaItems as $item): ?>
                        <div class="gallery-item locked" onclick="openLightbox(this)">
                            <?php if ($item['media_type'] === 'image'): ?>
                                <img src="<?= htmlspecialchars($item['file_path']) ?>"
                                     alt="Gallery content" loading="lazy">
                                <span class="media-badge">FOTO</span>
                            <?php else: ?>
                                <video src="<?= htmlspecialchars($item['file_path']) ?>#t=0.1"
                                       preload="metadata" muted playsinline></video>
                                <span class="media-badge">VIDEO</span>
                            <?php endif; ?>
                            <div class="overlay"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox" id="lightbox" onclick="closeLightbox(event)">
        <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
        <div class="lightbox-content" id="lightboxContent"></div>
    </div>

    <script>
        const URL_TYPE    = '<?= $urlType ?>';
        const LOCK_MSG    = '<?= addslashes($lockMessage) ?>';

        let player;
        let unlocked      = false;
        let watchSeconds  = 0;
        let watchInterval = null;
        let completionId  = null;

        /* ── timers ── */
        function startWatchTimer() {
            if (watchInterval) return;
            watchInterval = setInterval(() => { watchSeconds++; }, 1000);
        }
        function stopWatchTimer() {
            clearInterval(watchInterval);
            watchInterval = null;
        }
        function sendWatchTime() {
            if (completionId && watchSeconds > 0) {
                const d = new FormData();
                d.append('id', completionId);
                d.append('watch_time', watchSeconds);
                navigator.sendBeacon('set_watched.php', d);
            }
        }
        window.addEventListener('beforeunload', () => { stopWatchTimer(); sendWatchTime(); });

        /* ── gallery lock / unlock ── */
        function unlockGallery() {
            if (unlocked) return;
            unlocked = true;
            const msg = document.getElementById('unlockMessage');
            msg.innerHTML = '<span class="icon">&#10003;</span> Contenido desbloqueado';
            msg.classList.add('unlocked');
            document.querySelectorAll('.gallery-item').forEach(el => {
                el.classList.replace('locked', 'unlocked');
            });
            if (!window.viewLogged) {
                fetch('set_watched.php?slug=<?= $slug ?>')
                    .then(r => r.json())
                    .then(d => { completionId = d.id; });
                window.viewLogged = true;
            }
        }
        function lockGallery() {
            if (!unlocked) return;
            unlocked = false;
            const msg = document.getElementById('unlockMessage');
            msg.innerHTML = '<span class="icon">&#9654;</span> ' + LOCK_MSG;
            msg.classList.remove('unlocked');
            document.querySelectorAll('.gallery-item').forEach(el => {
                el.classList.replace('unlocked', 'locked');
            });
            closeLightbox();
        }

        /* ── player initialization by type ── */
        <?php if ($urlType === 'youtube'): ?>

        document.getElementById('unlockMessage').addEventListener('click', () => {
            if (player && player.playVideo) player.playVideo();
        });

        function onYouTubeIframeAPIReady() {
            player = new YT.Player('player', {
                videoId: '<?= $videoId ?>',
                playerVars: { autoplay:1, mute:1, playsinline:1, controls:1, rel:0, modestbranding:1 },
                events: {
                    onReady: e => { e.target.mute(); e.target.playVideo(); },
                    onStateChange: e => {
                        if (e.data === YT.PlayerState.PLAYING) {
                            startWatchTimer(); unlockGallery();
                        } else if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.ENDED) {
                            stopWatchTimer(); sendWatchTime(); lockGallery();
                        }
                    }
                }
            });
        }

        <?php elseif ($urlType === 'vimeo'): ?>

        document.getElementById('unlockMessage').addEventListener('click', () => {
            if (player) player.play();
        });

        window.addEventListener('load', () => {
            player = new Vimeo.Player(document.getElementById('vimeo-player'));
            player.on('play',  () => { startWatchTimer(); unlockGallery(); });
            player.on('pause', () => { stopWatchTimer(); sendWatchTime(); lockGallery(); });
            player.on('ended', () => { stopWatchTimer(); sendWatchTime(); lockGallery(); });
        });

        <?php elseif ($urlType === 'video'): ?>

        document.getElementById('unlockMessage').addEventListener('click', () => {
            const v = document.getElementById('html5-player');
            if (v) v.play();
        });

        window.addEventListener('load', () => {
            const v = document.getElementById('html5-player');
            v.addEventListener('play',  () => { startWatchTimer(); unlockGallery(); });
            v.addEventListener('pause', () => { stopWatchTimer(); sendWatchTime(); lockGallery(); });
            v.addEventListener('ended', () => { stopWatchTimer(); sendWatchTime(); lockGallery(); });
        });

        <?php else: /* generic link */ ?>

        function onLinkVisit() {
            setTimeout(() => { startWatchTimer(); unlockGallery(); }, 1200);
        }

        <?php endif; ?>

        /* ── lightbox ── */
        function openLightbox(element) {
            if (!unlocked) return;
            const lightbox = document.getElementById('lightbox');
            const content  = document.getElementById('lightboxContent');
            const img   = element.querySelector('img');
            const video = element.querySelector('video');
            if (img)   content.innerHTML = `<img src="${img.src}" alt="Full size">`;
            else if (video) content.innerHTML = `<video src="${video.src}" controls autoplay style="max-width:100%;max-height:90vh;"></video>`;
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox(event) {
            if (event && event.target !== event.currentTarget && !event.target.classList.contains('lightbox-close')) return;
            const lightbox = document.getElementById('lightbox');
            const content  = document.getElementById('lightboxContent');
            const video = content.querySelector('video');
            if (video) video.pause();
            lightbox.classList.remove('active');
            content.innerHTML = '';
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
    </script>
</body>
</html>
