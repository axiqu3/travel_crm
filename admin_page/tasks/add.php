<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$message = "";
$message_type = "success";

$task_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$edit_task = null;

if ($task_id > 0) {
    $edit_query = mysqli_query($db, "SELECT * FROM tasks WHERE id = $task_id");
    if ($edit_query && mysqli_num_rows($edit_query) > 0) {
        $edit_task = mysqli_fetch_assoc($edit_query);
    }
}

// Fetch all users to show everyone (excluding admins)
$users_query = mysqli_query($db, "SELECT id, name FROM users WHERE role != 'admin' ORDER BY name ASC");
$users = [];
while ($u = mysqli_fetch_assoc($users_query)) {
    $users[] = $u;
}

if (isset($_POST['save_task'])) {
    $title = mysqli_real_escape_string($db, $_POST['title']);
    $description = mysqli_real_escape_string($db, $_POST['description']);
    $assigned_user_id = $_POST['assigned_user_id'] !== "" ? intval($_POST['assigned_user_id']) : "NULL";
    $status = mysqli_real_escape_string($db, $_POST['status']);
    
    if ($task_id > 0) {
        // Update
        $sql = "UPDATE tasks SET title = '$title', description = '$description', assigned_user_id = $assigned_user_id, status = '$status' WHERE id = $task_id";
        if (mysqli_query($db, $sql)) {
            header("Location: index.php?success=updated");
            exit;
        } else {
            $message = "Error updating task: " . mysqli_error($db);
            $message_type = "error";
        }
    } else {
        // Create
        $sql = "INSERT INTO tasks (title, description, assigned_user_id, status) VALUES ('$title', '$description', $assigned_user_id, '$status')";
        if (mysqli_query($db, $sql)) {
            header("Location: index.php?success=created");
            exit;
        } else {
            $message = "Error creating task: " . mysqli_error($db);
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $edit_task ? 'Edit Task' : 'Add Task'; ?> | <?= htmlspecialchars(COMPANY_NAME) ?></title>
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
        .premium-form-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 24px;
            max-width: 600px;
            width: 100%;
            margin: 0 auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            box-sizing: border-box;
        }
        .premium-form-card form {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
        }
        .form-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 12px;
            margin-bottom: 16px;
        }
        .form-scroll-area::-webkit-scrollbar {
            width: 6px;
        }
        .form-scroll-area::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 3px;
        }
        .form-scroll-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .form-scroll-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13px;
            background: #ffffff;
            color: #1e293b;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #0d283f;
            box-shadow: 0 0 0 3px rgba(13, 40, 63, 0.15);
            background: #ffffff;
        }
        .form-actions-bar {
            display: flex;
            gap: 12px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            margin-top: auto;
        }
        .btn {
            background: linear-gradient(135deg, #0d283f 0%, #1a4970 100%);
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(13, 40, 63, 0.2);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(13, 40, 63, 0.3);
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
            margin: 0 0 12px 0;
            border: 0;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <h1><?php echo $edit_task ? '✏ Edit Task' : '📋 Add New Task'; ?></h1>
        <a href="index.php" class="btn btn-secondary">Back to Tasks</a>
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

    <div class="premium-form-card">
        <form method="POST" autocomplete="off">
            <div class="form-scroll-area">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="title">Task Title *</label>
                        <input type="text" id="title" name="title" placeholder="e.g. Call High-Priority Customer" 
                               value="<?php echo $edit_task ? htmlspecialchars($edit_task['title']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" rows="5" placeholder="Describe the task instructions here..." required><?php echo $edit_task ? htmlspecialchars($edit_task['description']) : ''; ?></textarea>
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
                </div>
            </div>

            <div class="form-actions-bar">
                <button type="submit" name="save_task" class="btn"><?php echo $edit_task ? 'Update Task' : 'Save Task'; ?></button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>
