<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth('admin');

function dashboard_scalar($db, $sql, $field = 'value') {
    $result = mysqli_query($db, $sql);
    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return $row[$field] ?? 0;
}

function dashboard_currency($amount) {
    return '&#8377;' . number_format((float) $amount, 0);
}

function dashboard_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials ?: 'NA';
}

$total_customers = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM customer_master");
$active_customers = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM customer_master WHERE status = 'Active' OR status IS NULL OR status = ''");
$open_enquiries = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM enquiries WHERE status NOT IN ('Booked', 'Converted', 'Cancelled')");
$new_enquiries_week = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM enquiries WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$total_bookings = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM bookings");
$month_bookings = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM bookings WHERE YEAR(booking_date) = YEAR(CURDATE()) AND MONTH(booking_date) = MONTH(CURDATE())");
$total_profit = (float) dashboard_scalar($db, "SELECT COALESCE(SUM(profit), 0) AS value FROM bookings");
$month_profit = (float) dashboard_scalar($db, "SELECT COALESCE(SUM(profit), 0) AS value FROM bookings WHERE YEAR(booking_date) = YEAR(CURDATE()) AND MONTH(booking_date) = MONTH(CURDATE())");
$open_tasks = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM tasks WHERE status <> 'Completed'");
$confirmed_bookings = (int) dashboard_scalar($db, "SELECT COUNT(*) AS value FROM bookings WHERE status IN ('Booked', 'Confirmed', 'Completed')");
$booking_rate = $total_bookings > 0 ? round(($confirmed_bookings / $total_bookings) * 100) : 0;

$pipeline_order = ['New', 'Seen', 'Replied', 'Follow-up', 'Booked'];
$pipeline_counts = array_fill_keys($pipeline_order, 0);
$pipeline_result = mysqli_query($db, "SELECT status, COUNT(*) AS count FROM enquiries GROUP BY status");
if ($pipeline_result) {
    while ($row = mysqli_fetch_assoc($pipeline_result)) {
        if (array_key_exists($row['status'], $pipeline_counts)) {
            $pipeline_counts[$row['status']] = (int) $row['count'];
        }
    }
}
$pipeline_max = max(1, ...array_values($pipeline_counts));

$monthly_trend = [];
for ($i = 5; $i >= 0; $i--) {
    $month_key = date('Y-m', strtotime("-$i months"));
    $month_label = date('M', strtotime($month_key . '-01'));
    $month_key_safe = mysqli_real_escape_string($db, $month_key);
    $result = mysqli_query($db, "SELECT COALESCE(SUM(profit), 0) AS profit, COUNT(*) AS bookings FROM bookings WHERE DATE_FORMAT(booking_date, '%Y-%m') = '$month_key_safe'");
    $row = $result ? mysqli_fetch_assoc($result) : ['profit' => 0, 'bookings' => 0];
    $monthly_trend[] = [
        'label' => $month_label,
        'profit' => (float) ($row['profit'] ?? 0),
        'bookings' => (int) ($row['bookings'] ?? 0),
    ];
}
$max_month_profit = max(1, ...array_column($monthly_trend, 'profit'));

$recent_enquiries = mysqli_query($db, "SELECT id, customer_name, email, mobile, source, status, created_at FROM enquiries ORDER BY updated_at DESC, id DESC LIMIT 5");
$recent_bookings = mysqli_query($db, "SELECT id, passenger_name, customer_name, pnr, service_type, selling_cost, profit, status, booking_date FROM bookings ORDER BY id DESC LIMIT 5");
$recent_tasks = mysqli_query($db, "SELECT tasks.id, tasks.title, tasks.status, tasks.created_at, users.name AS assigned_name FROM tasks LEFT JOIN users ON users.id = tasks.assigned_user_id ORDER BY tasks.id DESC LIMIT 4");
$recent_activity = mysqli_query($db, "SELECT username, action, module, activity_date FROM activity_log ORDER BY id DESC LIMIT 5");

$admin_name = $_SESSION['user_name'] ?? 'Admin';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Dashboard | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/premium-dashboard.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<main class="main crm-dashboard">
    <header class="crm-topbar">
        <div>
            <p class="crm-eyebrow">Workspace overview</p>
            <h1><?php echo htmlspecialchars($greeting . ', ' . $admin_name); ?></h1>
            <p class="crm-subtitle">Here is what needs your attention today.</p>
        </div>
        <div class="crm-topbar-actions">
            <span class="crm-date"><?php echo date('D, d M Y'); ?></span>
            <a href="enquiry/add.php" class="crm-button crm-button-secondary">+ New enquiry</a>
            <a href="bookings/add.php" class="crm-button crm-button-primary">+ New booking</a>
            <a href="profile/index.php" class="crm-avatar" aria-label="Open profile"><?php echo htmlspecialchars(dashboard_initials($admin_name)); ?></a>
        </div>
    </header>

    <section class="crm-metrics" aria-label="CRM summary">
        <article class="crm-metric-card metric-customers">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <span class="crm-metric-kicker">Customers</span>
            </div>
            <strong><?php echo number_format($total_customers); ?></strong>
            <p><span><?php echo number_format($active_customers); ?></span> active customer records</p>
        </article>

        <article class="crm-metric-card metric-enquiries">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/></svg>
                </span>
                <span class="crm-metric-kicker">Open enquiries</span>
            </div>
            <strong><?php echo number_format($open_enquiries); ?></strong>
            <p><span><?php echo number_format($new_enquiries_week); ?></span> received in the last 7 days</p>
        </article>

        <article class="crm-metric-card metric-bookings">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 18-5-7 15-3-7-8-3Z"/><path d="m11 14 4-4"/></svg>
                </span>
                <span class="crm-metric-kicker">Total bookings</span>
            </div>
            <strong><?php echo number_format($total_bookings); ?></strong>
            <p><span><?php echo number_format($month_bookings); ?></span> this month &middot; <?php echo $booking_rate; ?>% booked</p>
        </article>

        <article class="crm-metric-card metric-revenue">
            <div class="crm-metric-head">
                <span class="crm-icon-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9M6 6h12M8 2c6 0 6 8 0 8h-2l10 12"/></svg>
                </span>
                <span class="crm-metric-kicker">Total profit</span>
            </div>
            <strong><?php echo dashboard_currency($total_profit); ?></strong>
            <p><span><?php echo dashboard_currency($month_profit); ?></span> earned this month</p>
        </article>
    </section>

    <section class="crm-primary-grid">
        <article class="crm-panel crm-pipeline-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Sales workflow</p>
                    <h2>Enquiry pipeline</h2>
                </div>
                <a href="enquiry/list.php">View enquiries <span>&rarr;</span></a>
            </div>
            <div class="crm-pipeline">
                <?php foreach ($pipeline_counts as $status => $count): ?>
                    <?php $width = max(3, round(($count / $pipeline_max) * 100)); ?>
                    <div class="crm-pipeline-row">
                        <div class="crm-pipeline-label">
                            <span class="pipeline-dot status-<?php echo strtolower(str_replace(' ', '-', $status)); ?>"></span>
                            <span><?php echo htmlspecialchars($status); ?></span>
                        </div>
                        <div class="crm-pipeline-track"><span style="width: <?php echo $width; ?>%"></span></div>
                        <strong><?php echo number_format($count); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="crm-pipeline-footer">
                <div><strong><?php echo number_format(array_sum($pipeline_counts)); ?></strong><span>tracked enquiries</span></div>
                <div><strong><?php echo number_format($pipeline_counts['Follow-up']); ?></strong><span>need follow-up</span></div>
                <div><strong><?php echo number_format($pipeline_counts['Booked']); ?></strong><span>converted to booking</span></div>
            </div>
        </article>

        <article class="crm-panel crm-performance-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Last six months</p>
                    <h2>Profit performance</h2>
                </div>
                <a href="bookings/reports.php">Reports <span>&rarr;</span></a>
            </div>
            <div class="crm-chart-summary">
                <strong><?php echo dashboard_currency(array_sum(array_column($monthly_trend, 'profit'))); ?></strong>
                <span>total profit</span>
            </div>
            <div class="crm-bar-chart" aria-label="Monthly profit chart">
                <?php foreach ($monthly_trend as $month): ?>
                    <?php $height = max(4, round(($month['profit'] / $max_month_profit) * 100)); ?>
                    <div class="crm-bar-column" title="<?php echo htmlspecialchars($month['label'] . ': ' . html_entity_decode(dashboard_currency($month['profit']))); ?>">
                        <span class="crm-bar-value"><?php echo $month['bookings']; ?></span>
                        <div class="crm-bar-track"><span style="height: <?php echo $height; ?>%"></span></div>
                        <small><?php echo htmlspecialchars($month['label']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="crm-chart-note">Numbers above each bar show booking volume.</p>
        </article>
    </section>

    <section class="crm-secondary-grid">
        <article class="crm-panel crm-contacts-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Latest contacts</p>
                    <h2>Recent enquiries</h2>
                </div>
                <a href="enquiry/add.php">Add enquiry <span>+</span></a>
            </div>
            <div class="crm-contact-list">
                <?php if ($recent_enquiries && mysqli_num_rows($recent_enquiries) > 0): ?>
                    <?php while ($enquiry = mysqli_fetch_assoc($recent_enquiries)): ?>
                        <a class="crm-contact-row" href="enquiry/view.php?id=<?php echo (int) $enquiry['id']; ?>">
                            <span class="crm-contact-avatar"><?php echo htmlspecialchars(dashboard_initials($enquiry['customer_name'])); ?></span>
                            <span class="crm-contact-main">
                                <strong><?php echo htmlspecialchars($enquiry['customer_name']); ?></strong>
                                <small><?php echo htmlspecialchars($enquiry['email'] ?: $enquiry['mobile'] ?: 'No contact details'); ?></small>
                            </span>
                            <span class="crm-source"><?php echo htmlspecialchars($enquiry['source'] ?: 'Direct'); ?></span>
                            <span class="crm-status status-<?php echo strtolower(str_replace(' ', '-', $enquiry['status'])); ?>"><?php echo htmlspecialchars($enquiry['status']); ?></span>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="crm-empty">No enquiries recorded yet.</div>
                <?php endif; ?>
            </div>
        </article>

        <div class="crm-side-stack">
            <article class="crm-panel crm-quick-panel">
                <div class="crm-panel-header">
                    <div>
                        <p class="crm-panel-kicker">Shortcuts</p>
                        <h2>Quick actions</h2>
                    </div>
                </div>
                <div class="crm-quick-grid">
                    <a href="master/add.php"><span>+</span><strong>Add customer</strong><small>Create a CRM record</small></a>
                    <a href="enquiry/add.php"><span>+</span><strong>Log enquiry</strong><small>Capture a new lead</small></a>
                    <a href="bookings/add.php"><span>+</span><strong>Add booking</strong><small>Record a sale</small></a>
                    <a href="tasks/add.php"><span>+</span><strong>Create task</strong><small>Assign follow-up</small></a>
                </div>
            </article>

            <article class="crm-panel crm-tasks-panel">
                <div class="crm-panel-header">
                    <div>
                        <p class="crm-panel-kicker"><?php echo $open_tasks; ?> open</p>
                        <h2>Team tasks</h2>
                    </div>
                    <a href="tasks/index.php">View all <span>&rarr;</span></a>
                </div>
                <div class="crm-task-list">
                    <?php if ($recent_tasks && mysqli_num_rows($recent_tasks) > 0): ?>
                        <?php while ($task = mysqli_fetch_assoc($recent_tasks)): ?>
                            <a href="tasks/view.php?id=<?php echo (int) $task['id']; ?>" class="crm-task-row">
                                <span class="crm-task-check <?php echo strtolower($task['status']) === 'completed' ? 'is-complete' : ''; ?>"></span>
                                <span><strong><?php echo htmlspecialchars($task['title']); ?></strong><small><?php echo htmlspecialchars($task['assigned_name'] ?: 'Unassigned'); ?></small></span>
                                <em><?php echo htmlspecialchars($task['status']); ?></em>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="crm-empty">No team tasks yet.</div>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </section>

    <section class="crm-bottom-grid">
        <article class="crm-panel crm-bookings-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Latest sales</p>
                    <h2>Recent bookings</h2>
                </div>
                <a href="bookings/list.php">All bookings <span>&rarr;</span></a>
            </div>
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>Passenger / Customer</th><th>Service</th><th>PNR</th><th>Sale value</th><th>Profit</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($recent_bookings && mysqli_num_rows($recent_bookings) > 0): ?>
                        <?php while ($booking = mysqli_fetch_assoc($recent_bookings)): ?>
                            <tr onclick="window.location='bookings/view.php?id=<?php echo (int) $booking['id']; ?>'">
                                <td><strong><?php echo htmlspecialchars($booking['passenger_name'] ?: $booking['customer_name']); ?></strong><small><?php echo date('d M Y', strtotime($booking['booking_date'])); ?></small></td>
                                <td><?php echo htmlspecialchars($booking['service_type'] ?: 'Travel'); ?></td>
                                <td><code><?php echo $booking['pnr'] ? htmlspecialchars($booking['pnr']) : '&mdash;'; ?></code></td>
                                <td><?php echo dashboard_currency($booking['selling_cost']); ?></td>
                                <td class="crm-profit"><?php echo dashboard_currency($booking['profit']); ?></td>
                                <td><span class="crm-status status-<?php echo strtolower(str_replace(' ', '-', $booking['status'])); ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="crm-empty">No bookings recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="crm-panel crm-activity-panel">
            <div class="crm-panel-header">
                <div>
                    <p class="crm-panel-kicker">Live workspace</p>
                    <h2>Recent activity</h2>
                </div>
                <a href="admin/activity.php">View all <span>&rarr;</span></a>
            </div>
            <div class="crm-activity-list">
                <?php if ($recent_activity && mysqli_num_rows($recent_activity) > 0): ?>
                    <?php while ($activity = mysqli_fetch_assoc($recent_activity)): ?>
                        <div class="crm-activity-row">
                            <span class="crm-activity-dot"></span>
                            <div><strong><?php echo htmlspecialchars($activity['username']); ?></strong><p><?php echo htmlspecialchars($activity['action']); ?> <span><?php echo htmlspecialchars($activity['module']); ?></span></p><small><?php echo date('d M, h:i A', strtotime($activity['activity_date'])); ?></small></div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="crm-empty">No activity recorded yet.</div>
                <?php endif; ?>
            </div>
        </article>
    </section>
</main>

</body>
</html>
