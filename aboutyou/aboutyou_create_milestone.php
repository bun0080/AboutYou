<?php
/**
 * aboutyou - Create Milestone Page
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

$user_id = $_SESSION["id"];
$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $milestone_type = isset($_POST["milestone_type"]) ? trim($_POST["milestone_type"]) : "";
    $value = isset($_POST["value"]) ? trim($_POST["value"]) : "";
    $notes = isset($_POST["notes"]) ? trim($_POST["notes"]) : "";
    $milestone_date = isset($_POST["milestone_date"]) ? trim($_POST["milestone_date"]) : "";
    
    if (empty($milestone_type)) {
        $error_msg = "Milestone type is required";
    } elseif (empty($milestone_date)) {
        $error_msg = "Milestone date is required";
    } else {
        $sql = "INSERT INTO tbl_milestones (user_id, milestone_type, value, notes, milestone_date) 
                VALUES (?, ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "issss", $user_id, $milestone_type, $value, $notes, $milestone_date);
            
            if (mysqli_stmt_execute($stmt)) {
                $success_msg = "Milestone recorded successfully!";
                $milestone_type = "";
                $value = "";
                $notes = "";
                $milestone_date = "";
            } else {
                $error_msg = "Error recording milestone. Please try again.";
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
    <title>Add Milestone - AboutYou</title>
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
        input[type="datetime-local"],
        select,
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
        input[type="datetime-local"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .milestone-examples {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #666;
        }

        .milestone-examples strong {
            display: block;
            margin-bottom: 8px;
            color: #333;
        }

        .milestone-examples div {
            margin-bottom: 5px;
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

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-card">
            <div class="form-header">
                <h1>🎉 Record Milestone</h1>
                <p>Celebrate important moments in growth and development</p>
            </div>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success">✓ <?php echo $success_msg; ?></div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-error">✗ <?php echo $error_msg; ?></div>
            <?php endif; ?>

            <div class="milestone-examples">
                <strong>Common Milestones:</strong>
                <div>• First smile, first laugh, first word</div>
                <div>• First step, first tooth</div>
                <div>• Weight: 10 kg, Height: 75 cm</div>
                <div>• First day of school, graduation</div>
                <div>• Birthday, anniversary</div>
            </div>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="form-group">
                    <label for="milestone_type">Milestone Type *</label>
                    <input 
                        type="text" 
                        id="milestone_type" 
                        name="milestone_type" 
                        placeholder="e.g., First Word, Weight Milestone, First Step"
                        value="<?php echo htmlspecialchars($milestone_type); ?>"
                        required
                    >
                    <div class="help-text">What milestone are you recording?</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="value">Value / Measurement</label>
                        <input 
                            type="text" 
                            id="value" 
                            name="value" 
                            placeholder="e.g., 10 kg, 75 cm, 'Mama'"
                            value="<?php echo htmlspecialchars($value); ?>"
                        >
                        <div class="help-text">Optional: Specific value or measurement</div>
                    </div>

                    <div class="form-group">
                        <label for="milestone_date">Date *</label>
                        <input 
                            type="datetime-local" 
                            id="milestone_date" 
                            name="milestone_date" 
                            value="<?php echo htmlspecialchars($milestone_date); ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea 
                        id="notes" 
                        name="notes" 
                        placeholder="Any additional details or memories about this milestone?"
                    ><?php echo htmlspecialchars($notes); ?></textarea>
                    <div class="help-text">Optional: Add context or special memories</div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Record Milestone</button>
                    <a href="aboutyou.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
