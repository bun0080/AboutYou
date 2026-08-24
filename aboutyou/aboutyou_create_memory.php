<?php
/**
 * aboutyou - Create Memory Page
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
$success_msg = "";
$error_msg = "";

// 獲取使用者的膠囊選單
$sql = "SELECT id, title FROM tbl_time_capsules WHERE user_id = ? ORDER BY created_at DESC";
$capsules = [];
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $capsules[] = $row;
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $type_base = isset($_POST["type"]) ? trim($_POST["type"]) : "note";
    $content_text = isset($_POST["content_text"]) ? trim($_POST["content_text"]) : "";
    $capture_date = isset($_POST["capture_date"]) ? trim($_POST["capture_date"]) : ""; 
    $capsule_id = isset($_POST["capsule_id"]) && !empty($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : null;
    $visibility = isset($_POST["visibility"]) ? trim($_POST["visibility"]) : "private";
    
    if (empty($capture_date)) {
        $error_msg = "Capture date is required";
    } else {
        $upload_dir = "uploads/memories/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $text_saved = false;
        $has_files = isset($_FILES["media"]) && !empty($_FILES["media"]["name"][0]);

        if ($has_files) {
            $file_count = count($_FILES["media"]["name"]);
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES["media"]["error"][$i] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES["media"]["name"][$i];
                    $tmp_name = $_FILES["media"]["tmp_name"][$i];
                    $file_ext = getFileExtension($file_name);
                    
                    $new_file_name = uniqid("mem_") . "." . $file_ext;
                    $file_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        $media_url = $file_path;
                        $type = $type_base; 
                        $thumbnail_url = null;
                        
                        // 自動類型修正
                        if (isImageFile($new_file_name)) {
                            $type = "photo"; 
                            $thumb_name = uniqid("thumb_") . ".jpg";
                            $thumb_path = $upload_dir . $thumb_name;
                            if (createThumbnail($file_path, $thumb_path)) {
                                $thumbnail_url = $thumb_path;
                            }
                        } elseif (isVideoFile($new_file_name)) {
                            $type = "video";
                        }
                        
                        // 文本只跟隨第一個上傳的檔案
                        $current_text = !$text_saved ? $content_text : "";
                        
                        $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        if ($stmt = mysqli_prepare($link, $sql)) {
                            mysqli_stmt_bind_param($stmt, "iissssss", $user_id, $capsule_id, $type, $current_text, $media_url, $thumbnail_url, $capture_date, $visibility);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                        $text_saved = true;
                    }
                }
            }
        }

        // 如果只有文字沒有圖
        if (!$text_saved && !empty($content_text)) {
            $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) 
                    VALUES (?, ?, ?, ?, NULL, NULL, ?, ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "iissss", $user_id, $capsule_id, $type_base, $content_text, $capture_date, $visibility);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        
        // 成功後返回首頁
        header("Location: aboutyou.php" . ($capsule_id ? "?capsule_id=" . $capsule_id : ""));
        exit;
    }
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Memory - AboutYou</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; display: flex; align-items: center; justify-content: center; }
        .container { max-width: 600px; width: 100%; }
        .form-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); }
        .form-header { text-align: center; margin-bottom: 30px; }
        .form-header h1 { font-size: 28px; color: #667eea; margin-bottom: 10px; }
        .form-header p { color: #999; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 14px; }
        input[type="text"], input[type="date"], input[type="file"], select, textarea { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 14px; transition: border-color 0.3s ease; }
        input[type="text"]:focus, input[type="date"]:focus, input[type="file"]:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        textarea { resize: vertical; min-height: 100px; }
        .form-actions { display: flex; gap: 10px; margin-top: 30px; }
        .btn { flex: 1; padding: 12px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-block; text-align: center; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4); }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .help-text { font-size: 12px; color: #999; margin-top: 5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media (max-width: 600px) { .form-card { padding: 20px; } .form-header h1 { font-size: 24px; } .form-actions { flex-direction: column; } .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-card">
            <div class="form-header">
                <h1>📸 Add Memory</h1>
                <p>Capture a moment in time (支援多檔上傳)</p>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-error">✗ <?php echo $error_msg; ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label for="type">Memory Type *</label>
                        <select id="type" name="type" required>
                            <option value="photo" selected>📷 Photo / Video</option>
                            <option value="note">📝 Note</option>
                            <option value="letter">💌 Letter</option>
                            <option value="audio">🎵 Audio</option>
                            <option value="milestone">🎉 Milestone</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="capture_date">Capture Date *</label>
                        <input type="date" id="capture_date" name="capture_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="content_text">Description / Notes *</label>
                    <textarea id="content_text" name="content_text" placeholder="Write about this memory..."></textarea>
                </div>

                <div class="form-group">
                    <label for="media">Attach Media (可多選)</label>
                    <input type="file" id="media" name="media[]" accept="image/*,video/*,audio/*" multiple>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="capsule_id">Add to Capsule</label>
                        <select id="capsule_id" name="capsule_id">
                            <option value="">-- None (General Memory) --</option>
                            <?php foreach ($capsules as $capsule): ?>
                                <option value="<?php echo $capsule['id']; ?>">
                                    <?php echo htmlspecialchars($capsule['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="visibility">Visibility</label>
                        <select id="visibility" name="visibility">
                            <option value="private" selected>🔒 Private</option>
                            <option value="friends">👥 Friends</option>
                            <option value="public">🌍 Public</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Add Memory</button>
                    <a href="aboutyou.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
