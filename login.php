<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    $destination = $_SESSION['user_role'] === 'admin'
        ? 'admin_page/dashboard.php'
        : 'user_page/dashboard.php';
    header('Location: ' . $destination);
    exit;
}

$message = '';
$email = trim($_POST['email'] ?? '');

if (isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    $statement = mysqli_prepare(
        $db,
        'SELECT id, name, email, password, role, profile_completed FROM users WHERE email = ? LIMIT 1'
    );

    if ($statement) {
        mysqli_stmt_bind_param($statement, 's', $email);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $user = $result ? mysqli_fetch_assoc($result) : null;

        if ($user) {
            $password_info = password_get_info((string) $user['password']);
            $password_is_hashed = !empty($password_info['algo']);
            $password_is_valid = $password_is_hashed
                ? password_verify($password, $user['password'])
                : hash_equals((string) $user['password'], $password);

            if ($password_is_valid) {
                $status = 'Active';
                $status_statement = mysqli_prepare(
                    $db,
                    'SELECT status FROM customer_master WHERE email = ? LIMIT 1'
                );

                if ($status_statement) {
                    mysqli_stmt_bind_param($status_statement, 's', $email);
                    mysqli_stmt_execute($status_statement);
                    $status_result = mysqli_stmt_get_result($status_statement);
                    $customer = $status_result ? mysqli_fetch_assoc($status_result) : null;
                    $status = $customer['status'] ?? 'Active';
                }

                if ($status === 'Inactive') {
                    $message = 'Your login account is deactivated. Please contact the administrator.';
                } else {
                    if (!$password_is_hashed) {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $password_statement = mysqli_prepare($db, 'UPDATE users SET password = ? WHERE id = ?');
                        if ($password_statement) {
                            mysqli_stmt_bind_param($password_statement, 'si', $password_hash, $user['id']);
                            mysqli_stmt_execute($password_statement);
                        }
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['profile_completed'] = (int) ($user['profile_completed'] ?? 0);

                    $destination = $user['role'] === 'admin'
                        ? 'admin_page/dashboard.php'
                        : 'user_page/dashboard.php';
                    header('Location: ' . $destination);
                    exit;
                }
            }
        }
    }

    if ($message === '') {
        $message = 'Invalid email address or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071b2d">
    <title>Sign In | <?= htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; }
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            color: #17212b;
            background:
                linear-gradient(rgba(7, 27, 43, .58), rgba(7, 27, 43, .58)),
                url('assets/images/login-travel-bg.png') center / cover no-repeat fixed;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .login-shell {
            width: min(100%, 420px);
        }
        .login-card {
            padding: 32px;
            border: 1px solid #dfe4e8;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .08);
        }
        .brand-mark {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
            color: #0d283f;
            font-size: 15px;
            font-weight: 750;
        }
        .brand-icon {
            display: grid;
            place-items: center;
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: #0d283f;
            color: #fff;
            font-size: 16px;
        }
        h1 {
            margin: 0;
            color: #111827;
            font-size: 25px;
            line-height: 1.25;
        }
        .intro {
            margin: 8px 0 26px;
            color: #667085;
            font-size: 14px;
            line-height: 1.5;
        }
        .alert {
            margin-bottom: 20px;
            padding: 11px 12px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fef2f2;
            color: #991b1b;
            font-size: 13px;
            line-height: 1.45;
        }
        label {
            display: block;
            margin: 0 0 7px;
            color: #344054;
            font-size: 13px;
            font-weight: 650;
        }
        .field { margin-bottom: 18px; }
        input {
            width: 100%;
            min-height: 46px;
            padding: 0 13px;
            border: 1px solid #cfd6dd;
            border-radius: 8px;
            outline: none;
            background: #fff;
            color: #17212b;
            font: inherit;
            font-size: 14px;
        }
        input::placeholder { color: #98a2b3; }
        input:focus {
            border-color: #315f7d;
            box-shadow: 0 0 0 3px rgba(49, 95, 125, .12);
        }
        .password-wrap { position: relative; }
        .password-wrap input { padding-right: 62px; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 7px;
            transform: translateY(-50%);
            padding: 7px 8px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #52606d;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .password-toggle:hover,
        .password-toggle:focus-visible {
            background: #eef2f5;
            outline: none;
        }
        .submit {
            width: 100%;
            min-height: 46px;
            margin-top: 2px;
            border: 0;
            border-radius: 8px;
            background: #0d283f;
            color: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .submit:hover { background: #163d5c; }
        .submit:focus-visible { outline: 3px solid rgba(49, 95, 125, .25); outline-offset: 2px; }
        .security {
            margin: 18px 0 0;
            color: #8a94a0;
            font-size: 11px;
            text-align: center;
        }
        @media (max-width: 480px) {
            body { padding: 12px; }
            .login-card { padding: 26px 22px; }
        }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="login-card" aria-labelledby="login-title">
        <div class="brand-mark"><span class="brand-icon" aria-hidden="true">&#9992;</span><span><?= htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') ?></span></div>
        <h1 id="login-title">Sign in</h1>
        <p class="intro">Sign in with your work account to continue.</p>
        <?php if ($message !== ''): ?><div class="alert" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post">
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@company.com" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    <button id="togglePassword" class="password-toggle" type="button" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>
            <button class="submit" type="submit" name="login">Sign in to your account</button>
        </form>
        <p class="security">Protected account access</p>
    </section>
</main>
<script>
const toggle = document.getElementById('togglePassword');
const password = document.getElementById('password');
toggle.addEventListener('click', () => {
    const hidden = password.type === 'password';
    password.type = hidden ? 'text' : 'password';
    toggle.textContent = hidden ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
    toggle.setAttribute('aria-pressed', String(hidden));
});
</script>
</body>
</html>
