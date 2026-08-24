<?php
// aboutyou_edit_capsule.php
session_start();
$link = require_once "config.php";
require_once "aboutyou_helpers.php"; // 引入 helper 加強上傳工具支援
mysqli_set_charset($link, "utf8mb4");

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$username = $_SESSION["username"];
$capsule_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 獲取當前使用者的 user_id 確保資料操作安全性
$user_id = 0;
$u_sql = "SELECT id FROM tbl_user WHERE username = ?";
if($u_stmt = mysqli_prepare($link, $u_sql)){
    mysqli_stmt_bind_param($u_stmt, "s", $username);
    mysqli_stmt_execute($u_stmt);
    mysqli_stmt_bind_result($u_stmt, $user_id);
    mysqli_stmt_fetch($u_stmt);
    mysqli_stmt_close($u_stmt);
}

if(!$user_id) {
    die("未授權訪問。");
}

// =================== 處理刪除功能 (Delete Action) ===================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_delete"])) {
    $target_id = intval($_POST["capsule_id"]);

    // 1. 保留回憶：將關聯回憶項目的 capsule_id 設定為 NULL 斷開與此膠囊的關聯
    $preserve_sql = "UPDATE tbl_memories SET capsule_id = NULL WHERE capsule_id = ? AND user_id = ?";
    if ($p_stmt = mysqli_prepare($link, $preserve_sql)) {
        mysqli_stmt_bind_param($p_stmt, "ii", $target_id, $user_id);
        mysqli_stmt_execute($p_stmt);
        mysqli_stmt_close($p_stmt);
    }

    // 2. 刪除該指定的時空膠囊
    $delete_sql = "DELETE FROM tbl_time_capsules WHERE id = ? AND user_id = ?";
    if ($d_stmt = mysqli_prepare($link, $delete_sql)) {
        mysqli_stmt_bind_param($d_stmt, "ii", $target_id, $user_id);
        if (mysqli_stmt_execute($d_stmt)) {
            mysqli_stmt_close($d_stmt);
            header("Location: aboutyou.php");
            exit;
        }
        mysqli_stmt_close($d_stmt);
    }
    echo "<script>alert('刪除失敗，請稍後再試。');</script>";
}

// =================== 處理變更更新功能 (Update Action - 已加入頭像上傳處理) ===================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_update"])) {
    $title = trim($_POST["title"]);
    $description = trim($_POST["description"]);
    
    // 預設頭像為舊有頭像
    $profile_image_url = isset($_POST["existing_profile_image_url"]) ? trim($_POST["existing_profile_image_url"]) : null;
    
    // 處理新頭像上傳
    if (isset($_FILES["profile_image"]) && $_FILES["profile_image"]["error"] === UPLOAD_ERR_OK) {
        $upload_dir = "uploads/capsule_profiles/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = getFileExtension($_FILES["profile_image"]["name"]);
        $file_name = uniqid("cap_prof_") . "." . $file_ext;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $file_path)) {
            $profile_image_url = $file_path;
        }
    }
    
    $update_sql = "UPDATE tbl_time_capsules SET title = ?, description = ?, profile_image_url = ? WHERE id = ? AND user_id = ?";
    if ($up_stmt = mysqli_prepare($link, $update_sql)) {
        mysqli_stmt_bind_param($up_stmt, "sssii", $title, $description, $profile_image_url, $capsule_id, $user_id);
        mysqli_stmt_execute($up_stmt);
        mysqli_stmt_close($up_stmt);
        header("Location: aboutyou.php?capsule_id=" . $capsule_id);
        exit;
    }
}

// 載入當前膠囊現有內容 (包含 profile_image_url)
$capsule_title = "";
$capsule_desc = "";
$capsule_img = "";
if($capsule_id > 0) {
    $select_sql = "SELECT title, description, profile_image_url FROM tbl_time_capsules WHERE id = ? AND user_id = ?";
    if ($sel_stmt = mysqli_prepare($link, $select_sql)) {
        mysqli_stmt_bind_param($sel_stmt, "ii", $capsule_id, $user_id);
        mysqli_stmt_execute($sel_stmt);
        mysqli_stmt_bind_result($sel_stmt, $capsule_title, $capsule_desc, $capsule_img);
        mysqli_stmt_fetch($sel_stmt);
        mysqli_stmt_close($sel_stmt);
    }
} else {
    header("Location: aboutyou.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>編輯時空膠囊 - AboutYou</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .edit-container { max-width: 600px; margin: 50px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .current-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; background: #e2e8f0; border: 2px solid #cbd5e1; }
    </style>
</head>
<body>

<div class="edit-container">
    <h3 class="fw-bold text-dark mb-4">✏️ 編輯時空膠囊</h3>
    
    <form action="aboutyou_edit_capsule.php?id=<?php echo $capsule_id; ?>" method="POST" enctype="multipart/form-data" class="mb-4">
        <input type="hidden" name="existing_profile_image_url" value="<?php echo htmlspecialchars($capsule_img); ?>">
        
        <div class="mb-3">
            <label class="form-label fw-bold">膠囊名稱 (Title)</label>
            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($capsule_title); ?>" required>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-bold">描述 (Description)</label>
            <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($capsule_desc); ?></textarea>
        </div>

        <div class="mb-4 p-3 bg-light rounded border">
            <label class="form-label fw-bold d-block">膠囊頭像 (Capsule Profile Image)</label>
            <div class="d-flex align-items-center gap-3 mb-3">
                <img src="<?php echo $capsule_img ? htmlspecialchars($capsule_img) : 'images/default_capsule.png'; ?>" class="current-avatar" alt="Current Avatar">
                <span class="text-muted small">當前使用的頭像</span>
            </div>
            <input type="file" name="profile_image" class="form-control" accept="image/*">
            <div class="form-text text-muted">可選：上傳一張新圖片來更換此膠囊的頭像。</div>
        </div>
        
        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
            <a href="aboutyou.php?capsule_id=<?php echo $capsule_id; ?>" class="btn btn-light border">取消返回</a>
            <button type="submit" name="action_update" class="btn btn-primary px-4">儲存變更</button>
        </div>
    </form>

    <div class="bg-light p-3 rounded-3 border border-danger-subtle mt-5">
        <h5 class="text-danger fw-bold mb-2">⚠️ 危險區域 (Danger Zone)</h5>
        <p class="text-muted small mb-3">刪除此時空膠囊後將無法復原。請放心，該膠囊內所包含的所有相片、影片及文字回憶將會被完整保留。</p>
        
        <form action="aboutyou_edit_capsule.php?id=<?php echo $capsule_id; ?>" method="POST" onsubmit="return confirmDelete();">
            <input type="hidden" name="capsule_id" value="<?php echo $capsule_id; ?>">
            <button type="submit" name="action_delete" class="btn btn-danger btn-sm">刪除此 Capsule</button>
        </form>
    </div>
</div>

<script>
function confirmDelete() {
    return confirm("⚠️ 您確定要永久刪除這個時空膠囊嗎？\n\n此操作會將該膠囊外殼移除，但內含的 Memories 數據會安全保留在您的時間軸上。確定請按下『確定』。");
}
</script>

</body>
</html>
