<?php
session_start();
require_once("includes/db.php");

$message = "";
$email = "";

if (isset($_POST["login"])) {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    $user_statement = mysqli_prepare($db, "SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($user_statement, "s", $email);
    mysqli_stmt_execute($user_statement);
    $result = mysqli_stmt_get_result($user_statement);
    $user = $result ? mysqli_fetch_assoc($result) : null;

    if ($user) {
        $password_is_hashed = password_get_info($user['password'])['algo'] !== null;
        $password_is_valid = $password_is_hashed
            ? password_verify($password, $user['password'])
            : hash_equals($user['password'], $password);

        if ($password_is_valid) {
            // Check status in customer_master
            $status_statement = mysqli_prepare($db, "SELECT status FROM customer_master WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($status_statement, "s", $email);
            mysqli_stmt_execute($status_statement);
            $cust_status_q = mysqli_stmt_get_result($status_statement);
            if ($cust_status_q && mysqli_num_rows($cust_status_q) > 0) {
                $cust_status_row = mysqli_fetch_assoc($cust_status_q);
                if (($cust_status_row['status'] ?? 'Active') === 'Inactive') {
                    $message = "Your login account is deactivated. Please contact the administrator.";
                }
            }

            if (empty($message)) {
                if (!$password_is_hashed) {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $password_statement = mysqli_prepare($db, "UPDATE users SET password = ? WHERE id = ?");
                    mysqli_stmt_bind_param($password_statement, "si", $password_hash, $user['id']);
                    mysqli_stmt_execute($password_statement);
                }

                session_regenerate_id(true);
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

    if (empty($message)) {
        $message = "Invalid email address or password combination.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#071b2d">
<title>Sign In | <?= htmlspecialchars(COMPANY_NAME) ?></title>
<style>
  :root {
    --navy-950: #061522;
    --navy-900: #0a2235;
    --navy-800: #123a53;
    --teal-600: #087f8c;
    --teal-500: #0d9daa;
    --slate-900: #17212b;
    --slate-600: #5f6b76;
    --slate-400: #98a2ad;
    --line: #dce3e8;
    --surface: rgba(255, 255, 255, 0.97);
    --danger-bg: #fff1f1;
    --danger-border: #f3c8c8;
    --danger-text: #a02f2f;
  }

  * {
    box-sizing: border-box;
  }

  html,
  body {
    min-height: 100%;
    margin: 0;
  }

  body {
    min-height: 100vh;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color: var(--slate-900);
    background:
      linear-gradient(90deg, rgba(4, 18, 30, 0.82) 0%, rgba(4, 22, 35, 0.54) 48%, rgba(4, 18, 30, 0.42) 100%),
      url("assets/images/login-travel-bg.png") center / cover no-repeat fixed;
  }

  .login-page {
    min-height: 100vh;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(390px, 500px);
    gap: clamp(32px, 6vw, 96px);
    align-items: center;
    width: min(1240px, calc(100% - 64px));
    margin: 0 auto;
    padding: 48px 0;
  }

  .brand-content {
    max-width: 610px;
    color: #fff;
    text-shadow: 0 2px 20px rgba(0, 0, 0, 0.18);
  }

  .brand {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    margin-bottom: clamp(52px, 12vh, 120px);
    color: #fff;
    font-size: 16px;
    font-weight: 750;
    letter-spacing: -0.01em;
  }

  .brand-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 13px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    font-size: 19px;
  }

  .brand-kicker {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 18px;
    color: rgba(255, 255, 255, 0.78);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.13em;
    text-transform: uppercase;
  }

  .brand-kicker::before {
    content: "";
    width: 28px;
    height: 2px;
    border-radius: 99px;
    background: #4dd4dd;
  }

  .brand-content h1 {
    max-width: 580px;
    margin: 0;
    font-size: clamp(40px, 5vw, 66px);
    line-height: 1.05;
    letter-spacing: -0.055em;
    font-weight: 750;
  }

  .brand-description {
    max-width: 520px;
    margin: 24px 0 0;
    color: rgba(255, 255, 255, 0.76);
    font-size: clamp(15px, 1.4vw, 18px);
    line-height: 1.7;
  }

  .feature-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 34px;
  }

  .feature-row span {
    padding: 8px 12px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 99px;
    background: rgba(6, 21, 34, 0.24);
    color: rgba(255, 255, 255, 0.84);
    font-size: 12px;
    font-weight: 650;
    backdrop-filter: blur(8px);
  }

  .login-card {
    width: 100%;
    padding: clamp(32px, 4vw, 48px);
    border: 1px solid rgba(255, 255, 255, 0.7);
    border-radius: 24px;
    background: var(--surface);
    box-shadow: 0 30px 80px rgba(2, 17, 29, 0.32);
    backdrop-filter: blur(18px);
  }

  .card-label {
    margin: 0 0 10px;
    color: var(--teal-600);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.13em;
    text-transform: uppercase;
  }

  .login-card h2 {
    margin: 0;
    color: var(--navy-950);
    font-size: clamp(27px, 3vw, 34px);
    line-height: 1.15;
    letter-spacing: -0.035em;
  }

  .card-intro {
    margin: 10px 0 30px;
    color: var(--slate-600);
    font-size: 14px;
    line-height: 1.6;
  }

  .alert {
    margin: 0 0 22px;
    padding: 12px 14px;
    border: 1px solid var(--danger-border);
    border-radius: 10px;
    background: var(--danger-bg);
    color: var(--danger-text);
    font-size: 13px;
    font-weight: 650;
    line-height: 1.45;
  }

  .field {
    margin-bottom: 19px;
  }

  .field-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 8px;
  }

  .field > label,
  .field-label-row label {
    display: block;
    margin-bottom: 8px;
    color: #344250;
    font-size: 13px;
    font-weight: 700;
  }

  .field-label-row label {
    margin-bottom: 0;
  }

  .forgot-link {
    color: var(--teal-600);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
  }

  .forgot-link:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
  }

  .field input {
    width: 100%;
    min-height: 50px;
    padding: 0 15px;
    border: 1px solid var(--line);
    border-radius: 11px;
    outline: none;
    background: #fff;
    color: var(--slate-900);
    font: inherit;
    font-size: 14px;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
  }

  .field input::placeholder {
    color: var(--slate-400);
  }

  .field input:focus {
    border-color: var(--teal-500);
    box-shadow: 0 0 0 4px rgba(13, 157, 170, 0.12);
  }

  .password-wrap {
    position: relative;
  }

  .password-wrap input {
    padding-right: 64px;
  }

  .password-toggle {
    position: absolute;
    top: 50%;
    right: 10px;
    min-width: 44px;
    padding: 7px 6px;
    transform: translateY(-50%);
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: var(--slate-600);
    font: inherit;
    font-size: 11px;
    font-weight: 750;
    cursor: pointer;
  }

  .password-toggle:hover,
  .password-toggle:focus-visible {
    background: #edf4f5;
    color: var(--navy-900);
    outline: none;
  }

  .login-button {
    width: 100%;
    min-height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 7px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(135deg, var(--navy-900), var(--navy-800));
    color: #fff;
    font: inherit;
    font-size: 14px;
    font-weight: 750;
    cursor: pointer;
    box-shadow: 0 12px 24px rgba(10, 34, 53, 0.2);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
  }

  .login-button span {
    font-size: 18px;
    line-height: 1;
    transition: transform 0.18s ease;
  }

  .login-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 16px 30px rgba(10, 34, 53, 0.26);
  }

  .login-button:hover span {
    transform: translateX(3px);
  }

  .security-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    margin: 20px 0 0;
    color: #7b8791;
    font-size: 11px;
  }

  .security-note::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #23a871;
    box-shadow: 0 0 0 4px rgba(35, 168, 113, 0.1);
  }

  @media (max-width: 900px) {
    body {
      background-position: 42% center;
    }

    .login-page {
      grid-template-columns: 1fr;
      width: min(500px, calc(100% - 36px));
      gap: 28px;
      padding: 28px 0;
    }

    .brand-content {
      text-align: center;
    }

    .brand {
      justify-content: center;
      margin-bottom: 34px;
    }

    .brand-kicker {
      justify-content: center;
    }

    .brand-content h1 {
      font-size: clamp(34px, 9vw, 48px);
    }

    .brand-description {
      margin: 16px auto 0;
    }

    .feature-row {
      justify-content: center;
      margin-top: 22px;
    }
  }

  @media (max-width: 520px) {
    body {
      background-attachment: scroll;
    }

    .login-page {
      width: min(100% - 24px, 440px);
      padding: 20px 0;
    }

    .brand {
      margin-bottom: 22px;
    }

    .brand-description,
    .feature-row {
      display: none;
    }

    .login-card {
      padding: 28px 22px;
      border-radius: 19px;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
      scroll-behavior: auto !important;
      transition: none !important;
    }
  }
</style>
</head>
<body>

<main class="login-page">
  <section class="brand-content" aria-label="<?= htmlspecialchars(COMPANY_NAME) ?> introduction">
    <div class="brand">
      <span class="brand-icon" aria-hidden="true">&#9992;</span>
      <span><?= htmlspecialchars(COMPANY_NAME) ?></span>
    </div>

    <p class="brand-kicker">Travel business workspace</p>
    <h1>Every journey, managed in one place.</h1>
    <p class="brand-description">Keep enquiries, customers, bookings and daily follow-ups organised with one simple CRM workspace.</p>

    <div class="feature-row" aria-label="CRM features">
      <span>Enquiry tracking</span>
      <span>Booking management</span>
      <span>Customer records</span>
    </div>
  </section>

  <section class="login-card" aria-labelledby="login-title">
    <p class="card-label">Secure workspace</p>
    <h2 id="login-title">Welcome back</h2>
    <p class="card-intro">Sign in with your work account to continue.</p>

    <?php if (!empty($message)): ?>
      <div class="alert" role="alert" aria-live="polite"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" placeholder="name@company.com" required autocomplete="username" autofocus>
      </div>

      <div class="field">
        <div class="field-label-row">
          <label for="password">Password</label>
          <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
        </div>
        <div class="password-wrap">
          <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
          <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false">Show</button>
        </div>
      </div>

      <button type="submit" name="login" class="login-button">
        Sign in to your account
        <span aria-hidden="true">&rarr;</span>
      </button>
    </form>

    <p class="security-note">Protected account access</p>
  </section>
</main>

<script>
  const toggle = document.getElementById('togglePassword');
  const password = document.getElementById('password');

  toggle.addEventListener('click', () => {
    const isHidden = password.type === 'password';
    password.type = isHidden ? 'text' : 'password';
    toggle.textContent = isHidden ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    toggle.setAttribute('aria-pressed', String(isHidden));
  });
</script>

</body>
</html>
