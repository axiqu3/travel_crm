<?php
session_start();
require_once("includes/db.php");

$message = "";

if (isset($_POST["login"])) {
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $password = mysqli_real_escape_string($db, $_POST["password"]);

    $sql = "SELECT * FROM users WHERE email='$email'";
    $result = mysqli_query($db, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            // Check status in customer_master
            $cust_status_q = mysqli_query($db, "SELECT status FROM customer_master WHERE email = '$email' LIMIT 1");
            if ($cust_status_q && mysqli_num_rows($cust_status_q) > 0) {
                $cust_status_row = mysqli_fetch_assoc($cust_status_q);
                if (($cust_status_row['status'] ?? 'Active') === 'Inactive') {
                    $message = "Your login account is deactivated. Please contact the administrator.";
                }
            }
            
            if (empty($message)) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];

                if ($user["role"] == "admin") {
                    header("Location: admin_page/dashboard.php");
                    exit;
                } else {
                    header("Location: user_page/dashboard.php");
                    exit;
                }
            }
        }
    }
    $message = "Invalid email address or password combination.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login | Travel CRM Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="margin: 0; padding: 0;">

<div class="auth-container">
    <div class="auth-card">
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="font-size: 32px; margin-bottom: 8px;">✈</div>
            <h2 class="auth-title" style="margin: 0;">Travel CRM</h2>
            <p style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;">Sign in to access your business console</p>
        </div>

        <?php if (!empty($message)): ?>
            <div style="padding: 12px 16px; border-radius: 12px; font-size: 13px; font-weight: 600; margin-bottom: 20px; 
                background: #fee2e2; color: #991b1b; border: 1px solid rgba(239, 68, 68, 0.2);">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="name@company.com" required autocomplete="username">
            </div>

            <div class="form-group" style="margin-bottom: 28px;">
                <div class="d-flex justify-between align-center mb-4" style="margin-bottom: 8px;">
                    <label for="password" style="margin: 0;">Password</label>
                </div>
                <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
            </div>

            <button type="submit" name="login" style="width: 100%; padding: 14px; border-radius: 12px;">Sign In to Account</button>
        </form>
    </div>
</div>

</body>
</html>