<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

// Load Auto-Delete Configuration & Silent Automated Cleanup
$config_file = __DIR__ . '/auto_delete_config.json';
$auto_delete_days = 0;
$config = [];
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true) ?: [];
    $auto_delete_days = intval($config['threshold_days'] ?? 0);
}

if ($auto_delete_days > 0) {
    $last_cleanup = $config['last_cleanup_run'] ?? '';
    $today = date('Y-m-d');
    if ($last_cleanup !== $today) {
        $cleanup_query = "DELETE FROM enquiries WHERE created_at < NOW() - INTERVAL $auto_delete_days DAY";
        if (mysqli_query($db, $cleanup_query)) {
            $config['last_cleanup_run'] = $today;
            file_put_contents($config_file, json_encode($config));
            $deleted_count = mysqli_affected_rows($db);
            if ($deleted_count > 0) {
                mysqli_query($db, "INSERT INTO activity_log (username, action, module, activity_date) VALUES ('System', 'Auto-cleaned $deleted_count old enquiries (threshold: $auto_delete_days days)', 'Enquiry', NOW())");
            }
        }
    }
}

// Fetch unread count for notification bell before we clear them
$unread_res = mysqli_query($db, "SELECT COUNT(*) as count FROM enquiries WHERE notified = 0");
$unread_count = mysqli_fetch_assoc($unread_res)['count'] ?? 0;

// Get search query
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_db = mysqli_real_escape_string($db, $search);

// Get filter parameters
$filter_date = isset($_GET['date']) ? trim($_GET['date']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_source = isset($_GET['source']) ? trim($_GET['source']) : '';

// Build conditions
$where_conditions = [];

if ($search !== "") {
    $where_conditions[] = "(e.customer_name LIKE '%$search_db%' 
                           OR e.mobile LIKE '%$search_db%' 
                           OR e.email LIKE '%$search_db%' 
                           OR e.subject LIKE '%$search_db%')";
}

if ($filter_date !== "") {
    $filter_date_db = mysqli_real_escape_string($db, $filter_date);
    $where_conditions[] = "DATE(e.created_at) = '$filter_date_db'";
}

if ($filter_status !== "") {
    $filter_status_db = mysqli_real_escape_string($db, $filter_status);
    $where_conditions[] = "e.status = '$filter_status_db'";
}

if ($filter_source !== "") {
    $filter_source_db = mysqli_real_escape_string($db, $filter_source);
    $where_conditions[] = "e.source = '$filter_source_db'";
}

$where_clause = "";
if (count($where_conditions) > 0) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Fetch all distinct sources from database for filter dropdown options
$source_res = mysqli_query($db, "SELECT DISTINCT source FROM enquiries WHERE source IS NOT NULL AND source <> '' ORDER BY source ASC");
$sources = [];
while ($s_row = mysqli_fetch_assoc($source_res)) {
    $sources[] = $s_row['source'];
}

// Count total chats for pagination
$count_query = "SELECT COUNT(*) as total FROM enquiries e
INNER JOIN (
    SELECT MAX(id) as max_id
    FROM enquiries
    GROUP BY IF(mobile = '' OR mobile IS NULL, id, mobile)
) latest ON e.id = latest.max_id
$where_clause";

$count_result = mysqli_query($db, $count_query);
$count_row = mysqli_fetch_assoc($count_result);
$total_records = $count_row['total'] ?? 0;

// Calculate pagination parameters
$limit = 500;
$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages) $page = $total_pages;

$offset = ($page - 1) * $limit;

// Helper to format inbox time exactly like Gmail
function format_inbox_time($datetime) {
    $time = strtotime($datetime);
    if (!$time) return '';
    
    $today = strtotime('today');
    $diff = $time - $today;
    
    if ($diff >= 0 && $diff < 86400) {
        // Today, show time (e.g. 2:30 PM)
        return date('g:i A', $time);
    } else {
        // Not today, check if it's the current year
        if (date('Y', $time) === date('Y')) {
            // Current year, show "5 Jul"
            return date('j M', $time);
        } else {
            // Previous year, show "5 Jul 25"
            return date('j M y', $time);
        }
    }
}


// Query the page records
$data_query = "SELECT e.*, 
       (SELECT COUNT(*) FROM enquiry_messages WHERE enquiry_id = e.id) AS message_count
FROM enquiries e
INNER JOIN (
    SELECT MAX(id) as max_id
    FROM enquiries
    GROUP BY IF(mobile = '' OR mobile IS NULL, id, mobile)
) latest ON e.id = latest.max_id
$where_clause
ORDER BY e.updated_at DESC, e.id DESC
LIMIT $limit OFFSET $offset";

$data = mysqli_query($db, $data_query);
?>


<!DOCTYPE html>
<html>
<head>
    <title>Enquiries Hub | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        .select-col {
            display: none !important;
        }
        .auto-clean-container {
            display: none !important;
        }
        .delete-mode-active .select-col {
            display: flex !important;
        }
        .delete-mode-active .auto-clean-container {
            display: flex !important;
        }

        /* ── GMAIL STYLE INBOX LAYOUT ── */
        .inbox-list {
            display: flex;
            flex-direction: column;
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .inbox-row {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: background-color 0.15s ease;
            position: relative;
            text-decoration: none;
            color: inherit;
        }

        .inbox-row:last-child {
            border-bottom: none;
        }

        .inbox-row:hover {
            background-color: #f1f5f9;
        }

        .inbox-row.unread {
            background-color: #ffffff;
            color: var(--text-primary);
        }

        .inbox-row.read {
            background-color: #f8fafc;
            color: var(--text-secondary);
        }

        /* Checkbox Column */
        .inbox-select-col {
            align-self: center;
            padding-right: 12px;
            display: none !important;
            flex-shrink: 0;
        }

        .delete-mode-active .inbox-select-col {
            display: flex !important;
        }

        /* Unread Dot Column */
        .inbox-dot-col {
            width: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            flex-shrink: 0;
        }

        .inbox-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #3b82f6; /* Beautiful brand notification blue */
            display: inline-block;
        }

        /* Customer Name Column */
        .inbox-name-col {
            width: 160px;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 12px;
            flex-shrink: 0;
        }

        .inbox-row.unread .inbox-name-col {
            font-weight: 700;
            color: #0d283f;
        }

        .inbox-row.read .inbox-name-col {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .inbox-msg-count {
            font-size: 11px;
            font-weight: normal;
            color: var(--text-muted);
            margin-left: 4px;
        }

        /* Subject & Snippet Column */
        .inbox-message-col {
            flex: 1;
            min-width: 0;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 16px;
        }

        .inbox-row.unread .inbox-message-col {
            color: var(--text-primary);
        }

        .inbox-row.read .inbox-message-col {
            color: var(--text-secondary);
        }

        .inbox-subject {
            font-weight: 600;
        }

        .inbox-row.unread .inbox-subject {
            color: #0d283f;
        }

        .inbox-row.read .inbox-subject {
            color: var(--text-primary);
        }

        .inbox-separator {
            color: var(--text-muted);
        }

        .inbox-snippet {
            color: var(--text-muted);
        }

        .inbox-row.unread .inbox-snippet {
            color: var(--text-secondary);
        }

        /* Badge Column */
        .inbox-badge-col {
            padding-right: 16px;
            flex-shrink: 0;
        }

        .inbox-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .inbox-badge.whatsapp {
            background: #dcfce7;
            color: #166534;
        }

        .inbox-badge.email {
            background: #e0f2fe;
            color: #0369a1;
        }

        .inbox-badge.direct {
            background: #f1f5f9;
            color: #475569;
        }

        /* Time Column */
        .inbox-time-col {
            font-size: 12px;
            text-align: right;
            white-space: nowrap;
            flex-shrink: 0;
            width: 70px;
        }

        .inbox-row.unread .inbox-time-col {
            font-weight: 700;
            color: #0d283f;
        }

        .inbox-row.read .inbox-time-col {
            color: var(--text-muted);
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
    <a href="list.php" class="active">Enquiry</a>
    <a href="../tasks/index.php">Tasks</a>
    <a href="../admin/activity.php">Activity</a>
    <a href="../../login.php" class="logout">Logout</a>
</div>

<div class="main" id="enquiry-dashboard">
    <div class="header">
        <form method="GET" action="list.php" style="flex: 1; max-width: 400px; display: flex;">
            <input name="q" class="search" placeholder="Search enquiries..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%;">
            <?php if ($filter_date !== ''): ?><input type="hidden" name="date" value="<?php echo htmlspecialchars($filter_date); ?>"><?php endif; ?>
            <?php if ($filter_status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>"><?php endif; ?>
            <?php if ($filter_source !== ''): ?><input type="hidden" name="source" value="<?php echo htmlspecialchars($filter_source); ?>"><?php endif; ?>
        </form>
        <span class="notify" style="position: relative; display: inline-flex; align-items: center; justify-content: center;">
            🔔
            <?php if ($unread_count > 0): ?>
                <span class="bell-badge" style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: 700; line-height: 1; min-width: 16px; text-align: center; box-shadow: 0 0 0 2px var(--bg-primary);"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h1>Enquiries Hub</h1>
            <button id="btnBulkDelete" onclick="deleteSelectedEnquiries()" class="btn" style="background: #ef4444; border: none; color: white; padding: 6px 12px; font-size: 12px; border-radius: 8px; display: none; align-items: center; gap: 6px; cursor: pointer;">
                🗑 Delete Selected (<span id="deleteCount">0</span>)
            </button>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="check_emails.php" class="btn" style="background: #3b82f6; border: none; color: white; display: flex; align-items: center; gap: 6px; cursor: pointer; text-decoration: none;">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4Zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2Zm13 2.383-4.708 2.825L15 11.105V5.383Zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741ZM1 11.105l4.708-2.897L1 5.383v5.722Z"/>
                </svg>
                Check Email
            </a>
            <a href="check_whatsapp.php" class="btn" style="background: #25D366; border: none; color: white; display: flex; align-items: center; gap: 6px; cursor: pointer; text-decoration: none;">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.977h.004c4.368 0 7.927-3.56 7.93-7.928a7.886 7.886 0 0 0-2.327-5.615zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.69-4.98c-.204-.104-1.207-.596-1.394-.664-.189-.07-.326-.104-.462.104-.137.207-.53.664-.65.804-.12.137-.24.154-.444.053-.204-.1-.864-.319-1.646-1.018-.607-.542-1.018-1.213-1.137-1.418-.12-.204-.013-.315.088-.416.09-.091.204-.24.306-.36.1-.12.133-.2.2-.333.067-.133.033-.25-.017-.35-.05-.1-462-1.114-.63-1.523-.164-.397-.335-.343-.462-.35-.126-.007-.271-.007-.416-.007a.81.81 0 0 0-.588.275c-.204.207-.78.761-.78 1.857 0 1.095.8 2.153.91 2.302.112.15 1.573 2.4 3.81 3.364.533.23 1.0.367 1.343.475.534.17 1.02.146 1.402.089.426-.064 1.207-.493 1.378-.967.172-.474.172-.88.12-.967-.05-.084-.189-.133-.393-.237z"/>
                </svg>
                Check WhatsApp
            </a>

            <a href="add.php" class="btn">+ Add Enquiry</a>
            <button onclick="toggleDeleteMode()" class="btn" style="background: #ef4444; border: none; color: white; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                🗑 Delete Options
            </button>
        </div>
    </div>
    
    <hr>

    <form method="GET" action="list.php" style="margin: 20px 0; display: flex; flex-wrap: wrap; gap: 16px; align-items: center; background: #ffffff; padding: 12px 20px; border-radius: 10px; border: 1px solid #eaedf2; box-shadow: var(--shadow-sm);">
        <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">

        <div style="display: flex; align-items: center; gap: 8px;">
            <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Date:</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="this.form.submit()" style="padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border-dark); font-size: 12px; outline: none; background: #f8fafc; font-family: inherit; color: var(--text-primary);">
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Status:</label>
            <select name="status" onchange="this.form.submit()" style="padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border-dark); font-size: 12px; outline: none; background: #f8fafc; font-family: inherit; min-width: 125px; color: var(--text-primary);">
                <option value="">All Statuses</option>
                <option value="New" <?php echo $filter_status === 'New' ? 'selected' : ''; ?>>New</option>
                <option value="Seen" <?php echo $filter_status === 'Seen' ? 'selected' : ''; ?>>Seen</option>
                <option value="Replied" <?php echo $filter_status === 'Replied' ? 'selected' : ''; ?>>Replied</option>
                <option value="Follow-up" <?php echo $filter_status === 'Follow-up' ? 'selected' : ''; ?>>Follow-up</option>
                <option value="Converted" <?php echo $filter_status === 'Converted' ? 'selected' : ''; ?>>Converted</option>
                <option value="Cancelled" <?php echo $filter_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                <option value="Booked" <?php echo $filter_status === 'Booked' ? 'selected' : ''; ?>>Booked</option>
            </select>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Source:</label>
            <select name="source" onchange="this.form.submit()" style="padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border-dark); font-size: 12px; outline: none; background: #f8fafc; font-family: inherit; min-width: 125px; color: var(--text-primary);">
                <option value="">All Sources</option>
                <?php foreach ($sources as $source_val): ?>
                    <option value="<?php echo htmlspecialchars($source_val); ?>" <?php echo $filter_source === $source_val ? 'selected' : ''; ?>><?php echo htmlspecialchars($source_val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="auto-clean-container" style="display: flex; align-items: center; gap: 8px; margin-left: auto;">
            <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Auto-Clean:</label>
            <select onchange="updateAutoDeleteSettings(this.value)" style="padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border-dark); font-size: 12px; outline: none; background: #f8fafc; font-family: inherit; color: var(--text-primary); cursor: pointer;">
                <option value="0" <?php echo $auto_delete_days === 0 ? 'selected' : ''; ?>>Disabled</option>
                <option value="7" <?php echo $auto_delete_days === 7 ? 'selected' : ''; ?>>1 Week</option>
                <option value="14" <?php echo $auto_delete_days === 14 ? 'selected' : ''; ?>>2 Weeks</option>
                <option value="30" <?php echo $auto_delete_days === 30 ? 'selected' : ''; ?>>30 Days</option>
                <option value="90" <?php echo $auto_delete_days === 90 ? 'selected' : ''; ?>>90 Days</option>
            </select>
        </div>

        <?php if ($filter_date !== '' || $filter_status !== '' || $filter_source !== '' || $search !== ''): ?>
            <a href="list.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 11px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;">✕ Clear Filters</a>
        <?php endif; ?>
    </form>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            Enquiry saved successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">
            Enquiry deleted successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['email_import'])): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            <?php echo intval($_GET['email_import']); ?> new enquiries imported from email!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['whatsapp_import'])): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            <?php echo intval($_GET['whatsapp_import']); ?> new enquiries imported from WhatsApp!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['whatsapp_error'])): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">
            <?php echo htmlspecialchars($_GET['whatsapp_error']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['customer_added'])): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            Enquiry converted and saved to Customers successfully!
        </div>
    <?php endif; ?>

    <div class="table-card" style="background: transparent; border: none; box-shadow: none; padding: 0;">
        <!-- Select All Bar (Visible in Delete Mode) -->
        <div class="inbox-select-col select-col" style="margin-bottom: 12px; background: #ffffff; padding: 12px 16px; border-radius: 12px; border: 1px solid var(--border-color); align-items: center; gap: 8px;">
            <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
            <label for="selectAll" style="font-weight: 600; cursor: pointer; color: var(--text-secondary);">Select All Chats</label>
        </div>

        <div class="inbox-list">
            <?php if (mysqli_num_rows($data) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($data)): ?>
                    <?php 
                    $is_unread = (strtolower($row['status'] ?? '') === 'new');
                    ?>
                    <div class="inbox-row <?php echo $is_unread ? 'unread' : 'read'; ?>" onclick="location.href='chat.php?id=<?php echo $row['id']; ?>'">
                        <!-- Checkbox (Visible in Delete Mode) -->
                        <div class="inbox-select-col select-col">
                            <input type="checkbox" value="<?php echo $row['id']; ?>" class="row-checkbox" onclick="event.stopPropagation(); toggleRowCheckbox();">
                        </div>

                        <!-- Unread Dot Column -->
                        <div class="inbox-dot-col">
                            <?php if ($is_unread): ?>
                                <span class="inbox-dot"></span>
                            <?php endif; ?>
                        </div>

                        <!-- Customer Name -->
                        <div class="inbox-name-col">
                            <?php echo htmlspecialchars($row['customer_name']); ?>
                            <?php if ($row['message_count'] > 1): ?>
                                <span class="inbox-msg-count">(<?php echo $row['message_count']; ?>)</span>
                            <?php endif; ?>
                        </div>

                        <!-- Subject & Message Snippet -->
                        <div class="inbox-message-col">
                            <span class="inbox-subject"><?php echo htmlspecialchars($row['subject'] ?: 'No Subject'); ?></span>
                            <span class="inbox-separator"> - </span>
                            <span class="inbox-snippet">
                                <?php 
                                $desc = trim($row['description'] ?? '');
                                $desc = preg_replace('/\s+/', ' ', $desc);
                                if (strlen($desc) > 120) {
                                    echo htmlspecialchars(substr($desc, 0, 120)) . '...';
                                } else {
                                    echo htmlspecialchars($desc ?: 'No message preview');
                                }
                                ?>
                            </span>
                        </div>

                        <!-- Badge Column -->
                        <div class="inbox-badge-col" style="display: flex; gap: 6px; align-items: center;">
                            <span class="inbox-badge <?php echo strtolower($row['source'] ?: 'direct'); ?>">
                                <?php echo htmlspecialchars($row['source'] ?: 'Direct'); ?>
                            </span>
                            <span class="inbox-badge <?php echo strtolower($row['status'] ?: 'new'); ?>" style="<?php 
                                $st = strtolower($row['status'] ?: 'new');
                                if ($st === 'new') echo 'background: #dbeafe; color: #1e40af;';
                                elseif ($st === 'seen') echo 'background: #f1f5f9; color: #475569;';
                                elseif ($st === 'replied') echo 'background: #e0f2fe; color: #0369a1;';
                                elseif ($st === 'follow-up') echo 'background: #fef9c3; color: #854d0e;';
                                elseif ($st === 'cancelled') echo 'background: #fee2e2; color: #991b1b;';
                                elseif ($st === 'converted') echo 'background: #dcfce7; color: #166534;';
                                elseif ($st === 'booked') echo 'background: #e0e7ff; color: #3730a3;';
                                else echo 'background: #f1f5f9; color: #475569;';
                            ?>">
                                <?php echo htmlspecialchars($row['status'] ?: 'New'); ?>
                            </span>
                        </div>

                        <!-- Time Column -->
                        <div class="inbox-time-col" title="<?php echo htmlspecialchars($row['updated_at']); ?>">
                            <?php echo format_inbox_time($row['updated_at']); ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; color: var(--text-secondary); padding: 60px 0; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
                    No enquiries found in database. Click "Add Enquiry" to create one.
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border: 1px solid var(--border-color); border-radius: 12px; background: #ffffff; margin-top: 16px;">
                <div style="color: var(--text-secondary); font-size: 12px;">
                    Showing Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (Total: <?php echo $total_records; ?> chats)
                </div>
                <div style="display: flex; gap: 8px;">
                    <?php if ($page > 1): ?>
                        <a href="list.php?q=<?php echo urlencode($search); ?>&date=<?php echo urlencode($filter_date); ?>&status=<?php echo urlencode($filter_status); ?>&source=<?php echo urlencode($filter_source); ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center;">&laquo; Previous</a>
                    <?php else: ?>
                        <span class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; opacity: 0.5; cursor: not-allowed; display: inline-flex; align-items: center;">&laquo; Previous</span>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="list.php?q=<?php echo urlencode($search); ?>&date=<?php echo urlencode($filter_date); ?>&status=<?php echo urlencode($filter_status); ?>&source=<?php echo urlencode($filter_source); ?>&page=<?php echo $page + 1; ?>" class="btn" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center;">Next &raquo;</a>
                    <?php else: ?>
                        <span class="btn" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; opacity: 0.5; cursor: not-allowed; display: inline-flex; align-items: center;">Next &raquo;</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>





<script>


function toggleDeleteMode() {
    const container = document.getElementById('enquiry-dashboard');
    container.classList.toggle('delete-mode-active');
    
    // If delete mode was turned off, clear checkbox selections
    if (!container.classList.contains('delete-mode-active')) {
        document.getElementById('btnBulkDelete').style.display = 'none';
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
    } else {
        updateBulkDeleteButton();
    }
}

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = master.checked;
    });
    updateBulkDeleteButton();
}

function toggleRowCheckbox() {
    const master = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.row-checkbox');
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
    
    master.checked = (checkedCount === checkboxes.length);
    updateBulkDeleteButton();
}

function updateBulkDeleteButton() {
    const btn = document.getElementById('btnBulkDelete');
    const countSpan = document.getElementById('deleteCount');
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
    
    if (checkedCount > 0) {
        countSpan.textContent = checkedCount;
        btn.style.display = 'inline-flex';
    } else {
        btn.style.display = 'none';
    }
}

function deleteSelectedEnquiries() {
    const checkedCheckboxes = document.querySelectorAll('.row-checkbox:checked');
    const ids = Array.from(checkedCheckboxes).map(cb => cb.value);
    
    if (ids.length === 0) return;
    
    if (!confirm(`Are you sure you want to delete the ${ids.length} selected enquiries? This will also delete their conversation logs.`)) {
        return;
    }
    
    const formData = new FormData();
    ids.forEach(id => formData.append('ids[]', id));
    
    fetch('bulk_delete.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Failed to delete selected items.');
    });
}

function updateAutoDeleteSettings(days) {
    const formData = new FormData();
    formData.append('days', days);
    
    fetch('save_auto_delete.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Auto-clean settings updated successfully!');
            window.location.reload();
        } else {
            alert('Error updating settings: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Failed to save settings.');
    });
}

// Auto-check WhatsApp in background
let listPollId = null;
function startListPolling() {
    if (listPollId) return;
    listPollId = setInterval(pollCheckWhatsApp, 10000);
}
function stopListPolling() {
    if (listPollId) {
        clearInterval(listPollId);
        listPollId = null;
    }
}
function pollCheckWhatsApp() {
    fetch('check_whatsapp.php?ajax=1')
    .then(res => res.json())
    .then(data => {
        if (data.success && data.imported > 0) {
            window.location.reload();
        }
    })
    .catch(err => console.error('Auto-check error:', err));
}

// Initial triggers
startListPolling();

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopListPolling();
    } else {
        pollCheckWhatsApp();
        startListPolling();
    }
});
</script>
</body>
</html>
<?php
// Mark viewed enquiries as notified
mysqli_query($db, "UPDATE enquiries SET notified = 1 WHERE notified = 0");
?>

