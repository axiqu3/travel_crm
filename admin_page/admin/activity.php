<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$data = mysqli_query($db, "SELECT * FROM activity_log ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Activity | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <h1>Admin Activity Log</h1>
    </div>
    
    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <input type="text" class="search" placeholder="Search activity by user, action, module...">
    </div>
    
    <hr>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($data)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row["id"]); ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($row["username"]); ?></td>
                        <td>
                            <span class="badge booked" style="font-size: 11px;">
                                <?php echo htmlspecialchars($row["action"]); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row["module"]); ?></td>
                        <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($row["activity_date"]); ?></td>
                        <td>
                            <a href="activity_view.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                            No activity records found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
