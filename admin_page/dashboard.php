<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth('admin');

// Fetch metrics dynamically
$today_bookings_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings WHERE DATE(booking_date) = CURDATE()");
$today_bookings = mysqli_fetch_assoc($today_bookings_query)['count'];

$today_profit_query = mysqli_query($db, "SELECT SUM(profit) as profit FROM bookings WHERE DATE(booking_date) = CURDATE()");
$today_profit = mysqli_fetch_assoc($today_profit_query)['profit'] ?: 0;

$month_profit_query = mysqli_query($db, "SELECT SUM(profit) as profit FROM bookings WHERE MONTH(booking_date) = MONTH(CURDATE()) AND YEAR(booking_date) = YEAR(CURDATE())");
$month_profit = mysqli_fetch_assoc($month_profit_query)['profit'] ?: 0;

$pending_payments_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings WHERE status = 'Pending'");
$pending_payments = mysqli_fetch_assoc($pending_payments_query)['count'];

// Premium Metrics
$total_bookings_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings");
$total_bookings = mysqli_fetch_assoc($total_bookings_query)['count'];

$total_profit_query = mysqli_query($db, "SELECT SUM(profit) as profit FROM bookings");
$total_profit = mysqli_fetch_assoc($total_profit_query)['profit'] ?: 0;

$confirmed_bookings_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings WHERE status = 'Confirmed'");
$confirmed_bookings = mysqli_fetch_assoc($confirmed_bookings_query)['count'];

$cancelled_bookings_query = mysqli_query($db, "SELECT COUNT(*) as count FROM bookings WHERE status = 'Cancelled'");
$cancelled_bookings = mysqli_fetch_assoc($cancelled_bookings_query)['count'];

// Monthly revenue trend (last 6 months)
$monthly_trend = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $query = mysqli_query($db, "SELECT SUM(profit) as profit, COUNT(*) as count FROM bookings WHERE DATE_FORMAT(booking_date, '%Y-%m') = '$month'");
    $result = mysqli_fetch_assoc($query);
    $monthly_trend[] = [
        'month' => date('M Y', strtotime("$month-01")),
        'profit' => $result['profit'] ?: 0,
        'count' => $result['count'] ?: 0
    ];
}

// Service type distribution
$service_distribution_query = mysqli_query($db, "SELECT service_type, COUNT(*) as count FROM bookings WHERE service_type != '' GROUP BY service_type ORDER BY count DESC LIMIT 5");
$service_distribution = [];
while ($row = mysqli_fetch_assoc($service_distribution_query)) {
    $service_distribution[] = $row;
}

// Fetch Top Users
$top_users_query = mysqli_query($db, "SELECT assigned_user, COUNT(*) as count FROM bookings WHERE assigned_user != '' GROUP BY assigned_user ORDER BY count DESC LIMIT 5");

// Fetch Recent Bookings
$recent_bookings_query = mysqli_query($db, "SELECT * FROM bookings ORDER BY id DESC LIMIT 6");

// Fetch Recent Audits
$audit_log_query = mysqli_query($db, "SELECT * FROM activity_log ORDER BY id DESC LIMIT 5");

// Calculate conversion rate
$conversion_rate = $total_bookings > 0 ? round(($confirmed_bookings / $total_bookings) * 100, 1) : 0;

// Calculate average profit per booking
$avg_profit = $total_bookings > 0 ? round($total_profit / $total_bookings, 2) : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Travel CRM Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/premium-dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="master/list.php">Master</a>
    <a href="bookings/list.php">Bookings</a>
    <a href="bookings/add.php">Add Booking</a>
    <a href="bookings/reports.php">Reports</a>
    <a href="enquiry/list.php"<?= (strpos($_SERVER['PHP_SELF'], '/enquiry/') !== false) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="tasks/index.php">Tasks</a>
    <a href="admin/activity.php">Activity</a>
    <a href="../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search passenger, PNR, supplier...">
        <span class="notify">🔔</span>
        <a href="profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Dashboard Overview</h1>
        <div class="date-range">
            <span class="current-date"><?php echo date('F d, Y'); ?></span>
        </div>
    </div>

    <!-- Premium Stats Row -->
    <div class="premium-cards-row">
        <div class="premium-card gradient-blue">
            <div class="premium-card-icon">📊</div>
            <div class="premium-card-content">
                <h3>Total Bookings</h3>
                <h1><?php echo $total_bookings; ?></h1>
                <span class="premium-change green">All time records</span>
            </div>
            <div class="premium-card-sparkline" data-sparkline="bookings"></div>
        </div>

        <div class="premium-card gradient-green">
            <div class="premium-card-icon">💰</div>
            <div class="premium-card-content">
                <h3>Total Revenue</h3>
                <h1>₹<?php echo number_format($total_profit, 2); ?></h1>
                <span class="premium-change green">Lifetime profit</span>
            </div>
            <div class="premium-card-sparkline" data-sparkline="revenue"></div>
        </div>

        <div class="premium-card gradient-purple">
            <div class="premium-card-icon">✅</div>
            <div class="premium-card-content">
                <h3>Confirmed</h3>
                <h1><?php echo $confirmed_bookings; ?></h1>
                <span class="premium-change green"><?php echo $conversion_rate; ?>% conversion</span>
            </div>
            <div class="premium-card-sparkline" data-sparkline="confirmed"></div>
        </div>

        <div class="premium-card gradient-orange">
            <div class="premium-card-icon">⏳</div>
            <div class="premium-card-content">
                <h3>Pending</h3>
                <h1><?php echo $pending_payments; ?></h1>
                <span class="premium-change orange">Action required</span>
            </div>
            <div class="premium-card-sparkline" data-sparkline="pending"></div>
        </div>
    </div>

    <!-- Secondary Stats Row -->
    <div class="secondary-cards-row">
        <div class="secondary-card">
            <div class="secondary-card-header">
                <span class="secondary-icon">📈</span>
                <span class="secondary-label">Avg. Profit/Booking</span>
            </div>
            <div class="secondary-card-value">₹<?php echo number_format($avg_profit, 2); ?></div>
        </div>

        <div class="secondary-card">
            <div class="secondary-card-header">
                <span class="secondary-icon">🎯</span>
                <span class="secondary-label">Today's Bookings</span>
            </div>
            <div class="secondary-card-value"><?php echo $today_bookings; ?></div>
        </div>

        <div class="secondary-card">
            <div class="secondary-card-header">
                <span class="secondary-icon">💵</span>
                <span class="secondary-label">Today's Profit</span>
            </div>
            <div class="secondary-card-value">₹<?php echo number_format($today_profit, 2); ?></div>
        </div>

        <div class="secondary-card">
            <div class="secondary-card-header">
                <span class="secondary-icon">📅</span>
                <span class="secondary-label">Monthly Profit</span>
            </div>
            <div class="secondary-card-value">₹<?php echo number_format($month_profit, 2); ?></div>
        </div>

        <div class="secondary-card">
            <div class="secondary-card-header">
                <span class="secondary-icon">❌</span>
                <span class="secondary-label">Cancelled</span>
            </div>
            <div class="secondary-card-value"><?php echo $cancelled_bookings; ?></div>
        </div>
    </div>

    <br>

    <!-- Premium Charts Section -->
    <div class="premium-charts-grid">
        <!-- Revenue Trend Chart -->
        <div class="premium-chart-card">
            <div class="premium-chart-header">
                <h2>Revenue Trend (6 Months)</h2>
                <div class="chart-legend">
                    <span class="legend-item"><span class="legend-dot blue"></span>Revenue</span>
                    <span class="legend-item"><span class="legend-dot green"></span>Bookings</span>
                </div>
            </div>
            <div class="premium-chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Service Distribution Chart -->
        <div class="premium-chart-card">
            <div class="premium-chart-header">
                <h2>Service Distribution</h2>
            </div>
            <div class="premium-chart-container">
                <canvas id="serviceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Premium Bottom Grid -->
    <div class="premium-bottom-grid">
        <!-- Recent Bookings Table -->
        <div class="premium-table-card">
            <div class="premium-table-header">
                <h2>Recent Bookings Activity</h2>
                <a href="bookings/list.php" class="btn-premium-view">View All</a>
            </div>
            <div class="premium-table-container">
                <table class="premium-table">
                    <thead>
                        <tr>
                            <th>Passenger</th>
                            <th>PNR</th>
                            <th>Service</th>
                            <th>Profit</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($recent_bookings_query) > 0): ?>
                            <?php while ($booking = mysqli_fetch_assoc($recent_bookings_query)): ?>
                            <tr>
                                <td class="passenger-cell">
                                    <div class="passenger-info">
                                        <span class="passenger-name"><?php echo htmlspecialchars($booking["passenger_name"]); ?></span>
                                    </div>
                                </td>
                                <td class="pnr-cell"><?php echo htmlspecialchars($booking["pnr"]); ?></td>
                                <td class="service-cell"><?php echo htmlspecialchars($booking["service_type"]); ?></td>
                                <td class="profit-cell">₹<?php echo number_format($booking["profit"], 2); ?></td>
                                <td class="status-cell">
                                    <span class="premium-badge <?php echo strtolower($booking["status"]); ?>">
                                        <?php echo htmlspecialchars($booking["status"]); ?>
                                    </span>
                                </td>
                                <td class="action-cell">
                                    <a href="bookings/view.php?id=<?php echo $booking['id']; ?>" class="btn-premium-action">View</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <div class="empty-icon">📋</div>
                                    <p>No bookings recorded yet</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Agents & Activity -->
        <div class="premium-side-column">
            <!-- Top Agents -->
            <div class="premium-side-card">
                <div class="premium-side-header">
                    <h2>Top Agents</h2>
                </div>
                <div class="premium-agents-list">
                    <?php if (mysqli_num_rows($top_users_query) > 0): ?>
                        <?php $rank = 1; while ($row = mysqli_fetch_assoc($top_users_query)): ?>
                        <div class="agent-item">
                            <div class="agent-rank rank-<?php echo $rank; ?>"><?php echo $rank; ?></div>
                            <div class="agent-info">
                                <span class="agent-name"><?php echo htmlspecialchars($row["assigned_user"]); ?></span>
                                <span class="agent-count"><?php echo $row["count"]; ?> bookings</span>
                            </div>
                            <div class="agent-bar">
                                <div class="agent-bar-fill" style="width: <?php echo ($row["count"] / mysqli_num_rows($top_users_query)) * 100; ?>%"></div>
                            </div>
                        </div>
                        <?php $rank++; endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-small">
                            <p>No agent records</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Audit Log -->
            <div class="premium-side-card">
                <div class="premium-side-header">
                    <h2>Recent Activity</h2>
                    <a href="admin/activity.php" class="btn-premium-small">View All</a>
                </div>
                <div class="premium-activity-list">
                    <?php if (mysqli_num_rows($audit_log_query) > 0): ?>
                        <?php while ($audit = mysqli_fetch_assoc($audit_log_query)): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <?php
                                $icon = '📝';
                                if (strpos(strtolower($audit['action']), 'create') !== false) $icon = '➕';
                                elseif (strpos(strtolower($audit['action']), 'update') !== false) $icon = '✏️';
                                elseif (strpos(strtolower($audit['action']), 'delete') !== false) $icon = '🗑️';
                                echo $icon;
                                ?>
                            </div>
                            <div class="activity-content">
                                <span class="activity-user"><?php echo htmlspecialchars($audit["username"]); ?></span>
                                <span class="activity-action"><?php echo htmlspecialchars($audit["action"]); ?></span>
                                <span class="activity-module"><?php echo htmlspecialchars($audit["module"]); ?></span>
                                <span class="activity-time"><?php echo htmlspecialchars($audit["activity_date"]); ?></span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-small">
                            <p>No activity recorded</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Revenue Trend Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
const monthlyLabels = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
const monthlyProfit = <?php echo json_encode(array_column($monthly_trend, 'profit')); ?>;
const monthlyCount = <?php echo json_encode(array_column($monthly_trend, 'count')); ?>;

new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: monthlyLabels,
        datasets: [{
            label: 'Revenue (₹)',
            data: monthlyProfit,
            borderColor: '#0d283f',
            backgroundColor: 'rgba(13, 40, 63, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 6,
            pointHoverRadius: 8,
            pointBackgroundColor: '#0d283f',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2
        }, {
            label: 'Bookings',
            data: monthlyCount,
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22, 163, 74, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 6,
            pointHoverRadius: 8,
            pointBackgroundColor: '#16a34a',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            intersect: false,
            mode: 'index'
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#0d283f',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                padding: 12,
                cornerRadius: 8,
                displayColors: true
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                },
                ticks: {
                    callback: function(value) {
                        return '₹' + value.toLocaleString();
                    }
                }
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                grid: {
                    display: false
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});

// Service Distribution Chart
const serviceCtx = document.getElementById('serviceChart').getContext('2d');
const serviceLabels = <?php echo json_encode(array_column($service_distribution, 'service_type')); ?>;
const serviceCounts = <?php echo json_encode(array_column($service_distribution, 'count')); ?>;

const colors = [
    '#0d283f',
    '#16a34a',
    '#ca8a04',
    '#dc2626',
    '#7c3aed'
];

new Chart(serviceCtx, {
    type: 'doughnut',
    data: {
        labels: serviceLabels,
        datasets: [{
            data: serviceCounts,
            backgroundColor: colors.slice(0, serviceLabels.length),
            borderWidth: 0,
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 20,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: {
                        size: 12,
                        weight: 600
                    }
                }
            },
            tooltip: {
                backgroundColor: '#0d283f',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                padding: 12,
                cornerRadius: 8
            }
        },
        cutout: '65%'
    }
});
</script>

</body>
</html>

