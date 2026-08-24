<?php
/**
 * aboutyou - Create Time Capsule Page
 */

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = isset($_POST["title"]) ? trim($_POST["title"]) : "";
    $description = isset($_POST["description"]) ? trim($_POST["description"]) : "";
    $delivery_date = date("Y-m-d H:i:s", strtotime("+10 years")); // Default for mobile
    
    $profile_image_url = null;
    // Handle profile image upload
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
        $error_msg = "Title is required";
    } else {
        $sql = "INSERT INTO tbl_time_capsules (user_id, title, description, delivery_date, profile_image_url, status) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
	    $status="pending";
            mysqli_stmt_bind_param($stmt, "isssss", $user_id, $title, $description, $delivery_date, $profile_image_url, $status);
            
            if (mysqli_stmt_execute($stmt)) {
                $capsule_id = mysqli_insert_id($link);
                $success_msg = "Time capsule created successfully!";
                // Clear form
                $title = "";
                $description = "";
                $profile_image_url = null;
            } else {
                $error_msg = "Error creating capsule. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Time Capsule - AboutYou</title>
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
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 600px;
            width: 100%;
        }

        .form-card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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
        input[type="file"],
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="file"]:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
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
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
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

        .help-text {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }

        @media (max-width: 600px) {
            .form-card {
                padding: 20px;
            }

            .form-header h1 {
                font-size: 24px;
            }

            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-card">
            <div class="form-header">
                <h1>⏳ Create Time Capsule</h1>
                <p>Seal your memories for the future</p>
            </div>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success">✓ <?php echo $success_msg; ?></div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-error">✗ <?php echo $error_msg; ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Capsule Title *</label>
                    <input 
                        type="text" 
                        id="title" 
                        name="title" 
                        placeholder="e.g., Baby's First Year, Summer 2024"
                        value="<?php echo htmlspecialchars($title); ?>"
                        required
                    >
                    <div class="help-text">Give your time capsule a meaningful name</div>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea 
                        id="description" 
                        name="description" 
                        placeholder="What's special about this time capsule? What memories are you preserving?"
                    ><?php echo htmlspecialchars($description); ?></textarea>
                    <div class="help-text">Optional: Add context or notes about this capsule</div>
                </div>

                <div class="form-group">
                    <label for="profile_image">Capsule Profile Image</label>
                    <input 
                        type="file" 
                        id="profile_image" 
                        name="profile_image"
                        accept="image/*"
                    >
                    <div class="help-text">Optional: Upload an image for this capsule</div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Capsule</button>
                    <a href="aboutyou.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
