<?php
// register_device.php - Register a device for auto-login
session_start();
$link = require_once "config.php";
mysqli_set_charset($link, "utf8mb4");

// Must be logged in to register a device
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];
$username = $_SESSION["username"];
$success_msg = "";
$error_msg = "";

// Get user's registered devices
$devices = [];
$dev_sql = "SELECT id, device_id, device_name, device_type, last_login, created_at, is_active 
            FROM tbl_device_auth WHERE user_id = ? ORDER BY last_login DESC";
if ($dev_stmt = mysqli_prepare($link, $dev_sql)) {
    mysqli_stmt_bind_param($dev_stmt, "i", $user_id);
    mysqli_stmt_execute($dev_stmt);
    $dev_res = mysqli_stmt_get_result($dev_stmt);
    while ($row = mysqli_fetch_assoc($dev_res)) {
        $devices[] = $row;
    }
    mysqli_stmt_close($dev_stmt);
}

// Handle device actions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Register new device
    if (isset($_POST["action"]) && $_POST["action"] == "register") {
        $device_id = isset($_POST["device_id"]) ? trim($_POST["device_id"]) : "";
        $device_name = isset($_POST["device_name"]) ? trim($_POST["device_name"]) : "";
        $device_type = isset($_POST["device_type"]) ? trim($_POST["device_type"]) : "mobile";
        
        if (empty($device_id)) {
            $error_msg = "無法獲取裝置識別碼，請重試。";
        } else {
            // Check if device already registered
            $check_sql = "SELECT id, user_id FROM tbl_device_auth WHERE device_id = ?";
            if ($check_stmt = mysqli_prepare($link, $check_sql)) {
                mysqli_stmt_bind_param($check_stmt, "s", $device_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    mysqli_stmt_bind_result($check_stmt, $existing_id, $existing_user_id);
                    mysqli_stmt_fetch($check_stmt);
                    if ($existing_user_id == $user_id) {
                        $error_msg = "此裝置已經註冊過了。";
                    } else {
                        $error_msg = "此裝置已綁定其他帳號。";
                    }
                } else {
                    // Register device
                    $ins_sql = "INSERT INTO tbl_device_auth (user_id, device_id, device_name, device_type) VALUES (?, ?, ?, ?)";
                    if ($ins_stmt = mysqli_prepare($link, $ins_sql)) {
                        mysqli_stmt_bind_param($ins_stmt, "isss", $user_id, $device_id, $device_name, $device_type);
                        if (mysqli_stmt_execute($ins_stmt)) {
			    setcookie('th_device_id', $device_id, time() + 86400 * 365, '/', '', isset($_SERVER['HTTPS']), true);
                            $success_msg = "✓ 裝置註冊成功！下次可以直接登入。";
                            // 重新導向至 aboutyou.php，並保留當前膠囊 ID（若有）
                            $redirect_url = "aboutyou.php";
                            if (!empty($_GET['capsule_id'])) {
                                 $redirect_url .= "?capsule_id=" . intval($_GET['capsule_id']);
                            }
                            header("Location: " . $redirect_url);
                            exit;
                            // Refresh device list
                            //header("Refresh:0");
                        } else {
                            $error_msg = "註冊失敗，請重試。";
                        }
                        mysqli_stmt_close($ins_stmt);
                    }
                }
                mysqli_stmt_close($check_stmt);
            }
        }
    }
    
    // Remove device registration
    if (isset($_POST["action"]) && $_POST["action"] == "remove") {
        $device_id = isset($_POST["device_id"]) ? trim($_POST["device_id"]) : "";
        if (!empty($device_id)) {
            $del_sql = "DELETE FROM tbl_device_auth WHERE device_id = ? AND user_id = ?";
            if ($del_stmt = mysqli_prepare($link, $del_sql)) {
                mysqli_stmt_bind_param($del_stmt, "si", $device_id, $user_id);
                if (mysqli_stmt_execute($del_stmt)) {
                    $success_msg = "裝置已移除。";
                    header("Location: register_device.php");
                    exit;
                }
                mysqli_stmt_close($del_stmt);
            }
        }
    }
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>裝置管理 - AboutYou</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Noto Sans TC', sans-serif;
            background: #fdfaf5;
            color: #4a3728;
            margin: 0;
            padding: 16px;
            font-size: 16px;
            line-height: 1.6;
        }
        .container { max-width: 500px; margin: 0 auto; }
        h1 { font-size: 22px; color: #b8956a; margin-bottom: 20px; text-align: center; }
        .card {
            background: #fffdf8;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #f0e8d8;
            box-shadow: 0 2px 8px rgba(120,90,60,0.06);
        }
        .card h2 { font-size: 17px; margin: 0 0 14px; color: #6b5540; }
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 14px;
            font-size: 14px;
        }
        .alert-success { background: #f0f8f0; color: #3a7a3a; border: 1px solid #d0e8d0; }
        .alert-error { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }
        .device-list { list-style: none; padding: 0; margin: 0; }
        .device-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px; border: 1px solid #f0e8d8; border-radius: 10px;
            margin-bottom: 8px; gap: 10px;
        }
        .device-icon { font-size: 28px; flex-shrink: 0; }
        .device-info { flex: 1; min-width: 0; }
        .device-name { font-weight: 500; font-size: 14px; }
        .device-meta { font-size: 11px; color: #9b8a78; }
        .device-status { font-size: 11px; }
        .device-status.active { color: #7a9a5c; }
        .device-status.inactive { color: #c9958b; }
        .btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 8px 14px; border-radius: 18px; font-size: 13px;
            font-weight: 500; cursor: pointer; border: 1.5px solid transparent;
            text-decoration: none; font-family: inherit; white-space: nowrap;
            min-height: 36px;
        }
        .btn-primary { background: #b8956a; color: #fff; border-color: #b8956a; }
        .btn-outline { background: #fff; color: #6b5540; border-color: #e5d9c8; }
        .btn-danger { background: transparent; color: #c9958b; border-color: #e5d0d0; }
        .btn-danger:hover { background: #fdf5f3; }
        .btn-sm { padding: 5px 10px; font-size: 12px; min-height: 28px; }
        .register-section { text-align: center; }
        .device-id-display {
            background: #f5f0e8; padding: 10px; border-radius: 8px;
            font-family: monospace; font-size: 12px; word-break: break-all;
            margin: 10px 0; color: #6b5540;
        }
        .back-link { display: block; text-align: center; margin-top: 16px; color: #b8956a; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📱 裝置管理</h1>
    
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>
    
    <!-- Register Current Device -->
    <div class="card register-section">
        <h2>📲 註冊此裝置</h2>
        <p style="font-size:14px;color:#9b8a78;">註冊後，下次使用此裝置可直接登入，無需輸入密碼。</p>
        
        <div class="device-id-display" id="device-id-display">正在獲取裝置識別碼...</div>
        
        <form method="POST" action="register_device.php" id="register-form">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="device_id" id="device-id-input">
            <input type="hidden" name="device_type" id="device-type-input">
            <input type="hidden" name="device_name" id="device-name-input">
            <button type="submit" class="btn btn-primary" id="register-btn" disabled>🔒 註冊此裝置</button>
        </form>
    </div>
    
    <!-- Registered Devices -->
    <?php if (count($devices) > 0): ?>
    <div class="card">
        <h2>🔑 已註冊裝置</h2>
        <ul class="device-list">
            <?php foreach ($devices as $device): ?>
                <li class="device-item">
                    <span class="device-icon">
                        <?php 
                        $type = $device['device_type'] ?? 'mobile';
                        if ($type == 'tablet') echo '📱';
                        elseif ($type == 'desktop') echo '💻';
                        else echo '📱';
                        ?>
                    </span>
                    <div class="device-info">
                        <div class="device-name">
                            <?php echo htmlspecialchars($device['device_name'] ?: '未命名裝置'); ?>
                        </div>
                        <div class="device-meta">
                            最後登入: <?php echo date('Y-m-d H:i', strtotime($device['last_login'])); ?>
                        </div>
                    </div>
                    <span class="device-status <?php echo $device['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $device['is_active'] ? '● 啟用' : '○ 停用'; ?>
                    </span>
                    <form method="POST" action="register_device.php" style="margin:0;" onsubmit="return confirm('確定要移除此裝置嗎？');">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="device_id" value="<?php echo htmlspecialchars($device['device_id']); ?>">
                        <button type="submit" class="btn btn-danger btn-sm">移除</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <a href="aboutyou.php" class="back-link">← 返回 AboutYou</a>
</div>

<script>
// ★★★ FIXED: Generate STABLE device fingerprint (no timestamp) ★★★
// ★★★ 增強版：加入 localStorage 隨機鹽值，徹底避免跨設備碰撞 ★★★
async function generateDeviceId() {
    // ----- 1. 取得或建立唯一的 local salt -----
    const SALT_KEY = 'th_device_salt';
    let salt = localStorage.getItem(SALT_KEY);
    if (!salt) {
        salt = 's_' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36);
        localStorage.setItem(SALT_KEY, salt);
    }

    // ----- 2. 嘗試取得高熵值（強化區分） -----
    let highEntropy = {};
    let model = 'unknown';
    let platformVersion = 'unknown';
    let architecture = 'unknown';
    if (navigator.userAgentData && navigator.userAgentData.getHighEntropyValues) {
        try {
            highEntropy = await navigator.userAgentData.getHighEntropyValues([
                'platformVersion',
                'architecture',
                'model'
            ]);
            model = highEntropy.model || 'unknown';
            platformVersion = highEntropy.platformVersion || 'unknown';
            architecture = highEntropy.architecture || 'unknown';
        } catch (e) {
            // 使用者拒絕或 API 不可用，保留 'unknown'
        }
    }

    // ----- 3. 若 model 仍為 unknown，嘗試從 userAgent 解析系統版本（作為輔助）-----
    if (model === 'unknown') {
        const match = navigator.userAgent.match(/CPU iPhone OS (\d+)_(\d+)/);
        if (match) {
            platformVersion = match[1] + '_' + match[2]; // 取完整版本
        }
        // 若無法取得型號，就保留 unknown，但我們已有 salt 區分
    }

    // ----- 4. 收集其他穩定特徵 -----
    let brand = 'unknown';
    let platform = 'unknown';
    let mobile = false;
    if (navigator.userAgentData) {
        const uad = navigator.userAgentData;
        brand = uad.brands.map(b => b.brand).join('|') || 'unknown';
        platform = uad.platform || 'unknown';
        mobile = uad.mobile || false;
    } else {
        // fallback: 從傳統 UA 解析
        const ua = navigator.userAgent;
        if (/iPhone|iPad/.test(ua)) platform = 'iOS';
        else if (/Android/.test(ua)) platform = 'Android';
        else if (/Windows/.test(ua)) platform = 'Windows';
        else if (/Macintosh/.test(ua)) platform = 'macOS';
        else if (/Linux/.test(ua)) platform = 'Linux';
        mobile = /Mobile|Android|iPhone|iPad|iPod/.test(ua);
        // 簡易品牌
        if (/Chrome/.test(ua)) brand = 'Chrome';
        else if (/Firefox/.test(ua)) brand = 'Firefox';
        else if (/Safari/.test(ua) && !/Chrome/.test(ua)) brand = 'Safari';
        else if (/Edge/.test(ua)) brand = 'Edge';
    }

    const language = navigator.language || 'unknown';
    const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'unknown';
    const screenRes = screen.width + 'x' + screen.height;
    const colorDepth = screen.colorDepth || 'unknown';
    const maxTouch = navigator.maxTouchPoints || 0;
    const hardwareConcurrency = navigator.hardwareConcurrency || 'unknown';

    // ----- 5. 組合指紋（加入 salt 保證唯一性）-----
    const fingerprintParts = [
        brand,
        platform,
        mobile ? 'mobile' : 'desktop',
        platformVersion,      // 完整版本，不截斷
        architecture,
        model,
        language,
        timeZone,
        screenRes,
        colorDepth,
        maxTouch,
        hardwareConcurrency,
        salt                    // ★ 關鍵：確保不同設備即使其他特徵相同，也能區分
    ];
    const fingerprint = fingerprintParts.join('|');

    // ----- 6. 產生雜湊（與原邏輯相同，不含時間戳）-----
    let hash = 0;
    for (let i = 0; i < fingerprint.length; i++) {
        const char = fingerprint.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash;
    }
    const deviceId = 'dev_' + Math.abs(hash).toString(36);

    // ----- 7. 回傳裝置資訊 -----
    return {
        deviceId: deviceId,
        deviceName: getDeviceName(platform, mobile),
        deviceType: getDeviceType(mobile, platform)
    };
}


function getDeviceType() {
    const ua = navigator.userAgent;
    if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) return 'tablet';
    if (/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(ua)) return 'mobile';
    return 'desktop';
}

function getDeviceName() {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua)) return 'iPad';
    if (/Android/.test(ua)) return 'Android 手機';
    if (/Macintosh/.test(ua)) return 'Mac 電腦';
    if (/Windows/.test(ua)) return 'Windows 電腦';
    if (/Linux/.test(ua)) return 'Linux 電腦';
    return '未知裝置';
}

// Initialize
(async function() {
    const device = await generateDeviceId();
    
    document.getElementById('device-id-display').textContent = device.deviceId;
    document.getElementById('device-id-input').value = device.deviceId;
    document.getElementById('device-type-input').value = device.deviceType;
    document.getElementById('device-name-input').value = device.deviceName;
    document.getElementById('register-btn').disabled = false;
})();
</script>
</body>
</html>
