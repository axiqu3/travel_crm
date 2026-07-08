<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$message = "";
$message_type = "success";

// Toggle status handler
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    $new_status = $_GET['toggle_status'] === 'Completed' ? 'Completed' : 'Pending';
    mysqli_query($db, "UPDATE tasks SET status = '$new_status' WHERE id = $task_id");
    header("Location: index.php?success=1");
    exit;
}

// Delete handler
if (isset($_POST['delete_task']) && isset($_POST['id'])) {
    $task_id = intval($_POST['id']);
    mysqli_query($db, "DELETE FROM tasks WHERE id = $task_id");
    $message = "Task deleted successfully.";
    $message_type = "success";
}

// Add / Update handler
if (isset($_POST['save_task'])) {
    $title = mysqli_real_escape_string($db, $_POST['title']);
    $description = mysqli_real_escape_string($db, $_POST['description']);
    $assigned_user_id = $_POST['assigned_user_id'] !== "" ? intval($_POST['assigned_user_id']) : "NULL";
    $status = mysqli_real_escape_string($db, $_POST['status']);
    
    if (isset($_POST['task_id']) && !empty($_POST['task_id'])) {
        // Update
        $task_id = intval($_POST['task_id']);
        $sql = "UPDATE tasks SET title = '$title', description = '$description', assigned_user_id = $assigned_user_id, status = '$status' WHERE id = $task_id";
        if (mysqli_query($db, $sql)) {
            $message = "Task updated successfully.";
            $message_type = "success";
        } else {
            $message = "Error updating task: " . mysqli_error($db);
            $message_type = "error";
        }
    } else {
        // Create
        $sql = "INSERT INTO tasks (title, description, assigned_user_id, status) VALUES ('$title', '$description', $assigned_user_id, '$status')";
        if (mysqli_query($db, $sql)) {
            $message = "Task created successfully.";
            $message_type = "success";
        } else {
            $message = "Error creating task: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}

if (isset($_GET['success'])) {
    $message = "Task status updated successfully.";
    $message_type = "success";
}

// Fetch all tasks
$tasks_query = mysqli_query($db, "
    SELECT t.*, u.name as user_name 
    FROM tasks t 
    LEFT JOIN users u ON t.assigned_user_id = u.id 
    ORDER BY t.id DESC
");

// Fetch active users (exclude admins)
$users_query = mysqli_query($db, "SELECT id, name FROM users WHERE role != 'admin' ORDER BY name ASC");
$users = [];
while ($u = mysqli_fetch_assoc($users_query)) {
    $users[] = $u;
}

// If editing, fetch edit target
$edit_task = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_query = mysqli_query($db, "SELECT * FROM tasks WHERE id = $edit_id");
    if ($edit_query && mysqli_num_rows($edit_query) > 0) {
        $edit_task = mysqli_fetch_assoc($edit_query);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tasks Console | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .task-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        @media (min-width: 992px) {
            .task-grid {
                grid-template-columns: 2fr 1fr;
            }
        }
        .task-card {
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 16px;
            background: var(--bg-card);
            margin-bottom: 16px;
            position: relative;
        }
        .task-card.completed {
            border-left: 4px solid #10b981;
        }
        .task-card.pending {
            border-left: 4px solid #f59e0b;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="../dashboard.php">Dashboard</a>
    <a href="../master/list.php">Master</a>
    <a href="../bookings/list.php">Bookings</a>
    <a href="../bookings/add.php">Add Booking</a>
    <a href="../bookings/reports.php">Reports</a>
    <a href="../enquiry/list.php"<?= (strpos($_SERVER['PHP_SELF'], '/enquiry/') !== false) ? ' class="active"' : '' ?>>Enquiry</a>
    <a href="index.php" class="active">Tasks</a>
    <a href="../admin/activity.php">Activity</a>
    <a href="../../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search tasks...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Task Management</h1>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="task-grid">
        <!-- Tasks List Column -->
        <div>
            <div class="card">
                <h2>All Assigned Tasks</h2>
                <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 14px;">
                    Track and verify tasks assigned to your agents.
                </p>

                <?php if (mysqli_num_rows($tasks_query) > 0): ?>
                    <?php while ($task = mysqli_fetch_assoc($tasks_query)): ?>
                        <div class="task-card <?php echo strtolower($task['status']); ?>">
                            <div class="d-flex justify-between align-center" style="margin-bottom: 8px;">
                                <h3 style="margin: 0; font-size: 16px;"><?php echo htmlspecialchars($task['title']); ?></h3>
                                <div>
                                    <span class="badge <?php echo strtolower($task['status']) === 'completed' ? 'booked' : 'cancelled'; ?>">
                                        <?php echo htmlspecialchars($task['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 12px; white-space: pre-wrap;"><?php echo htmlspecialchars($task['description']); ?></p>
                            
                            <div class="d-flex justify-between align-center" style="font-size: 12px; color: var(--text-secondary); border-top: 1px solid var(--border-dark); padding-top: 10px;">
                                <div>
                                    👤 Assigned to: <strong style="color: var(--text-main);"><?php echo $task['user_name'] ? htmlspecialchars($task['user_name']) : 'All Users'; ?></strong>
                                    &nbsp;&nbsp;•&nbsp;&nbsp;
                                    📅 Created: <strong><?php echo date('M d, Y h:i A', strtotime($task['created_at'])); ?></strong>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="index.php?toggle_status=<?php echo $task['status'] === 'Completed' ? 'Pending' : 'Completed'; ?>&id=<?php echo $task['id']; ?>" 
                                       class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                        Mark <?php echo $task['status'] === 'Completed' ? 'Pending' : 'Completed'; ?>
                                    </a>
                                    
                                    <a href="index.php?edit=<?php echo $task['id']; ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;">Edit</a>
                                    
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this task?');">
                                        <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" name="delete_task" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2);">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 0; color: var(--text-secondary);">
                        <div style="font-size: 48px; margin-bottom: 12px;">📋</div>
                        <h3>No Tasks Found</h3>
                        <p>Create a task on the right to assign to users.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add / Edit Column -->
        <div>
            <div class="card">
                <h2><?php echo $edit_task ? 'Edit Task' : 'Create Task'; ?></h2>
                <hr style="margin: 16px 0;">
                
                <form method="POST">
                    <?php if ($edit_task): ?>
                        <input type="hidden" name="task_id" value="<?php echo $edit_task['id']; ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="title">Task Title</label>
                        <input type="text" id="title" name="title" placeholder="e.g. Call High-Priority Customer" 
                               value="<?php echo $edit_task ? htmlspecialchars($edit_task['title']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="4" placeholder="Describe the task instructions here..." required><?php echo $edit_task ? htmlspecialchars($edit_task['description']) : ''; ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="assigned_user_id">Assign To User</label>
                        <select id="assigned_user_id" name="assigned_user_id">
                            <option value="">All Users</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo ($edit_task && $edit_task['assigned_user_id'] == $u['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="Pending" <?php echo ($edit_task && $edit_task['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Completed" <?php echo ($edit_task && $edit_task['status'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>

                    <button type="submit" name="save_task" style="width: 100%; padding: 12px; background: var(--accent-color); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
                        <?php echo $edit_task ? 'Update Task' : 'Create Task'; ?>
                    </button>
                    
                    <?php if ($edit_task): ?>
                        <a href="index.php" class="btn btn-secondary" style="display: block; text-align: center; margin-top: 12px; padding: 11px;">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
