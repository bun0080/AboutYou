<?php
/**
 * aboutyou - View Capsule Page
 */

ini_set("session.cookie_secure", 1);
ini_set("session.cookie_httponly", 1);
ini_set("session.use_only_cookies", 1);

session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$link = require_once "config.php";
require_once "aboutyou_helpers.php";

$user_id = $_SESSION["id"];
$capsule_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if ($capsule_id <= 0) {
    header("location: aboutyou.php");
    exit;
}

// Get capsule details
$sql = "SELECT id, title, description, profile_image_url, created_at 
        FROM tbl_time_capsules 
        WHERE id = ? AND user_id = ?";

$capsule = null;
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $capsule_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $capsule = $row;
    }
    mysqli_stmt_close($stmt);
}

if (!$capsule) {
    header("location: aboutyou.php");
    exit;
}

// Get memories in this capsule
$sql_old = "SELECT id, type, content_text, media_url, thumbnail_url, capture_date, created_at, visibility 
        FROM tbl_memories 
        WHERE capsule_id = ? AND user_id = ? 
        ORDER BY capture_date DESC";
// 改為顯示自己擁有或共享的記憶
$sql = "SELECT m.id, m.type, m.content_text, m.media_url, m.thumbnail_url, m.capture_date, m.created_at, m.visibility 
        FROM tbl_memories m
        LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id
        WHERE m.capsule_id = ? 
          AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
        ORDER BY m.capture_date DESC";

$memories = [];
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $capsule_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $memories[] = $row;
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($capsule["title"]); ?> - AboutYou</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
            color: #333;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .capsule-profile-image {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            background-color: #eee;
            flex-shrink: 0;
        }

        .header-info {
            flex-grow: 1;
        }

        .header-info h1 {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 5px;
        }

        .header-info p {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title::before {
            content: '';
            width: 3px;
            height: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 1.5px;
        }

        .capsule-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-item {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .info-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding: 10px 0;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }

        .timeline-item {
            position: relative;
            padding-left: 40px;
            margin-bottom: 20px;
        }

        .timeline-marker {
            position: absolute;
            left: 0;
            top: 0;
            width: 28px;
            height: 28px;
            background: white;
            border: 2px solid #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .timeline-content {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }

        .timeline-date {
            font-size: 11px;
            color: #999;
            margin-bottom: 3px;
        }

        .timeline-title {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
        }

        .timeline-description {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
            margin-bottom: 10px;
        }

        .memory-thumbnail {
            width: 100%;
            max-width: 200px;
            height: 120px;
            border-radius: 6px;
            object-fit: cover;
            margin-top: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 20px 10px;
            color: #999;
        }

        .empty-state-icon {
            font-size: 36px;
            margin-bottom: 10px;
        }

        .empty-state-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .empty-state-text {
            font-size: 13px;
            color: #999;
            margin-bottom: 15px;
        }

        .memory-date-group {
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #333;
            padding-left: 40px; /* Align with timeline items */
        }

        @media (max-width: 480px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
            .capsule-profile-image {
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <?php if ($capsule["profile_image_url"]): ?>
                <img src="<?php echo htmlspecialchars($capsule["profile_image_url"]); ?>" alt="Capsule Profile" class="capsule-profile-image">
            <?php else: ?>
                <img src="images/default_capsule.png" alt="Default Profile" class="capsule-profile-image">
            <?php endif; ?>
            <div class="header-info">
                <h1><?php echo htmlspecialchars($capsule["title"]); ?></h1>
                <p><?php echo htmlspecialchars($capsule["description"]); ?></p>
            </div>
            <div class="header-actions">
                <a href="aboutyou_create_memory.php?capsule=<?php echo $capsule["id"]; ?>" class="btn btn-primary">+ Add Memory</a>
                <a href="aboutyou_edit_capsule.php?id=<?php echo $capsule["id"]; ?>" class="btn btn-secondary">Edit Capsule</a>
                <a href="aboutyou.php" class="btn btn-secondary">Back to Timeline</a>
            </div>
        </div>

        <!-- Capsule Info -->
        <div class="section">
            <div class="section-title">ℹ️ Capsule Details</div>
            <div class="capsule-info-grid">
                <div class="info-item">
                    <div class="info-label">Created</div>
                    <div class="info-value"><?php echo formatDateTime($capsule["created_at"]); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Memories</div>
                    <div class="info-value"><?php echo count($memories); ?></div>
                </div>
            </div>
        </div>

        <!-- Memories Section -->
        <div class="section">
            <div class="section-title">📸 Memories in This Capsule</div>
            
            <?php if (count($memories) > 0): ?>
                <div class="timeline">
                    <?php 
                    $current_date_group = "";
                    foreach ($memories as $memory): 
                        $memory_date = formatDate($memory["capture_date"]);
                        if ($memory_date !== $current_date_group): 
                            $current_date_group = $memory_date;
                    ?>
                            <div class="memory-date-group"><?php echo $current_date_group; ?></div>
                    <?php endif; ?>
                        <div class="timeline-item">
                            <div class="timeline-marker">
                                <?php echo getMemoryIcon($memory["type"]); ?>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo getMemoryTypeLabel($memory["type"]); ?></div>
                                <div class="timeline-description">
                                    <?php echo htmlspecialchars($memory["content_text"]); ?>
                                </div>
                                <?php if ($memory["thumbnail_url"]): ?>
                                    <img src="<?php echo htmlspecialchars($memory["thumbnail_url"]); ?>" alt="Memory Thumbnail" class="memory-thumbnail">
                                <?php elseif ($memory["media_url"] && isVideoFile($memory["media_url"])): ?>
                                    <video src="<?php echo htmlspecialchars($memory["media_url"]); ?>" controls class="memory-thumbnail"></video>
                                <?php endif; ?>
                                <div class="timeline-date">
                                    Added <?php echo getTimeAgo($memory["created_at"]); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📸</div>
                    <div class="empty-state-title">No Memories Yet</div>
                    <div class="empty-state-text">Start adding photos, videos, and notes to this time capsule.</div>
                    <a href="aboutyou_create_memory.php?capsule=<?php echo $capsule["id"]; ?>" class="btn btn-primary">Add Memory</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
