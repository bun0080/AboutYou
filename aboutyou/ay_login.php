<?php 
// 1. 安全配置
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

session_start(); 

if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: aboutyou.php");
    exit;
}
 
$link = require_once "config.php";
mysqli_set_charset($link, "utf8mb4"); // ★ 关键：确保读取时用 UTF-8

$username = "";
$password = ""; 
$username_err = "";
$password_err = "";
$login_err = "";
 
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }

    if(empty($username_err) && empty($password_err)){
        $sql = "SELECT u.id, u.username, u.nickname, u.icon_url, u.password
                FROM linux_website.tbl_user u 
                WHERE u.username = ? 
                GROUP BY u.id, u.username, u.nickname, u.icon_url, u.password
                LIMIT 1";

        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $username);
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                if(mysqli_stmt_num_rows($stmt) == 1){
                    mysqli_stmt_bind_result($stmt, $id, $dbusername, $db_nickname, $db_icon_url, $hash);
                    if(mysqli_stmt_fetch($stmt)){
                        $is_password_correct = false;
                        if (password_verify($password, $hash)) {
                            $is_password_correct = true;
                        } elseif ($password === $hash) {
                            $is_password_correct = true;
                        }

                        if($is_password_correct){
                                $_SESSION["loggedin"] = true;
                                $_SESSION["id"] = $id;
                                $_SESSION["username"] = $dbusername;
                                $_SESSION["nickname"] = $db_nickname ?: $dbusername;
                                $_SESSION["icon_url"] = $db_icon_url;
                                $_SESSION["userlv"] = !empty($user_level) ? $user_level : 'member'; 
                                $_SESSION["application"] = !empty($application) ? $application : '';
                                $_SESSION["rotation"] = 'portrait';
                                
                                header("location: aboutyou.php");
                                exit;
                            
                        } else{
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else{
                    $login_err = "Invalid username or password.";
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    mysqli_close($link);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="login.css">
</head>
<body>
    <div class="bg"></div>
    <div class="wrapper">
        <h2 class="login-h2">Login</h2>
        <p class="login-p">Please fill in your credentials to login.</p>
        <?php if(!empty($login_err)) echo '<div class="alert alert-danger">' . $login_err . '</div>'; ?>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-field field-username">
                <input type="text" name="username" placeholder="Email / Username" class="<?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>" required>
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>
            <div class="form-field field-password">
                <input type="password" name="password" placeholder="Password" class="<?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" required>
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-field field-submit">
                <button type="submit" class="btn-login">Login</button>
            </div>
            <p class="signup-link">Don't have an account? <a href="register.php">Sign up now</a>.</p>
        </form>
    </div>
</body>
</html>
