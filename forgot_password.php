<?php
session_start();
require_once("includes/db.php");

$message = "";
$step = 1;

// ---------- STEP 1: verify email + phone ----------
if (isset($_POST["verify"])) {
    $email = mysqli_real_escape_string($db, trim($_POST["email"]));
    $phone = mysqli_real_escape_string($db, trim($_POST["phone"]));

    // NOTE: change "phone" below to match your actual column name if different
    $sql = "SELECT id, email FROM users WHERE email='$email' AND phone='$phone' LIMIT 1";
    $result = mysqli_query($db, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $_SESSION['reset_user_id'] = $user['id'];
        $_SESSION['reset_email']   = $user['email'];
        $step = 2;
    } else {
        $message = "We couldn't match that email and phone number. Please check and try again.";
        $step = 1;
    }
}

// ---------- STEP 2: set new password ----------
if (isset($_POST["reset"])) {
    if (!isset($_SESSION['reset_user_id'])) {
        $message = "Your verification session expired. Please start again.";
        $step = 1;
    } else {
        $new_password     = $_POST["new_password"];
        $confirm_password = $_POST["confirm_password"];

        if (strlen($new_password) < 6) {
            $message = "Password must be at least 6 characters.";
            $step = 2;
        } elseif ($new_password !== $confirm_password) {
            $message = "Passwords do not match. Please try again.";
            $step = 2;
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = intval($_SESSION['reset_user_id']);
            mysqli_query($db, "UPDATE users SET password='" . mysqli_real_escape_string($db, $hashed) . "' WHERE id=$user_id");

            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_email']);

            header("Location: login.php?reset=success");
            exit;
        }
    }
}

// If step 1 hasn't been (re)verified in this request but a session exists, show step 2
if ($step === 1 && isset($_SESSION['reset_user_id']) && !isset($_POST["verify"])) {
    $step = 2;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | <?= htmlspecialchars(COMPANY_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
  :root{
    --navy-deep:#0a1a30;
    --navy:#0e2645;
    --navy-light:#173a63;
    --gold:#d4a24c;
    --cream:#ffffff;
    --ink:#182233;
    --ink-soft:#5b6472;
    --line:#e6e1d6;
    --danger-bg:#fdecec;
    --danger-text:#9c2b2b;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;height:100%;}
  body{
    font-family:'Inter',sans-serif;
    background:var(--cream);
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:100vh;
    padding:24px;
  }

  .stage{
    width:100%;
    max-width:920px;
    min-height:560px;
    display:grid;
    grid-template-columns:1fr 1fr;
    background:var(--cream);
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 30px 60px -20px rgba(10,26,48,0.35);
    position:relative;
  }

  .brand-panel{
    position:relative;
    background:radial-gradient(120% 140% at 20% 0%, var(--navy-light) 0%, var(--navy) 45%, var(--navy-deep) 100%);
    color:var(--cream);
    padding:44px 40px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    overflow:hidden;
  }
  .brand-panel .route-svg{
    position:absolute; inset:0; width:100%; height:100%; opacity:0.9;
  }
  .brand-mark{ position:relative; z-index:2; display:flex; align-items:center; gap:10px; }
  .brand-mark .glyph{
    width:34px;height:34px; border:1.5px solid rgba(247,244,238,0.5); border-radius:50%;
    display:flex;align-items:center;justify-content:center; font-size:15px;
  }
  .brand-mark .word{ font-family:'Fraunces',serif; font-weight:600; font-size:19px; letter-spacing:0.02em; }

  .brand-copy{ position:relative; z-index:2; }
  .brand-copy h1{
    font-family:'Fraunces',serif; font-weight:500; font-size:30px; line-height:1.25;
    margin:0 0 12px; max-width:280px;
  }
  .brand-copy p{ font-size:13.5px; line-height:1.6; color:rgba(247,244,238,0.65); max-width:270px; margin:0; }

  .step-strip{
    position:relative; z-index:2; display:flex; gap:22px; padding-top:22px;
    border-top:1px dashed rgba(247,244,238,0.25);
  }
  .step-strip div{ font-family:'JetBrains Mono',monospace; font-size:10.5px; letter-spacing:0.06em; color:rgba(247,244,238,0.35); transition:color .2s ease; }
  .step-strip div.active{ color:rgba(247,244,238,0.9); }
  .step-strip strong{ display:block; font-size:13px; color:var(--gold); letter-spacing:0.03em; margin-bottom:2px; }

  .perforation{
    position:absolute; left:50%; top:0; bottom:0; width:0;
    border-left:2px dashed rgba(10,26,48,0.15); z-index:5;
  }
  .perforation::before, .perforation::after{
    content:""; position:absolute; left:50%; transform:translateX(-50%);
    width:26px;height:26px; background:var(--cream); border-radius:50%;
  }
  .perforation::before{ top:-13px; }
  .perforation::after{ bottom:-13px; }

  .form-panel{
    background:var(--cream); padding:52px 48px; display:flex; flex-direction:column; justify-content:center;
  }
  .form-panel .eyebrow{
    font-family:'JetBrains Mono',monospace; font-size:11px; letter-spacing:0.12em;
    text-transform:uppercase; color:var(--gold); margin-bottom:10px;
  }
  .form-panel h2{ font-family:'Fraunces',serif; font-weight:600; font-size:26px; color:var(--ink); margin:0 0 6px; }
  .form-panel .sub{ font-size:13.5px; color:var(--ink-soft); margin:0 0 28px; }

  .alert{
    padding:12px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:20px;
    background:var(--danger-bg); color:var(--danger-text); border:1px solid rgba(156,43,43,0.15);
  }

  .field{ margin-bottom:20px; }
  .field label{
    display:block; font-size:12px; font-weight:600; letter-spacing:0.03em; color:var(--ink);
    margin-bottom:7px; text-transform:uppercase;
  }
  .field input{
    width:100%; padding:13px 14px; border:1.5px solid var(--line); border-radius:10px;
    background:#fff; font-size:14px; color:var(--ink); font-family:'Inter',sans-serif;
    transition:border-color .15s ease, box-shadow .15s ease;
  }
  .field input::placeholder{ color:#b7b2a6; }
  .field input:focus{
    outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(212,162,76,0.18);
  }

  .password-wrap{ position:relative; }
  .password-wrap input{ padding-right:44px; }
  .toggle-eye{
    position:absolute; right:12px; top:50%; transform:translateY(-50%);
    background:none; border:none; cursor:pointer; padding:4px; display:flex; color:var(--ink-soft);
  }
  .toggle-eye:hover{ color:var(--ink); }
  .toggle-eye svg{ width:18px; height:18px; }
  .toggle-eye .eye-off{ display:none; }

  .btn-fly{
    width:100%; padding:14px; border:none; border-radius:10px; background:var(--navy);
    color:var(--cream); font-size:14px; font-weight:600; letter-spacing:0.02em; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:8px;
    transition:background .15s ease, transform .15s ease;
  }
  .btn-fly:hover{ background:var(--navy-deep); transform:translateY(-1px); }
  .btn-fly svg{ width:15px; height:15px; }

  .back-link{
    display:inline-block; margin-top:18px; font-size:12.5px; color:var(--ink-soft);
    text-decoration:none; text-align:center;
  }
  .back-link:hover{ color:var(--ink); text-decoration:underline; }

  @media (max-width: 760px){
    .stage{ grid-template-columns:1fr; }
    .brand-panel{ padding:36px 32px 28px; min-height:200px; }
    .brand-copy h1{ font-size:24px; }
    .perforation{
      left:0; right:0; top:auto; bottom:0; height:0; width:auto;
      border-left:none; border-top:2px dashed rgba(10,26,48,0.15);
    }
    .perforation::before{ left:0; top:-13px; transform:translate(-50%,0); }
    .perforation::after{ left:100%; bottom:auto; top:-13px; transform:translate(-50%,0); }
    .form-panel{ padding:36px 32px 44px; }
  }

  /* Standard CRM password reset */
  body{ background:#f5f7fa; color:#172b3d; }
  .stage{
    max-width:440px; min-height:0; display:block; border:1px solid #e1e7ed;
    border-radius:12px; box-shadow:0 12px 34px rgba(13,40,63,.10);
  }
  .brand-panel{ min-height:0; padding:20px 26px; background:#0d283f; display:block; }
  .brand-panel .route-svg,.brand-copy,.perforation{ display:none; }
  .brand-mark{ font-family:'Inter',sans-serif; }
  .brand-mark .glyph{ width:32px; height:32px; border-radius:8px; }
  .brand-mark .word{ font-family:'Inter',sans-serif; font-size:15px; }
  .step-strip{ margin-top:18px; padding-top:14px; border-top:1px solid rgba(255,255,255,.12); }
  .step-strip strong{ color:#ffffff; }
  .form-panel{ padding:32px 30px 34px; }
  .form-panel .eyebrow{ font-family:'Inter',sans-serif; color:#0d283f; font-weight:700; }
  .form-panel h2{ font-family:'Inter',sans-serif; color:#0d283f; font-size:23px; }
  .field label{ text-transform:none; color:#405568; }
  .field input{ border:1px solid #d7e0e8; border-radius:7px; padding:11px 12px; }
  .field input:focus{ border-color:#0d283f; box-shadow:0 0 0 3px rgba(13,40,63,.10); }
  .btn-fly{ border-radius:7px; background:#0d283f; }
  .btn-fly:hover{ background:#071b2d; transform:none; }
  .back-link{ color:#405568; }
</style>
</head>
<body>

<div class="stage">

  <div class="brand-panel">
    <svg class="route-svg" viewBox="0 0 460 560" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M -20 460 C 90 380, 140 300, 230 300 S 380 180, 480 90"
            fill="none" stroke="rgba(247,244,238,0.28)" stroke-width="1.5" stroke-dasharray="2 8" stroke-linecap="round"/>
      <circle cx="-20" cy="460" r="4" fill="#d4a24c"/>
      <circle cx="480" cy="90" r="4" fill="#d4a24c"/>
      <g transform="translate(230,300) rotate(-38)">
        <path d="M0 -9 L3 0 L0 9 L-1 5 L-6 6 L-4 0 L-6 -6 L-1 -5 Z" fill="#f7f4ee" opacity="0.9"/>
      </g>
      <circle cx="60" cy="120" r="90" fill="rgba(247,244,238,0.03)"/>
      <circle cx="380" cy="440" r="130" fill="rgba(247,244,238,0.03)"/>
    </svg>

    <div class="brand-mark">
      <span class="glyph">✈</span>
      <span class="word">Travel Agency</span>
    </div>

    <div class="brand-copy">
      <h1>Let's get you back into your console.</h1>
      <p>Verify your identity with your registered email and phone number, then set a new password.</p>
    </div>

    <div class="step-strip">
      <div class="<?php echo $step === 1 ? 'active' : ''; ?>"><strong>01</strong>Verify identity</div>
      <div class="<?php echo $step === 2 ? 'active' : ''; ?>"><strong>02</strong>New password</div>
    </div>
  </div>

  <div class="perforation"></div>

  <div class="form-panel">

    <?php if ($step === 1): ?>

      <div class="eyebrow">Step 01 of 02</div>
      <h2>Verify your identity</h2>
      <p class="sub">Enter the email and phone number linked to your account.</p>

      <?php if (!empty($message)): ?>
        <div class="alert"><?php echo $message; ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="field">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" placeholder="name@company.com" required autocomplete="email">
        </div>

        <div class="field">
          <label for="phone">Phone Number</label>
          <input type="tel" id="phone" name="phone" placeholder="9876543210" required autocomplete="tel">
        </div>

        <button type="submit" name="verify" class="btn-fly">
          Verify &amp; Continue
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>

      <a href="login.php" class="back-link">← Back to login</a>

    <?php else: ?>

      <div class="eyebrow">Step 02 of 02</div>
      <h2>Set a new password</h2>
      <p class="sub">Identity verified for <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong>. Choose a new password below.</p>

      <?php if (!empty($message)): ?>
        <div class="alert"><?php echo $message; ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="field">
          <label for="new_password">New Password</label>
          <div class="password-wrap">
            <input type="password" id="new_password" name="new_password" placeholder="••••••••" required minlength="6" autocomplete="new-password">
            <button type="button" class="toggle-eye" data-target="new_password" aria-label="Show password">
              <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.9 18.9 0 0 1 5.06-5.94M9.9 4.24A10.6 10.6 0 0 1 12 4c7 0 11 8 11 8a18.9 18.9 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
            </button>
          </div>
        </div>

        <div class="field">
          <label for="confirm_password">Confirm New Password</label>
          <div class="password-wrap">
            <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required minlength="6" autocomplete="new-password">
            <button type="button" class="toggle-eye" data-target="confirm_password" aria-label="Show password">
              <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.9 18.9 0 0 1 5.06-5.94M9.9 4.24A10.6 10.6 0 0 1 12 4c7 0 11 8 11 8a18.9 18.9 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" name="reset" class="btn-fly">
          Update Password
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>

      <a href="forgot_password.php" class="back-link">← Start over</a>

    <?php endif; ?>

  </div>

</div>

<script>
  document.querySelectorAll('.toggle-eye').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const input = document.getElementById(btn.getAttribute('data-target'));
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.querySelector('.eye-on').style.display = isHidden ? 'none' : 'block';
      btn.querySelector('.eye-off').style.display = isHidden ? 'block' : 'none';
      btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  });
</script>

</body>
</html>
