<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();
require_once(__DIR__ . "/../includes/profile_image.php");
require_once(__DIR__ . "/../includes/dashboard_profile_update.php");

function agent_dashboard_scalar($db, $sql, $field = 'value') {
    $result = mysqli_query($db, $sql);
    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return $row[$field] ?? 0;
}

function agent_dashboard_currency($amount) {
    return '&#8377;' . number_format((float) $amount, 0);
}

function agent_dashboard_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials ?: 'AG';
}

function agent_dashboard_status_class($status) {
    $class = strtolower(trim((string) $status));
    $class = preg_replace('/[^a-z0-9]+/', '-', $class);
    return trim($class, '-');
}

$user_name = $_SESSION['user_name'] ?? 'Agent';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$dashboard_profile_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dashboard_profile_image'])) {
    $dashboard_profile_result = profile_image_upload(
        $db,
        $user_id,
        $_FILES['profile_image'] ?? null,
        $_POST['profile_image_csrf_token'] ?? ''
    );
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dashboard_profile_details'])) {
    $dashboard_profile_result = dashboard_profile_update($db, $user_id, $_POST);
}
$user_name = $_SESSION['user_name'] ?? $user_name;
$dashboard_profile_information = profile_information_for_user($db, $user_id);
$dashboard_profile_image = $dashboard_profile_information['profile_image'] ?? '';
$dashboard_profile_image_url = $dashboard_profile_image !== '' ? '../' . $dashboard_profile_image : '';
$safe_user_name = mysqli_real_escape_string($db, $user_name);
$booking_owner = "created_by = '$safe_user_name'";
$enquiry_owner = "(assigned_user = '$safe_user_name' OR created_by = '$safe_user_name')";

$total_bookings = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM bookings WHERE $booking_owner");
$today_bookings = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM bookings WHERE $booking_owner AND DATE(booking_date) = CURDATE()");
$total_enquiries = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM enquiries WHERE $enquiry_owner");
$today_enquiries = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM enquiries WHERE $enquiry_owner AND DATE(created_at) = CURDATE()");
$total_customers = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM customer_master WHERE customer_type IS NULL OR customer_type <> 'User'");
$month_customers = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM customer_master WHERE (customer_type IS NULL OR customer_type <> 'User') AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
$pending_payments = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM bookings WHERE $booking_owner AND status = 'Pending'");
$open_tasks = (int) agent_dashboard_scalar($db, "SELECT COUNT(*) AS value FROM tasks WHERE (assigned_user_id = $user_id OR assigned_user_id IS NULL) AND status <> 'Completed'");

$pipeline_order = ['New', 'Seen', 'Replied', 'Follow-up', 'Booked'];
$pipeline_counts = array_fill_keys($pipeline_order, 0);
$pipeline_result = mysqli_query($db, "SELECT status, COUNT(*) AS count FROM enquiries WHERE $enquiry_owner GROUP BY status");
if ($pipeline_result) {
    while ($row = mysqli_fetch_assoc($pipeline_result)) {
        if (array_key_exists($row['status'], $pipeline_counts)) {
            $pipeline_counts[$row['status']] = (int) $row['count'];
        }
    }
}
$pipeline_max = max(1, ...array_values($pipeline_counts));

$monthly_trend = [];
$trend_data = [];
$start_month = date('Y-m-01', strtotime("-5 months"));
$end_month = date('Y-m-t');

$trend_sql = "
    SELECT 
        DATE_FORMAT(booking_date, '%Y-%m') AS month_key,
        COUNT(*) AS bookings 
    FROM bookings 
    WHERE $booking_owner 
      AND booking_date BETWEEN '$start_month' AND '$end_month'
    GROUP BY month_key
";
$trend_res = mysqli_query($db, $trend_sql);
if ($trend_res) {
    while ($row = mysqli_fetch_assoc($trend_res)) {
        $trend_data[$row['month_key']] = $row;
    }
}

for ($i = 5; $i >= 0; $i--) {
    $month_key = date('Y-m', strtotime("-$i months"));
    $month_label = date('M', strtotime($month_key . '-01'));
    $row = $trend_data[$month_key] ?? ['bookings' => 0];
    $monthly_trend[] = [
        'label' => $month_label,
        'bookings' => (int) ($row['bookings'] ?? 0),
    ];
}
$max_month_bookings = max(1, ...array_column($monthly_trend, 'bookings'));

$recent_enquiries = mysqli_query($db, "SELECT id, customer_name, email, mobile, source, status FROM enquiries WHERE $enquiry_owner ORDER BY updated_at DESC, id DESC LIMIT 5");
$recent_bookings = mysqli_query($db, "SELECT id, passenger_name, customer_name, pnr, service_type, selling_cost, status, booking_date FROM bookings WHERE $booking_owner ORDER BY id DESC LIMIT 5");
$assigned_tasks = mysqli_query($db, "SELECT id, title, status, created_at FROM tasks WHERE assigned_user_id = $user_id OR assigned_user_id IS NULL ORDER BY CASE WHEN status = 'Completed' THEN 1 ELSE 0 END, id DESC LIMIT 5");

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
    <script src="../assets/js/dashboard-profile.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<main class="main crm-dashboard">
    <header class="crm-topbar">
        <div>
            <p class="crm-eyebrow">My workspace</p>
            <h1><?php echo htmlspecialchars($greeting . ', ' . $user_name); ?></h1>
            <p class="crm-subtitle">A simple view of your bookings, leads and follow-ups.</p>
        </div>
        <div class="crm-topbar-actions">
            <span class="crm-date"><?php echo date('D, d M Y'); ?></span>
            <a href="enquiry/add.php?return_to=dashboard" class="crm-button crm-button-secondary">+ New enquiry</a>
            <a href="bookings/add.php?return_to=dashboard" class="crm-button crm-button-primary">+ New booking</a>
            <form method="POST" enctype="multipart/form-data" class="crm-dashboard-profile-form" data-dashboard-profile>
                <input type="hidden" name="update_dashboard_profile_image" value="1">
                <input type="hidden" name="profile_image_csrf_token" value="<?php echo htmlspecialchars(profile_image_csrf_token()); ?>">
                <input type="file" name="profile_image" id="user-dashboard-profile-image" accept="image/jpeg,image/png,image/webp" hidden data-profile-image-input>
                <button type="button" class="crm-avatar crm-profile-image-button" aria-label="Open profile information" title="Profile information" aria-expanded="false" aria-controls="user-dashboard-profile-panel" data-profile-toggle>
                    <?php if ($dashboard_profile_image_url !== ''): ?>
                        <img src="<?php echo htmlspecialchars($dashboard_profile_image_url); ?>" alt="" class="crm-avatar-image">
                    <?php else: ?>
                        <span class="crm-avatar-initials"><?php echo htmlspecialchars(agent_dashboard_initials($user_name)); ?></span>
                    <?php endif; ?>
                </button>
                <section class="crm-profile-popover" id="user-dashboard-profile-panel" aria-label="Profile information" data-profile-panel hidden>
                    <div class="crm-profile-popover-head">
                        <div class="crm-profile-popover-avatar" data-profile-preview>
                            <?php if ($dashboard_profile_image_url !== ''): ?>
                                <img src="<?php echo htmlspecialchars($dashboard_profile_image_url); ?>" alt="">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars(agent_dashboard_initials($user_name)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars($dashboard_profile_information['name'] ?? $user_name); ?></strong>
                            <span><?php echo htmlspecialchars(ucfirst((string) ($dashboard_profile_information['role'] ?? 'user'))); ?> account</span>
                        </div>
                    </div>
                    <label for="user-dashboard-profile-image" class="crm-profile-photo-action">Change personal photo</label>
                    <p class="crm-profile-upload-status" data-profile-upload-status>JPG, PNG or WebP · maximum 5MB</p>
                    <dl class="crm-profile-information-list">
                        <div><dt>Email</dt><dd><?php echo htmlspecialchars($dashboard_profile_information['email'] ?? ($_SESSION['user_email'] ?? 'Not set')); ?></dd></div>
                        <div><dt>Phone</dt><dd><?php echo htmlspecialchars(($dashboard_profile_information['phone'] ?? '') ?: 'Not set'); ?></dd></div>
                        <div><dt>Location</dt><dd><?php
                            $user_location = array_filter([
                                trim((string) ($dashboard_profile_information['city'] ?? '')),
                                trim((string) ($dashboard_profile_information['country'] ?? '')),
                            ]);
                            echo htmlspecialchars($user_location ? implode(', ', $user_location) : 'Not set');
                        ?></dd></div>
                    </dl>
                    <button type="button" class="crm-profile-edit-action" aria-controls="user-dashboard-profile-edit" data-profile-edit-open>Edit details &amp; password</button>
                </section>
            </form>
            <?php
            $profile_edit_prefix = 'user';
            include __DIR__ . '/../includes/dashboard_profile_edit_modal.php';
            ?>
        </div>
    </header>

    <?php if ($dashboard_profile_result): ?>
        <div class="crm-dashboard-profile-alert <?php echo $dashboard_profile_result['success'] ? 'is-success' : 'is-error'; ?>" role="alert">
            <?php echo htmlspecialchars($dashboard_profile_result['message']); ?>
        </div>
    <?php endif; ?>

    <section class="crm-metrics" aria-label="Agent summary">
        <article class="crm-metric-card metric-customers">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 18-5-7 15-3-7-8-3Z"/><path d="m11 14 4-4"/></svg>
                </span>
                <span class="crm-metric-kicker">My bookings</span>
            </div>
            <strong><?php echo number_format($total_bookings); ?></strong>
            <p><span><?php echo number_format($today_bookings); ?></span> created today</p>
        </article>

        <article class="crm-metric-card metric-enquiries">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/></svg>
                </span>
                <span class="crm-metric-kicker">My enquiries</span>
            </div>
            <strong><?php echo number_format($total_enquiries); ?></strong>
            <p><span><?php echo number_format($today_enquiries); ?></span> added today</p>
        </article>

        <article class="crm-metric-card metric-bookings">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <span class="crm-metric-kicker">Total customers</span>
            </div>
            <strong><?php echo number_format($total_customers); ?></strong>
            <p><span><?php echo number_format($month_customers); ?></span> added this month</p>
        </article>

        <article class="crm-metric-card metric-revenue">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M12 6v6l4 2"/></svg>
                </span>
                <span class="crm-metric-kicker">Pending actions</span>
            </div>
            <strong><?php echo number_format($pending_payments + $open_tasks); ?></strong>
            <p><span><?php echo number_format($pending_payments); ?></span> payments &middot; <?php echo number_format($open_tasks); ?> tasks</p>
        </article>
    </section>

    <section class="crm-primary-grid">
        <article class="crm-panel crm-performance-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Last six months</p>
                    <h2>My booking activity</h2>
                </div>
                <a href="bookings/list.php">My bookings <span>&rarr;</span></a>
            </div>
            <div class="crm-chart-summary">
                <strong><?php echo number_format(array_sum(array_column($monthly_trend, 'bookings'))); ?></strong>
                <span>total bookings</span>
            </div>
            <div class="crm-bar-chart" aria-label="Monthly booking activity chart">
                <?php foreach ($monthly_trend as $month): ?>
                    <?php $height = max(4, round(($month['bookings'] / $max_month_bookings) * 100)); ?>
                    <div class="crm-bar-column" title="<?php echo htmlspecialchars($month['label'] . ': ' . $month['bookings'] . ' booking' . ($month['bookings'] === 1 ? '' : 's')); ?>">
                        <span class="crm-bar-value"><?php echo $month['bookings']; ?></span>
                        <div class="crm-bar-track"><span style="height: <?php echo $height; ?>%"></span></div>
                        <small><?php echo htmlspecialchars($month['label']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="crm-chart-note">Numbers above each bar show your booking volume.</p>
        </article>

        <article class="crm-panel crm-pipeline-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Lead progress</p>
                    <h2>My enquiry pipeline</h2>
                </div>
                <a href="enquiry/list.php">View enquiries <span>&rarr;</span></a>
            </div>
            <div class="crm-pipeline">
                <?php foreach ($pipeline_counts as $status => $count): ?>
                    <?php $width = max(3, round(($count / $pipeline_max) * 100)); ?>
                    <div class="crm-pipeline-row">
                        <div class="crm-pipeline-label">
                            <span class="pipeline-dot status-<?php echo agent_dashboard_status_class($status); ?>"></span>
                            <span><?php echo htmlspecialchars($status); ?></span>
                        </div>
                        <div class="crm-pipeline-track"><span style="width: <?php echo $width; ?>%"></span></div>
                        <strong><?php echo number_format($count); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="crm-pipeline-footer">
                <div><strong><?php echo number_format(array_sum($pipeline_counts)); ?></strong><span>my enquiries</span></div>
                <div><strong><?php echo number_format($pipeline_counts['Follow-up']); ?></strong><span>need follow-up</span></div>
                <div><strong><?php echo number_format($pipeline_counts['Booked']); ?></strong><span>converted</span></div>
            </div>
        </article>
    </section>

    <section class="crm-secondary-grid">
        <article class="crm-panel crm-contacts-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Latest contacts</p>
                    <h2>My recent enquiries</h2>
                </div>
                <a href="enquiry/add.php?return_to=dashboard">Add enquiry <span>+</span></a>
            </div>
            <div class="crm-contact-list">
                <?php if ($recent_enquiries && mysqli_num_rows($recent_enquiries) > 0): ?>
                    <?php while ($enquiry = mysqli_fetch_assoc($recent_enquiries)): ?>
                        <a class="crm-contact-row" href="enquiry/view.php?id=<?php echo (int) $enquiry['id']; ?>">
                            <span class="crm-contact-avatar"><?php echo htmlspecialchars(agent_dashboard_initials($enquiry['customer_name'])); ?></span>
                            <span class="crm-contact-main">
                                <strong><?php echo htmlspecialchars($enquiry['customer_name']); ?></strong>
                                <small><?php echo htmlspecialchars($enquiry['email'] ?: $enquiry['mobile'] ?: 'No contact details'); ?></small>
                            </span>
                            <span class="crm-source"><?php echo htmlspecialchars($enquiry['source'] ?: 'Direct'); ?></span>
                            <span class="crm-status status-<?php echo agent_dashboard_status_class($enquiry['status']); ?>"><?php echo htmlspecialchars($enquiry['status']); ?></span>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="crm-empty">No enquiries assigned to you yet.</div>
                <?php endif; ?>
            </div>
        </article>

        <div class="crm-side-stack">
            <article class="crm-panel crm-quick-panel">
                <div class="crm-panel-header">
                    <div>
                        <p class="crm-panel-kicker">Shortcuts</p>
                        <h2>Quick Actions</h2>
                    </div>
                </div>
                <div class="crm-quick-grid">
                    <a href="customers/add.php?return_to=dashboard" aria-label="Add customer"><span>+</span><strong>Add Customer</strong><small>Create a customer record</small></a>
                    <a href="enquiry/add.php?return_to=dashboard" aria-label="Add enquiry"><span>+</span><strong>Add Enquiry</strong><small>Capture a new lead</small></a>
                    <a href="bookings/add.php?return_to=dashboard" aria-label="Add booking"><span>+</span><strong>Add Booking</strong><small>Record a new booking</small></a>
                    <a href="tasks/index.php" aria-label="Open my tasks"><span>&rarr;</span><strong>My Tasks</strong><small>Review your follow-ups</small></a>
                </div>
            </article>

            <article class="crm-panel crm-tasks-panel">
                <div class="crm-panel-header">
                    <div>
                        <p class="crm-panel-kicker"><?php echo number_format($open_tasks); ?> open</p>
                        <h2>My assigned tasks</h2>
                    </div>
                    <a href="tasks/index.php">View all <span>&rarr;</span></a>
                </div>
                <div class="crm-task-list">
                    <?php if ($assigned_tasks && mysqli_num_rows($assigned_tasks) > 0): ?>
                        <?php while ($task = mysqli_fetch_assoc($assigned_tasks)): ?>
                            <a href="tasks/view.php?id=<?php echo (int) $task['id']; ?>" class="crm-task-row">
                                <span class="crm-task-check <?php echo strtolower($task['status']) === 'completed' ? 'is-complete' : ''; ?>"></span>
                                <span><strong><?php echo htmlspecialchars($task['title']); ?></strong><small><?php echo !empty($task['created_at']) ? date('d M Y', strtotime($task['created_at'])) : 'No date'; ?></small></span>
                                <em><?php echo htmlspecialchars($task['status']); ?></em>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="crm-empty">No tasks assigned to you.</div>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </section>

    <section class="crm-bottom-grid" style="grid-template-columns: minmax(0, 1fr);">
        <article class="crm-panel crm-bookings-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Latest sales</p>
                    <h2>My recent bookings</h2>
                </div>
                <a href="bookings/list.php">All bookings <span>&rarr;</span></a>
            </div>
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>Passenger / Customer</th><th>Service</th><th>PNR</th><th>Sale value</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($recent_bookings && mysqli_num_rows($recent_bookings) > 0): ?>
                        <?php while ($booking = mysqli_fetch_assoc($recent_bookings)): ?>
                            <tr onclick="window.location='bookings/view.php?id=<?php echo (int) $booking['id']; ?>'">
                                <td><strong><?php echo htmlspecialchars($booking['passenger_name'] ?: $booking['customer_name']); ?></strong><small><?php echo !empty($booking['booking_date']) ? date('d M Y', strtotime($booking['booking_date'])) : 'No date'; ?></small></td>
                                <td><?php echo htmlspecialchars($booking['service_type'] ?: 'Travel'); ?></td>
                                <td><code><?php echo $booking['pnr'] ? htmlspecialchars($booking['pnr']) : '&mdash;'; ?></code></td>
                                <td><?php echo agent_dashboard_currency($booking['selling_cost']); ?></td>
                                <td><span class="crm-status status-<?php echo agent_dashboard_status_class($booking['status']); ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="crm-empty">No bookings recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</main>

</body>
</html>
