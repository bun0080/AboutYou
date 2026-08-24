<?php
/**
 * aboutyou - Share Memory with Users
 * 第一步：接收檔案並暫存，顯示使用者清單
 * 第二步：處理提交，儲存記憶並關聯共享使用者
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
$error_msg = "";

// ========== Step 2: 處理最終提交（確認分享） ==========
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["confirm_share"])) {
    $selected_users = isset($_POST["shared_users"]) ? $_POST["shared_users"] : [];
    $selected_users = array_filter($selected_users, 'is_numeric');
    $selected_users = array_map('intval', $selected_users);
    // 確保當前使用者自己一定可見（強制加入）
    if (!in_array($user_id, $selected_users)) {
        $selected_users[] = $user_id;
    }

    if (!isset($_SESSION['pending_memory']) || empty($_SESSION['pending_memory'])) {
        $error_msg = "沒有待處理的記憶資料，請重新上傳。";
    } else {
        $pending = $_SESSION['pending_memory'];
        $capsule_id = $pending['capsule_id'];
        $content_text = $pending['content_text'];
        $client_dates = $pending['client_dates'];
        $temp_files = $pending['temp_files'];

        $upload_dir = "uploads/memories/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $text_saved = false;
        $inserted_memory_ids = [];

        // 處理每個暫存檔案
        foreach ($temp_files as $tf) {
            $tmp_path = $tf['tmp_path'];
            $orig_name = $tf['original_name'];
            $file_ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (empty($file_ext) || strlen($file_ext) > 10) {
                $mime_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/heic'=>'heic','image/heif'=>'heif','video/mp4'=>'mp4'];
                $file_ext = $mime_map[$tf['mime_type']] ?? 'jpg';
            }
            $new_file_name = uniqid("mem_") . "." . $file_ext;
            $final_path = $upload_dir . $new_file_name;

            if (!rename($tmp_path, $final_path)) {
                if (!copy($tmp_path, $final_path)) continue;
                unlink($tmp_path);
            }

            $media_url = $final_path;
            $type = "note";
            $thumbnail_url = null;
            $client_date = isset($client_dates[$orig_name]) ? $client_dates[$orig_name] : null;
            $capture_date = getMediaCaptureDate($final_path, $client_date);
            $is_heif = isHeifFile($new_file_name);

            // 類型識別與縮圖
            if ($is_heif) {
                $converted_jpg = $upload_dir . uniqid("mem_conv_") . ".jpg";
                if (convertHeifToJpeg($final_path, $converted_jpg)) {
                    $media_url = $converted_jpg;
                    $type = "photo";
                    $tn = uniqid("thumb_") . ".jpg";
                    if (createThumbnail($converted_jpg, $upload_dir . $tn)) $thumbnail_url = $upload_dir . $tn;
                } else {
                    $type = "photo";
                }
            } elseif (isImageFile($new_file_name)) {
                $type = "photo";
                $tn = uniqid("thumb_") . ".jpg";
                if (createThumbnail($final_path, $upload_dir . $tn)) $thumbnail_url = $upload_dir . $tn;
            } elseif (isVideoFile($new_file_name)) {
                $type = "video";
            }

            $current_text = !$text_saved ? $content_text : "";
            $visibility = "private"; // 使用 private，權限由共享表控制

            $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "iissssss", $user_id, $capsule_id, $type, $current_text, $media_url, $thumbnail_url, $capture_date, $visibility);
                if (mysqli_stmt_execute($stmt)) {
                    $memory_id = mysqli_insert_id($link);
                    $inserted_memory_ids[] = $memory_id;
                    $text_saved = true;
                }
                mysqli_stmt_close($stmt);
            }
        }

        // 如果沒有檔案但文字不為空，單獨插入文字記憶
        if (!$text_saved && !empty($content_text)) {
            $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) 
                    VALUES (?, ?, 'note', ?, NULL, NULL, ?, 'private')";
            if ($stmt = mysqli_prepare($link, $sql)) {
                $capture_date = date('Y-m-d');
                mysqli_stmt_bind_param($stmt, "iiss", $user_id, $capsule_id, $content_text, $capture_date);
                if (mysqli_stmt_execute($stmt)) {
                    $memory_id = mysqli_insert_id($link);
                    $inserted_memory_ids[] = $memory_id;
                }
                mysqli_stmt_close($stmt);
            }
        }

        // 插入共享關聯
        if (!empty($inserted_memory_ids) && !empty($selected_users)) {
            $share_sql = "INSERT INTO tbl_memory_shared (memory_id, target_user_id) VALUES (?, ?)";
            if ($share_stmt = mysqli_prepare($link, $share_sql)) {
                foreach ($inserted_memory_ids as $mid) {
                    foreach ($selected_users as $target_uid) {
                        mysqli_stmt_bind_param($share_stmt, "ii", $mid, $target_uid);
                        mysqli_stmt_execute($share_stmt);
                    }
                }
                mysqli_stmt_close($share_stmt);
            }
        }

        // 清理臨時目錄
        if (!empty($temp_files)) {
            $temp_dir = dirname($temp_files[0]['tmp_path']);
            if (is_dir($temp_dir)) {
                array_map('unlink', glob($temp_dir . "/*"));
                rmdir($temp_dir);
            }
        }

        unset($_SESSION['pending_memory']);

        header("Location: aboutyou.php" . ($capsule_id ? "?capsule_id=" . $capsule_id : ""));
        exit;
    }
}

// ========== Step 1: 接收檔案並暫存（首次提交） ==========
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST["confirm_share"])) {
    $capsule_id = isset($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : 0;
    $content_text = isset($_POST["content_text"]) ? trim($_POST["content_text"]) : "";
    $client_dates_json = isset($_POST["client_dates"]) ? $_POST["client_dates"] : "{}";
    $client_dates = json_decode($client_dates_json, true);
    if (!is_array($client_dates)) $client_dates = [];

    if ($capsule_id <= 0) {
        $error_msg = "無效的膠囊 ID。";
        // 但仍繼續顯示選擇畫面，避免中斷
    }

    // 建立臨時目錄
    $temp_base = "uploads/temp/";
    if (!is_dir($temp_base)) mkdir($temp_base, 0755, true);
    $temp_session_dir = $temp_base . session_id() . "_" . time();
    mkdir($temp_session_dir, 0755, true);

    $temp_files = [];
    $has_files = isset($_FILES['media']) && (
        (is_array($_FILES['media']['name']) && !empty($_FILES['media']['name'][0])) ||
        (!is_array($_FILES['media']['name']) && !empty($_FILES['media']['name']))
    );

    if ($has_files) {
        $uploaded_files = [];
        if (is_array($_FILES['media']['name'])) {
            for ($i = 0; $i < count($_FILES['media']['name']); $i++) {
                $uploaded_files[] = [
                    'name' => $_FILES['media']['name'][$i],
                    'type' => $_FILES['media']['type'][$i],
                    'tmp_name' => $_FILES['media']['tmp_name'][$i],
                    'error' => $_FILES['media']['error'][$i],
                    'size' => $_FILES['media']['size'][$i],
                ];
            }
        } else {
            $uploaded_files[] = [
                'name' => $_FILES['media']['name'],
                'type' => $_FILES['media']['type'],
                'tmp_name' => $_FILES['media']['tmp_name'],
                'error' => $_FILES['media']['error'],
                'size' => $_FILES['media']['size'],
            ];
        }

        foreach ($uploaded_files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) continue;
            if (!file_exists($file['tmp_name'])) continue;
            $dest = $temp_session_dir . "/" . basename($file['name']);
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $temp_files[] = [
                    'tmp_path' => $dest,
                    'original_name' => $file['name'],
                    'mime_type' => $file['type']
                ];
            }
        }
    }

    // 存入 Session
    $_SESSION['pending_memory'] = [
        'capsule_id' => $capsule_id,
        'content_text' => $content_text,
        'client_dates' => $client_dates,
        'temp_files' => $temp_files,
    ];

    // 繼續顯示選擇畫面（無需 redirect）
}

// ========== 顯示使用者清單頁面 ==========
// 取得所有使用者（除了自己，但自己也會顯示且預設勾選）
$all_users_sql = "SELECT id, username FROM tbl_user ORDER BY username";
$all_users = [];
if ($result = mysqli_query($link, $all_users_sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $all_users[] = $row;
    }
    mysqli_free_result($result);
}

// 如果沒有待處理資料（直接訪問），導回首頁
if (empty($_SESSION['pending_memory']) && !isset($error_msg)) {
    header("Location: aboutyou.php");
    exit;
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>選擇可見對象 - AboutYou</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container { max-width: 700px; width: 100%; }
        .card {
            background: white;
            padding: 30px 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .card h1 { font-size: 26px; color: #667eea; margin-bottom: 10px; }
        .card p { color: #999; margin-bottom: 20px; }
        .user-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 10px;
        }
        .user-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        .user-item:last-child { border-bottom: none; }
        .user-item label {
            font-size: 15px;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }
        .user-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .select-all {
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .select-all input { width: 18px; height: 18px; cursor: pointer; }
        .select-all label { font-size: 14px; font-weight: 500; cursor: pointer; }
        .file-preview {
            background: #f9f9f9;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #666;
            word-break: break-word;
        }
        @media (max-width: 600px) {
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>👥 選擇可見對象</h1>
        <p>這批記憶將被以下使用者看到（可複選）</p>

        <?php if (!empty($error_msg)): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php
        $pending = $_SESSION['pending_memory'] ?? null;
        if ($pending):
        ?>
            <div class="file-preview">
                <?php if (!empty($pending['temp_files'])): ?>
                    📎 已選擇 <?php echo count($pending['temp_files']); ?> 個檔案
                <?php else: ?>
                    📝 僅文字記憶（無附件）
                <?php endif; ?>
                <?php if (!empty($pending['content_text'])): ?>
                    <br>📝 文字：<?php echo nl2br(htmlspecialchars($pending['content_text'])); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="aboutyou_share.php">
            <input type="hidden" name="confirm_share" value="1">

            <div class="select-all">
                <input type="checkbox" id="select_all" onclick="toggleAll(this.checked)">
                <label for="select_all">全選</label>
            </div>

            <div class="user-list">
                <?php foreach ($all_users as $user): ?>
                    <div class="user-item">
                        <label>
                            <input type="checkbox" name="shared_users[]" value="<?php echo $user['id']; ?>"
                                   <?php echo ($user['id'] == $user_id) ? 'checked disabled' : ''; ?>>
                            <?php echo htmlspecialchars($user['username']); ?>
                            <?php if ($user['id'] == $user_id): ?>
                                <span style="font-size:12px;color:#999;">（你）</span>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <a href="aboutyou.php<?php echo ($pending && $pending['capsule_id']) ? '?capsule_id=' . $pending['capsule_id'] : ''; ?>" class="btn btn-secondary">取消</a>
                <button type="submit" class="btn btn-primary">確認發佈</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAll(checked) {
    document.querySelectorAll('input[name="shared_users[]"]').forEach(cb => {
        if (!cb.disabled) cb.checked = checked;
    });
}
</script>
</body>
</html>
