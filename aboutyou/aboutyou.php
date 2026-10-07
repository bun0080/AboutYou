<?php
// aboutyou.php - 最終版（保留回憶牆，新增「軌跡」日曆分頁）

// ============================================================
// ★ STEP 1: PHP 配置与错误处理
// ============================================================
@ini_set('upload_max_filesize', '100M');
@ini_set('post_max_size', '100M');
@ini_set('max_file_uploads', '20');
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '120');
@ini_set('max_input_time', '120');

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================================
// ★ STEP 1.5: 偵測 POST 被 post_max_size 丟棄的情形（關鍵修復）
// ============================================================
function th_return_bytes($val) {
    $val = trim($val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $val = intval($val);
    switch ($last) {
        case 'g': $val *= 1024; // no break
        case 'm': $val *= 1024; // no break
        case 'k': $val *= 1024;
    }
    return $val;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $content_length = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
    $post_max_bytes = th_return_bytes(ini_get('post_max_size'));
    $upload_max_bytes = th_return_bytes(ini_get('upload_max_filesize'));

    // 情境 A：POST body 超過 post_max_size → $_POST 與 $_FILES 均為空
    $looks_like_dropped_post = ($content_length > 0
        && empty($_POST)
        && empty($_FILES)
        && !isset($_POST['action_delete_memory'])
        && !isset($_POST['action_edit_memory_text'])
        && !isset($_POST['action_delete_comment'])
        && !isset($_POST['action_edit_comment'])
        && !isset($_POST['set_default_capsule']));

    if ($looks_like_dropped_post) {
        $msg = sprintf(
            '上傳失敗：本次請求大小約 %.1f MB，但伺服器 post_max_size = %s、upload_max_filesize = %s。' .
            '請減少檔案數量或壓縮後重試，或請管理員調高 php.ini 限制。',
            $content_length / 1048576,
            ini_get('post_max_size'),
            ini_get('upload_max_filesize')
        );
        $_SESSION['upload_error'] = $msg;
	// ★ 新增：AJAX 請求直接回 JSON，避免 fetch 拿到 HTML
	    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
	        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
	        header('Content-Type: application/json; charset=utf-8');
	        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
	        exit;
	    }
        $back = 'aboutyou.php';
        if (isset($_GET['capsule_id'])) $back .= '?capsule_id=' . intval($_GET['capsule_id']);
        header("Location: $back");
        exit;
    }
}


// ============================================================
// ★ STEP 2: Session 與登入檢測
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

$link = require_once "config.php";
require_once "aboutyou_helpers.php";
mysqli_set_charset($link, "utf8mb4");

$is_device_login = false;
$is_session_login = false;
$current_user_id = null;
$current_username = null;

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION["id"])) {
    $is_session_login = true;
    $current_user_id = intval($_SESSION["id"]);
    $current_username = $_SESSION["username"] ?? '';
}
if (!$is_session_login) {
    $device_id = $_COOKIE['th_device_id'] ?? $_GET['device_id'] ?? null;
    if ($device_id) {
        $safe_device_id = mysqli_real_escape_string($link, $device_id);
        $auth_result = mysqli_query($link, 
            "SELECT da.user_id, u.username FROM tbl_device_auth da 
             JOIN tbl_user u ON da.user_id = u.id 
             WHERE da.device_id = '$safe_device_id' AND da.is_active = 1 LIMIT 1");
        if ($auth_result && mysqli_num_rows($auth_result) > 0) {
            $auth_row = mysqli_fetch_assoc($auth_result);
            $is_device_login = true;
            $current_user_id = intval($auth_row['user_id']);
            $current_username = $auth_row['username'];
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $current_user_id;
            $_SESSION["username"] = $current_username;
            $_SESSION["login_type"] = "device";
            mysqli_query($link, "UPDATE tbl_device_auth SET last_login = NOW() WHERE device_id = '$safe_device_id'");
            setcookie('th_device_id', $device_id, time() + 86400*365, '/', '', isset($_SERVER['HTTPS']), true);
        }
    }
}

if (!$is_session_login && !$is_device_login) {
    header("Location: login.php");
    exit;
}

$user_id = $current_user_id;
$username = $current_username;
$my_nickname = $_SESSION["nickname"] ?? $username;
$my_icon = $_SESSION["icon_url"] ?? 'images/default_avatar.png';

// 验证用户 & 默认胶囊
$verify_result = mysqli_query($link, "SELECT id, aboutyou_default_capsule FROM tbl_user WHERE id = $user_id");
if (!$verify_result || mysqli_num_rows($verify_result) == 0) die("使用者驗證失敗");
$user_row = mysqli_fetch_assoc($verify_result);
$user_default_capsule = $user_row['aboutyou_default_capsule'] ? intval($user_row['aboutyou_default_capsule']) : null;

// ============================================================
// ★ STEP 3: 胶囊选择逻辑
// ============================================================
$selected_capsule_id = isset($_GET['capsule_id']) ? intval($_GET['capsule_id']) : null;
if ($selected_capsule_id === null && $user_default_capsule) {
    $check = mysqli_query($link, "SELECT id FROM tbl_time_capsules WHERE id = $user_default_capsule");
    if ($check && mysqli_num_rows($check) > 0) $selected_capsule_id = $user_default_capsule;
    if ($check) mysqli_free_result($check);
}
if ($selected_capsule_id === null) {
    $first = mysqli_query($link, "SELECT id FROM tbl_time_capsules ORDER BY created_at DESC LIMIT 1");
    if ($first && mysqli_num_rows($first) > 0) {
        $row = mysqli_fetch_assoc($first);
        $selected_capsule_id = intval($row['id']);
    }
    if ($first) mysqli_free_result($first);
}

// ============================================================
// ★ STEP 4: 新增 AJAX 端點（軌跡日曆專用，修復數據查詢優化）
// ============================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'trajectory') {
    header('Content-Type: application/json');
    // 禁止瀏覽器快取AJAX
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    $capsule_id = isset($_GET['capsule_id']) ? intval($_GET['capsule_id']) : 0;
    if (!$capsule_id) {
        echo json_encode(['error' => 'Missing capsule_id']);
        exit;
    }
    // 權限檢查（與原有邏輯一致）
    $perm_sql = "SELECT id FROM tbl_time_capsules WHERE id = ? AND (user_id = ? OR EXISTS(
                    SELECT 1 FROM tbl_memories m LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id
                    WHERE m.capsule_id = ? AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
                 )) LIMIT 1";
    $perm_stmt = mysqli_prepare($link, $perm_sql);
    mysqli_stmt_bind_param($perm_stmt, "iiiii", $capsule_id, $user_id, $capsule_id, $user_id, $user_id);
    mysqli_stmt_execute($perm_stmt);
    mysqli_stmt_store_result($perm_stmt);
    if (mysqli_stmt_num_rows($perm_stmt) === 0) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    mysqli_stmt_close($perm_stmt);
    $action = isset($_GET['sub_action']) ? $_GET['sub_action'] : 'month_data';
    if ($action === 'month_data') {
        $year = intval($_GET['year']);
        $month = intval($_GET['month']);
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-d', strtotime('+1 month', strtotime($start_date)));

        // 1. 获取有回忆的日期（现有逻辑）
        $sql = "SELECT 
                m.capture_date,
                COUNT(*) as cnt,
                MAX(COALESCE(m.thumbnail_url, m.media_url)) as first_media,
                MAX(m.type) as first_type
                FROM tbl_memories m
                WHERE m.capsule_id = ?
                AND m.capture_date >= ? AND m.capture_date < ?
                AND (m.user_id = ? OR EXISTS (
                SELECT 1 FROM tbl_memory_shared s 
                WHERE s.memory_id = m.id AND FIND_IN_SET(?, s.target_user_ids) > 0
                ))
                GROUP BY m.capture_date";
        $stmt = mysqli_prepare($link, $sql);
 if (!$stmt) {
        echo json_encode(['error' => 'DB prepare failed: ' . mysqli_error($link)]);
        exit;
    }
        mysqli_stmt_bind_param($stmt, "issii", $capsule_id, $start_date, $end_date, $user_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[$row['capture_date']] = [
                'count' => $row['cnt'],
                'first_media' => $row['first_media'],
                'first_type' => $row['first_type']
            ];
        }
        mysqli_stmt_close($stmt);

        // ★ 2. 新增：查询该月有里程碑的日期
        $ms_sql = "SELECT DISTINCT DATE(milestone_date) as ms_date
                FROM tbl_milestones
                WHERE capsule_id = ? AND milestone_date >= ? AND milestone_date < ?";
        $ms_stmt = mysqli_prepare($link, $ms_sql);
        mysqli_stmt_bind_param($ms_stmt, "iss", $capsule_id, $start_date, $end_date);
        mysqli_stmt_execute($ms_stmt);
        $ms_result = mysqli_stmt_get_result($ms_stmt);
        while ($ms_row = mysqli_fetch_assoc($ms_result)) {
            $date = $ms_row['ms_date'];
            if (!isset($data[$date])) {
                // 该日期没有回忆，但要有数据才能让前端渲染黄色背景
                $data[$date] = ['count' => 0, 'first_media' => null, 'first_type' => null];
            }
            // 标记有里程碑
            $data[$date]['has_milestone'] = true;
        }
        mysqli_stmt_close($ms_stmt);

        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
    if ($action === 'day_memories') {
        $date = $_GET['date'];
        // 取得該日所有回憶（不含留言）
        $sql = "SELECT m.id, m.type, m.content_text, m.media_url, m.thumbnail_url, 
               u.nickname, u.icon_url, m.user_id,
               COALESCE(s.like_it, 0) AS like_it
        FROM tbl_memories m
        JOIN tbl_user u ON m.user_id = u.id
        LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id
        WHERE m.capsule_id = ? AND m.capture_date = ?
          AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
        ORDER BY m.created_at ASC";

        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "isii", $capsule_id, $date, $user_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $memories = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $row['nickname'] = $row['nickname'] ?? $row['username'];
            $row['icon_url'] = $row['icon_url'] ?? 'images/default_avatar.png';
            $memories[] = $row;
        }
        mysqli_stmt_close($stmt);

        // 2. 取得該日里程碑記錄 (優化 4)
        $ms_sql = "SELECT ms.id, ms.value, ms.milestone_type, u.nickname, u.icon_url
           FROM tbl_milestones ms
           JOIN tbl_user u ON ms.user_id = u.id
           WHERE DATE(ms.milestone_date) = ?
             AND ms.capsule_id = ?
             AND EXISTS (
                 SELECT 1 FROM tbl_memories m
                 LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id
                 WHERE m.capsule_id = ms.capsule_id
                   AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
                 LIMIT 1
             )";
        $ms_stmt = mysqli_prepare($link, $ms_sql);
        mysqli_stmt_bind_param($ms_stmt, "siii", $date, $capsule_id, $user_id, $user_id);
        mysqli_stmt_execute($ms_stmt);
        $ms_result = mysqli_stmt_get_result($ms_stmt);
        $milestones = [];
        while ($mrow = mysqli_fetch_assoc($ms_result)) {
            $mrow['nickname'] = $mrow['nickname'] ?? '';
            $mrow['icon_url'] = $mrow['icon_url'] ?? 'images/default_avatar.png';
            $milestones[] = $mrow;
        }
        mysqli_stmt_close($ms_stmt);
        echo json_encode(['success' => true, 'memories' => $memories, 'milestones'=> $milestones]);
        exit;
    }
    echo json_encode(['error' => 'Invalid sub_action']);
    exit;
}

// ============================================================
// ★ STEP 4.5: 喜愛項目（like_it）AJAX 端點
// ============================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'toggle_like') {
    header('Content-Type: application/json; charset=utf-8');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    $memory_id = isset($_POST['memory_id']) ? intval($_POST['memory_id']) : 0;
    $like      = isset($_POST['like']) ? (intval($_POST['like']) ? 1 : 0) : 0;

    if ($memory_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid memory_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 權限檢查：使用者擁有該回憶，或該回憶有共享給他
    $check_sql = "SELECT m.id FROM tbl_memories m
                  LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id
                  WHERE m.id = ?
                    AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
                  LIMIT 1";
    $check_stmt = mysqli_prepare($link, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "iii", $memory_id, $user_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    if (mysqli_stmt_num_rows($check_stmt) === 0) {
        mysqli_stmt_close($check_stmt);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_close($check_stmt);

    // Upsert：若已存在 → 更新 like_it；若不存在 → 建立一筆，target_user_ids 帶入自己
    $upsert_sql = "INSERT INTO tbl_memory_shared (memory_id, target_user_ids, like_it)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE like_it = ?";
    $upsert_stmt = mysqli_prepare($link, $upsert_sql);
 if (!$upsert_stmt) {
        echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . mysqli_error($link)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $target_str  = (string)$user_id;
    mysqli_stmt_bind_param($upsert_stmt, "isii", $memory_id, $target_str, $like, $like);
    $ok = mysqli_stmt_execute($upsert_stmt);
    mysqli_stmt_close($upsert_stmt);

    echo json_encode(['success' => (bool)$ok, 'like_it' => $like], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'favorites_list') {
    header('Content-Type: application/json; charset=utf-8');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    $fav_capsule_id = isset($_GET['capsule_id']) ? intval($_GET['capsule_id']) : 0;
    if (!$fav_capsule_id) {
        echo json_encode(['success' => false, 'error' => 'Missing capsule_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "SELECT m.id, m.type, m.content_text, m.media_url, m.thumbnail_url,
                   m.capture_date, m.created_at, m.user_id AS memory_owner_id
            FROM tbl_memories m
            JOIN tbl_memory_shared s ON m.id = s.memory_id
            WHERE m.capsule_id = ?
              AND s.like_it = 1
              AND m.media_url IS NOT NULL AND m.media_url != ''
              AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
            ORDER BY m.created_at DESC";
    $stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . mysqli_error($link)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    mysqli_stmt_bind_param($stmt, "iii", $fav_capsule_id, $user_id, $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['like_it'] = 1;
        $items[] = $row;
    }
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ★ STEP 5: 原有 POST 處理（完全保留，未更動）
// ============================================================
// 刪除 Memory
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_delete_memory"])) {
    $memory_id = intval($_POST["memory_id"]);
    if ($memory_id > 0) {
        $get = mysqli_prepare($link, "SELECT media_url, thumbnail_url FROM tbl_memories WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($get, "ii", $memory_id, $user_id);
        mysqli_stmt_execute($get);
        mysqli_stmt_bind_result($get, $media_url, $thumbnail_url);
        if (mysqli_stmt_fetch($get)) {
            mysqli_stmt_close($get);
            $del = mysqli_prepare($link, "DELETE FROM tbl_memories WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($del, "ii", $memory_id, $user_id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
            if ($media_url && file_exists($media_url)) @unlink($media_url);
            if ($thumbnail_url && file_exists($thumbnail_url)) @unlink($thumbnail_url);
        } else {
            mysqli_stmt_close($get);
        }
    }
    header("Location: aboutyou.php" . ($selected_capsule_id ? "?capsule_id=" . $selected_capsule_id : ""));
    exit;
}
// 編輯 Memory 文字
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_edit_memory_text"])) {
    $memory_id = intval($_POST["memory_id"]);
    $new_text = trim($_POST["content_text"]);
    if ($memory_id > 0) {
        $stmt = mysqli_prepare($link, "UPDATE tbl_memories SET content_text = ? WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $new_text, $memory_id, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: aboutyou.php" . ($selected_capsule_id ? "?capsule_id=" . $selected_capsule_id : ""));
    exit;
}
// 編輯留言
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_edit_comment"])) {
    $comment_id = intval($_POST["comment_id"]);
    $new_comment = trim($_POST["comment_text"]);
    if ($comment_id > 0 && !empty($new_comment)) {
        $stmt = mysqli_prepare($link, "UPDATE tbl_memory_comments SET comment_text = ? WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $new_comment, $comment_id, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: aboutyou.php" . ($selected_capsule_id ? "?capsule_id=" . $selected_capsule_id : ""));
    exit;
}
// 刪除留言
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_delete_comment"])) {
    $comment_id = intval($_POST["comment_id"]);
    if ($comment_id > 0) {
        $stmt = mysqli_prepare($link, "DELETE FROM tbl_memory_comments WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $comment_id, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: aboutyou.php" . ($selected_capsule_id ? "?capsule_id=" . $selected_capsule_id : ""));
    exit;
}
// 設置默認膠囊
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["set_default_capsule"])) {
    $target = intval($_POST["default_capsule_id"]);
    mysqli_query($link, "UPDATE tbl_user SET aboutyou_default_capsule = $target WHERE id = $user_id");
    mysqli_query($link, "UPDATE tbl_time_capsules SET is_default = 0 WHERE user_id = $user_id");
    mysqli_query($link, "UPDATE tbl_time_capsules SET is_default = 1 WHERE id = $target");
    header("Location: aboutyou.php?capsule_id=" . $target);
    exit;
}
// 新增回憶（POST 处理）
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_post_memory"])) {
    $content_text = trim($_POST["content_text"] ?? "");
    $capsule_id = isset($_POST["capsule_id"]) && !empty($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : $selected_capsule_id;
    $visibility = "private";

    $shared_users = isset($_POST["shared_users"]) ? $_POST["shared_users"] : [];
    $shared_users = array_filter($shared_users, 'is_numeric');
    $shared_users = array_map('intval', $shared_users);
    if (!in_array($user_id, $shared_users)) $shared_users[] = $user_id;
    $shared_users = array_unique(array_map('intval', $shared_users));
    sort($shared_users);
    $shared_user_ids_str = implode(',', $shared_users);

    $client_dates_json = $_POST["client_dates"] ?? "{}";
    $client_dates = json_decode($client_dates_json, true);
    if (!is_array($client_dates)) $client_dates = [];
    $upload_dir = "uploads/memories/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $text_saved = false;
    $inserted_memory_ids = [];
    $has_files = isset($_FILES['media']) && is_array($_FILES['media']['name']) && !empty($_FILES['media']['name'][0]);

    if ($has_files) {
        $uploaded_files = [];
        for ($i = 0; $i < count($_FILES['media']['name']); $i++) {
            $uploaded_files[] = [
                'name' => $_FILES['media']['name'][$i],
                'type' => $_FILES['media']['type'][$i],
                'tmp_name' => $_FILES['media']['tmp_name'][$i],
                'error' => $_FILES['media']['error'][$i],
                'size' => $_FILES['media']['size'][$i],
            ];
        }
       $upload_err_map = [
	    UPLOAD_ERR_INI_SIZE   => '超過 upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
	    UPLOAD_ERR_FORM_SIZE  => '超過表單 MAX_FILE_SIZE',
	    UPLOAD_ERR_PARTIAL    => '只上傳了一部分',
	    UPLOAD_ERR_NO_FILE    => '沒有檔案',
	    UPLOAD_ERR_NO_TMP_DIR => '缺少臨時目錄',
	    UPLOAD_ERR_CANT_WRITE => '寫入磁碟失敗',
	    UPLOAD_ERR_EXTENSION  => '被 PHP 擴充中止',
	];

       foreach ($uploaded_files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
	        $reason = $upload_err_map[$file['error']] ?? ('錯誤碼 ' . $file['error']);
	        error_log("[aboutyou] Upload failed: {$file['name']} -> $reason");
	        // 累積到 session，最後再一次性顯示
	        if (!isset($_SESSION['upload_error'])) {
	            $_SESSION['upload_error'] = "檔案「{$file['name']}」上傳失敗：$reason";
	        }
	        continue;
	    }
            if (!file_exists($file['tmp_name'])) {
	        error_log("[aboutyou] tmp file missing: {$file['tmp_name']}");
	        continue;
	    }

            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (empty($file_ext) || strlen($file_ext) > 10) {
                $mime_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/heic'=>'heic','image/heif'=>'heif','video/mp4'=>'mp4'];
                $file_ext = $mime_map[$file['type']] ?? 'jpg';
            }
            $new_file_name = uniqid("mem_") . "." . $file_ext;
            $final_path = $upload_dir . $new_file_name;
            if (!move_uploaded_file($file['tmp_name'], $final_path)) continue;

            $media_url = $final_path; $type = "note";
            $thumbnail_url = null;
            $client_date = isset($client_dates[$file['name']]) ? $client_dates[$file['name']] : null;
            $capture_date = getMediaCaptureDate($final_path, $client_date);
            $is_heif = isHeifFile($new_file_name);
            if ($is_heif) {
                $converted_jpg = $upload_dir . uniqid("mem_conv_") . ".jpg";
                if (convertHeifToJpeg($final_path, $converted_jpg)) {
                    $media_url = $converted_jpg;
                    $type = "photo";
                    $tn = uniqid("thumb_") . ".jpg";
                    if (createThumbnail($converted_jpg, $upload_dir . $tn)) $thumbnail_url = $upload_dir . $tn;
                } else { $type = "photo"; }
            } elseif (isImageFile($new_file_name)) {
                $type = "photo";
                $tn = uniqid("thumb_") . ".jpg";
                if (createThumbnail($final_path, $upload_dir . $tn)) $thumbnail_url = $upload_dir . $tn;
            } elseif (isVideoFile($new_file_name)) {
	        $type = "video";
	        // ★ 新增：用 ffmpeg 抽 JPEG 縮圖
	        $vt = uniqid("vthumb_") . ".jpg";
	        if (createVideoThumbnail($final_path, $upload_dir . $vt, 0.5, 400)) {
	            $thumbnail_url = $upload_dir . $vt;
	        }
	    }

            $current_text = !$text_saved ? $content_text : "";
            $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($link, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "iissssss", $user_id, $capsule_id, $type, $current_text, $media_url, $thumbnail_url, $capture_date, $visibility);
                if (mysqli_stmt_execute($stmt)) {
                    $inserted_id = mysqli_insert_id($link);
                    $inserted_memory_ids[] = $inserted_id;
                    $text_saved = true;
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    if (!$text_saved && !empty($content_text)) {
        $capture_date = date('Y-m-d');
        $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) VALUES (?, ?, 'note', ?, NULL, NULL, ?, ?)";
        $stmt = mysqli_prepare($link, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iisss", $user_id, $capsule_id, $content_text, $capture_date, $visibility);
            if (mysqli_stmt_execute($stmt)) {
                $inserted_id = mysqli_insert_id($link);
                $inserted_memory_ids[] = $inserted_id;
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (!empty($inserted_memory_ids) && !empty($shared_user_ids_str)) {
        $share_sql = "INSERT INTO tbl_memory_shared (memory_id, target_user_ids) VALUES (?, ?)";
        $share_stmt = mysqli_prepare($link, $share_sql);
        if ($share_stmt) {
            foreach ($inserted_memory_ids as $mid) {
                mysqli_stmt_bind_param($share_stmt, "is", $mid, $shared_user_ids_str);
                mysqli_stmt_execute($share_stmt);
            }
            mysqli_stmt_close($share_stmt);
        }
    }

    // ----- 同步 Milestone (更新 milestone_updated) -----
    if (!empty($inserted_memory_ids) && $capsule_id) {
        $first_mem_id = $inserted_memory_ids[0];
        $capture_date = null;
        $date_sql = "SELECT capture_date FROM tbl_memories WHERE id = ?";
        if ($dstmt = mysqli_prepare($link, $date_sql)) {
            mysqli_stmt_bind_param($dstmt, "i", $first_mem_id);
            mysqli_stmt_execute($dstmt);
            mysqli_stmt_bind_result($dstmt, $capture_date);

            mysqli_stmt_fetch($dstmt);
 
        if (mysqli_stmt_fetch($dstmt)) {
            $check_sql = "SELECT id FROM tbl_milestones WHERE user_id = ? AND milestone_date = ?";
            if ($cstmt = mysqli_prepare($link, $check_sql)) {
                mysqli_stmt_bind_param($cstmt, "is", $user_id, $capture_date);
                mysqli_stmt_execute($cstmt);
                mysqli_stmt_store_result($cstmt);
                if (mysqli_stmt_num_rows($cstmt) > 0) {
                    $upd_sql = "UPDATE tbl_milestones SET milestone_updated = NOW(),memory_id= ?  WHERE user_id = ? AND milestone_date = ?";
                    if ($ustmt = mysqli_prepare($link, $upd_sql)) {
                        mysqli_stmt_bind_param($ustmt, "iis", $first_mem_id, $user_id, $capture_date);
                        mysqli_stmt_execute($ustmt);
                        mysqli_stmt_close($ustmt);
                    }
                } else {
                    $ins_sql = "INSERT INTO tbl_milestones (user_id, memory_id, milestone_type, value, notes, milestone_date, milestone_updated)
                                VALUES (?, ?, 'custom', '', '', ?, NOW())";
                    if ($istmt = mysqli_prepare($link, $ins_sql)) {
                        mysqli_stmt_bind_param($istmt, "iis", $user_id,$first_mem_id, $capture_date);
                        mysqli_stmt_execute($istmt);
                        mysqli_stmt_close($istmt);
                    }
                }
                mysqli_stmt_close($cstmt);
            }
        }
mysqli_stmt_close($dstmt);
    }
}
    // ★ AJAX 請求：回傳 JSON（含成功/警告訊息、重導 URL）
    $redirect_url = 'aboutyou.php' . ($capsule_id ? "?capsule_id=" . $capsule_id : "");
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        $resp = ['success' => true, 'redirect' => $redirect_url];
        if (isset($_SESSION['upload_error'])) {
            $resp['warning'] = $_SESSION['upload_error'];
            unset($_SESSION['upload_error']);
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }

    header("Location: $redirect_url");
    exit;
}
// 留言提交
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action_post_comment"])) {
    $target_date = $_POST["target_date"];
    $comment_text = trim($_POST["comment_text"]);
    $c_capsule_id = isset($_POST["capsule_id"]) && !empty($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : $selected_capsule_id;
    if (!empty($comment_text)) {
        $com_sql = "INSERT INTO tbl_memory_comments (user_id, capsule_id, target_date, comment_text) VALUES (?, ?, ?, ?)";
        $com_stmt = mysqli_prepare($link, $com_sql);
        if ($com_stmt) {
            mysqli_stmt_bind_param($com_stmt, "iiss", $user_id, $c_capsule_id, $target_date, $comment_text);
            mysqli_stmt_execute($com_stmt);
            mysqli_stmt_close($com_stmt);
        }
    }
    header("Location: aboutyou.php" . ($c_capsule_id ? "?capsule_id=" . $c_capsule_id : ""));
    exit;
}

// ============================================================
// ★ STEP 6: 獲取膠囊列表、用戶列表、以及記憶（原有邏輯，完全保留）
// ============================================================
$all_capsules = [];
$capsules_sql = "SELECT tc.id, tc.title, tc.profile_image_url, tc.is_default, tc.user_id, tc.created_at,
                        u.username as owner_name,
                        (SELECT COUNT(*) FROM tbl_memories WHERE capsule_id = tc.id) as memory_count
                 FROM tbl_time_capsules tc
                 JOIN tbl_user u ON tc.user_id = u.id
                 ORDER BY tc.created_at DESC";
$capsules_result = mysqli_query($link, $capsules_sql);
if ($capsules_result) {
    while ($row = mysqli_fetch_assoc($capsules_result)) $all_capsules[] = $row;
    mysqli_free_result($capsules_result);
}

$selected_capsule_info = null;
if ($selected_capsule_id) {
    foreach ($all_capsules as $cap) {
        if ($cap['id'] == $selected_capsule_id) { $selected_capsule_info = $cap; break; }
    }
}
$is_capsule_owner = $selected_capsule_info && $selected_capsule_info['user_id'] == $user_id;
$is_user_default = ($user_default_capsule && $selected_capsule_id == $user_default_capsule);
$my_mem_count = 0; $my_mile_count = 0;
$r1 = mysqli_query($link, "SELECT COUNT(*) FROM tbl_memories WHERE capsule_id = $selected_capsule_id");
if ($r1) { $my_mem_count = mysqli_fetch_row($r1)[0]; mysqli_free_result($r1); }
$r2 = mysqli_query($link, "SELECT COUNT(*) FROM tbl_milestones WHERE capsule_id = $selected_capsule_id");
if ($r2) { $my_mile_count = mysqli_fetch_row($r2)[0]; mysqli_free_result($r2); }

// 获取所有用户（用于“可觀看的使用者”），包含 nickname 和 icon_url
$all_users = [];
$users_sql = "SELECT id, username, nickname, icon_url FROM tbl_user ORDER BY username";
$users_result = mysqli_query($link, $users_sql);
if ($users_result) {
    while ($row = mysqli_fetch_assoc($users_result)) {
        $row['nickname'] = $row['nickname'] ?? $row['username'];
        $row['icon_url'] = $row['icon_url'] ?? 'images/default_avatar.png';
        $all_users[] = $row;
    }
    mysqli_free_result($users_result);
}

// 获取评论数据（关联用户昵称和头像）
$comments_data = [];
if ($selected_capsule_id) {
    $fc_sql = "SELECT c.id as comment_id, c.user_id as comment_user_id, c.target_date, c.comment_text, c.created_at, 
                      u.username, u.nickname, u.icon_url
               FROM tbl_memory_comments c 
               JOIN tbl_user u ON c.user_id = u.id 
               WHERE c.capsule_id = ? 
               ORDER BY c.created_at ASC";
    $fc_stmt = mysqli_prepare($link, $fc_sql);
    if ($fc_stmt) {
        mysqli_stmt_bind_param($fc_stmt, "i", $selected_capsule_id);
        mysqli_stmt_execute($fc_stmt);
        $fc_res = mysqli_stmt_get_result($fc_stmt);
        while ($c_row = mysqli_fetch_assoc($fc_res)) {
            $c_row['nickname'] = $c_row['nickname'] ?? $c_row['username'];
            $c_row['icon_url'] = $c_row['icon_url'] ?? 'images/default_avatar.png';
            $comments_data[$c_row['target_date']][] = $c_row;
        }
        mysqli_stmt_close($fc_stmt);
    }
}

// ★ 獲取記憶 (原有 AJAX 分段加載邏輯，完全保留)
$total_memories = 0;
$is_ajax = isset($_GET['ajax_load']) && $_GET['ajax_load'] == '1';
$limit_days = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 5; 
$offset_days = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

if ($is_ajax) {
    // ★ 先清掉所有早於此處的殘留輸出（config.php 的 BOM、尾隨換行、意外警告等）
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start(); // 開始緩衝
}

$memories = [];
$total_days = 0;

if ($selected_capsule_id && $selected_capsule_info) {
    // 1. 先計算總共有多少「不同的日期」
    $count_sql = "SELECT COUNT(DISTINCT m.capture_date) as total_days 
                  FROM tbl_memories m 
                  LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id 
                  WHERE m.capsule_id = ? 
                    AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)";
    if ($stmt = mysqli_prepare($link, $count_sql)) {
        mysqli_stmt_bind_param($stmt, "iii", $selected_capsule_id, $user_id, $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) $total_days = $row['total_days'];
        mysqli_stmt_close($stmt);
    }

    // 2. 獲取要顯示的日期列表 (分页获取日期)
    $date_list = [];
    $date_sql = "SELECT DISTINCT capture_date 
                 FROM tbl_memories m 
                 LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id 
                 WHERE m.capsule_id = ? 
                   AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
                 ORDER BY capture_date DESC LIMIT ? OFFSET ?";
    if ($stmt = mysqli_prepare($link, $date_sql)) {
        mysqli_stmt_bind_param($stmt, "iiiii", $selected_capsule_id, $user_id, $user_id, $limit_days, $offset_days);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $date_list[] = $row['capture_date'];
        mysqli_stmt_close($stmt);
    }

    // 3. 根據獲取的日期列表，一次撈取這些天數內所有的記憶
    if (!empty($date_list)) {
        $date_placeholders = implode(',', array_fill(0, count($date_list), '?'));
        $mem_sql = "SELECT m.id, m.type, m.content_text, m.media_url, m.thumbnail_url, m.capture_date, m.created_at, 
                   m.user_id AS memory_owner_id, u.username, u.nickname, u.icon_url,
                   COALESCE(s.like_it, 0) AS like_it
            FROM tbl_memories m 
            JOIN tbl_user u ON m.user_id = u.id
            LEFT JOIN tbl_memory_shared s ON m.id = s.memory_id
            WHERE m.capsule_id = ? 
            AND (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0)
            AND m.capture_date IN ($date_placeholders)
            ORDER BY m.capture_date DESC, m.created_at ASC";
        
        $params = array_merge([$selected_capsule_id, $user_id, $user_id], $date_list);
        $stmt = mysqli_prepare($link, $mem_sql);
	if ($stmt === false) {
	    error_log('[aboutyou ajax] mem_sql prepare failed: ' . mysqli_error($link));
	} else {
	       $types = "iii" . str_repeat("s", count($date_list));
	        mysqli_stmt_bind_param($stmt, $types, ...$params);
	        if (!mysqli_stmt_execute($stmt)) {
		        error_log('[aboutyou ajax] mem_sql execute failed: ' . mysqli_stmt_error($stmt));
		} else {
	        	$mem_res = mysqli_stmt_get_result($stmt);
		        while ($m = mysqli_fetch_assoc($mem_res)) {
		            $m['nickname'] = $m['nickname'] ?? $m['username'];
		            $m['icon_url'] = $m['icon_url'] ?? 'images/default_avatar.png';
		            $memories[] = $m;
		        }
		}
        mysqli_stmt_close($stmt);
    }
}
}
$login_type_display = ($is_device_login) ? '📱 自動登入' : '';

function render_memories_html($memories, $offset_days, $user_id, $selected_capsule_id, $comments_data) {
    $html = '';
    if (count($memories) == 0 && $offset_days == 0) {
        $html .= '<div style="text-align:center;padding:30px 0;color:var(--text-light);">✨ 還沒有回憶，上傳第一張照片吧</div>';
    } else {
        $grouped = [];
        foreach ($memories as $m) {
            $grouped[$m['capture_date']][] = $m;
        }
        foreach ($grouped as $date => $mems) {
            $module_hash = md5($date . '_' . $offset_days);
            $html .= '<div class="memory-module">';
            $html .= '<div class="memory-date-bar">📅 '.htmlspecialchars($date).' <span class="badge-tag badge-tag-light">'.count($mems).' 項</span></div>';
            
            $media_items = [];
            foreach ($mems as $m) {
                if (!empty($m['media_url'])) {
                    $media_items[] = ['id'=>$m['id'], 'url'=>$m['media_url'], 'thumb' => $m['thumbnail_url'] ?? null, 'type'=>$m['type'], 'owner_id'=>$m['memory_owner_id'], 'like_it'=>intval($m['like_it'] ?? 0)];
                }
            }
            if (count($media_items)) {
                $html .= '<div class="media-grid">';
                foreach ($media_items as $idx => $md) {
                    $isOwn = ($md['owner_id'] == $user_id);
                    $html .= '<div class="media-thumb" onclick="openLightbox(\''.$module_hash.'\','.$idx.')">';
                    if ($md['type']==='video' || isVideoFile($md['url'])) {
	    		if (!empty($md['thumb']) && file_exists($md['thumb'])) {
		            // ★ 有 ffmpeg 縮圖 → 用 img，iOS 也能正常顯示
		            $html .= '<img src="'.htmlspecialchars($md['thumb']).'" alt="" loading="lazy"><span class="thumb-type-tag">🎥</span>';
			} else {
                            // fallback：沒縮圖時才用 video
			    $html .= '<video src="'.htmlspecialchars($md['url']).'" muted preload="metadata" playsinline webkit-playsinline></video><span class="thumb-type-tag">🎥</span>';
			}
                    } else {
                        $html .= '<img src="'.htmlspecialchars($md['url']).'" alt="" loading="lazy"><span class="thumb-type-tag">📷</span>';
                    }
                    if ($isOwn) {
                        $html .= '<form method="POST" action="aboutyou.php?capsule_id='.$selected_capsule_id.'" onsubmit="return confirm(\'確定要刪除嗎？\');" onclick="event.stopPropagation();" style="margin:0;">';
                        $html .= '<input type="hidden" name="action_delete_memory" value="1"><input type="hidden" name="memory_id" value="'.$md['id'].'">';
                        $html .= '<button type="submit" class="delete-thumb-btn">✕</button></form>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
                $html .= '<script>window.mediaData_'.$module_hash.'='.json_encode($media_items).';</script>';
            }
            
            $texts = [];
            foreach ($mems as $m) {
                if (!empty(trim($m['content_text']))) {
                    $texts[] = ['id'=>$m['id'], 'text'=>$m['content_text'], 'owner_id'=>$m['memory_owner_id']];
                }
            }
            if (!empty($texts)) {
                $html .= '<div class="text-block">';
                foreach ($texts as $t) {
                    $html .= '<p style="margin:0 0 6px;word-break:break-word;">'.htmlspecialchars($t['text']).'</p>';
                    if ($t['owner_id'] == $user_id) {
                        $html .= '<button class="edit-btn-inline" onclick="openTextEditor('.$t['id'].',\''.htmlspecialchars(addslashes($t['text'])).'\')">✏️ 編輯文字</button>';
                    }
                }
                $html .= '</div>';
            }
            
            $html .= '<div class="comments-block">';
            if (isset($comments_data[$date])) {
                foreach ($comments_data[$date] as $c) {
                    $isCOwner = ($c['comment_user_id'] == $user_id);
                    $c_nickname = $c['nickname'] ?? $c['username'];
                    $c_icon = $c['icon_url'] ?? 'images/default_avatar.png';
                    $html .= '<div class="comment-bubble">';
                    if ($isCOwner) {
                        $html .= '<div class="comment-actions-row">';
                        $html .= '<button onclick="openCommentEditor('.$c['comment_id'].',\''.htmlspecialchars(addslashes($c['comment_text'])).'\')">✏️</button>';
                        $html .= '<button class="del-btn" onclick="deleteComment('.$c['comment_id'].')">🗑️</button>';
                        $html .= '</div>';
                    }
                    $html .= '<div class="comment-line">';
                    $html .= '<img src="'.htmlspecialchars($c_icon).'" class="comment-avatar">';
                    $html .= '<span class="comment-author">'.htmlspecialchars($c_nickname).'</span>';
                    $html .= '<span class="comment-time">'.date('m/d H:i',strtotime($c['created_at'])).'</span>';
                    if ($isCOwner) $html .= '<span style="font-size:10px;color:var(--text-light);">（你）</span>';
                    $html .= '<span class="comment-body">'.htmlspecialchars($c['comment_text']).'</span>';
                    $html .= '</div>';
                    $html .= '</div>';
                }
            }
            $html .= '<form action="aboutyou.php?capsule_id='.$selected_capsule_id.'" method="POST" class="comment-form-row" style="display:flex;gap:6px;align-items:center;margin-top:10px;">';
            $html .= '<input type="hidden" name="action_post_comment" value="1">';
            $html .= '<input type="hidden" name="capsule_id" value="'.$selected_capsule_id.'">';
            $html .= '<input type="hidden" name="target_date" value="'.htmlspecialchars($date).'">';
            $html .= '<div class="comment-input-wrapper" style="position:relative;flex:1;">';
            $html .= '<input type="text" name="comment_text" placeholder="留言..." required>';
            $html .= '<button type="button" class="emoji-btn" onclick="toggleEmojiPickerForComment(this, \'comment-input-'.$module_hash.'\')">😀</button>';
            $html .= '<div id="picker-comment-input-'.$module_hash.'" class="custom-emoji-picker" style="display:none;position:absolute;bottom:100%;right:0;background:#fff;border:1px solid #ddd;padding:6px;border-radius:6px;z-index:1000;width:200px;flex-wrap:wrap;gap:4px;"></div>';
            $html .= '</div>';
            $html .= '<button type="submit" class="btn btn-sm btn-primary" style="flex-shrink:0;">送出</button>';
            $html .= '</form>';
            $html .= '</div>'; 
            $html .= '</div>'; 
        }
    }
    return $html;
}

// 判斷若是 AJAX，則直接輸出函數結果並結束
if ($is_ajax) {
    // ★ 丟棄緩衝區中任何意外輸出，保證 JSON 純淨
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'html' => render_memories_html($memories, $offset_days, $user_id, $selected_capsule_id, $comments_data),
        'has_more' => ($offset_days + $limit_days < $total_days)
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>AboutYou - 回憶時光機</title>
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="/apple-touch-icon">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* ===== 所有樣式（包含新增的軌跡日曆樣式） ===== */
        :root {
            --bg: #fdfaf5; --card: #fffdf8; --primary: #b8956a;
            --primary-light: #faf3e8; --text: #4a3728; --text-soft: #6b5540;
            --text-light: #9b8a78; --border: #e5d9c8; --border-light: #f0e8d8;
            --danger: #c9958b; --shadow: 0 2px 8px rgba(120,90,60,0.06);
            --radius: 14px; --radius-lg: 20px;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            font-family: 'Noto Sans TC', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg); color: var(--text); margin: 0; padding: 0;
            font-size: 16px; line-height: 1.65; -webkit-font-smoothing: antialiased; overflow-x: hidden;
        }
        .app-container { max-width: 600px; margin: 0 auto; padding: 8px 10px 60px; width: 100%; }
        .app-header { display: flex; align-items: center; justify-content: space-between; padding: 6px 4px 10px; position: sticky; top: 0; background: var(--bg); z-index: 100; }
        .app-logo { font-size: 20px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 5px; }
        .header-actions { display: flex; gap: 6px; align-items: center; }
        .login-type-badge { font-size: 10px; color: #7a9a5c; background: #f0f5e8; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 3px; padding: 4px 8px; border-radius: 20px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid transparent; text-decoration: none; font-family: inherit; white-space: nowrap; min-height: 34px; -webkit-tap-highlight-color: transparent; user-select: none; transition: all 0.15s; }
        .btn:active { transform: scale(0.96); }
        .btn-sm { padding: 1px 5px; font-size: 13px; border-radius: 18px; min-height: 34px; }
        .btn-xs { padding: 4px 10px; font-size: 11px; border-radius: 14px; min-height: 28px; }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-outline { background: #fff; color: var(--text-soft); border-color: var(--border); }
        .btn-ghost { background: transparent; color: var(--text-soft); border-color: transparent; }
        .btn-back { background: #fff; color: var(--text-soft); border: 1.5px solid var(--border); font-size: 13px; gap: 4px; }
        .btn-back:active { background: var(--primary-light); }
        .btn-refresh { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); font-size: 16px; padding: 7px 12px; border-radius: 50%; min-height: 40px; width: 40px; display: inline-flex; align-items: center; justify-content: center; }
        .btn-refresh:active { background: var(--primary-light); transform: scale(0.92); }
        .btn-device { background: linear-gradient(135deg, #f8f4ef 0%, #f0e8d8 100%); color: var(--text-soft); border: 1px solid var(--border); font-size: 13px; gap: 3px; padding: 4px 8px; border-radius: 20px; min-height: 34px; box-shadow: 0 1px 3px rgba(120,90,60,0.08); transition: all 0.2s ease; }
        .btn-device:hover { background: linear-gradient(135deg, #f0e8d8 0%, #e8dcc8 100%); border-color: #d0c0a0; box-shadow: 0 2px 8px rgba(120,90,60,0.15); }
        .btn-device:active { transform: scale(0.96); background: var(--primary-light); }
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
        .stat-card { background: var(--card); border-radius: var(--radius); padding: 12px 8px; text-align: center; border: 1px solid var(--border-light); }
        .stat-num { font-size: 24px; font-weight: 700; color: var(--primary); line-height: 1.2; }
        .stat-label { font-size: 10px; color: var(--text-light); letter-spacing: 0.5px; margin-top: 2px; }
        .section-card { background: var(--card); border-radius: var(--radius-lg); padding: 16px; margin-bottom: 14px; border: 1px solid var(--border-light); box-shadow: var(--shadow); width: 100%; overflow: hidden; }
        .section-title-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 10px; }
        .section-title { font-size: 16px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 6px; }
        .capsule-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; background: #fff; border: 1.5px solid var(--border-light); border-radius: var(--radius); margin-bottom: 6px; cursor: pointer; width: 100%; transition: all 0.15s; }
        .capsule-item:active { background: var(--primary-light); border-color: var(--primary); }
        .capsule-item.active { border-color: var(--primary); background: var(--primary-light); }
        .capsule-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); flex-shrink: 0; background: #f5f0e8; }
        .capsule-info { flex: 1; min-width: 0; }
        .capsule-name { font-size: 14px; font-weight: 500; color: var(--text); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .capsule-meta { font-size: 11px; color: var(--text-light); display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .capsule-actions { display: flex; gap: 4px; flex-shrink: 0; align-items: center; }
        .owner-tag { font-size: 10px; padding: 2px 6px; border-radius: 6px; background: #f5f0e8; color: var(--text-soft); }
        .default-badge { font-size: 10px; padding: 2px 7px; border-radius: 8px; background: #e8f5e0; color: #5a8a3c; font-weight: 500; display: inline-flex; align-items: center; gap: 3px; }
        .default-dot { width: 5px; height: 5px; background: #6a9a4c; border-radius: 50%; }
        .upload-area { border: 2.5px dashed var(--border); border-radius: var(--radius); padding: 22px 16px; text-align: center; background: linear-gradient(135deg,#fefdf9,#faf6f0); cursor: pointer; width: 100%; touch-action: manipulation; }
        .upload-icon { font-size: 36px; margin-bottom: 4px; }
        .upload-label { font-size: 15px; font-weight: 500; color: var(--text-soft); }
        .upload-hint { font-size: 12px; color: var(--text-light); margin-top: 4px; }
        .scanning-indicator { display: flex; align-items: center; gap: 8px; padding: 10px; font-size: 14px; color: var(--primary); }
        .scanning-spinner { width: 16px; height: 16px; border: 2.5px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .preview-date-group { margin-bottom: 12px; border: 1px solid var(--border-light); border-radius: var(--radius); overflow: hidden; }
        .preview-date-header { padding: 8px 12px; background: var(--primary-light); font-size: 12px; font-weight: 500; color: var(--primary); display: flex; align-items: center; justify-content: space-between; }
        .count-badge { font-size: 10px; background: var(--primary); color: #fff; padding: 1px 7px; border-radius: 9px; }
        .preview-files-row { padding: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
        .preview-thumb { position: relative; width: 75px; height: 75px; border-radius: 10px; overflow: hidden; border: 2px solid var(--border-light); flex-shrink: 0; }
        .preview-thumb img, .preview-thumb video { width: 100%; height: 100%; object-fit: cover; }
        .preview-thumb .remove-btn { position: absolute; top: 2px; right: 2px; width: 22px; height: 22px; background: rgba(190,130,120,0.9); color: #fff; border: none; border-radius: 50%; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 5; }
        .preview-thumb .type-tag { position: absolute; bottom: 2px; left: 2px; font-size: 10px; background: rgba(0,0,0,0.5); color: #fff; padding: 1px 4px; border-radius: 3px; }
        .share-toggle { display: inline-flex; align-items: center; gap: 6px; background: var(--primary-light); border: 1.5px solid var(--border); padding: 7px 14px; border-radius: 20px; font-size: 14px; color: var(--text-soft); cursor: pointer; transition: 0.2s; user-select: none; min-height: 40px; }
        .share-toggle:hover { background: #f0e8d8; border-color: #d0c0a0; }
        .share-toggle .arrow { font-size: 12px; transition: transform 0.2s; }
        .share-toggle .arrow.open { transform: rotate(180deg); }
        .share-panel { display: none; margin-top: 12px; border: 1px solid var(--border-light); border-radius: var(--radius); background: #fffdf8; padding: 10px 12px; max-height: 200px; overflow-y: auto; }
        .share-panel.open { display: block; }
        .share-user-item { display: flex; align-items: center; padding: 6px 0; border-bottom: 1px solid #f5f0e8; }
        .share-user-item:last-child { border-bottom: none; }
        .share-user-item label { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text); cursor: pointer; width: 100%; }
        .share-user-item input[type="checkbox"] { width: 17px; height: 17px; cursor: pointer; accent-color: var(--primary); }
        .share-user-item .username { flex: 1; }
        .share-user-item .self-tag { font-size: 11px; color: var(--text-light); }
        .share-user-item .user-avatar { width: 24px; height: 24px; border-radius: 50%; object-fit: cover; }
        .share-panel-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 6px; border-bottom: 1px solid var(--border-light); margin-bottom: 6px; font-size: 13px; color: var(--text-light); }
        .share-panel-header .select-all-btn { background: none; border: none; color: var(--primary); cursor: pointer; font-size: 13px; font-weight: 500; padding: 2px 8px; border-radius: 4px; }
        .share-panel-header .select-all-btn:hover { background: var(--primary-light); }
        /* 回憶牆原有樣式 */
        .memory-module { border: 1px solid var(--border-light); border-radius: var(--radius-lg); background: var(--card); margin-bottom: 16px; overflow: hidden; }
        .memory-date-bar { padding: 10px 14px; background: linear-gradient(135deg,#fdf9f2,#faf5ed); border-bottom: 1px solid var(--border-light); font-size: 14px; font-weight: 600; color: var(--text-soft); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .badge-tag { font-size: 10px; padding: 2px 7px; border-radius: 9px; font-weight: 500; }
        .badge-tag-light { background: #f5ede3; color: var(--text-soft); }
        .badge-tag-accent { background: #edf2f7; color: #6b8a9e; }
        .media-grid { padding: 10px; display: flex; flex-wrap: wrap; gap: 6px; }
        .media-thumb { position: relative; width: calc(33.333% - 4px); aspect-ratio: 1; border-radius: 10px; overflow: hidden; border: 2px solid var(--border-light); cursor: pointer; background: #faf8f3; }
        @media (min-width: 480px) { .media-thumb { width: 120px; height: 120px; aspect-ratio: auto; } }
        @media (max-width: 600px) { .btn-back { display: none !important;  }}
        .media-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .media-thumb video { width: 100%; height: 100%; object-fit: cover; background: #000; }
        .media-thumb .delete-thumb-btn { position: absolute; top: 4px; right: 4px; width: 24px; height: 24px; background: rgba(190,130,120,0.85); color: #fff; border: none; border-radius: 50%; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 5; }
        .media-thumb .thumb-type-tag { position: absolute; bottom: 4px; left: 4px; font-size: 10px; background: rgba(0,0,0,0.5); color: #fff; padding: 1px 5px; border-radius: 3px; }
        .text-block { padding: 12px 14px; border-top: 1px dashed var(--border-light); font-size: 15px; color: var(--text); line-height: 1.7; }
        .edit-btn-inline { display: inline-block; margin-top: 6px; background: transparent; border: 1px solid var(--border); border-radius: 14px; padding: 5px 12px; font-size: 12px; cursor: pointer; color: var(--text-light); font-family: inherit; min-height: 32px; }
        .comments-block { padding: 10px 14px 14px; background: #fefdfa; border-top: 1px solid var(--border-light); }
        .comment-bubble { margin-bottom: 8px; background: #fff; padding: 10px 12px; border-radius: var(--radius); border: 1px solid var(--border-light); position: relative; word-wrap: break-word; }
        .comment-bubble .comment-line { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; }
        .comment-author { font-weight: 600; color: var(--primary); font-size: 13px; }
        .comment-time { font-size: 11px; color: var(--text-light); }
        .comment-body { font-size: 14px; color: var(--text); line-height: 1.6; word-break: break-word; }
        .comment-actions-row { position: absolute; top: 8px; right: 8px; display: flex; gap: 4px; }
        .comment-actions-row button { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 3px 7px; font-size: 11px; cursor: pointer; color: var(--text-light); font-family: inherit; min-height: 26px; }
        .comment-form-row { display: flex; gap: 6px; margin-top: 10px; align-items: center; }
        .comment-form-row input { flex: 1; min-width: 0; padding: 9px 35px 9px 13px; border: 1.5px solid var(--border); border-radius: 20px; font-size: 15px; font-family: inherit; color: var(--text); background: #fff; }
        .comment-form-row input:focus { outline: none; border-color: var(--primary); }
        .comment-form-row button { flex-shrink: 0; padding: 9px 14px; border-radius: 20px; font-size: 13px; min-height: 40px; white-space: nowrap; }
        .comment-form-row .emoji-btn { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 18px; cursor: pointer; }
        .custom-emoji-picker { display: none; position: absolute; bottom: 100%; right: 0; background: #fff; border: 1px solid #ddd; padding: 6px; border-radius: 6px; z-index: 1000; width: 200px; flex-wrap: wrap; gap: 4px; }
        .custom-emoji-picker .emoji-item { cursor: pointer; font-size: 20px; padding: 2px; }
        .custom-emoji-picker .emoji-item:hover { background: #eee; border-radius: 4px; }
        .comment-avatar { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; vertical-align: middle; margin-right: 4px; }
        .lightbox { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.93); z-index: 9999; flex-direction: column; align-items: center; justify-content: center; }
        .lightbox.active { display: flex; }
        /* ===== Lightbox 媒體容器：交叉淡入淡出 + 支援手勢 ===== */
#lightbox-content {
    position: relative;
    width: 95vw;
    height: 75vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    touch-action: none;          /* 讓自訂手勢生效 */
    -webkit-user-select: none;
    user-select: none;
}
#lightbox-content .lb-media-item {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) scale(1);
    max-width: 95vw;
    max-height: 75vh;
    object-fit: contain;
    border-radius: 6px;
    opacity: 0;
    transition: opacity 0.18s ease;
    will-change: transform, opacity;
    user-select: none;
    -webkit-user-drag: none;
    -webkit-touch-callout: none;
    pointer-events: none;
}
#lightbox-content .lb-media-item[data-visible="1"] {
    pointer-events: auto;
}
        .lightbox-top-bar { position: absolute; top: max(10px, env(safe-area-inset-top)); right: 10px; display: flex; gap: 8px; z-index: 10; }
        .lightbox-top-bar button { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 20px; padding: 8px 14px; font-size: 14px; cursor: pointer; font-family: inherit; min-height: 40px; }
        .lightbox-nav-area { position: absolute; top: 50%; transform: translateY(-50%); width: 45px; height: 55px; display: flex; align-items: center; justify-content: center; font-size: 26px; color: rgba(255,255,255,0.6); cursor: pointer; z-index: 10; }
        .lightbox-nav-area.left { left: 0; } .lightbox-nav-area.right { right: 0; }
        .lightbox-counter { color: rgba(255,255,255,0.6); font-size: 13px; margin-top: 10px; }
        #lightbox-like-btn {
    font-size: 22px;
    line-height: 1;
    padding: 6px 12px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 20px;
    color: #fff;
    cursor: pointer;
    transition: transform 0.15s ease, background 0.15s ease;
}
#lightbox-like-btn:active {
    transform: scale(0.9);
    background: rgba(255,255,255,0.3);
}
#lightbox-like-btn.is-liked {
    background: rgba(255, 105, 130, 0.25);
    border-color: rgba(255, 105, 130, 0.6);
}
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.45); z-index: 10000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-dialog { background: #fff; border-radius: var(--radius-lg); padding: 20px; width: 90%; max-width: 420px; }
        .modal-dialog h3 { font-size: 16px; font-weight: 600; margin: 0 0 10px; }
        .modal-dialog textarea { width: 100%; padding: 12px; border: 1.5px solid var(--border); border-radius: var(--radius); font-size: 15px; font-family: inherit; resize: vertical; min-height: 80px; }
        .modal-dialog textarea:focus { outline: none; border-color: var(--primary); }
        .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
        .text-muted { color: var(--text-light); font-size: 14px; }
        .comment-input-wrapper { position:relative; flex:1; }
        .comment-input-wrapper input { width:100%; padding-right:40px; }
        .comment-input-wrapper .emoji-btn { position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; font-size:18px; cursor:pointer; }
        .comment-input-wrapper .custom-emoji-picker { display:none; position:absolute; bottom:100%; right:0; background:#fff; border:1px solid #ddd; padding:6px; border-radius:6px; z-index:1000; width:200px; flex-wrap:wrap; gap:4px; }
        .comment-input-wrapper .custom-emoji-picker.open { display:flex; }
        .emoji-item { cursor:pointer; font-size:20px; padding:2px; }
        .emoji-item:hover { background:#eee; border-radius:4px; }

        /* ===== 新增：軌跡日曆專用樣式（修復網格布局） ===== */
.tab-bar {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border-light);
    margin-bottom: 16px;
}
.tab-btn {
    flex: 4 1 0%;
    min-width: 0;
    padding: 10px 0;
    background: transparent;
    border: none;
    font-size: 15px;
    font-weight: 500;
    color: var(--text-light);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: 0.2s;
    white-space: nowrap;
}
.tab-btn[data-tab="favorites"] {
    flex: 2 1 0%;   /* 4:4:2 */
    font-size: 17px;
}
.tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
}
.tab-content { display: none; }
.tab-content.active { display: block; }
.trajectory-container { padding: 4px 0; }
.cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.cal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
}
/* 固定網格，移除JS動態覆蓋衝突代碼 */
.cal-grid {
    display: grid;
    grid-template-columns: 30px repeat(7, 1fr);
    gap: 4px;
    margin-bottom: 12px;
}
.cal-week-label {
    font-size: 11px;
    color: var(--text-light);
    text-align: center;
    padding: 6px 0;
    background: #f8f4ef;
    border-radius: 4px;
}
.cal-day-header {
    text-align: center;
    font-size: 12px;
    color: var(--text-light);
    padding: 6px 0;
    font-weight: 500;
}
.cal-day {
    aspect-ratio: 1 / 1;
    background: #fff;
    border: 1px solid var(--border-light);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: 0.15s;
    position: relative;
    overflow: hidden;
}
.cal-day:hover { border-color: var(--primary); }
.cal-day.empty { background: #faf8f5; cursor: default; }
.cal-day .day-number {
    position: absolute;
    top: 2px;
    left: 4px;
    background: rgba(255, 255, 255, 0.85);
    padding: 0 5px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    z-index: 5;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.cal-day .day-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    position: absolute;
    top: 0;
    left: 0;
    opacity: 0.7;
}
.cal-day .day-count {
    position: absolute;
    bottom: 2px;
    right: 4px;
    background: rgba(0,0,0,0.5);
    color: #fff;
    font-size: 10px;
    padding: 0 5px;
    border-radius: 8px;
    z-index: 3;
}
.cal-day.today { border-color: var(--primary); background: var(--primary-light); }
.cal-day.selected { border-color: #764ba2; background: #f3e8ff; }
.cal-day.has-memory { border-color: var(--primary); }
.cal-day-detail {
    margin-top: 16px;
    border-top: 1px solid var(--border);
    padding-top: 12px;
}
.cal-day-detail h4 { margin: 0 0 10px; font-size: 16px; }
.cal-memory-item {
    display: flex;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f0ebe3;
}
.cal-memory-item:last-child { border-bottom: none; }
.cal-memory-item .mem-thumb {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    cursor: pointer;
}
.cal-memory-item .mem-text {
    flex: 1;
    font-size: 14px;
    color: var(--text);
}
.cal-memory-item .mem-meta {
    font-size: 12px;
    color: var(--text-light);
}
.milestone-quick-add {
    margin-top: 16px;
    border-top: 1px solid var(--border);
    padding-top: 12px;
}
.milestone-quick-add label {
    font-weight: 600;
    font-size: 14px;
}
.milestone-quick-add .input-group {
    display: flex;
    gap: 8px;
    margin-top: 6px;
}
.milestone-quick-add .input-group input {
    flex: 1;
    padding: 8px 12px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
}
.milestone-quick-add .input-group button {
    flex-shrink: 0;
}
#milestone-msg {
    font-size: 13px;
    color: #7a9a5c;
    margin-top: 4px;
}
.cal-loading {
    text-align: center;
    padding: 20px;
    color: var(--text-light);
    grid-column: 1 / -1;
}
.cal-day.has-milestone .day-number {
    background: #ffd700; /* 金色 */
    color: #333;         /* 深色文字保持可读 */
    box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
/* ===== 上傳進度條 Overlay ===== */
.upload-progress-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(74, 55, 40, 0.55);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.upload-progress-overlay.active { display: flex; }
.upload-progress-card {
    background: var(--card);
    border-radius: var(--radius-lg);
    padding: 28px 24px;
    width: 100%;
    max-width: 360px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(74, 55, 40, 0.25);
    border: 1px solid var(--border-light);
}
.upload-progress-icon { font-size: 42px; margin-bottom: 8px; display: block; }
.upload-progress-title {
    font-size: 17px; font-weight: 600;
    color: var(--text); margin-bottom: 16px;
}
.upload-progress-bar {
    width: 100%; height: 10px;
    background: var(--primary-light);
    border-radius: 6px; overflow: hidden;
    margin-bottom: 12px;
}
.upload-progress-fill {
    height: 100%; width: 0%;
    background: linear-gradient(90deg, #c9a77b, var(--primary));
    border-radius: 6px;
    transition: width 0.25s ease;
}
.upload-progress-fill.processing {
    background: linear-gradient(90deg, var(--primary), #e0c8a0, var(--primary));
    background-size: 200% 100%;
    animation: uploadStripes 1.4s linear infinite;
}
@keyframes uploadStripes {
    0%   { background-position: 100% 0; }
    100% { background-position: -100% 0; }
}
.upload-progress-text { font-size: 13px; color: var(--text-soft); margin-bottom: 4px; font-weight: 500; }
.upload-progress-hint { font-size: 11px; color: var(--text-light); line-height: 1.5; }
.upload-progress-card.failed .upload-progress-title { color: #c0392b; }
.upload-progress-card.failed .upload-progress-fill {
    background: #c9958b !important; width: 100% !important; animation: none !important;
}
.upload-progress-close {
    margin-top: 16px; padding: 8px 24px;
    border: none; border-radius: 20px;
    background: var(--primary); color: #fff;
    font-family: inherit; font-size: 14px; font-weight: 500;
    cursor: pointer; min-height: 38px;
}
.upload-progress-close:active { transform: scale(0.96); }
    </style>
</head>
<body>
<?php if (isset($_SESSION['upload_error'])): ?>
<div style="background:#fdf2f2;color:#c0392b;padding:10px 14px;border-radius:12px;margin:6px 10px;font-size:13px;text-align:center;border:1px solid #f5c6cb;max-width:600px;margin-left:auto;margin-right:auto;">
    ⚠️ <?php echo htmlspecialchars($_SESSION['upload_error']); ?>
</div>
<?php unset($_SESSION['upload_error']); endif; ?>
<div class="app-container">
    <div class="app-header">
        <div class="app-logo"><span class="app-logo-icon">⏳</span><a href="<?php echo $_SERVER['REQUEST_URI']; ?>" title="重新整理">AboutYou</a></div>
        <div class="header-actions">
            <?php if ($login_type_display): ?><span class="login-type-badge"><?php echo $login_type_display; ?></span><?php endif; ?>
            <?php $_SESSION['edit_profile_referer'] = $_SERVER['REQUEST_URI']; ?>
            <a href="edit_profile.php" class="btn btn-outline btn-sm">✏️ 個人資料</a>
            <a href="register_device.php" class="btn btn-device btn-sm">📱 裝置</a>
            <a href="welcome.php" class="btn btn-back btn-sm">Back</a>
        </div>
    </div>

    <div class="stats-row">
        <div class="stat-card"><div class="stat-num"><?php echo count($all_capsules); ?></div><div class="stat-label">全部膠囊</div></div>
        <div class="stat-card"><div class="stat-num"><?php echo $my_mem_count; ?></div><div class="stat-label">我的回憶</div></div>
        <div class="stat-card"><div class="stat-num"><?php echo $my_mile_count; ?></div><div class="stat-label">里程碑</div></div>
    </div>

    <div class="section-card">
        <div class="section-title-row">
            <div class="section-title">⏳ 所有膠囊</div>
            <a href="aboutyou_create_capsule.php" class="btn btn-primary btn-xs">＋ 新增</a>
        </div>
        <?php if (empty($all_capsules)): ?>
            <p class="text-muted">還沒有膠囊，<a href="aboutyou_create_capsule.php">建立第一個</a></p>
        <?php else: ?>
            <?php foreach ($all_capsules as $cap): 
                $isActive = ($cap['id'] == $selected_capsule_id);
                $isOwner = ($cap['user_id'] == $user_id);
                $isDefault = ($user_default_capsule && $cap['id'] == $user_default_capsule);
                $img = $cap['profile_image_url'] ?: 'images/default_capsule.png';
            ?>
                <div class="capsule-item <?php echo $isActive ? 'active' : ''; ?>" 
                     onclick="location.href='aboutyou.php?capsule_id=<?php echo $cap['id']; ?>'">
                    <img src="<?php echo htmlspecialchars($img); ?>" class="capsule-avatar" alt="">
                    <div class="capsule-info">
                        <div class="capsule-name">
                            <?php echo htmlspecialchars($cap['title']); ?>
                            <?php if ($isDefault): ?>
                                <span class="default-badge"><span class="default-dot"></span>預設</span>
                            <?php endif; ?>
                        </div>
                        <div class="capsule-meta">
                            <span class="owner-tag"><?php echo $isOwner ? '我的' : htmlspecialchars($cap['owner_name']); ?></span>
                            <span><?php echo $cap['memory_count']; ?> 回憶</span>
                        </div>
                    </div>
                    <div class="capsule-actions" onclick="event.stopPropagation();">
                        <?php if (!$isDefault): ?>
                            <form action="aboutyou.php" method="POST" style="margin:0;">
                                <input type="hidden" name="default_capsule_id" value="<?php echo $cap['id']; ?>">
                                <button type="submit" name="set_default_capsule" class="btn btn-ghost btn-xs">⭐ 設為預設</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isOwner): ?>
                            <a href="aboutyou_edit_capsule.php?id=<?php echo $cap['id']; ?>" class="btn btn-ghost btn-xs">✏️</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($selected_capsule_id): ?>
    <div class="section-card">
        <div class="section-title">📷 新增回憶</div>
        <form id="memory-form" action="aboutyou.php?capsule_id=<?php echo $selected_capsule_id; ?>" method="POST" enctype="multipart/form-data" accept-charset="UTF-8">
            <input type="hidden" name="action_post_memory" value="1">
            <input type="hidden" name="capsule_id" value="<?php echo $selected_capsule_id; ?>">
            <input type="hidden" name="client_dates" id="client-dates-input" value="{}">
            <textarea name="content_text" placeholder="寫下這段回憶..." style="width:100%;padding:12px;border:1.5px solid var(--border);border-radius:var(--radius);font-size:15px;font-family:inherit;color:var(--text);resize:vertical;min-height:50px;margin-bottom:10px;"></textarea>
            <div class="upload-area" id="upload-area" onclick="document.getElementById('media-input').click();">
                <div class="upload-icon">📁</div>
                <div class="upload-label">點這裡選擇照片或影片</div>
                <div class="upload-hint">支援 JPG、PNG、HEIC、MP4（可多選）</div>
            </div>
            <input type="file" id="media-input" name="media[]" style="position:absolute;opacity:0;width:1px;height:1px;overflow:hidden;" accept="image/*,video/*" multiple>
            <div id="scanning-indicator" class="scanning-indicator" style="display:none;"><div class="scanning-spinner"></div><span id="scanning-text">掃描照片日期中...</span></div>
            <div id="preview-groups-container" style="margin-top:10px;"></div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; flex-wrap:wrap; gap:8px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="share-toggle" id="shareToggle" onclick="toggleSharePanel()">
                        <span>👥 給誰看</span>
                        <span class="arrow" id="shareArrow">▼</span>
                    </span>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">📤 發佈</button>
            </div>

            <div class="share-panel open" id="sharePanel">
                <div class="share-panel-header">
                    <span>可觀看的使用者</span>
                    <button type="button" class="select-all-btn" id="selectAllBtn" onclick="toggleAllCheckboxes()">全選</button>
                </div>
                <?php foreach ($all_users as $u): 
                    $isSelf = ($u['id'] == $user_id);
                ?>
                    <div class="share-user-item">
                        <label>
                            <input type="checkbox" name="shared_users[]" value="<?php echo $u['id']; ?>" 
                                   <?php echo $isSelf ? 'checked disabled' : 'checked'; ?>>
                            <img src="<?php echo htmlspecialchars($u['icon_url']); ?>" class="user-avatar" alt="">
                            <span class="username"><?php echo htmlspecialchars($u['nickname']); ?></span>
                            <?php if ($isSelf): ?>
                                <span class="self-tag">（你）</span>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($selected_capsule_id && $selected_capsule_info): ?>
    <div class="section-card">
        <!-- 雙分頁按鈕 -->
        <div class="tab-bar">
            <button class="tab-btn active" data-tab="timeline">📜 回憶牆</button>
            <button class="tab-btn" data-tab="trajectory">📅 軌跡</button>
            <button class="tab-btn" data-tab="favorites">❤️</button>
        </div>

        <!-- 左分頁：回憶牆（原有內容，完全保留） -->
        <div id="tab-timeline" class="tab-content active">
            <div id="memories-container">
                <div id="memories-wrapper">
                    <?php
                    echo render_memories_html($memories, $offset_days, $user_id, $selected_capsule_id, $comments_data);
                    ?>
                </div>
                <div id="scroll-loading" style="display:none; text-align:center; padding:15px; color:#999; font-size:14px;">
                    <div class="scanning-spinner" style="display:inline-block; margin-right:8px; vertical-align:middle; width:16px; height:16px; border-top-color:#999;"></div>載入中...
                </div>
                <div id="scroll-end" style="display:<?php echo ($total_days <= $limit_days && $total_days > 0) ? 'block' : 'none'; ?>; text-align:center; padding:20px 10px; color:#999; font-size:13px;">
                    — 已載入所有貼文 —
                </div>
            </div>
        </div>

        <!-- 右分頁：軌跡（全新日曆）。移除 style="display:none;" ，交由 CSS .tab-content 管理 -->
        <div id="tab-trajectory" class="tab-content">
            <div class="trajectory-container">
                <!-- 月曆標題與切換 -->
                <div class="cal-header">
                    <button id="cal-prev" class="btn btn-outline btn-sm">◀</button>
                    <span id="cal-title" class="cal-title">2026年 7月</span>
                    <button id="cal-next" class="btn btn-outline btn-sm">▶</button>
                </div>
                <!-- 月曆網格 -->
                <div id="cal-grid" class="cal-grid"></div>
                <!-- 單日詳細 -->
                <div id="cal-day-detail" class="cal-day-detail" style="display:none;">
                    <h4 id="cal-day-title"></h4>
                    <div id="cal-day-memories"></div>
                    <!-- Milestone 快速輸入 -->
                    <div class="milestone-quick-add">
                        <label>🏆 里程碑</label>
                        <div class="input-group">
                            <input type="text" id="milestone-input" placeholder="例如：第一次走路">
                            <button id="milestone-submit" class="btn btn-primary btn-sm">生成</button>
                        </div>
                        <div id="milestone-msg"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ❤️ 喜愛項目分頁 -->
        <div id="tab-favorites" class="tab-content">
            <div id="favorites-container">
                <div class="cal-loading">載入中...</div>
            </div>
        </div>
    </div>
    <?php elseif (empty($all_capsules)): ?>
    <div class="section-card"><div style="text-align:center;padding:20px 0;color:var(--text-light);">📦 還沒有任何膠囊<br><a href="aboutyou_create_capsule.php" class="btn btn-primary btn-sm" style="margin-top:10px;">＋ 建立第一個膠囊</a></div></div>
    <?php endif; ?>
</div>

<!-- 上傳進度 Overlay -->
<div id="upload-progress-overlay" class="upload-progress-overlay">
    <div class="upload-progress-card" id="upload-progress-card">
        <span class="upload-progress-icon" id="upload-progress-icon">📤</span>
        <div class="upload-progress-title" id="upload-progress-title">上傳中…</div>
        <div class="upload-progress-bar">
            <div id="upload-progress-fill" class="upload-progress-fill"></div>
        </div>
        <div class="upload-progress-text" id="upload-progress-text">準備中…</div>
        <div class="upload-progress-hint" id="upload-progress-hint">請勿關閉或重新整理視窗</div>
    </div>
</div>

<!-- ===== 原有 Lightbox、Modal 等（完全保留） ===== -->
<div class="lightbox" id="lightbox">
    <div class="lightbox-top-bar"><button id="lightbox-like-btn" title="標記喜愛">🤍</button><button id="lightbox-delete-btn">🗑️ 刪除</button><button onclick="closeLightbox()">✕ 關閉</button></div>
    <div class="lightbox-nav-area left" id="lightbox-prev" onclick="lightboxNavigate(-1)">◀</div>
    <div id="lightbox-content"></div>
    <div class="lightbox-nav-area right" id="lightbox-next" onclick="lightboxNavigate(1)">▶</div>
    <div class="lightbox-counter" id="lightbox-counter"></div>
</div>
<form method="POST" action="aboutyou.php?capsule_id=<?php echo $selected_capsule_id; ?>" id="lightbox-delete-form" style="display:none;"><input type="hidden" name="action_delete_memory" value="1"><input type="hidden" name="memory_id" id="lightbox-delete-memory-id"></form>
<div class="modal-overlay" id="text-edit-modal"><div class="modal-dialog"><h3>✏️ 編輯文字</h3><form method="POST" action="aboutyou.php?capsule_id=<?php echo $selected_capsule_id; ?>"><input type="hidden" name="action_edit_memory_text" value="1"><input type="hidden" name="memory_id" id="text-edit-memory-id"><textarea name="content_text" id="text-edit-textarea" rows="3"></textarea><div class="modal-actions"><button type="button" class="btn btn-outline btn-sm" onclick="closeTextEditor()">取消</button><button type="submit" class="btn btn-primary btn-sm">儲存</button></div></form></div></div>
<div class="modal-overlay" id="comment-edit-modal"><div class="modal-dialog"><h3>✏️ 編輯留言</h3><form method="POST" action="aboutyou.php?capsule_id=<?php echo $selected_capsule_id; ?>"><input type="hidden" name="action_edit_comment" value="1"><input type="hidden" name="comment_id" id="comment-edit-id"><textarea name="comment_text" id="comment-edit-textarea" rows="2"></textarea><div class="modal-actions"><button type="button" class="btn btn-outline btn-sm" onclick="closeCommentEditor()">取消</button><button type="submit" class="btn btn-primary btn-sm">儲存</button></div></form></div></div>
<form method="POST" action="aboutyou.php?capsule_id=<?php echo $selected_capsule_id; ?>" id="comment-delete-form"><input type="hidden" name="action_delete_comment" value="1"><input type="hidden" name="comment_id" id="comment-delete-id"></form>

<script>
// ===== 原有全域函數（Lightbox, 編輯, 留言, 分享, 上傳預覽等）完全保留 =====
let lb={hash:null,items:[],idx:0,deleted:false};
let currentDayFirstMemoryId = null;
window.__capsuleId = <?php echo $selected_capsule_id ?: 0; ?>;
window.currentDayMedia = [];
function openLightbox(h,i){const d=window['mediaData_'+h];if(!d||!d.length)return;lb.hash=h;lb.items=[...d];lb.idx=Math.min(i,d.length-1);lb.deleted=false;document.getElementById('lightbox').classList.add('active');document.body.style.overflow='hidden';renderLb();}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
    document.body.style.overflow = '';
    if (window.__lbResetZoom) window.__lbResetZoom();
    if (lb.deleted) location.reload();
}
function lightboxNavigate(d){const n=lb.idx+d;if(n<0||n>=lb.items.length)return;lb.idx=n;renderLb();}
let lbRenderToken = 0;

function renderLb() {
    const m = lb.items[lb.idx];
    if (!m) return;
    const c  = document.getElementById('lightbox-content');
    const ct = document.getElementById('lightbox-counter');
    const p  = document.getElementById('lightbox-prev');
    const n  = document.getElementById('lightbox-next');
    const db = document.getElementById('lightbox-delete-btn');

    // 切換時重置縮放
    if (window.__lbResetZoom) window.__lbResetZoom();

    const isVideo = m.type === 'video'
        || /\.(mp4|webm|mov|avi|mkv|flv|wmv)$/i.test(m.url);
    const token = ++lbRenderToken;

    // 1) 先建好新媒體元素（此時 opacity=0）
    const newEl = document.createElement(isVideo ? 'video' : 'img');
    newEl.className = 'lb-media-item';
    if (isVideo) {
        newEl.src = m.url;
        newEl.controls = true;
        newEl.playsInline = true;
        newEl.setAttribute('webkit-playsinline', '');
        newEl.preload = 'metadata';
    } else {
        newEl.src = m.url;
        newEl.alt = '';
        newEl.draggable = false;
    }

    // 2) 新媒體「準備好」後才揭露，同時讓舊媒體淡出
    let revealed = false;
    const reveal = () => {
        if (revealed) return;
        if (token !== lbRenderToken) {          // 已有更新的渲染請求
            if (newEl.parentNode) newEl.remove();
            return;
        }
        revealed = true;

        // 舊元素（可能有多個殘留）先標記為淡出
        const olds = Array.from(c.querySelectorAll('.lb-media-item'));
        olds.forEach(el => {
            el.dataset.visible = '0';
            el.style.opacity = '0';
        });

        c.appendChild(newEl);
        void newEl.offsetWidth;                  // 強制 reflow
        newEl.dataset.visible = '1';
        newEl.style.opacity = '1';

        // 220ms 後清掉舊元素
        setTimeout(() => {
            olds.forEach(el => { if (el.parentNode) el.remove(); });
        }, 220);
    };

    if (isVideo) {
        newEl.addEventListener('loadeddata', reveal, { once: true });
        setTimeout(reveal, 250);                 // fallback
    } else {
        if (newEl.complete && newEl.naturalWidth > 0) {
            reveal();                            // 快取命中，立刻顯示
        } else {
            newEl.addEventListener('load',  reveal, { once: true });
            newEl.addEventListener('error', reveal, { once: true });
            setTimeout(reveal, 1500);            // fallback
        }
    }

    // 3) UI 更新
    ct.textContent = (lb.idx + 1) + ' / ' + lb.items.length;
    p.style.display = lb.idx === 0 ? 'none' : 'flex';
    n.style.display = lb.idx >= lb.items.length - 1 ? 'none' : 'flex';
    db.style.display = (m.owner_id == <?php echo $user_id; ?>) ? 'inline-block' : 'none';
    // ★ 更新愛心按鈕
    const likeBtn = document.getElementById('lightbox-like-btn');
    if (likeBtn) {
        likeBtn.textContent = m.like_it ? '❤️' : '🤍';
        likeBtn.classList.toggle('is-liked', !!m.like_it);
        likeBtn.onclick = function (e) {
            e.stopPropagation();
            toggleLike(m.id);
        };
    }
    db.onclick = async function () {
        if (!confirm('確定要刪除嗎？')) return;
        const mid = lb.items[lb.idx].id;
        lb.deleted = true;
        lb.items.splice(lb.idx, 1);
        if (!lb.items.length) { closeLightbox(); location.reload(); return; }
        if (lb.idx >= lb.items.length) lb.idx = lb.items.length - 1;
        renderLb();
        document.getElementById('lightbox-delete-memory-id').value = mid;
        try {
            await fetch(document.getElementById('lightbox-delete-form').action, {
                method: 'POST',
                body: new FormData(document.getElementById('lightbox-delete-form'))
            });
        } catch (e) {}
    };
}

// ============================================================
// ★ 喜愛項目：切換與狀態同步
// ============================================================
function syncLikeState(memoryId, newState) {
    // 更新 lb.items
    if (Array.isArray(lb.items)) {
        lb.items.forEach(it => { if (it && it.id === memoryId) it.like_it = newState; });
    }
    // 更新所有 window.mediaData_xxx 與其他陣列
    const arrays = [window.currentDayMedia, window.favoritesMediaData];
    Object.keys(window).forEach(k => {
        if (k.startsWith('mediaData_') && Array.isArray(window[k])) arrays.push(window[k]);
    });
    arrays.forEach(arr => {
        if (!Array.isArray(arr)) return;
        arr.forEach(it => { if (it && it.id === memoryId) it.like_it = newState; });
    });
}

function toggleLike(memoryId) {
    const m = (lb.items || []).find(it => it.id === memoryId);
    if (!m) return;
    const newState = m.like_it ? 0 : 1;
    const btn = document.getElementById('lightbox-like-btn');
    if (btn) btn.disabled = true;

    fetch('aboutyou.php?ajax_action=toggle_like', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({ memory_id: memoryId, like: newState })
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            console.error('toggle_like failed:', res.error);
            return;
        }
        syncLikeState(memoryId, res.like_it);
        if (btn) {
            btn.textContent = res.like_it ? '❤️' : '🤍';
            btn.classList.toggle('is-liked', !!res.like_it);
        }
        // 若正在 ❤️ 分頁並取消標記 → 重新載入清單
        if (lb.hash === 'favorites' && !res.like_it && typeof loadFavorites === 'function') {
            setTimeout(loadFavorites, 250);
        }
    })
    .catch(err => console.error('toggle_like error:', err))
    .finally(() => { if (btn) btn.disabled = false; });
}

// ❤️ 分頁載入與 Lightbox
function loadFavorites() {
    const container = document.getElementById('favorites-container');
    if (!container) return;
    container.innerHTML = '<div class="cal-loading">載入中...</div>';

    const capId = window.__capsuleId || 0;
    if (!capId) { container.innerHTML = ''; return; }

    fetch(`aboutyou.php?ajax_action=favorites_list&capsule_id=${capId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.items || !res.items.length) {
            container.innerHTML = '<div style="text-align:center;padding:30px 0;color:var(--text-light);">💔 還沒有喜愛的項目</div>';
            window.favoritesMediaData = [];
            return;
        }
        const items = res.items.map(m => ({
            id: m.id,
            url: m.media_url,
            thumb: m.thumbnail_url || null,
            type: m.type,
            owner_id: m.memory_owner_id,
            like_it: 1
        }));
        window.favoritesMediaData = items;

        let html = '<div class="media-grid" style="padding:0;">';
        items.forEach((m, idx) => {
            const isVideo = m.type === 'video' || /\.(mp4|webm|mov|avi|mkv|flv|wmv)$/i.test(m.url);
            let inner;
            if (isVideo) {
                inner = m.thumb
                    ? `<img src="${escAttr(m.thumb)}" alt="" loading="lazy"><span class="thumb-type-tag">🎥</span>`
                    : `<video src="${escAttr(m.url)}" muted preload="metadata" playsinline webkit-playsinline></video><span class="thumb-type-tag">🎥</span>`;
            } else {
                inner = `<img src="${escAttr(m.url)}" alt="" loading="lazy"><span class="thumb-type-tag">📷</span>`;
            }
            html += `<div class="media-thumb" onclick="openFavLightbox(${idx})">${inner}</div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    })
    .catch(err => {
        console.error('favorites_list error:', err);
        container.innerHTML = '<div style="padding:20px;color:#c0392b;">載入失敗</div>';
    });
}

function escAttr(s) {
    if (!s) return '';
    return String(s).replaceAll('&', '&amp;').replaceAll('"', '&quot;')
                    .replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

window.openFavLightbox = function (idx) {
    if (!window.favoritesMediaData || !window.favoritesMediaData.length) return;
    lb.hash = 'favorites';
    lb.items = [...window.favoritesMediaData];
    lb.idx = idx;
    lb.deleted = false;
    document.getElementById('lightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
    renderLb();
};

// ============================================================
// ★ Lightbox 手勢：雙指縮放 / 單指拖曳 / 左右滑動切圖
// ============================================================
(function () {
    const lbContent = document.getElementById('lightbox-content');
    if (!lbContent) return;

    const zoom = {
        scale: 1, tx: 0, ty: 0,
        startScale: 1, startTx: 0, startTy: 0,
        pinchStartDist: 0,
        panStartX: 0, panStartY: 0,
        isPinching: false, isPanning: false,
        didPinch: false, didPan: false,
        startTouchX: 0, startTouchY: 0, startTime: 0,
    };

    function getActiveEl() {
        const els = lbContent.querySelectorAll('.lb-media-item[data-visible="1"]');
        return els.length ? els[els.length - 1] : null;
    }

    function applyTransform(animate) {
        const el = getActiveEl();
const navs = document.querySelectorAll('.lightbox-nav-area');
        if (!el) return;
        el.style.transition = animate ? 'transform 0.18s ease' : 'none';
        el.style.transform =
            `translate(-50%, -50%) translate(${zoom.tx}px, ${zoom.ty}px) scale(${zoom.scale})`;
navs.forEach(el => el.style.opacity = zoom.scale > 1.05 ? '0' : '0.6');
    }

    function resetZoom() {
        zoom.scale = 1; zoom.tx = 0; zoom.ty = 0;
        zoom.isPinching = false; zoom.isPanning = false;
        zoom.didPinch = false; zoom.didPan = false;
        const el = getActiveEl();
        if (el) {
            el.style.transition = 'transform 0.18s ease';
            el.style.transform = 'translate(-50%, -50%) scale(1)';
        }
    }
    window.__lbResetZoom = resetZoom;

    lbContent.addEventListener('touchstart', function (e) {
        const el = getActiveEl();
        if (!el) return;

        if (e.touches.length === 2) {
            zoom.isPinching = true;
            zoom.didPinch = true;
            zoom.isPanning = false;
            const t1 = e.touches[0], t2 = e.touches[1];
            zoom.pinchStartDist =
                Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1;
            zoom.startScale = zoom.scale;
            el.style.transition = 'none';
        } else if (e.touches.length === 1) {
            const t = e.touches[0];
            zoom.startTouchX = t.clientX;
            zoom.startTouchY = t.clientY;
            zoom.startTime = Date.now();
            zoom.panStartX = t.clientX;
            zoom.panStartY = t.clientY;
            zoom.startTx = zoom.tx;
            zoom.startTy = zoom.ty;
            if (zoom.scale > 1.02) {
                zoom.isPanning = true;
                zoom.didPan = true;
                el.style.transition = 'none';
            }
        }
    }, { passive: true });

    lbContent.addEventListener('touchmove', function (e) {
        if (zoom.isPinching && e.touches.length === 2) {
            const t1 = e.touches[0], t2 = e.touches[1];
            const dist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
            let newScale = zoom.startScale * (dist / zoom.pinchStartDist);
            newScale = Math.max(1, Math.min(5, newScale));
            zoom.scale = newScale;
            if (zoom.scale <= 1.02) { zoom.tx = 0; zoom.ty = 0; }
            applyTransform(false);
            e.preventDefault();
        } else if (zoom.isPanning && e.touches.length === 1) {
            const t = e.touches[0];
            zoom.tx = zoom.startTx + (t.clientX - zoom.panStartX);
            zoom.ty = zoom.startTy + (t.clientY - zoom.panStartY);
            applyTransform(false);
            e.preventDefault();
        }
    }, { passive: false });

    lbContent.addEventListener('touchend', function (e) {
        if (e.touches.length === 0) {
            if (zoom.isPinching) {
                zoom.isPinching = false;
                if (zoom.scale < 1.05) {
                    zoom.scale = 1; zoom.tx = 0; zoom.ty = 0;
                }
                applyTransform(true);
            }
            if (zoom.isPanning) {
                zoom.isPanning = false;
                applyTransform(true);
            }

            // 只有「沒縮放、沒拖曳」時，單指滑動才觸發切圖
            if (!zoom.didPinch && !zoom.didPan && zoom.scale <= 1.05) {
                const t = e.changedTouches[0];
                const dx = t.clientX - zoom.startTouchX;
                const dy = t.clientY - zoom.startTouchY;
                const dt = Date.now() - zoom.startTime;
                if (Math.abs(dx) > 50 &&
                    Math.abs(dx) > Math.abs(dy) * 1.5 &&
                    dt < 600) {
                    lightboxNavigate(dx > 0 ? -1 : 1);
                }
            }
	    // 在 touchend 內，若 !didPinch && !didPan 且單指快速點兩下：
const now = Date.now();
if (now - zoom.lastTap < 300) {
    zoom.scale = zoom.scale > 1.05 ? 1 : 2.5;
    if (zoom.scale === 1) { zoom.tx = 0; zoom.ty = 0; }
    applyTransform(true);
    zoom.lastTap = 0;
} else {
    zoom.lastTap = now;
}
            zoom.didPinch = false;
            zoom.didPan = false;
        } else if (e.touches.length === 1 && zoom.isPinching) {
            // 雙指抬起一指 → 進入單指拖曳
            zoom.isPinching = false;
            if (zoom.scale > 1.02) {
                zoom.isPanning = true;
                zoom.didPan = true;
                const t = e.touches[0];
                zoom.panStartX = t.clientX;
                zoom.panStartY = t.clientY;
                zoom.startTx = zoom.tx;
                zoom.startTy = zoom.ty;
            }
        }
    }, { passive: true });
})();
document.addEventListener('keydown',function(e){if(!document.getElementById('lightbox').classList.contains('active'))return;if(e.key==='ArrowLeft')lightboxNavigate(-1);else if(e.key==='ArrowRight')lightboxNavigate(1);else if(e.key==='Escape')closeLightbox();});

function openTextEditor(id,t){document.getElementById('text-edit-memory-id').value=id;document.getElementById('text-edit-textarea').value=t;document.getElementById('text-edit-modal').classList.add('active');}
function closeTextEditor(){document.getElementById('text-edit-modal').classList.remove('active');}
function openCommentEditor(id,t){document.getElementById('comment-edit-id').value=id;document.getElementById('comment-edit-textarea').value=t;document.getElementById('comment-edit-modal').classList.add('active');}
function closeCommentEditor(){document.getElementById('comment-edit-modal').classList.remove('active');}
function deleteComment(id){if(confirm('確定要刪除嗎？')){document.getElementById('comment-delete-id').value=id;document.getElementById('comment-delete-form').submit();}}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');}));

function toggleSharePanel(){const panel=document.getElementById('sharePanel');const arrow=document.getElementById('shareArrow');panel.classList.toggle('open');arrow.classList.toggle('open');}
function toggleAllCheckboxes(){const checkboxes=document.querySelectorAll('#sharePanel input[type="checkbox"]:not(:disabled)');const allChecked=Array.from(checkboxes).every(cb=>cb.checked);checkboxes.forEach(cb=>cb.checked=!allChecked);}

// ===== 上傳預覽（原有） =====
document.addEventListener('DOMContentLoaded',function(){
    const input=document.getElementById('media-input');if(!input)return;
    const preview=document.getElementById('preview-groups-container'),scanning=document.getElementById('scanning-indicator'),scanText=document.getElementById('scanning-text'),form=document.getElementById('memory-form'),datesInput=document.getElementById('client-dates-input');
    let allFiles=[],fid=0;
    input.addEventListener('change',function(e){if(this.files&&this.files.length)processFiles(this.files);});
    async function processFiles(list){scanning.style.display='flex';preview.innerHTML='';allFiles=[];const files=Array.from(list);for(let i=0;i<files.length;i++){scanText.textContent='掃描中 '+(i+1)+'/'+files.length;try{allFiles.push({id:fid++,file:files[i],date:await getFileDate(files[i])});}catch(e){allFiles.push({id:fid++,file:files[i],date:fmtDate(files[i].lastModified)});}}scanning.style.display='none';renderPreviews();updateInput();}
    async function getFileDate(file){const ext=file.name.split('.').pop().toLowerCase();if(ext==='heic'||ext==='heif')return fmtDate(file.lastModified);if(file.type.startsWith('image/')){try{const d=await readExif(file);if(d)return d;}catch(e){}}return fmtDate(file.lastModified);}
    function readExif(file){return new Promise((ok,no)=>{const r=new FileReader();r.onload=e=>{try{ok(parseExif(e.target.result));}catch(err){no(err);}};r.onerror=no;r.readAsArrayBuffer(file.slice(0,131072));});}
    function parseExif(buf){const v=new DataView(buf);if(v.getUint16(0,false)!==0xFFD8)return null;let o=2;while(o<v.byteLength-4){const m=v.getUint16(o,false),sl=v.getUint16(o+2,false);if(m===0xFFE1){const h=String.fromCharCode(...new Uint8Array(buf.slice(o+4,o+10)));if(h==='Exif\x00\x00')return readIFD(new DataView(buf.slice(o+10,o+10+sl-2)));break;}else if(m===0xFFD9)break;o+=2+sl;}return null;}
    function readIFD(dv){const g16=(o,le)=>dv.getUint16(o,le??true);const g32=(o,le)=>dv.getUint32(o,le??true);const gs=(o,l)=>{let s='';for(let i=0;i<l;i++){const c=dv.getUint8(o+i);if(!c)break;s+=String.fromCharCode(c);}return s;};function rIFD(off){try{const n=g16(off);let dt=null,sub=null;for(let i=0;i<n;i++){const eo=off+2+i*12,tag=g16(eo),ty=g16(eo+2),ct=g32(eo+4),vo=g32(eo+8);if(tag===0x9003&&ty===2){const s=gs(vo,ct);if(s)dt=s;}else if(tag===0x9004&&ty===2&&!dt){const s=gs(vo,ct);if(s)dt=s;}else if(tag===0x0132&&ty===2&&!dt){const s=gs(vo,ct);if(s)dt=s;}else if(tag===0x8769)sub=vo;}if(dt){const f=cvtDate(dt);if(f)return f;}if(sub&&sub>0&&sub<dv.byteLength){const sd=rIFD(sub);if(sd)return sd;}return null;}catch(e){return null;}}return rIFD(0);}
    function cvtDate(s){if(!s)return null;s=s.replace(/\0/g,'').trim();const m=s.match(/(\d{4}):(\d{2}):(\d{2})\s+\d{2}:\d{2}:\d{2}/);if(m)return`${m[1]}-${m[2]}-${m[3]}`;const m2=s.match(/(\d{4})-(\d{2})-(\d{2})/);return m2?`${m2[1]}-${m2[2]}-${m2[3]}`:null;}
    function fmtDate(ts){const d=new Date(ts);return`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;}
    function renderPreviews(){preview.innerHTML='';const g={};allFiles.forEach(f=>{if(!g[f.date])g[f.date]=[];g[f.date].push(f);});Object.keys(g).sort((a,b)=>b.localeCompare(a)).forEach(d=>{const gd=document.createElement('div');gd.className='preview-date-group';gd.innerHTML=`<div class="preview-date-header"><span>📅 ${d}</span><span class="count-badge">${g[d].length}</span></div>`;const fr=document.createElement('div');fr.className='preview-files-row';g[d].forEach(f=>fr.appendChild(makeThumb(f)));gd.appendChild(fr);preview.appendChild(gd);});}
    function makeThumb(fo){const el=document.createElement('div');el.className='preview-thumb';const url=URL.createObjectURL(fo.file);const del=document.createElement('button');del.className='remove-btn';del.textContent='✕';del.onclick=e=>{e.preventDefault();e.stopPropagation();removeFile(fo.id,el);};const tag=document.createElement('span');tag.className='type-tag';if(fo.file.type.startsWith('image/')){const img=document.createElement('img');img.src=url;el.appendChild(img);tag.textContent='📷';}else if(fo.file.type.startsWith('video/')){const v=document.createElement('video');v.src=url;v.muted=true;v.preload='metadata';el.appendChild(v);tag.textContent='🎥';v.addEventListener('loadeddata',()=>v.currentTime=1);}el.appendChild(del);el.appendChild(tag);return el;}
    function removeFile(fid,el){allFiles=allFiles.filter(f=>f.id!==fid);el.style.opacity='0';el.style.transform='scale(0.8)';setTimeout(()=>{renderPreviews();updateInput();},200);}
    function updateInput(){const dt=new DataTransfer();allFiles.forEach(f=>dt.items.add(f.file));input.files=dt.files;}
    // 伺服器限制（由 PHP 注入）
const SERVER_POST_MAX   = <?php echo th_return_bytes(ini_get('post_max_size')); ?>;
const SERVER_UPLOAD_MAX = <?php echo th_return_bytes(ini_get('upload_max_filesize')); ?>;
const SERVER_FILE_MAX   = <?php echo th_return_bytes(ini_get('upload_max_filesize')); ?>;
const EFFECTIVE_LIMIT   = Math.min(SERVER_POST_MAX, SERVER_UPLOAD_MAX);

// ===== 送出表單：改用 XHR 上傳 + 進度條 =====
let isSubmitting = false;

form.addEventListener('submit', function(e) {
    e.preventDefault();                 // ★ 一律攔截，改由 JS 送出
    if (isSubmitting) return false;     // ★ 防連點
    isSubmitting = true;

    const hasText = this.querySelector('textarea').value.trim().length > 0;
    if (!allFiles.length && !hasText) {
        alert('請選擇照片或寫一些文字');
        isSubmitting = false;
        return false;
    }

    // 逐檔大小檢查
    for (const f of allFiles) {
        if (f.file.size > SERVER_FILE_MAX) {
            alert(`檔案「${f.file.name}」大小 ${(f.file.size/1048576).toFixed(1)}MB，\n超過單檔上限 ${(SERVER_FILE_MAX/1048576).toFixed(1)}MB。\n請先壓縮或改用電腦上傳。`);
            isSubmitting = false;
            return false;
        }
    }

    // 總大小檢查
    const total = allFiles.reduce((s, f) => s + f.file.size, 0);
    if (total > EFFECTIVE_LIMIT * 0.95) {
        alert(
          `本次要上傳 ${allFiles.length} 個檔案，總大小 ${(total/1048576).toFixed(1)}MB，\n` +
          `超過伺服器允許的 ${(EFFECTIVE_LIMIT/1048576).toFixed(1)}MB。\n\n` +
          `建議：\n• 一次只上傳 1–2 個檔案\n• 或先壓縮影片再上傳`
        );
        isSubmitting = false;
        return false;
    }

    // 寫入 client_dates
    const cd = {};
    allFiles.forEach(f => cd[f.file.name] = f.date);
    datesInput.value = JSON.stringify(cd);

    // ===== 開始 AJAX 上傳 =====
    startAjaxUpload(this);
    return false;
});

function startAjaxUpload(form) {
    const overlay = document.getElementById('upload-progress-overlay');
    const card    = document.getElementById('upload-progress-card');
    const iconEl  = document.getElementById('upload-progress-icon');
    const titleEl = document.getElementById('upload-progress-title');
    const fillEl  = document.getElementById('upload-progress-fill');
    const textEl  = document.getElementById('upload-progress-text');
    const hintEl  = document.getElementById('upload-progress-hint');
    const submitBtn = form.querySelector('button[type="submit"]');

    // 復原狀態
    overlay.classList.add('active');
    card.classList.remove('failed');
    const oldClose = card.querySelector('.upload-progress-close');
    if (oldClose) oldClose.remove();
    fillEl.classList.remove('processing');
    fillEl.style.width = '0%';
    iconEl.textContent = '📤';
    titleEl.textContent = '上傳中…';
    textEl.textContent = '準備中…';
    hintEl.textContent = '請勿關閉或重新整理視窗';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '⏳ 發佈中…'; }

    const formData = new FormData(form);
    const xhr = new XMLHttpRequest();
    let uploadPhaseDone = false;

    // ── 上傳位元組階段（0–95%）
    xhr.upload.addEventListener('progress', function(ev) {
        if (!ev.lengthComputable) return;
        const rawPct = ev.loaded / ev.total;
        const pct = Math.min(95, rawPct * 95);
        fillEl.style.width = pct + '%';

        const loadedMB = (ev.loaded / 1048576).toFixed(1);
        const totalMB  = (ev.total  / 1048576).toFixed(1);
        textEl.textContent = `上傳中 ${pct.toFixed(0)}%  (${loadedMB} / ${totalMB} MB)`;
    });

    // ── 位元組送完 → 進入伺服器處理階段（不確定進度動畫）
    xhr.upload.addEventListener('load', function() {
        uploadPhaseDone = true;
        fillEl.classList.add('processing');
        fillEl.style.width = '100%';
        titleEl.textContent = '伺服器處理中…';
        textEl.textContent = '正在儲存檔案、生成縮圖';
        hintEl.textContent = '依檔案數量與大小，可能需要數秒至數十秒，請稍候';
    });

    // ── 收到回應
    xhr.addEventListener('load', function() {
        let resp = null;
        try { resp = JSON.parse(xhr.responseText); } catch (err) {}

        if (xhr.status === 200 && resp && resp.success) {
            fillEl.classList.remove('processing');
            fillEl.style.width = '100%';
            iconEl.textContent = '✅';
            titleEl.textContent = '發佈成功！';
            textEl.textContent = resp.warning ? '（部分檔案有警告）' : '正在跳轉…';
            hintEl.textContent = resp.warning || '';
            setTimeout(() => {
                window.location.href = resp.redirect || 'aboutyou.php';
            }, 600);
            return;
        }

        showUploadError(
            (resp && resp.error) ? resp.error : `伺服器回應異常（HTTP ${xhr.status}）`
        );
    });

    // ── 網路層錯誤
    xhr.addEventListener('error', function() {
        showUploadError('網路連線中斷，請檢查網路後重試。');
    });
    xhr.addEventListener('timeout', function() {
        showUploadError('上傳逾時，請減少檔案數量或壓縮後重試。');
    });
    xhr.addEventListener('abort', function() {
        showUploadError('上傳已取消。');
    });

    function showUploadError(msg) {
        card.classList.add('failed');
        fillEl.classList.remove('processing');
        fillEl.style.width = '100%';
        iconEl.textContent = '⚠️';
        titleEl.textContent = '上傳失敗';
        textEl.textContent = '';
        hintEl.textContent = msg;

        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '📤 發佈'; }
        isSubmitting = false;

        if (!card.querySelector('.upload-progress-close')) {
            const btn = document.createElement('button');
            btn.className = 'upload-progress-close';
            btn.textContent = '關閉';
            btn.onclick = () => {
                overlay.classList.remove('active');
                card.classList.remove('failed');
            };
            card.appendChild(btn);
        }
    }

    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    // 若上傳量很大可設 timeout（ms）：例如 10 分鐘
    xhr.timeout = 600000;
    xhr.send(formData);
}
});

// ===== Emoji 功能（原有） =====
function toggleEmojiPickerForComment(btn, inputId) {
    const picker = document.getElementById('picker-' + inputId);
    if (!picker) return;
    if (picker.style.display === 'none' || !picker.style.display) {
        const emojiList = ['👍', '❤️', '😆', '😂', '😮', '😢', '😡', '🎉', '🔥', '✨', '👏', '🙏', '💯', '😎', '👀', '🤔'];
        picker.innerHTML = emojiList.map(emoji => `<span class="emoji-item" onclick="appendEmojiToComment('${inputId}', '${emoji}')">${emoji}</span>`).join('');
        picker.style.display = 'flex';
        const rect = btn.getBoundingClientRect();
        picker.style.bottom = (rect.height + 5) + 'px';
        picker.style.right = '0';
        document.querySelectorAll('.custom-emoji-picker').forEach(p => {
            if (p.id !== 'picker-' + inputId) p.style.display = 'none';
        });
    } else {
        picker.style.display = 'none';
    }
}
function appendEmojiToComment(inputId, emoji) {
    const picker = document.getElementById('picker-' + inputId);
    if (!picker) return;
    const form = picker.closest('form');
    if (!form) return;
    const input = form.querySelector('input[name="comment_text"]');
    if (input) {
        input.value += emoji;
        input.focus();
    }
    picker.style.display = 'none';
}

// ===== 裝置指紋（原有） =====
(async function(){const isMobile=/Mobile|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);if(!isMobile)return;let dId=getCookie('th_device_id');if(!dId){dId=await genFp();setCookie('th_device_id',dId,365);}async function genFp(){const c=[navigator.userAgent,navigator.language,navigator.platform||'unknown',screen.width+'x'+screen.height,screen.colorDepth,Intl.DateTimeFormat().resolvedOptions().timeZone,navigator.hardwareConcurrency||'unknown',navigator.maxTouchPoints||0];const r=c.join('|');let h=0;for(let i=0;i<r.length;i++){h=((h<<5)-h)+r.charCodeAt(i);h|=0;}return'dev_'+Math.abs(h).toString(36);}function getCookie(n){const v=`; ${document.cookie}`;const p=v.split(`; ${n}=`);return p.length===2?p.pop().split(';').shift():null;}function setCookie(n,v,d){const dt=new Date();dt.setTime(dt.getTime()+(d*86400000));document.cookie=`${n}=${v};expires=${dt.toUTCString()};path=/;SameSite=Lax;Secure`;}})();

// ============================================================
// ★ 重寫：軌跡日曆功能（修復日曆不渲染、Lightbox衝突）
// ============================================================
(function() {
    let currentYear = null, currentMonth = null;
    let calendarData = {};
    let selectedDate = null;
    let isInitialLoad = true;
    const capsuleId = <?php echo $selected_capsule_id ?: 0; ?>;
    if (!capsuleId) return;

    // DOM 節點緩存
    const gridEl = document.getElementById('cal-grid');
    const titleEl = document.getElementById('cal-title');
    const prevBtn = document.getElementById('cal-prev');
    const nextBtn = document.getElementById('cal-next');
    const detailEl = document.getElementById('cal-day-detail');
    const dayTitleEl = document.getElementById('cal-day-title');
    const dayMemoriesEl = document.getElementById('cal-day-memories');
    const milestoneInput = document.getElementById('milestone-input');
    const milestoneSubmit = document.getElementById('milestone-submit');
    const milestoneMsg = document.getElementById('milestone-msg');

    // 分頁切換綁定
    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            sessionStorage.setItem('aboutyou_active_tab', targetTab);
            // 分頁切換激活狀態
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`tab-${targetTab}`).classList.add('active');

            // 切換到軌跡分頁才初始化日曆
            if (targetTab === 'trajectory') {
                // 分頁切換激活狀態
                const now = new Date();
                if (!currentYear) {
                    currentYear = now.getFullYear();
                    currentMonth = now.getMonth() + 1;
                    loadMonthData(currentYear, currentMonth);
                } else {
                    renderCalendar(currentYear, currentMonth);
                }
            }
if (targetTab === 'favorites') {
    loadFavorites();
}
        });
    });

    // 載入整月數據
    function loadMonthData(year, month) {
        gridEl.innerHTML = '<div class="cal-loading">載入中...</div>';
        fetch(`aboutyou.php?ajax_action=trajectory&sub_action=month_data&capsule_id=${capsuleId}&year=${year}&month=${month}`)
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    calendarData = res.data;
                    renderCalendar(year, month);
                } else {
                    gridEl.innerHTML = `<div class="cal-loading" style="color:red;">載入失敗：${res.error || ''}</div>`;
                }
            })
            .catch(err => {
                gridEl.innerHTML = `<div class="cal-loading" style="color:red;">網路錯誤</div>`;
                console.error('月曆載入錯誤：', err);
            });
    }

    // 渲染月曆主函數
    function renderCalendar(year, month) {
        currentYear = year;
        currentMonth = month;
        titleEl.textContent = `${year}年 ${month}月`;
        gridEl.innerHTML = '';

        // 星期標題行：空週數格 + 一二三四五六日
        const weekEmptyLabel = document.createElement('div');
        weekEmptyLabel.className = 'cal-week-label';
        gridEl.appendChild(weekEmptyLabel);
        const weekText = ['一','二','三','四','五','六','日'];
        weekText.forEach(w => {
            const wDiv = document.createElement('div');
            wDiv.className = 'cal-day-header';
            wDiv.innerText = w;
            gridEl.appendChild(wDiv);
        });

        // 月份基礎數據
        const firstDate = new Date(year, month - 1, 1);
        const lastDate = new Date(year, month, 0);
        const totalDays = lastDate.getDate();
        let firstDayIndex = firstDate.getDay(); // 0=周日
        firstDayIndex = firstDayIndex === 0 ? 6 : firstDayIndex - 1; // 轉為周一為0

        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;

        const firstWeekNum = getISOWeek(firstDate);
        const firstWeekLabel = document.createElement('div');
        firstWeekLabel.className = 'cal-week-label';
        firstWeekLabel.innerText = `W${firstWeekNum}`;
        gridEl.appendChild(firstWeekLabel);

        // 填充月初空白格 (因為已經有週數佔據了第1欄，空白格會乖乖排在星期欄位)
        for (let i = 0; i < firstDayIndex; i++) {
            const empty = document.createElement('div');
            empty.className = 'cal-day empty';
            gridEl.appendChild(empty);
        }

        // 逐日渲染
        for (let d = 1; d <= totalDays; d++) {
            const dateObj = new Date(year, month - 1, d);
            const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`;

            // 💡 修正 2：只有在星期一「且不是該月的第一天」時，才需要產生新的週數標籤
            if (dateObj.getDay() === 1 && d !== 1) {
                const weekNum = getISOWeek(dateObj);
                const weekLabel = document.createElement('div');
                weekLabel.className = 'cal-week-label';
                weekLabel.innerText = `W${weekNum}`;
                gridEl.appendChild(weekLabel);
            }

            // 日期格子DOM
            const dayBox = document.createElement('div');
            dayBox.className = 'cal-day';
            if (dateStr === todayStr) dayBox.classList.add('today');
            dayBox.dataset.date = dateStr;

            const dayNum = document.createElement('span');
            dayNum.className = 'day-number';
            dayNum.innerText = d;
            dayBox.appendChild(dayNum);

            // 有回憶則顯示縮圖與數量
            if (calendarData[dateStr]) {
                const data = calendarData[dateStr];
                if (data) {
                    // ★ 标记是否有里程碑
                    if (data.has_milestone) {
                        dayBox.classList.add('has-milestone');
                    }
                    // 如果有回忆（count > 0）
                    if (data.count && data.count > 0) {
                        dayBox.classList.add('has-memory');
                        if (data.first_media) {
 			   const url = data.first_media;
			    const looksLikeImage = /\.(jpg|jpeg|png|webp|gif|heic|bmp)$/i.test(url);

			    if (looksLikeImage) {
			        // ★ 照片、或影片的 ffmpeg 縮圖，都用 <img>
			        const img = new Image();
			        img.className = 'day-thumb';
			        img.src = url;
			        dayBox.appendChild(img);
			    } else {
			        // 沒有縮圖的影片 → fallback 用 <video>（iOS 可能仍黑，但至少 Android 正常）
			        const video = document.createElement('video');
			        video.className = 'day-thumb';
			        video.src = url;
			        video.muted = true;
			        video.playsInline = true;
			        video.setAttribute('webkit-playsinline', '');
			        video.preload = 'auto';
			        video.addEventListener('loadeddata', () => { try { video.currentTime = 0.5; } catch(e){} });
			        dayBox.appendChild(video);
			    }
			}
                    }
                }
                const countBadge = document.createElement('span');
                countBadge.className = 'day-count';
                countBadge.innerText = data.count;
                dayBox.appendChild(countBadge);
            }

            // 點擊日期載入單日內容
            dayBox.addEventListener('click', () => {
                document.querySelectorAll('.cal-day').forEach(el => el.classList.remove('selected'));
                dayBox.classList.add('selected');
                loadDayDetail(dateStr);
            });
            gridEl.appendChild(dayBox);
        }

        // 月末剩餘空白填充
        const lastDayIdx = lastDate.getDay() === 0 ? 6 : lastDate.getDay() - 1;
        for (let i = lastDayIdx + 1; i < 7; i++) {
            const empty = document.createElement('div');
            empty.className = 'cal-day empty';
            gridEl.appendChild(empty);
        }

        // 首次載入自動選取今日
        if (isInitialLoad) {
            isInitialLoad = false;
            const targetMonth = `${year}-${String(month).padStart(2,'0')}`;
            if (todayStr.startsWith(targetMonth)) {
                const targetDayEl = document.querySelector(`.cal-day[data-date="${todayStr}"]`);
                if (targetDayEl) {
                    targetDayEl.classList.add('selected');
                    loadDayDetail(todayStr);
                }
            } else {
                detailEl.style.display = 'none';
            }
        } else {
            detailEl.style.display = 'none';
        }
    }

    // === loadDayDetail 函数（精简防御版） ===
    function loadDayDetail(date) {
        selectedDate = date;
        sessionStorage.setItem('aboutyou_selected_date', date);
        detailEl.style.display = 'block';
        dayTitleEl.innerText = `📅 ${date} 的回憶`;
        dayMemoriesEl.innerHTML = '<div class="cal-loading">載入中...</div>';

        fetch(`aboutyou.php?ajax_action=trajectory&sub_action=day_memories&capsule_id=${capsuleId}&date=${date}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success) {
                    dayMemoriesEl.innerHTML = '<div style="padding:20px;color:#c0392b;">載入失敗：' + (res.error || '') + '</div>';
                    return;
                }
                let html = '';

                // --- 回忆部分 ---
                if (res.memories && res.memories.length > 0) {
                    let mediaHtml = '<div class="media-grid">';
                    let textHtml = '<div class="text-block">';
                    let hasMedia = false, hasText = false;
                    window.currentDayMedia = [];

                    res.memories.forEach((m) => {
                        if (m.media_url) {
                            hasMedia = true;
                            const isVideo = m.type === 'video' || /\.(mp4|webm|mov|avi)$/i.test(m.media_url);
                            let innerHtml;
                            if (isVideo) {
                                if (m.thumbnail_url) {
                                    // ★ 用 ffmpeg 縮圖
                                    innerHtml = `<img src="${escapeHtml(m.thumbnail_url)}" alt="" loading="lazy"><span class="thumb-type-tag">🎥</span>`;
                                } else {
                                    innerHtml = `<video src="${escapeHtml(m.media_url)}" muted preload="metadata" playsinline webkit-playsinline></video><span class="thumb-type-tag">🎥</span>`;
                                }
                            } else {
                                innerHtml = `<img src="${escapeHtml(m.media_url)}" alt="" loading="lazy"><span class="thumb-type-tag">📷</span>`;
                            }
                            const mediaIdx = window.currentDayMedia.length;
                            mediaHtml += `<div class="media-thumb" onclick="openCalLightbox(${mediaIdx})">${innerHtml}</div>`;
                            window.currentDayMedia.push({
                                id: m.id, url: m.media_url, type: m.type, owner_id: m.user_id || 0, like_it: m.like_it ? 1 : 0 
                            });
                        }
                        if (m.content_text && m.content_text.trim() !== '') {
                            hasText = true;
                            textHtml += `<p style="margin:0 0 6px;word-break:break-word;">${nl2br(escapeHtml(m.content_text))}</p>`;
                        }
                    });
                    mediaHtml += '</div>';
                    textHtml += '</div>';

                    if (hasMedia) html += mediaHtml;
                    if (hasText) html += textHtml;
                } else {
                    html += '<div style="padding:20px;color:#999;text-align:center;">📭 該日期無回憶內容</div>';
                }

                // --- 里程碑部分 ---
                if (res.milestones && res.milestones.length > 0) {
                    html += '<div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px;"><div style="font-weight:600;font-size:15px;color:var(--text);margin-bottom:8px;">🏆 里程碑</div>';
                    res.milestones.forEach(ms => {
                        const msId = ms.id;
                        const msValue = escapeHtml(ms.value || '');
                        const msNick = escapeHtml(ms.nickname || '');
                        const msIcon = escapeHtml(ms.icon_url || 'images/default_avatar.png');
                        html += `
                        <div class="milestone-item" data-id="${msId}" style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fefdfa;border:1px solid #f0e8d8;border-radius:8px;margin-top:8px;">
                            <span style="font-size:18px;">🏆</span>
                            <div class="milestone-value" style="flex:1;font-size:14px;color:var(--text);">${msValue}</div>
                            <div style="font-size:11px;color:var(--text-light);display:flex;align-items:center;gap:4px;flex-shrink:0;">
                                <img src="${msIcon}" style="width:16px;height:16px;border-radius:50%;object-fit:cover;">
                                ${msNick}
                            </div>
                            <button class="btn btn-outline btn-xs edit-milestone-btn" data-ms-id="${msId}">✏️ Edit</button>
                        </div>`;
                    });
                    html += '</div>';
                }

                dayMemoriesEl.innerHTML = html;
                // 处理快速添加区域的 label 显示（避免重复标题）
                const quickAdd = document.querySelector('.milestone-quick-add');
                if (quickAdd) {
                    const label = quickAdd.querySelector('label');
                    if (label) {
                        if (res.milestones && res.milestones.length > 0) {
                            label.style.display = 'none';   // 已有里程碑时隐藏静态标题
                        } else {
                            label.style.display = '';       // 无里程碑时显示标题（恢复默认）
                        }
                    }
                }
                // 移除旧的里程碑容器（如果有）
                const oldMs = document.getElementById('cal-day-milestones');
                if (oldMs) oldMs.remove();
                
            })
            .catch(err => {
                console.error('loadDayDetail error:', err);
                dayMemoriesEl.innerHTML = '<div style="padding:20px;color:#c0392b;">載入失敗2，請重新點擊日期</div>';
            });
    }

function editMilestone(id, btn) {
                    const item = btn.closest('.milestone-item');
                    if (!item) return;
                    const valueDiv = item.querySelector('.milestone-value');
                    const currentText = valueDiv.innerText.trim();
                    // 替换为输入框
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.value = currentText;
                    input.className = 'milestone-edit-input';
                    input.style.width = '100%';
                    input.style.padding = '4px 8px';
                    input.style.border = '1.5px solid var(--primary)';
                    input.style.borderRadius = '6px';
                    input.style.fontSize = '14px';
                    // 替换内容
                    valueDiv.innerHTML = '';
                    valueDiv.appendChild(input);
                    // 隐藏原按钮，显示保存/取消
                    btn.style.display = 'none';
                    const saveBtn = document.createElement('button');
                    saveBtn.className = 'btn btn-primary btn-xs';
                    saveBtn.innerText = '儲存';
                    saveBtn.onclick = function(e) {
                        e.stopPropagation();
                        const newVal = input.value.trim();
                        if (newVal === '') {
                            alert('內容不可為空');
                            return;
                        }
                        saveMilestoneEdit(id, newVal, item);
                    };
                    const cancelBtn = document.createElement('button');
                    cancelBtn.className = 'btn btn-outline btn-xs';
                    cancelBtn.innerText = '取消';
                    cancelBtn.onclick = function(e) {
                        e.stopPropagation();
                        // 恢复显示
                        valueDiv.innerText = currentText;
                        btn.style.display = 'inline-block';
                        saveBtn.remove();
                        cancelBtn.remove();
                    };
                    // 插入按钮组
                    const btnGroup = document.createElement('span');
                    btnGroup.style.display = 'flex';
                    btnGroup.style.gap = '4px';
                    btnGroup.appendChild(saveBtn);
                    btnGroup.appendChild(cancelBtn);
                    item.appendChild(btnGroup);
                    // 自动聚焦
                    input.focus();
                    input.select();
                }

                // 保存里程碑编辑（AJAX）
                function saveMilestoneEdit(id, newValue, container) {
                    const msgEl = document.getElementById('milestone-msg');
                    if (msgEl) msgEl.innerText = '儲存中...';
                    fetch('aboutyou_api.php?action=update_milestone', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            milestone_id: id,
                            value: newValue
                        })
                    })
                    .then(res => res.json())
                    .then(ret => {
                        if (ret.success) {
                            if (msgEl) msgEl.innerText = '✅ 更新成功';
                            // 刷新当前日期的详情
                            if (selectedDate) loadDayDetail(selectedDate);
                        } else {
                            if (msgEl) msgEl.innerText = '❌ 更新失敗：' + (ret.error || '未知錯誤');
                        }
                    })
                    .catch(err => {
                        if (msgEl) msgEl.innerText = '❌ 網路請求異常';
                        console.error(err);
                    });
                }
            // 事件委托：监听所有点击，若点击的元素是 .edit-milestone-btn 则触发编辑
                document.addEventListener('click', function(e) {
                    const btn = e.target.closest('.edit-milestone-btn');
                    if (btn) {
                        const msId = btn.dataset.msId;
                        // 调用闭包内的 editMilestone 函数
                        editMilestone(msId, btn);
                    }
                });
    // 进入行内编辑模式
    
    window.openCalLightbox = function(idx) {
        if (!window.currentDayMedia || window.currentDayMedia.length === 0) return;
        lb.hash = 'cal_day'; 
        lb.items = [...window.currentDayMedia];
        lb.idx = idx;
        lb.deleted = false;
        document.getElementById('lightbox').classList.add('active');
        document.body.style.overflow = 'hidden';
        renderLb();
    };

    // 月份切換按鈕
    prevBtn.addEventListener('click', () => {
        if (currentMonth === 1) {
            currentYear--;
            currentMonth = 12;
        } else {
            currentMonth--;
        }
        loadMonthData(currentYear, currentMonth);
    });
    nextBtn.addEventListener('click', () => {
        if (currentMonth === 12) {
            currentYear++;
            currentMonth = 1;
        } else {
            currentMonth++;
        }
        loadMonthData(currentYear, currentMonth);
    });

    // Milestone 提交
    milestoneSubmit.addEventListener('click', () => {
        const text = milestoneInput.value.trim();
        milestoneMsg.innerText = '';
        if (!text) {
            milestoneMsg.innerText = '⚠️ 請輸入里程碑內容';
            return;
        }
        if (!selectedDate) {
            milestoneMsg.innerText = '⚠️ 請先點擊日曆任意日期';
            return;
        }
        let params = {
            milestone_type: 'custom',
            value: text,
            notes: '',
            milestone_date: `${selectedDate} 00:00:00`,
            capsule_id: capsuleId
        };
        // 附加當日第一筆回憶的 ID 給 API
        if (currentDayFirstMemoryId) {
            params.memory_id = currentDayFirstMemoryId;
        }

        // 提交接口
        fetch('aboutyou_api.php?action=create_milestone', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams(params)
        })
        .then(res => res.json())
        .then(ret => {
            if (ret.success) {
                milestoneMsg.innerText = '✅ 里程碑儲存成功';
                milestoneInput.value = '';
                setTimeout(() => location.reload(), 800);
            } else {
                milestoneMsg.innerText = `❌ 失敗：${ret.error || '未知錯誤'}`;
            }
        })
        .catch(() => milestoneMsg.innerText = '❌ 網路請求異常');
    });

    // 工具函數
    function getISOWeek(date) {
        const d = new Date(date);
        d.setHours(0,0,0,0);
        d.setDate(d.getDate() + 3 - (d.getDay() + 6) % 7);
        const weekStart = new Date(d.getFullYear(), 0, 4);
        return 1 + Math.round(((d - weekStart) / 86400000 - 3 + (weekStart.getDay() + 6) % 7) / 7);
    }
    function escapeHtml(str) {
        if (!str) return '';
        return str.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
    }
    function nl2br(str) {
        if (!str) return '';
        return str.replaceAll('\n','<br>');
    }
    function getMemIcon(type) {
        const map = {photo:'📷',video:'🎥',note:'📝',milestone:'🏆'};
        return map[type] || '📌';
    }
})();

// 3. ✅ 新增：在網頁載入完成後，讀取狀態並自動切換
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = sessionStorage.getItem('aboutyou_active_tab');
        if (savedTab === 'trajectory') {
            // 自動切換到軌跡分頁
            const trajBtn = document.querySelector('.tab-btn[data-tab="trajectory"]');
            if (trajBtn) trajBtn.click();
            
            const savedDate = sessionStorage.getItem('aboutyou_selected_date');
            if (savedDate) {
                // 等待日曆 AJAX 渲染完成後，自動點擊該日期
                const observer = new MutationObserver((mutations, obs) => {
                    const dayEl = document.querySelector(`.cal-day[data-date="${savedDate}"]`);
                    if (dayEl) {
                        dayEl.click();
                        obs.disconnect(); // 點擊後停止觀察
                    }
                });
                observer.observe(document.getElementById('cal-grid'), { childList: true });
            }
        } else if (savedTab === 'favorites') {
    const favBtn = document.querySelector('.tab-btn[data-tab="favorites"]');
    if (favBtn) favBtn.click();
}
    });
    
// ============================================================
// ★ 重寫：無限滾動載入（防抖鎖死、禁止重複綁定、修復瘋狂請求Bug）
// ============================================================
<?php if ($selected_capsule_id && $total_days > $limit_days): ?>
(function() {
    const capsuleId = <?php echo $selected_capsule_id; ?>;
    const limit = <?php echo $limit_days; ?>;
    let offset = limit;
    let isLoading = false;
    let hasMore = <?php echo $total_days > $limit_days ? 'true' : 'false'; ?>;
    const loadingDom = document.getElementById('scroll-loading');
    const endDom = document.getElementById('scroll-end');
    const wrapperDom = document.getElementById('memories-wrapper');

    // 滾動防抖標記，避免連續觸發
    let scrollTimer = null;
    window.addEventListener('scroll', function() {
        if (!document.getElementById('tab-timeline').classList.contains('active')) return;
        if (scrollTimer) clearTimeout(scrollTimer);
        scrollTimer = setTimeout(() => {
            if (isLoading || !hasMore) return;
            const scrollBottom = window.innerHeight + window.scrollY;
            const pageBottom = document.body.offsetHeight - 300;
            if (scrollBottom >= pageBottom) loadMore();
        }, 120);
    });

        async function loadMore() {
        if (isLoading || !hasMore) return;
        isLoading = true;
        loadingDom.style.display = 'block';

        try {
            // ---------- 1) 發出請求 ----------
            const res = await fetch(
                `aboutyou.php?capsule_id=${capsuleId}&ajax_load=1&offset=${offset}&limit=${limit}`,
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );

            // ---------- 2) 先檢查 HTTP 狀態 ----------
            if (!res.ok) {
                console.error(`[scroll] HTTP ${res.status} ${res.statusText}`);
                hasMore = false;
                if (endDom) endDom.style.display = 'block';
                return;
            }

            // ---------- 3) 用 text() 拿原始內容，方便 debug ----------
            const raw = await res.text();

            // ---------- 4) 手動解析 JSON ----------
            let data;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                console.error('[scroll] 伺服器回傳非 JSON，前 500 字元如下：');
                console.error(raw.slice(0, 500));
                hasMore = false;   // 停止繼續請求，避免無限迴圈
                if (endDom) endDom.style.display = 'block';
                return;
            }

            // ---------- 5) 驗證回應結構 ----------
            if (!data || typeof data.html !== 'string') {
                console.error('[scroll] 回應格式錯誤，缺少 html 欄位：', data);
                hasMore = false;
                if (endDom) endDom.style.display = 'block';
                return;
            }

            // ---------- 6) 解析 HTML 並執行內嵌 script ----------
            const parser = new DOMParser();
            const doc = parser.parseFromString(data.html, 'text/html');

            // 執行所有 <script>（定義 window.mediaData_xxx 等）
            const scripts = doc.querySelectorAll('script');
            scripts.forEach(script => {
                if (script.textContent.trim()) {
                    try {
                        // 用 Function 比 eval 稍微安全一點，並且不會被 CSP 阻擋
                        // （若你的站台有嚴格 CSP，仍可能失敗，到時再處理）
                        (new Function(script.textContent))();
                    } catch (e) {
                        console.error('[scroll] 執行內嵌 script 失敗:', e, script.textContent.slice(0, 200));
                    }
                }
            });

            // ---------- 7) 把 HTML 插入頁面（不包含 <script>） ----------
            // 先把 script 從 doc 中移除，再取 body.innerHTML，
            // 避免瀏覽器插入後又執行一次造成重複定義
            scripts.forEach(s => s.remove());
            const bodyContent = doc.body.innerHTML;
            wrapperDom.insertAdjacentHTML('beforeend', bodyContent);

            // ---------- 8) 更新狀態 ----------
            offset += limit;
            hasMore = !!data.has_more;

            if (!hasMore) {
                if (endDom) endDom.style.display = 'block';
            }

        } catch (err) {
            // 網路層錯誤（斷線、CORS、DNS 等）
            console.error('[scroll] 網路或解析異常:', err);
            // 不把 hasMore 設為 false，允許使用者之後再捲動重試
            // 但如果連續失敗多次，可以考慮加個失敗計數器
        } finally {
            loadingDom.style.display = 'none';
            isLoading = false;
        }
    }
})();
<?php endif; ?>
</script>
</body>
</html>
