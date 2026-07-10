<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth();

$user_id = intval($_SESSION['user_id']);
$message = "";

// Toggle status handler
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    $new_status = $_GET['toggle_status'] === 'Completed' ? 'Completed' : 'Pending';
    
    // Ensure the task is assigned to the current user
    $sql = "UPDATE tasks SET status = '$new_status' WHERE id = $task_id AND (assigned_user_id = $user_id OR assigned_user_id IS NULL)";
    if (mysqli_query($db, $sql)) {
        header("Location: index.php?success=1");
        exit;
    }
}

if (isset($_GET['success'])) {
    $message = "Task status updated successfully.";
}

// Fetch user's assigned tasks
$data = mysqli_query($db, "SELECT * FROM tasks WHERE assigned_user_id = $user_id OR assigned_user_id IS NULL ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Tasks | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .task-row.completed {
            background-color: rgba(16, 185, 129, 0.03);
        }
        .task-row.pending {
            background-color: rgba(245, 158, 11, 0.03);
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <h1>My Tasks</h1>
    </div>
    
    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <input type="text" class="search" placeholder="Search tasks by title, status...">
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="table-card">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th style="width: 250px;">Task Title</th>
                    <th>Description</th>
                    <th style="width: 130px;">Assigned Date</th>
                    <th style="width: 120px;">Status</th>
                    <th style="width: 150px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($data)): ?>
                    <tr class="task-row <?php echo strtolower($row['status']); ?>" style="border-bottom: 1px solid var(--border-dark);">
                        <td><?php echo htmlspecialchars($row["id"]); ?></td>
                        <td style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($row["title"]); ?></td>
                        <td style="color: var(--text-secondary); font-size: 13px; white-space: pre-wrap;"><?php echo htmlspecialchars($row["description"]); ?></td>
                        <td style="color: var(--text-secondary); font-size: 13px;">
                            <?php echo date('M d, Y', strtotime($row["created_at"])); ?>
                        </td>
                        <td>
                            <span class="badge <?php echo strtolower($row['status']) === 'completed' ? 'booked' : 'cancelled'; ?>" style="font-size: 11px;">
                                <?php echo htmlspecialchars($row["status"]); ?>
                            </span>
                        </td>
                        <td>
                            <a href="index.php?toggle_status=<?php echo $row['status'] === 'Completed' ? 'Pending' : 'Completed'; ?>&id=<?php echo $row['id']; ?>" 
                               class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">
                                Mark <?php echo $row['status'] === 'Completed' ? 'Pending' : 'Completed'; ?>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                            No tasks assigned to you.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
