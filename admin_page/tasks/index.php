<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth();

// Ensure task_completions table exists
mysqli_query($db, "
CREATE TABLE IF NOT EXISTS task_completions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  status VARCHAR(50) DEFAULT 'Completed',
  completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_task_user (task_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$message = "";
$message_type = "success";

// Save handler (Create / Update)
if (isset($_POST['save_task'])) {
    $title = mysqli_real_escape_string($db, $_POST['title']);
    $description = mysqli_real_escape_string($db, $_POST['description']);
    $assigned_user_id = $_POST['assigned_user_id'] !== "" ? intval($_POST['assigned_user_id']) : "NULL";
    $status = mysqli_real_escape_string($db, $_POST['status']);
    $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
    
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

// Delete handler
if (isset($_POST['delete_task']) && isset($_POST['id'])) {
    $task_id = intval($_POST['id']);
    mysqli_query($db, "DELETE FROM tasks WHERE id = $task_id");
    mysqli_query($db, "DELETE FROM task_completions WHERE task_id = $task_id");
    $message = "Task deleted successfully.";
    $message_type = "success";
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $message = "Task created successfully.";
    } elseif ($_GET['success'] === 'updated') {
        $message = "Task updated successfully.";
    } else {
        $message = "Task status updated successfully.";
    }
    $message_type = "success";
}

// Edit handler loading details
$edit_task = null;
if (isset($_GET['edit'])) {
    $edit_task_id = intval($_GET['edit']);
    if ($edit_task_id > 0) {
        $edit_res = mysqli_query($db, "SELECT * FROM tasks WHERE id = $edit_task_id");
        if ($edit_res && mysqli_num_rows($edit_res) > 0) {
            $edit_task = mysqli_fetch_assoc($edit_res);
        }
    }
}

// Fetch all users to show everyone (excluding admins)
$users_query = mysqli_query($db, "SELECT id, name FROM users WHERE role != 'admin' ORDER BY name ASC");
$users = [];
while ($users_query && ($u = mysqli_fetch_assoc($users_query))) {
    $users[] = $u;
}

// Get search query
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_db = mysqli_real_escape_string($db, $search);

$where_clause = "";
if ($search !== "") {
    $where_clause = "WHERE (
        CAST(t.id AS CHAR) LIKE '%$search_db%'
        OR t.title LIKE '%$search_db%'
        OR t.description LIKE '%$search_db%'
        OR t.status LIKE '%$search_db%'
        OR u.name LIKE '%$search_db%'
    )";
}

// Fetch all tasks showing everyone (joined with users)
$tasks_query = mysqli_query($db, "
    SELECT t.*, u.name as user_name 
    FROM tasks t 
    LEFT JOIN users u ON t.assigned_user_id = u.id 
    $where_clause
    ORDER BY t.id DESC
");

// Count total users (excluding admins)
$total_users_res = mysqli_query($db, "SELECT COUNT(*) as total FROM users WHERE role != 'admin'");
$total_users_row = mysqli_fetch_assoc($total_users_res);
$total_users = intval($total_users_row['total'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tasks Hub | <?= htmlspecialchars(COMPANY_NAME) ?></title>
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
        .task-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 12px;
            margin-bottom: 8px;
            min-height: 0;
        }
        .task-scroll-area::-webkit-scrollbar {
            width: 6px;
        }
        .task-scroll-area::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 3px;
        }
        .task-scroll-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .task-scroll-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .premium-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 20px;
            box-sizing: border-box;
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
            padding: 14px 16px;
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
            cursor: pointer;
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
            margin: 0 0 12px 0;
            border: 0;
            border-top: 1px solid #e2e8f0;
        }
        .search-bar-container {
            margin-bottom: 16px;
            display: flex;
            background: #ffffff;
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid #eaedf2;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }
        .search-bar-container input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 13px;
            font-family: inherit;
            color: #1e293b;
            background: transparent;
        }

        /* Modals styling */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1200;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-backdrop.open {
            display: flex;
        }
        
        .modal-card {
            width: min(95vw, 1150px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 39, 71, .28);
            overflow: hidden;
            animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-head {
            padding: 18px 22px;
            background: #fff;
            color: #0f172a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .modal-head h2 {
            margin: 0;
            color: inherit;
            font-size: 15px;
            font-weight: 600;
        }
        
        .modal-close {
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .modal-close:hover {
            color: #0f172a;
            opacity: 1;
        }
        
        .ev-form {
            padding: 22px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }
        
        .ev-form label {
            display: block;
            margin-bottom: 6px;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ev-form input,
        .ev-form select,
        .ev-form textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: rgba(0, 0, 0, .02);
            color: #1e293b;
            font: inherit;
            box-sizing: border-box;
            transition: border-color 0.2s, background-color 0.2s;
        }
        
        .ev-form input:focus,
        .ev-form select:focus,
        .ev-form textarea:focus {
            border-color: #0d283f;
            background: #fff;
            outline: none;
        }
        
        .ev-form .span-2 {
            grid-column: span 2;
        }

        .ev-form .span-4 {
            grid-column: span 4;
        }
        
        .modal-actions {
            grid-column: span 4;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        @media (max-width: 992px) {
            .ev-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .ev-form .span-2,
            .ev-form .span-4,
            .modal-actions {
                grid-column: span 2;
            }
        }

        @media (max-width: 600px) {
            .ev-form {
                grid-template-columns: 1fr;
            }
            .ev-form .span-2,
            .ev-form .span-4,
            .modal-actions {
                grid-column: span 1;
            }
            .modal-actions {
                flex-direction: column-reverse;
            }
            .modal-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="dashboard-title-row">
        <div>
            <h1>📋 Task Management</h1>
        </div>
        <div>
            <button type="button" class="btn" id="openAddTask">+ Add Task</button>
        </div>
    </div>
    
    <hr>

    <?php if (!empty($message)): ?>
        <div id="taskFlashMessage" data-auto-hide="<?php echo $message_type === 'success' ? '1' : '0'; ?>" style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;
            transition: opacity .3s ease, transform .3s ease, margin .3s ease, padding .3s ease;
            background: <?php echo $message_type == 'success' ? '#dcfce7' : '#fee2e2'; ?>; 
            color: <?php echo $message_type == 'success' ? '#166534' : '#991b1b'; ?>;
            border: 1px solid <?php echo $message_type == 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Sticky Search Bar -->
    <div class="search-bar-container">
        <form method="GET" action="index.php" role="search" style="width: 100%; display: flex; gap: 8px;">
            <input type="text" name="q" placeholder="Search by ID, title, description, status or assignee..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn" style="padding: 6px 14px;">Search</button>
            <?php if ($search !== ""): ?>
                <a href="index.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 11px; border-radius: 8px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="task-scroll-area">
        <div class="premium-card" style="padding: 0; overflow: hidden;">
            <table class="premium-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Task Title</th>
                        <th>Description</th>
                        <th style="width: 150px;">Created Date</th>
                        <th style="width: 180px; text-align: center;">Completion Rate</th>
                        <th style="width: 180px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($tasks_query) > 0): ?>
                        <?php while ($task = mysqli_fetch_assoc($tasks_query)): 
                            // Calculate completion rate
                            $task_id_val = intval($task['id']);
                            $comp_res = mysqli_query($db, "SELECT COUNT(*) as count FROM task_completions WHERE task_id = $task_id_val AND status = 'Completed'");
                            $completed_count = intval(mysqli_fetch_assoc($comp_res)['count'] ?? 0);
                            
                            // Check backward compatibility for tasks assigned to a specific user
                            if ($task['assigned_user_id'] !== null && $task['status'] === 'Completed') {
                                $chk_comp = mysqli_query($db, "SELECT id FROM task_completions WHERE task_id = $task_id_val AND user_id = " . intval($task['assigned_user_id']));
                                if (mysqli_num_rows($chk_comp) === 0) {
                                    $completed_count++;
                                }
                            }
                            
                            // Format rate display
                            $rate_percent = $total_users > 0 ? round(($completed_count / $total_users) * 100) : 0;
                        ?>
                            <tr onclick="location.href='view.php?id=<?php echo $task['id']; ?>'">
                                <td style="font-weight: 700; color: #64748b;">#<?php echo $task['id']; ?></td>
                                <td style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($task['title']); ?></td>
                                <td style="color: #475569; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($task['description']); ?></td>
                                <td style="color: #64748b; font-size: 12px;"><?php echo date('d M Y', strtotime($task['created_at'])); ?></td>
                                <td style="text-align: center;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px; flex-direction: column;">
                                        <div style="font-weight: 700; color: #0d283f; font-size: 13px;">
                                            <?php echo $completed_count; ?> / <?php echo $total_users; ?> Completed
                                        </div>
                                        <div style="width: 100px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; position: relative;">
                                            <div style="width: <?php echo $rate_percent; ?>%; height: 100%; background: #10b981; border-radius: 3px;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align: center;" onclick="event.stopPropagation();">
                                    <div style="display: flex; gap: 6px; justify-content: center;">
                                        <a href="view.php?id=<?php echo $task['id']; ?>" class="btn" style="padding: 4px 10px; font-size: 11px; border-radius: 6px; background: #0d283f;">View</a>
                                        <button type="button" 
                                                class="btn btn-secondary edit-btn-inline" 
                                                style="padding: 4px 10px; font-size: 11px; border-radius: 6px;"
                                                data-id="<?php echo $task['id']; ?>"
                                                data-title="<?php echo htmlspecialchars($task['title']); ?>"
                                                data-description="<?php echo htmlspecialchars($task['description']); ?>"
                                                data-assigned-user-id="<?php echo $task['assigned_user_id'] ?: ''; ?>"
                                                data-status="<?php echo $task['status']; ?>">
                                            Edit
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this task?');">
                                            <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
                                            <button type="submit" name="delete_task" class="btn btn-secondary" style="padding: 4px 10px; font-size: 11px; border-radius: 6px; background: rgba(239, 68, 68, 0.03); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.12);">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                <div style="font-size: 48px; margin-bottom: 12px;">📋</div>
                                <h3 style="font-weight: 700; color: #0f172a; font-size: 14px;">No Tasks Found</h3>
                                <p style="font-size: 12px; margin: 4px 0 0 0;">Create a task or refine your search query.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Task Modal -->
<div class="modal-backdrop" id="taskModal">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="modalTitle"><?= $edit_task ? '✏️ Edit Task' : '📋 Add New Task' ?></h2>
            <button class="modal-close" type="button" data-close>&times;</button>
        </div>
        <form class="ev-form" method="POST" id="taskForm" autocomplete="off">
            <input type="hidden" name="task_id" id="modal_task_id" value="<?= $edit_task ? $edit_task['id'] : '0' ?>">
            
            <div class="span-2">
                <label for="modal_title">Task Title *</label>
                <input type="text" id="modal_title" name="title" placeholder="e.g. Call High-Priority Customer" 
                       value="<?= $edit_task ? htmlspecialchars($edit_task['title']) : '' ?>" required>
            </div>

            <div class="span-4">
                <label for="modal_description">Description *</label>
                <textarea id="modal_description" name="description" rows="5" placeholder="Describe the task instructions here..." required><?= $edit_task ? htmlspecialchars($edit_task['description']) : '' ?></textarea>
            </div>

            <div>
                <label for="modal_assigned_user_id">Assign To User</label>
                <select id="modal_assigned_user_id" name="assigned_user_id">
                    <option value="">All Users</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($edit_task && $edit_task['assigned_user_id'] == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="modal_status">Status</label>
                <select id="modal_status" name="status">
                    <option value="Pending" <?php echo ($edit_task && $edit_task['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Completed" <?php echo ($edit_task && $edit_task['status'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>

            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" data-close>Cancel</button>
                <button type="submit" name="save_task" class="btn" id="modalSubmitBtn"><?= $edit_task ? 'Update Task' : 'Save Task' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('taskModal');
    const titleInput = document.getElementById('modal_title');
    const descInput = document.getElementById('modal_description');
    const assignedInput = document.getElementById('modal_assigned_user_id');
    const statusInput = document.getElementById('modal_status');
    const taskIdInput = document.getElementById('modal_task_id');
    const modalTitle = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('modalSubmitBtn');

    const open = () => {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    const close = () => {
        modal.classList.remove('open');
        document.body.style.overflow = '';
        if (new URLSearchParams(window.location.search).get('edit') || new URLSearchParams(window.location.search).get('action')) {
            window.history.replaceState({}, document.title, 'index.php');
        }
    };

    document.getElementById('openAddTask').addEventListener('click', () => {
        modalTitle.innerHTML = '📋 Add New Task';
        submitBtn.textContent = 'Save Task';
        taskIdInput.value = '0';
        titleInput.value = '';
        descInput.value = '';
        assignedInput.value = '';
        statusInput.value = 'Pending';
        open();
    });

    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', close));
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    // Inline edit click handler
    document.querySelectorAll('.edit-btn-inline').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            modalTitle.innerHTML = '✏️ Edit Task';
            submitBtn.textContent = 'Update Task';
            taskIdInput.value = btn.dataset.id;
            titleInput.value = btn.dataset.title;
            descInput.value = btn.dataset.description;
            assignedInput.value = btn.dataset.assignedUserId;
            statusInput.value = btn.dataset.status;
            open();
        });
    });

    // Open automatically if PHP loaded edit_task (fallback URL navigation) or action=add
    const initialEdit = <?php echo isset($edit_task) && $edit_task ? 'true' : 'false' ?>;
    const isAddAction = new URLSearchParams(window.location.search).get('action') === 'add';
    if (initialEdit || isAddAction) {
        if (isAddAction) {
            modalTitle.innerHTML = '📋 Add New Task';
            submitBtn.textContent = 'Save Task';
            taskIdInput.value = '0';
            titleInput.value = '';
            descInput.value = '';
            assignedInput.value = '';
            statusInput.value = 'Pending';
        }
        open();
    }

})();
</script>
</body>
</html>
