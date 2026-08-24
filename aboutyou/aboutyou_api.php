<?php
/**
 * aboutyou API Handler
 * Handles all API requests for time capsules, memories, and milestones
 */

header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");

ini_set("session.cookie_secure", 1);
ini_set("session.cookie_httponly", 1);
ini_set("session.use_only_cookies", 1);

session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$link = require_once "config.php";
require_once "aboutyou_helpers.php"; 

$method = $_SERVER["REQUEST_METHOD"];
$action = isset($_GET["action"]) ? trim($_GET["action"]) : "";
$user_id = $_SESSION["id"];

$response = ["success" => false, "message" => "", "data" => null];

try {
    // ===== TIME CAPSULE ENDPOINTS =====
    
    if ($action === "create_capsule" && $method === "POST") {
        $title = isset($_POST["title"]) ? trim($_POST["title"]) : "";
        $description = isset($_POST["description"]) ? trim($_POST["description"]) : "";
        $delivery_date = date("Y-m-d H:i:s", strtotime("+10 years")); 
        
        $profile_image_url = null;
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

        if (empty($title)) {
            throw new Exception("Title is required");
        }
        
        $sql = "INSERT INTO tbl_time_capsules (user_id, title, description, delivery_date, profile_image_url, status) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "isssss", $user_id, $title, $description, $delivery_date, $profile_image_url, $status = 'pending');
            if (mysqli_stmt_execute($stmt)) {
                $capsule_id = mysqli_insert_id($link);
                $response["success"] = true;
                $response["message"] = "Time capsule created successfully";
                $response["data"] = ["capsule_id" => $capsule_id];
            } else {
                throw new Exception("Failed to create capsule");
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    elseif ($action === "get_capsules" && $method === "GET") {
        $sql = "SELECT id, title, description, profile_image_url, delivery_date, created_at, status 
                FROM tbl_time_capsules 
                WHERE user_id = ? 
                ORDER BY created_at DESC";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $capsules = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $capsules[] = $row;
            }
            
            $response["success"] = true;
            $response["data"] = $capsules;
            mysqli_stmt_close($stmt);
        }
    }
    
    elseif ($action === "get_capsule" && $method === "GET") {
        $capsule_id = isset($_GET["capsule_id"]) ? intval($_GET["capsule_id"]) : 0;
        
        if ($capsule_id <= 0) {
            throw new Exception("Invalid capsule ID");
        }
        
        $sql = "SELECT id, title, description, profile_image_url, delivery_date, created_at, status 
                FROM tbl_time_capsules 
                WHERE id = ? AND user_id = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $capsule_id, $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                $response["success"] = true;
                $response["data"] = $row;
            } else {
                throw new Exception("Capsule not found");
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    elseif ($action === "update_capsule" && $method === "POST") {
        $capsule_id = isset($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : 0;
        $title = isset($_POST["title"]) ? trim($_POST["title"]) : "";
        $description = isset($_POST["description"]) ? trim($_POST["description"]) : "";
        $delivery_date = isset($_POST["delivery_date"]) ? trim($_POST["delivery_date"]) : null;
        
        $profile_image_url = null;
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
        } else if (isset($_POST["existing_profile_image_url"])) {
            $profile_image_url = $_POST["existing_profile_image_url"];
        }

        if ($capsule_id <= 0) {
            throw new Exception("Invalid capsule ID");
        }
        
        $verify_sql = "SELECT id FROM tbl_time_capsules WHERE id = ? AND user_id = ?";
        if ($stmt = mysqli_prepare($link, $verify_sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $capsule_id, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) === 0) {
                throw new Exception("Unauthorized: capsule not found");
            }
            mysqli_stmt_close($stmt);
        }
        
        $sql = "UPDATE tbl_time_capsules SET title = ?, description = ?, profile_image_url = ? ";
        $params = ["sss", $title, $description, $profile_image_url];

        if ($delivery_date) {
            $sql .= ", delivery_date = ? ";
            $params[0] .= "s";
            $params[] = $delivery_date;
        }
        $sql .= "WHERE id = ? AND user_id = ?";
        $params[0] .= "ii";
        $params[] = $capsule_id;
        $params[] = $user_id;

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, ...$params);
            if (mysqli_stmt_execute($stmt)) {
                $response["success"] = true;
                $response["message"] = "Capsule updated successfully";
            } else {
                throw new Exception("Failed to update capsule");
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    elseif ($action === "delete_capsule" && $method === "POST") {
        $capsule_id = isset($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : 0;
        
        if ($capsule_id <= 0) {
            throw new Exception("Invalid capsule ID");
        }
        
        $sql = "DELETE FROM tbl_time_capsules WHERE id = ? AND user_id = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $capsule_id, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $response["success"] = true;
                $response["message"] = "Capsule deleted successfully";
            } else {
                throw new Exception("Failed to delete capsule");
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // ===== MEMORY ENDPOINTS =====
    
    elseif ($action === "create_memory" && $method === "POST") {
        $type = isset($_POST["type"]) ? trim($_POST["type"]) : "";
        $content_text = isset($_POST["content_text"]) ? trim($_POST["content_text"]) : "";
        $capture_date = isset($_POST["capture_date"]) ? trim($_POST["capture_date"]) : ""; 
        $capsule_id = isset($_POST["capsule_id"]) && !empty($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : null;
        $visibility = isset($_POST["visibility"]) ? trim($_POST["visibility"]) : "private";
        
        if (empty($type) || empty($capture_date)) {
            throw new Exception("Type and capture date are required");
        }
        
        $media_url = null;
        $thumbnail_url = null;
        
        if (isset($_FILES["media"]) && $_FILES["media"]["error"] === UPLOAD_ERR_OK) {
            $upload_dir = "uploads/memories/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_ext = getFileExtension($_FILES["media"]["name"]);
            $file_name = uniqid("mem_") . "." . $file_ext;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES["media"]["tmp_name"], $file_path)) {
                $media_url = $file_path;
                
                // 自動類型修正
                if (isImageFile($file_name)) {
                    $type = "photo"; 
                    $thumb_name = uniqid("thumb_") . ".jpg";
                    $thumb_path = $upload_dir . $thumb_name;
                    if (createThumbnail($file_path, $thumb_path)) {
                        $thumbnail_url = $thumb_path;
                    }
                } elseif (isVideoFile($file_name)) {
                    $type = "video";
                }
            }
        }
        
        $sql = "INSERT INTO tbl_memories (user_id, capsule_id, type, content_text, media_url, thumbnail_url, capture_date, visibility) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "iissssss", $user_id, $capsule_id, $type, $content_text, $media_url, $thumbnail_url, $capture_date, $visibility);
            if (mysqli_stmt_execute($stmt)) {
                $memory_id = mysqli_insert_id($link);
                $response["success"] = true;
                $response["message"] = "Memory created successfully";
                $response["data"] = ["memory_id" => $memory_id];
            } else {
                throw new Exception("Failed to create memory");
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    elseif ($action === "get_memories" && $method === "GET") {
        $capsule_id = isset($_GET["capsule_id"]) && !empty($_GET["capsule_id"]) ? intval($_GET["capsule_id"]) : null;
        $offset = isset($_GET["offset"]) ? intval($_GET["offset"]) : 0;
        $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 50;
        
        $where_clause = "WHERE (m.user_id = ? OR FIND_IN_SET(?, s.target_user_ids) > 0) ";
        $params = ["i", $user_id];

        if ($capsule_id) {
            $where_clause .= "AND m.capsule_id = ? ";
            $params[0] .= "i";
            $params[] = $capsule_id;
        }

        $sql = "SELECT m.id, m.type, m.content_text, m.media_url, m.thumbnail_url, m.capture_date, m.created_at, m.visibility, u.nickname, u.icon_url
                FROM tbl_memories m
                JOIN tbl_user u ON m.user_id = u.id
                " . $where_clause . "
                ORDER BY m.capture_date DESC, m.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[0] .= "ii";
        $params[] = $limit;
        $params[] = $offset;

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, ...$params);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $memories = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $memories[] = $row;
            }
            
            $response["success"] = true;
            $response["data"] = $memories;
            mysqli_stmt_close($stmt);
        }
    }
    
    elseif ($action === "delete_memory" && $method === "POST") {
        $memory_id = isset($_POST["memory_id"]) ? intval($_POST["memory_id"]) : 0;
        
        if ($memory_id <= 0) {
            throw new Exception("Invalid memory ID");
        }
        
        $sql = "DELETE FROM tbl_memories WHERE id = ? AND user_id = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $memory_id, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $response["success"] = true;
                $response["message"] = "Memory deleted successfully";
            } else {
                throw new Exception("Failed to delete memory");
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // ===== MILESTONE ENDPOINTS =====
    
    elseif ($action === "create_milestone" && $method === "POST") {
        $milestone_type = isset($_POST["milestone_type"]) ? trim($_POST["milestone_type"]) : "";
        $value = isset($_POST["value"]) ? trim($_POST["value"]) : "";
        $notes = isset($_POST["notes"]) ? trim($_POST["notes"]) : "";
        $milestone_date = isset($_POST["milestone_date"]) ? trim($_POST["milestone_date"]) : "";
        $capsule_id = isset($_POST["capsule_id"]) ? intval($_POST["capsule_id"]) : null;
        if (empty($capsule_id)) {
            throw new Exception("Capsule ID is required");
        }
        if (empty($milestone_type) || empty($milestone_date)) {
            throw new Exception("Milestone type and date are required");
        }
        
        // 1. 檢查當天是否已有該使用者的里程碑記錄
        $check_sql = "SELECT id, value FROM tbl_milestones WHERE user_id = ? AND milestone_date = ?";
        if ($stmt = mysqli_prepare($link, $check_sql)) {
            mysqli_stmt_bind_param($stmt, "is", $user_id, $milestone_date);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $existing = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if ($existing) {
                // 2. 已存在記錄：將內容以 ';' 拼接累加並更新記錄
                $old_value = trim($existing["value"]);
                $new_value = ($old_value !== "") ? ($old_value . ";" . $value) : $value;

                $update_sql = "UPDATE tbl_milestones SET value = ? WHERE id = ?";
                if ($ustmt = mysqli_prepare($link, $update_sql)) {
                    mysqli_stmt_bind_param($ustmt, "si", $new_value, $existing["id"]);
                    if (mysqli_stmt_execute($ustmt)) {
                        $response["success"] = true;
                        $response["message"] = "Milestone updated (accumulated) successfully";
                        $response["data"] = ["milestone_id" => $existing["id"]];
                    } else {
                        throw new Exception("Failed to update milestone");
                    }
                    mysqli_stmt_close($ustmt);
                }
            } else {
                // 3. 不存在記錄：全新新增記錄
                $insert_sql = "INSERT INTO tbl_milestones (user_id, capsule_id, milestone_type, value, notes, milestone_date) 
                               VALUES (?, ?, ?, ?, ?, ?)";
                if ($istmt = mysqli_prepare($link, $insert_sql)) {
                    mysqli_stmt_bind_param($istmt, "iissss", $user_id, $capsule_id, $milestone_type, $value, $notes, $milestone_date);
                    if (mysqli_stmt_execute($istmt)) {
                        $milestone_id = mysqli_insert_id($link);
                        $response["success"] = true;
                        $response["message"] = "Milestone created successfully";
                        $response["data"] = ["milestone_id" => $milestone_id];
                    } else {
                        throw new Exception("Failed to create milestone");
                    }
                    mysqli_stmt_close($istmt);
                }
            }
        } else {
            throw new Exception("Failed to prepare check milestone query");
        }
    }
    
    elseif ($action === "get_milestones" && $method === "GET") {
        $sql = "SELECT id, milestone_type, value, notes, milestone_date, created_at 
                FROM tbl_milestones 
                WHERE user_id = ? 
                ORDER BY milestone_date DESC";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $milestones = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $milestones[] = $row;
            }
            
            $response["success"] = true;
            $response["data"] = $milestones;
            mysqli_stmt_close($stmt);
        }
    }
    elseif ($action === "update_milestone" && $method === "POST") {
    $milestone_id = isset($_POST["milestone_id"]) ? intval($_POST["milestone_id"]) : 0;
    $new_value = isset($_POST["value"]) ? trim($_POST["value"]) : "";

    if ($milestone_id <= 0 || empty($new_value)) {
        throw new Exception("Milestone ID and value are required");
    }

    // 先检查该里程碑是否存在
    $check_sql = "SELECT capsule_id FROM tbl_milestones WHERE id = ?";
    if ($stmt = mysqli_prepare($link, $check_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $milestone_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) === 0) {
            throw new Exception("Milestone not found");
        }
        mysqli_stmt_close($stmt);
    } else {
        throw new Exception("Database error");
    }


    // 更新 value 和 user_id（更新为当前操作用户），并更新 milestone_updated 时间
    $update_sql = "UPDATE tbl_milestones 
                   SET value = ?, user_id = ?, milestone_updated = NOW() 
                   WHERE id = ?";
    if ($stmt = mysqli_prepare($link, $update_sql)) {
        mysqli_stmt_bind_param($stmt, "sii", $new_value, $user_id, $milestone_id);
        if (mysqli_stmt_execute($stmt)) {
            $response["success"] = true;
            $response["message"] = "Milestone updated successfully";
        } else {
            throw new Exception("Failed to update milestone");
        }
        mysqli_stmt_close($stmt);
    }
}
    else {
        throw new Exception("Invalid action or method");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    $response["error"] = $e->getMessage();
}

mysqli_close($link);
echo json_encode($response);
?>
