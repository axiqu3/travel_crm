<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$user_id = intval($_SESSION['user_id'] ?? 0);
$message = "";
$message_type = "";

function admin_profile_initials($name) {
    $parts = preg_split('/\s+/', trim((string) $name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials ?: 'AD';
}

// Handle Update Request
if (isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($db, trim($_POST['name'] ?? ''));
    $email = mysqli_real_escape_string($db, trim($_POST['email'] ?? ''));

    // Check password
    $password_sql = "";
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm = $_POST['confirm'] ?? '';
        if ($password === $confirm) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $hashed = mysqli_real_escape_string($db, $hashed);
            $password_sql = ", password = '$hashed'";
        } else {
            $message = "Passwords do not match.";
            $message_type = "error";
        }
    }

    if (empty($message)) {
        $update_query = "UPDATE users SET name = '$name', email = '$email' $password_sql WHERE id = $user_id";
        if (mysqli_query($db, $update_query)) {
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            if (isset($_POST['company_name'])) {
                $new_company = mysqli_real_escape_string($db, trim($_POST['company_name']));
                mysqli_query($db, "INSERT INTO settings (`key`, `value`) VALUES ('company_name', '$new_company') ON DUPLICATE KEY UPDATE `value` = '$new_company'");
            }

            header("Location: index.php?updated=true");
            exit();
        } else {
            $message = "Unable to update the administrator profile. Please try again.";
            $message_type = "error";
        }
    }
}

if (isset($_GET['updated'])) {
    $message = "Profile details updated successfully.";
    $message_type = "success";
}

// Fetch current logged-in user details
$admin_query = mysqli_query($db, "SELECT * FROM users WHERE id = $user_id");
$admin = $admin_query ? mysqli_fetch_assoc($admin_query) : null;

if (!$admin) {
    $admin = [
        'name' => $_SESSION['user_name'] ?? 'Admin User',
        'email' => $_SESSION['user_email'] ?? 'admin@gmail.com',
        'role' => 'admin'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Profile | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/premium-dashboard.css">
    <link rel="stylesheet" href="../../assets/css/premium-profile.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<main class="main crm-dashboard profile-workspace">
    <header class="crm-topbar profile-topbar">
        <div>
            <p class="crm-eyebrow">Administrator settings</p>
            <h1>Administrator profile</h1>
            <p class="crm-subtitle">Manage your identity, company details and account security.</p>
        </div>
        <div class="crm-topbar-actions">
            <a href="../dashboard.php" class="profile-back-link"><span aria-hidden="true">&larr;</span> Back to dashboard</a>
            <span class="crm-avatar" aria-hidden="true"><?php echo htmlspecialchars(admin_profile_initials($admin['name'])); ?></span>
        </div>
    </header>

    <?php if (!empty($message)): ?>
        <div class="profile-alert <?php echo $message_type === 'success' ? 'is-success' : 'is-error'; ?>" role="alert" aria-live="polite">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="profile-layout">
        <aside class="profile-summary" aria-label="Administrator summary">
            <div class="profile-cover"></div>
            <div class="profile-summary-body">
                <div class="profile-avatar-large" aria-hidden="true"><?php echo htmlspecialchars(admin_profile_initials($admin['name'])); ?></div>
                <h2><?php echo htmlspecialchars($admin['name']); ?></h2>
                <span class="profile-email"><?php echo htmlspecialchars($admin['email']); ?></span>
                <span class="profile-role"><?php echo htmlspecialchars(ucfirst($admin['role'])); ?> account</span>

                <div class="profile-facts">
                    <div class="profile-fact">
                        <span>Account status</span>
                        <strong class="is-active">Active</strong>
                    </div>
                    <div class="profile-fact">
                        <span>Access level</span>
                        <strong>Full control</strong>
                    </div>
                    <div class="profile-fact">
                        <span>Workspace</span>
                        <strong><?= htmlspecialchars(COMPANY_NAME) ?></strong>
                    </div>
                </div>
            </div>
        </aside>

        <section class="profile-form-card" aria-labelledby="admin-details-title">
            <form method="POST">
                <div class="profile-section-header">
                    <div>
                        <p class="profile-section-kicker">Account information</p>
                        <h2 id="admin-details-title">Administrator details</h2>
                    </div>
                    <span>Required fields</span>
                </div>

                <div class="profile-form-grid">
                    <div class="profile-field">
                        <label for="name">Administrator name</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($admin['name']); ?>" placeholder="Enter administrator name" required autocomplete="name">
                    </div>

                    <div class="profile-field">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" placeholder="admin@company.com" required autocomplete="email">
                    </div>

                    <div class="profile-field is-full">
                        <label for="company_name">Travel agency company name</label>
                        <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars(COMPANY_NAME); ?>" placeholder="Enter company name" required autocomplete="organization">
                        <p class="profile-field-help">This name appears throughout the CRM workspace.</p>
                    </div>
                </div>

                <hr class="profile-divider">

                <div class="profile-section-header">
                    <div>
                        <p class="profile-section-kicker">Account security</p>
                        <h2>Change password</h2>
                    </div>
                    <span>Optional</span>
                </div>

                <div class="profile-form-grid">
                    <div class="profile-field">
                        <label for="password">New password</label>
                        <div class="profile-password-wrap">
                            <input type="password" id="password" name="password" placeholder="Enter a new password" autocomplete="new-password">
                            <button type="button" class="profile-password-toggle" data-password-toggle="password" aria-label="Show new password" aria-pressed="false">Show</button>
                        </div>
                        <p class="profile-field-help">Leave blank to keep your current password.</p>
                    </div>

                    <div class="profile-field">
                        <label for="confirm">Confirm new password</label>
                        <div class="profile-password-wrap">
                            <input type="password" id="confirm" name="confirm" placeholder="Repeat the new password" autocomplete="new-password">
                            <button type="button" class="profile-password-toggle" data-password-toggle="confirm" aria-label="Show confirmed password" aria-pressed="false">Show</button>
                        </div>
                    </div>
                </div>

                <div class="profile-actions">
                    <a href="../dashboard.php" class="profile-cancel">Cancel</a>
                    <button type="submit" name="update_profile" class="profile-save">Save administrator profile <span aria-hidden="true">&rarr;</span></button>
                </div>
            </form>
        </section>
    </div>
</main>

<script>
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        button.textContent = isHidden ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(isHidden));
    });
});
</script>

</body>
</html>
