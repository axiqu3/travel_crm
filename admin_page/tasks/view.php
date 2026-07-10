<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($task_id <= 0) {
    header("Location: index.php");
    exit;
}

// Fetch task details
$task_res = mysqli_query($db, "SELECT * FROM tasks WHERE id = $task_id");
if (!$task_res || mysqli_num_rows($task_res) === 0) {
    header("Location: index.php");
    exit;
}
$task = mysqli_fetch_assoc($task_res);

$message = "";
$message_type = "success";

// Handle user task toggle
if (isset($_GET['toggle_user_id'])) {
    $user_id_toggle = intval($_GET['toggle_user_id']);
    
    // Check if completion record exists
    $check_q = mysqli_query($db, "SELECT * FROM task_completions WHERE task_id = $task_id AND user_id = $user_id_toggle");
    if ($check_q && mysqli_num_rows($check_q) > 0) {
        // Delete it (mark Pending)
        mysqli_query($db, "DELETE FROM task_completions WHERE task_id = $task_id AND user_id = $user_id_toggle");
    } else {
        // Insert it (mark Completed)
        mysqli_query($db, "INSERT INTO task_completions (task_id, user_id, status) VALUES ($task_id, $user_id_toggle, 'Completed')");
    }
    
    header("Location: view.php?id=$task_id&success=1");
    exit;
}

if (isset($_GET['success'])) {
    $message = "Task completion status updated successfully.";
    $message_type = "success";
}

// Fetch all users in the system (excluding admins)
$users_query = mysqli_query($db, "SELECT id, name, role, email FROM users WHERE role != 'admin' ORDER BY name ASC");
$users = [];
while ($u = mysqli_fetch_assoc($users_query)) {
    // Check if they completed the task
    $uid = intval($u['id']);
    $comp_check = mysqli_query($db, "SELECT status FROM task_completions WHERE task_id = $task_id AND user_id = $uid");
    
    $is_completed = false;
    if ($comp_check && mysqli_num_rows($comp_check) > 0) {
        $row = mysqli_fetch_assoc($comp_check);
        if ($row['status'] === 'Completed') {
            $is_completed = true;
        }
    } else {
        // Backward compatibility: check if task is completed and assigned to this specific user
        if ($task['assigned_user_id'] !== null && intval($task['assigned_user_id']) === $uid && $task['status'] === 'Completed') {
            $is_completed = true;
        }
    }
    
    $u['is_completed'] = $is_completed;
    $users[] = $u;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Task Completions | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        body {
            height: 100vh;
            overflow: hidden;
            margin: 0;
            background: #f8fafc;
        }
        .main {
            height: calc(100vh - 20px);
            margin-top: 10px;
            margin-bottom: 10px;
            margin-right: 20px;
            padding: 16px 24px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-sizing: border-box;
            background: #f8fafc;
            border: none;
            box-shadow: none;
        }
        .dashboard-title-row {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dashboard-title-row h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .view-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 12px;
            margin-bottom: 8px;
            min-height: 0;
        }
        .view-scroll-area::-webkit-scrollbar {
            width: 6px;
        }
        .view-scroll-area::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 3px;
        }
        .view-scroll-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .view-scroll-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .premium-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 24px;
            box-sizing: border-box;
            margin-bottom: 20px;
        }
        .premium-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .premium-table th {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            padding: 12px 16px;
            border-bottom: 2px solid #eaedf2;
            letter-spacing: 0.5px;
        }
        .premium-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            color: #1e293b;
        }
        .premium-table tr:last-child td {
            border-bottom: none;
        }
        .premium-table tr {
            transition: background 0.15s ease;
        }
        .premium-table tr:hover {
            background: #f8fafc;
        }
        .badge {
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            display: inline-block;
        }
        .badge-completed {
            background: #dcfce7;
            color: #166534;
        }
        .badge-pending {
            background: #fffbeb;
            color: #b45309;
        }
        .btn {
            background: linear-gradient(135deg, #0d283f 0%, #1a4970 100%);
            border: none;
            border-radius: 8px;
            padding: 6px 14px;
            color: white;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 3px 5px rgba(13, 40, 63, 0.15);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 10px rgba(13, 40, 63, 0.2);
        }
        .btn-secondary {
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #cbd5e1;
            box-shadow: none;
        }
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            box-shadow: none;
        }
        hr {
            margin: 0 0 16px 0;
            border: 0;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <div>
            <h1>📋 Task Completion Tracker</h1>
        </div>
        <div>
            <a href="index.php" class="btn btn-secondary">Back to Tasks</a>
        </div>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;
            border: 1px solid <?php echo $message_type == 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="view-scroll-area">
        <!-- Task Info Details Card -->
        <div class="premium-card">
            <h2 style="font-size: 16px; font-weight: 800; color: #0d283f; margin: 0 0 8px 0;"><?php echo htmlspecialchars($task['title']); ?></h2>
            <p style="color: #475569; font-size: 13px; margin: 0 0 16px 0; line-height: 1.5; white-space: pre-wrap;"><?php echo htmlspecialchars($task['description']); ?></p>
            <div style="font-size: 11px; color: #64748b; border-top: 1px solid #f1f5f9; padding-top: 10px;">
                📅 Created on: <strong><?php echo date('d M Y, h:i A', strtotime($task['created_at'])); ?></strong>
            </div>
        </div>

        <!-- Task Completion Table -->
        <div class="premium-card" style="padding: 0; overflow: hidden;">
            <table class="premium-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">User ID</th>
                        <th>User Name</th>
                        <th>Role</th>
                        <th>Email Address</th>
                        <th style="width: 150px; text-align: center;">Task Status</th>
                        <th style="width: 150px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td style="font-weight: 700; color: #64748b;">#<?php echo $u['id']; ?></td>
                            <td style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($u['name']); ?></td>
                            <td style="color: #475569; font-size: 12px; font-weight: 600; text-transform: capitalize;"><?php echo htmlspecialchars($u['role']); ?></td>
                            <td style="color: #64748b; font-size: 12px;"><?php echo htmlspecialchars($u['email']); ?></td>
                            <td style="text-align: center;">
                                <span class="badge badge-<?php echo $u['is_completed'] ? 'completed' : 'pending'; ?>">
                                    <?php echo $u['is_completed'] ? 'Completed' : 'Pending'; ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <a href="view.php?id=<?php echo $task_id; ?>&toggle_user_id=<?php echo $u['id']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 10px; border-radius: 6px;">
                                    Mark <?php echo $u['is_completed'] ? 'Pending' : 'Completed'; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
