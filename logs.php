<?php
session_start();
require_once 'config.php';

// Only admins may view
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

// Gallery filter
$filterSlug = $conn->real_escape_string($_GET['gallery'] ?? '');
$slugCondition = $filterSlug ? "WHERE c.slug = '$filterSlug'" : "";
$slugConditionSimple = $filterSlug ? "WHERE slug = '$filterSlug'" : "";

// Fetch all galleries for dropdown
$allGalleries = $conn->query("
    SELECT g.slug, g.title, COUNT(c.id) as views
    FROM galleries g
    LEFT JOIN completions c ON c.slug = g.slug
    GROUP BY g.slug, g.title
    ORDER BY views DESC
")->fetch_all(MYSQLI_ASSOC);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Fetch completion records with pagination
$paginationWhere = $filterSlug ? "WHERE slug = '$filterSlug'" : "";
$result = $conn->query("SELECT * FROM completions $paginationWhere ORDER BY completed_at DESC LIMIT $perPage OFFSET $offset");
$totalLogs = (int)$conn->query("SELECT COUNT(*) AS c FROM completions $paginationWhere")->fetch_assoc()['c'];
$totalPages = ceil($totalLogs / $perPage);

// --- Statistics ---
$stats_total = $totalLogs;
$stats_today = (int)$conn->query("SELECT COUNT(*) AS c FROM completions $paginationWhere " . ($filterSlug ? "AND" : "WHERE") . " DATE(completed_at) = CURDATE()")->fetch_assoc()['c'];
$stats_week = (int)$conn->query("SELECT COUNT(*) AS c FROM completions $paginationWhere " . ($filterSlug ? "AND" : "WHERE") . " completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$stats_month = (int)$conn->query("SELECT COUNT(*) AS c FROM completions $paginationWhere " . ($filterSlug ? "AND" : "WHERE") . " completed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_assoc()['c'];

// Unique visitors
$unique_total = (int)$conn->query("SELECT COUNT(DISTINCT ip_address) AS c FROM completions $paginationWhere")->fetch_assoc()['c'];
$unique_today = (int)$conn->query("SELECT COUNT(DISTINCT ip_address) AS c FROM completions $paginationWhere " . ($filterSlug ? "AND" : "WHERE") . " DATE(completed_at) = CURDATE()")->fetch_assoc()['c'];
$unique_week = (int)$conn->query("SELECT COUNT(DISTINCT ip_address) AS c FROM completions $paginationWhere " . ($filterSlug ? "AND" : "WHERE") . " completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];

// Watch time stats
$watchTimeStats = $conn->query("
    SELECT
        COALESCE(AVG(watch_time), 0) as avg_time,
        COALESCE(SUM(watch_time), 0) as total_time,
        COALESCE(MAX(watch_time), 0) as max_time
    FROM completions $paginationWhere
")->fetch_assoc();
$avgWatchTime = (int)$watchTimeStats['avg_time'];
$totalWatchTime = (int)$watchTimeStats['total_time'];
$maxWatchTime = (int)$watchTimeStats['max_time'];

// Top galleries (only shown when no filter)
$topGalleries = $conn->query("
    SELECT c.slug, g.title, COUNT(*) as views,
           COALESCE(AVG(c.watch_time), 0) as avg_watch,
           COALESCE(SUM(c.watch_time), 0) as total_watch,
           COUNT(DISTINCT c.ip_address) as unique_visitors
    FROM completions c
    LEFT JOIN galleries g ON c.slug = g.slug
    GROUP BY c.slug
    ORDER BY views DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Views per day (last 7 days)
$dailyViews = $conn->query("
    SELECT DATE(completed_at) as date, COUNT(*) as views
    FROM completions
    $paginationWhere
    " . ($filterSlug ? "AND" : "WHERE") . " completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(completed_at)
    ORDER BY date ASC
")->fetch_all(MYSQLI_ASSOC);

// Views per hour (today)
$hourlyViews = $conn->query("
    SELECT HOUR(completed_at) as hour, COUNT(*) as views
    FROM completions
    $paginationWhere
    " . ($filterSlug ? "AND" : "WHERE") . " DATE(completed_at) = CURDATE()
    GROUP BY HOUR(completed_at)
    ORDER BY hour ASC
")->fetch_all(MYSQLI_ASSOC);

// Device breakdown
$devices = $conn->query("
    SELECT
        SUM(CASE WHEN user_agent LIKE '%Mobile%' OR user_agent LIKE '%Android%' OR user_agent LIKE '%iPhone%' THEN 1 ELSE 0 END) as mobile,
        SUM(CASE WHEN user_agent LIKE '%Tablet%' OR user_agent LIKE '%iPad%' THEN 1 ELSE 0 END) as tablet,
        SUM(CASE WHEN user_agent NOT LIKE '%Mobile%' AND user_agent NOT LIKE '%Android%' AND user_agent NOT LIKE '%iPhone%' AND user_agent NOT LIKE '%Tablet%' AND user_agent NOT LIKE '%iPad%' THEN 1 ELSE 0 END) as desktop
    FROM completions $paginationWhere
")->fetch_assoc();

// Country breakdown
$countryStats = $conn->query("
    SELECT country, COUNT(*) as views, COUNT(DISTINCT ip_address) as unique_visitors
    FROM completions
    $paginationWhere
    " . ($filterSlug ? "AND" : "WHERE") . " country IS NOT NULL AND country != ''
    GROUP BY country
    ORDER BY views DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Recent activity (last 10)
$recentActivity = $conn->query("
    SELECT c.*, g.title as gallery_title
    FROM completions c
    LEFT JOIN galleries g ON c.slug = g.slug
    $slugCondition
    ORDER BY c.completed_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Growth calculation
$growthWhere = $filterSlug ? "AND slug = '$filterSlug'" : "";
$lastWeekViews = (int)$conn->query("
    SELECT COUNT(*) AS c FROM completions
    WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    $growthWhere
")->fetch_assoc()['c'];
$growth = $lastWeekViews > 0 ? round((($stats_week - $lastWeekViews) / $lastWeekViews) * 100, 1) : 100;

// Per-gallery breakdown (all galleries stats)
$galleryBreakdown = $conn->query("
    SELECT c.slug, g.title,
           COUNT(*) as views,
           COUNT(DISTINCT c.ip_address) as unique_visitors,
           COALESCE(AVG(c.watch_time), 0) as avg_watch,
           COALESCE(SUM(c.watch_time), 0) as total_watch,
           MAX(c.completed_at) as last_view
    FROM completions c
    LEFT JOIN galleries g ON c.slug = g.slug
    GROUP BY c.slug
    ORDER BY views DESC
")->fetch_all(MYSQLI_ASSOC);

// Helper: format seconds to human readable
function formatSeconds($secs) {
    $secs = (int)$secs;
    if ($secs < 60) return $secs . 's';
    if ($secs < 3600) return floor($secs / 60) . 'm ' . ($secs % 60) . 's';
    return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analytics Dashboard<?= $filterSlug ? ' - ' . htmlspecialchars($filterSlug) : '' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --orange-gradient: linear-gradient(135deg, #f5af19 0%, #f12711 100%);
            --card-bg: rgba(30, 30, 40, 0.8);
            --border-color: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f0f1a 100%);
            min-height: 100vh;
            color: #fff;
        }

        .dashboard-header {
            padding: 2rem 0;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Gallery Filter */
        .gallery-filter {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.5rem;
            color: #fff;
            font-size: 0.9rem;
            min-width: 220px;
        }

        .gallery-filter:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.25);
            outline: none;
            background: var(--card-bg);
            color: #fff;
        }

        .gallery-filter option {
            background: #1a1a2e;
            color: #fff;
        }

        .filter-active {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(102, 126, 234, 0.2);
            border: 1px solid rgba(102, 126, 234, 0.4);
            color: #667eea;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .filter-active a {
            color: #f5576c;
            text-decoration: none;
            font-weight: 700;
            margin-left: 4px;
        }

        /* Stat Cards */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .stat-card .icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-card .icon.primary { background: var(--primary-gradient); }
        .stat-card .icon.success { background: var(--success-gradient); }
        .stat-card .icon.warning { background: var(--warning-gradient); }
        .stat-card .icon.info { background: var(--info-gradient); }
        .stat-card .icon.orange { background: var(--orange-gradient); }

        .stat-card .label {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-card .change {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat-card .change.positive { color: #38ef7d; }
        .stat-card .change.negative { color: #f5576c; }

        /* Chart Cards */
        .chart-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            height: 100%;
        }

        .chart-card .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-card .card-title .badge {
            font-size: 0.7rem;
            padding: 4px 10px;
            border-radius: 20px;
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item .avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .activity-item .details {
            flex: 1;
            min-width: 0;
        }

        .activity-item .title {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .activity-item .meta {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.5);
        }

        .activity-item .time {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.4);
            white-space: nowrap;
        }

        /* Top Galleries */
        .gallery-rank {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .gallery-rank:last-child {
            border-bottom: none;
        }

        .gallery-rank .rank {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .gallery-rank .rank.gold { background: linear-gradient(135deg, #f5af19, #f12711); }
        .gallery-rank .rank.silver { background: linear-gradient(135deg, #bdc3c7, #2c3e50); }
        .gallery-rank .rank.bronze { background: linear-gradient(135deg, #c6426e, #642b73); }

        .gallery-rank .info {
            flex: 1;
            min-width: 0;
        }

        .gallery-rank .name {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .gallery-rank .slug {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.4);
        }

        .gallery-rank .views {
            font-weight: 600;
            color: #667eea;
            white-space: nowrap;
        }

        /* Device Stats */
        .device-stat {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
        }

        .device-stat .icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .device-stat .bar {
            flex: 1;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        .device-stat .bar-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 1s ease;
        }

        .device-stat .percentage {
            font-weight: 600;
            min-width: 50px;
            text-align: right;
        }

        /* Gallery Breakdown Table */
        .breakdown-table {
            width: 100%;
        }

        .breakdown-row {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            margin: 0 -10px;
            padding-left: 10px;
            padding-right: 10px;
            border-radius: 10px;
        }

        .breakdown-row:hover {
            background: rgba(102, 126, 234, 0.1);
            color: inherit;
        }

        .breakdown-row:last-child {
            border-bottom: none;
        }

        .breakdown-row .gallery-name {
            flex: 1;
            min-width: 0;
        }

        .breakdown-row .gallery-name .name {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .breakdown-row .gallery-name .slug-text {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.4);
        }

        .breakdown-row .stat-col {
            text-align: center;
            min-width: 70px;
        }

        .breakdown-row .stat-col .stat-num {
            font-weight: 700;
            font-size: 1rem;
        }

        .breakdown-row .stat-col .stat-label {
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.4);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Data Table */
        .data-table {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
        }

        .data-table .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .data-table .table-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .table {
            margin: 0;
            --bs-table-bg: transparent;
        }

        .table th {
            background: rgba(0, 0, 0, 0.2);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.5);
            padding: 1rem 1.5rem;
            border: none;
        }

        .table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .table .slug-badge {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .table .ip-text {
            font-family: monospace;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .table .device-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .table .device-badge.mobile { background: rgba(56, 239, 125, 0.2); color: #38ef7d; }
        .table .device-badge.desktop { background: rgba(79, 172, 254, 0.2); color: #4facfe; }
        .table .device-badge.tablet { background: rgba(240, 147, 251, 0.2); color: #f093fb; }

        .table .watch-badge {
            background: rgba(245, 175, 25, 0.2);
            color: #f5af19;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            font-family: monospace;
        }

        /* Pagination */
        .pagination-wrapper {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            background: transparent;
            border: 1px solid var(--border-color);
            color: rgba(255, 255, 255, 0.7);
            padding: 8px 15px;
            border-radius: 8px;
            margin: 0 3px;
        }

        .page-link:hover {
            background: rgba(102, 126, 234, 0.2);
            border-color: #667eea;
            color: #fff;
        }

        .page-item.active .page-link {
            background: var(--primary-gradient);
            border-color: transparent;
            color: #fff;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.4);
        }

        .empty-state .icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stat-card .value {
                font-size: 1.5rem;
            }
            .breakdown-row .stat-col {
                min-width: 50px;
            }
            .breakdown-row .stat-col .stat-num {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <!-- Header -->
    <div class="dashboard-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title">Analytics Dashboard</h1>
            <p class="text-muted mb-0">
                <?php if ($filterSlug): ?>
                    <span class="filter-active">
                        Filtrando: <strong><?= htmlspecialchars($filterSlug) ?></strong>
                        <a href="logs.php" title="Quitar filtro">&times;</a>
                    </span>
                <?php else: ?>
                    Track your gallery performance and visitor insights
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <select class="gallery-filter" onchange="if(this.value) location.href='logs.php?gallery='+this.value; else location.href='logs.php';">
                <option value="">Todas las galerias</option>
                <?php foreach ($allGalleries as $g): ?>
                    <option value="<?= htmlspecialchars($g['slug']) ?>" <?= $filterSlug === $g['slug'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['title'] ?: $g['slug']) ?> (<?= $g['views'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="index.php" class="btn btn-outline-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="me-1" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8"/>
                </svg>
                Back
            </a>
        </div>
    </div>

    <!-- Main Stats -->
    <div class="row g-4 mb-4">
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="icon primary">&#128200;</div>
                <div class="label">Total Views</div>
                <div class="value"><?= number_format($stats_total) ?></div>
                <div class="change positive">
                    <span>&#9650;</span> All time
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="icon success">&#128293;</div>
                <div class="label">Today</div>
                <div class="value"><?= number_format($stats_today) ?></div>
                <div class="change <?= $stats_today > 0 ? 'positive' : '' ?>">
                    <span><?= $stats_today > 0 ? '&#9650;' : '&#8211;' ?></span> Live
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="icon warning">&#128640;</div>
                <div class="label">This Week</div>
                <div class="value"><?= number_format($stats_week) ?></div>
                <div class="change <?= $growth >= 0 ? 'positive' : 'negative' ?>">
                    <span><?= $growth >= 0 ? '&#9650;' : '&#9660;' ?></span> <?= abs($growth) ?>% vs last week
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="icon info">&#128101;</div>
                <div class="label">Unique Visitors</div>
                <div class="value"><?= number_format($unique_total) ?></div>
                <div class="change positive">
                    <span>&#128100;</span> <?= $unique_week ?> this week
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="icon orange">&#9202;</div>
                <div class="label">Avg Watch Time</div>
                <div class="value"><?= formatSeconds($avgWatchTime) ?></div>
                <div class="change positive">
                    <span>&#128336;</span> max: <?= formatSeconds($maxWatchTime) ?>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6">
            <div class="stat-card">
                <div class="icon" style="background: linear-gradient(135deg, #a855f7, #ec4899);">&#127916;</div>
                <div class="label">Total Watch Time</div>
                <div class="value"><?= formatSeconds($totalWatchTime) ?></div>
                <div class="change positive">
                    <span>&#128202;</span> <?= number_format($stats_month) ?> this month
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="card-title">
                    Views Overview
                    <span class="badge">Last 7 days</span>
                </div>
                <canvas id="viewsChart" height="100"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-title">
                    Device Breakdown
                </div>
                <?php
                $totalDevices = max(1, ($devices['mobile'] ?? 0) + ($devices['tablet'] ?? 0) + ($devices['desktop'] ?? 0));
                $mobilePercent = round((($devices['mobile'] ?? 0) / $totalDevices) * 100);
                $tabletPercent = round((($devices['tablet'] ?? 0) / $totalDevices) * 100);
                $desktopPercent = round((($devices['desktop'] ?? 0) / $totalDevices) * 100);
                ?>
                <div class="device-stat">
                    <div class="icon" style="background: var(--success-gradient);">&#128241;</div>
                    <div>
                        <div class="mb-1" style="font-weight: 500;">Mobile</div>
                        <div class="bar">
                            <div class="bar-fill" style="width: <?= $mobilePercent ?>%; background: var(--success-gradient);"></div>
                        </div>
                    </div>
                    <div class="percentage"><?= $mobilePercent ?>%</div>
                </div>
                <div class="device-stat">
                    <div class="icon" style="background: var(--info-gradient);">&#128187;</div>
                    <div>
                        <div class="mb-1" style="font-weight: 500;">Desktop</div>
                        <div class="bar">
                            <div class="bar-fill" style="width: <?= $desktopPercent ?>%; background: var(--info-gradient);"></div>
                        </div>
                    </div>
                    <div class="percentage"><?= $desktopPercent ?>%</div>
                </div>
                <div class="device-stat">
                    <div class="icon" style="background: var(--warning-gradient);">&#128194;</div>
                    <div>
                        <div class="mb-1" style="font-weight: 500;">Tablet</div>
                        <div class="bar">
                            <div class="bar-fill" style="width: <?= $tabletPercent ?>%; background: var(--warning-gradient);"></div>
                        </div>
                    </div>
                    <div class="percentage"><?= $tabletPercent ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Country Breakdown -->
    <?php if (!empty($countryStats)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="card-title">
                    Top Countries
                    <span class="badge"><?= count($countryStats) ?> countries</span>
                </div>
                <?php
                $maxCountryViews = max(array_column($countryStats, 'views'));
                ?>
                <?php foreach ($countryStats as $cs): ?>
                    <?php $pct = round(($cs['views'] / $maxCountryViews) * 100); ?>
                    <div class="device-stat">
                        <div class="icon" style="background: var(--primary-gradient); font-size: 1.1rem; font-weight: 700;">
                            <?= htmlspecialchars($cs['country']) ?>
                        </div>
                        <div style="flex: 1;">
                            <div class="d-flex justify-content-between mb-1">
                                <span style="font-weight: 500;"><?= htmlspecialchars($cs['country']) ?></span>
                                <span style="font-size: 0.8rem; color: rgba(255,255,255,0.5);"><?= $cs['unique_visitors'] ?> unique</span>
                            </div>
                            <div class="bar">
                                <div class="bar-fill" style="width: <?= $pct ?>%; background: var(--primary-gradient);"></div>
                            </div>
                        </div>
                        <div class="percentage"><?= number_format($cs['views']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Per-Gallery Breakdown (only when no filter) -->
    <?php if (!$filterSlug && !empty($galleryBreakdown)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="card-title">
                    Estadisticas por Galeria
                    <span class="badge">Click para filtrar</span>
                </div>
                <div class="breakdown-table">
                    <div class="breakdown-row" style="cursor: default; opacity: 0.6; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;">
                        <div class="gallery-name"><span class="name">Galeria</span></div>
                        <div class="stat-col"><span class="stat-label">Views</span></div>
                        <div class="stat-col"><span class="stat-label">Unicos</span></div>
                        <div class="stat-col"><span class="stat-label">Avg Watch</span></div>
                        <div class="stat-col"><span class="stat-label">Total Watch</span></div>
                        <div class="stat-col"><span class="stat-label">Ultimo</span></div>
                    </div>
                    <?php foreach ($galleryBreakdown as $gb): ?>
                        <a href="logs.php?gallery=<?= urlencode($gb['slug']) ?>" class="breakdown-row">
                            <div class="gallery-name">
                                <div class="name"><?= htmlspecialchars($gb['title'] ?: 'Untitled') ?></div>
                                <div class="slug-text"><?= htmlspecialchars($gb['slug']) ?></div>
                            </div>
                            <div class="stat-col">
                                <div class="stat-num"><?= number_format($gb['views']) ?></div>
                                <div class="stat-label">views</div>
                            </div>
                            <div class="stat-col">
                                <div class="stat-num"><?= number_format($gb['unique_visitors']) ?></div>
                                <div class="stat-label">unicos</div>
                            </div>
                            <div class="stat-col">
                                <div class="stat-num"><?= formatSeconds($gb['avg_watch']) ?></div>
                                <div class="stat-label">promedio</div>
                            </div>
                            <div class="stat-col">
                                <div class="stat-num"><?= formatSeconds($gb['total_watch']) ?></div>
                                <div class="stat-label">total</div>
                            </div>
                            <div class="stat-col">
                                <?php
                                $lastAgo = time() - strtotime($gb['last_view']);
                                if ($lastAgo < 60) $lastStr = 'Ahora';
                                elseif ($lastAgo < 3600) $lastStr = floor($lastAgo / 60) . 'm';
                                elseif ($lastAgo < 86400) $lastStr = floor($lastAgo / 3600) . 'h';
                                else $lastStr = floor($lastAgo / 86400) . 'd';
                                ?>
                                <div class="stat-num"><?= $lastStr ?></div>
                                <div class="stat-label">hace</div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Galleries & Recent Activity -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-title">
                    Top Galleries
                    <span class="badge">By views</span>
                </div>
                <?php if (empty($topGalleries)): ?>
                    <div class="empty-state">
                        <div class="icon">&#128202;</div>
                        <p>No data yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($topGalleries as $i => $gallery): ?>
                        <div class="gallery-rank">
                            <div class="rank <?= $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : '')) ?>">
                                <?= $i + 1 ?>
                            </div>
                            <div class="info">
                                <div class="name"><?= htmlspecialchars($gallery['title'] ?: 'Untitled') ?></div>
                                <div class="slug"><?= htmlspecialchars($gallery['slug']) ?> &middot; <?= formatSeconds($gallery['avg_watch']) ?> avg</div>
                            </div>
                            <div class="views"><?= number_format($gallery['views']) ?> views</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-title">
                    Recent Activity
                    <span class="badge">Live</span>
                </div>
                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state">
                        <div class="icon">&#128064;</div>
                        <p>No activity yet</p>
                    </div>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach (array_slice($recentActivity, 0, 6) as $activity): ?>
                            <?php
                            $isMobile = preg_match('/Mobile|Android|iPhone/i', $activity['user_agent']);
                            $timeAgo = time() - strtotime($activity['completed_at']);
                            if ($timeAgo < 60) $timeStr = 'Just now';
                            elseif ($timeAgo < 3600) $timeStr = floor($timeAgo / 60) . 'm ago';
                            elseif ($timeAgo < 86400) $timeStr = floor($timeAgo / 3600) . 'h ago';
                            else $timeStr = floor($timeAgo / 86400) . 'd ago';
                            $wt = isset($activity['watch_time']) ? (int)$activity['watch_time'] : 0;
                            ?>
                            <li class="activity-item">
                                <div class="avatar"><?= $isMobile ? '&#128241;' : '&#128187;' ?></div>
                                <div class="details">
                                    <div class="title"><?= htmlspecialchars($activity['gallery_title'] ?: $activity['slug']) ?></div>
                                    <div class="meta"><?= htmlspecialchars(substr($activity['ip_address'], 0, 12)) ?>... <?= !empty($activity['country']) ? '&middot; ' . htmlspecialchars($activity['country']) : '' ?> &middot; <?= formatSeconds($wt) ?> watched</div>
                                </div>
                                <div class="time"><?= $timeStr ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Detailed Logs Table -->
    <div class="data-table">
        <div class="table-header">
            <div class="table-title">Detailed Logs</div>
            <div class="d-flex gap-2">
                <span class="text-muted"><?= number_format($totalLogs) ?> total records</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Gallery</th>
                        <th>IP Address</th>
                        <th>Country</th>
                        <th>Device</th>
                        <th>Watch Time</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows === 0): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="icon">&#128202;</div>
                                    <p>No logs yet</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php
                            $isMobile = preg_match('/Mobile|Android|iPhone/i', $row['user_agent']);
                            $isTablet = preg_match('/Tablet|iPad/i', $row['user_agent']);
                            $deviceType = $isTablet ? 'tablet' : ($isMobile ? 'mobile' : 'desktop');
                            $deviceLabel = $isTablet ? 'Tablet' : ($isMobile ? 'Mobile' : 'Desktop');
                            $rowWatchTime = isset($row['watch_time']) ? (int)$row['watch_time'] : 0;
                            ?>
                            <tr>
                                <td><span class="slug-badge"><?= htmlspecialchars($row['slug']) ?></span></td>
                                <td><span class="ip-text"><?= htmlspecialchars($row['ip_address']) ?></span></td>
                                <td><span class="slug-badge"><?= htmlspecialchars($row['country'] ?? '—') ?></span></td>
                                <td><span class="device-badge <?= $deviceType ?>"><?= $deviceLabel ?></span></td>
                                <td><span class="watch-badge"><?= formatSeconds($rowWatchTime) ?></span></td>
                                <td><?= date('M j, Y \a\t g:i A', strtotime($row['completed_at'])) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pagination-wrapper">
                <div class="text-muted">
                    Showing <?= $offset + 1 ?> - <?= min($offset + $perPage, $totalLogs) ?> of <?= $totalLogs ?>
                </div>
                <nav>
                    <ul class="pagination">
                        <?php
                        $paginationParams = $filterSlug ? "&gallery=" . urlencode($filterSlug) : "";
                        ?>
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page - 1 ?><?= $paginationParams ?>">&laquo;</a>
                            </li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?><?= $paginationParams ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page + 1 ?><?= $paginationParams ?>">&raquo;</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Views Chart
const ctx = document.getElementById('viewsChart').getContext('2d');
const dailyData = <?= json_encode($dailyViews) ?>;

// Fill in missing days
const last7Days = [];
for (let i = 6; i >= 0; i--) {
    const date = new Date();
    date.setDate(date.getDate() - i);
    const dateStr = date.toISOString().split('T')[0];
    const found = dailyData.find(d => d.date === dateStr);
    last7Days.push({
        date: dateStr,
        views: found ? found.views : 0
    });
}

const gradient = ctx.createLinearGradient(0, 0, 0, 300);
gradient.addColorStop(0, 'rgba(102, 126, 234, 0.5)');
gradient.addColorStop(1, 'rgba(102, 126, 234, 0)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: last7Days.map(d => {
            const date = new Date(d.date);
            return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        }),
        datasets: [{
            label: 'Views',
            data: last7Days.map(d => d.views),
            borderColor: '#667eea',
            backgroundColor: gradient,
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#667eea',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(30, 30, 40, 0.9)',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: 'rgba(255, 255, 255, 0.1)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: 12
            }
        },
        scales: {
            x: {
                grid: {
                    color: 'rgba(255, 255, 255, 0.05)'
                },
                ticks: {
                    color: 'rgba(255, 255, 255, 0.5)'
                }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(255, 255, 255, 0.05)'
                },
                ticks: {
                    color: 'rgba(255, 255, 255, 0.5)',
                    stepSize: 1
                }
            }
        }
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
