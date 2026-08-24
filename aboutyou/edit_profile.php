<?php
session_start();
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$link = require_once "config.php";
mysqli_set_charset($link, "utf8mb4");
require_once "aboutyou_helpers.php";

$user_id = $_SESSION["id"];
$username = $_SESSION["username"];
$current_nickname = $_SESSION["nickname"] ?? $username;
$current_icon = $_SESSION["icon_url"] ?? null;

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 更新昵称
    $new_nickname = trim($_POST["nickname"] ?? "");
    if (!empty($new_nickname)) {
        $update_nick = "UPDATE tbl_user SET nickname = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $update_nick)) {
            mysqli_stmt_bind_param($stmt, "si", $new_nickname, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION["nickname"] = $new_nickname;
                $current_nickname = $new_nickname;
                $success_msg = "暱稱已更新。";
            } else {
                $error_msg = "更新暱稱失敗，請重試。";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // 头像上传
    if (isset($_FILES["profile_image"]) && $_FILES["profile_image"]["error"] === UPLOAD_ERR_OK) {
        $upload_dir = "uploads/avatars/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $file_ext = getFileExtension($_FILES["profile_image"]["name"]);
        $file_name = "avatar_" . $user_id . "_" . time() . "." . $file_ext;
        $file_path = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $file_path)) {
            if ($current_icon && file_exists($current_icon)) {
                @unlink($current_icon);
            }
            $update_icon = "UPDATE tbl_user SET icon_url = ? WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $update_icon)) {
                mysqli_stmt_bind_param($stmt, "si", $file_path, $user_id);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION["icon_url"] = $file_path;
                    $current_icon = $file_path;
                    $success_msg .= " 頭像已更新。";
                } else {
                    $error_msg = "頭像儲存失敗，請重試。";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error_msg = "頭像上傳失敗，請重試。";
        }
    }

    // ★ 移除儲存後跳轉，僅顯示訊息
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>編輯個人資料</title>
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
        .container { max-width: 600px; width: 100%; }
        .form-card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .form-header h1 {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 10px;
        }
        .form-header p {
            color: #999;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        input[type="text"],
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        input[type="text"]:focus,
        input[type="file"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .current-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            background: #e2e8f0;
            border: 2px solid #cbd5e1;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        .btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .avatar-preview {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .help-text {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        @media (max-width: 600px) {
            .form-card { padding: 20px; }
            .form-header h1 { font-size: 24px; }
            .form-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-card">
        <div class="form-header">
            <h1>✏️ 編輯個人資料</h1>
            <p>更新你的暱稱和頭像</p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success">✓ <?php echo $success_msg; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-error">✗ <?php echo $error_msg; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
            <div class="form-group">
                <label for="nickname">暱稱 (Nickname)</label>
                <input type="text" id="nickname" name="nickname" value="<?php echo htmlspecialchars($current_nickname); ?>" placeholder="輸入你的暱稱">
                <div class="help-text">留空則使用登入帳號名稱</div>
            </div>

            <div class="form-group">
                <label>當前頭像</label>
                <div class="avatar-preview">
                    <img src="<?php echo $current_icon ? htmlspecialchars($current_icon) : 'images/default_avatar.png'; ?>" class="current-avatar" alt="Avatar">
                    <span style="color:#999; font-size:13px;"><?php echo $current_icon ? '已上傳' : '預設頭像'; ?></span>
                </div>
                <label for="profile_image">上傳新頭像</label>
                <input type="file" id="profile_image" name="profile_image" accept="image/*">
                <div class="help-text">支援 JPG, PNG, GIF, 建議正方形比例</div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">儲存變更</button>
                <a href="<?php echo $_SESSION['edit_profile_referer'] ?? 'welcome.php'; ?>" class="btn btn-secondary">返回</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
