<?php
require_once(__DIR__ . "/../includes/db.php");
require_once(__DIR__ . "/../includes/auth.php");
check_auth();

$user_name = mysqli_real_escape_string($db, $_SESSION['user_name'] ?? '');

$where_clauses = [];

if (!empty($_GET['filter_date'])) {
    $filter_date = mysqli_real_escape_string($db, $_GET['filter_date']);
    $where_clauses[] = "DATE(created_at) = '$filter_date'";
}

if (!empty($_GET['filter_source'])) {
    $filter_source = mysqli_real_escape_string($db, $_GET['filter_source']);
    $where_clauses[] = "source = '$filter_source'";
}



if (!empty($_GET['filter_status'])) {
    $filter_status = mysqli_real_escape_string($db, $_GET['filter_status']);
    $where_clauses[] = "status = '$filter_status'";
}

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}
$query = "SELECT * FROM enquiries $where_sql ORDER BY id DESC";
$data = mysqli_query($db, $query);
if (!$data) {
    die("Database query failed: " . mysqli_error($db));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Enquiry Management | Travel CRM</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../assets/js/sidebar.js" defer></script>
</head>
<body>

<div class="sidebar">
    <h2 class="logo">✈ Travel CRM</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="bookings.php">My Bookings</a>
    <a href="bookings_add.php">Add Booking</a>
    <a href="customers.php">Customers</a>
    <a href="enquiry.php" class="active">Enquiry</a>
    <a href="activity.php">My Tasks</a>
    <a href="../login.php" class="logout">Logout</a>
</div>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search enquiries...">
        <span class="notify">🔔</span>
        <a href="profile.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Enquiries</h1>
        <div style="display: flex; gap: 8px;">
            <button onclick="openImportModal()" class="btn" style="background: #10b981; border: none; color: white; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.977h.004c4.368 0 7.927-3.56 7.93-7.928a7.886 7.886 0 0 0-2.327-5.615zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.69-4.98c-.204-.104-1.207-.596-1.394-.664-.189-.07-.326-.104-.462.104-.137.207-.53.664-.65.804-.12.137-.24.154-.444.053-.204-.1-.864-.319-1.646-1.018-.607-.542-1.018-1.213-1.137-1.418-.12-.204-.013-.315.088-.416.09-.091.204-.24.306-.36.1-.12.133-.2.2-.333.067-.133.033-.25-.017-.35-.05-.1-462-1.114-.63-1.523-.164-.397-.335-.343-.462-.35-.126-.007-.271-.007-.416-.007a.81.81 0 0 0-.588.275c-.204.207-.78.761-.78 1.857 0 1.095.8 2.153.91 2.302.112.15 1.573 2.4 3.81 3.364.533.23 1.0.367 1.343.475.534.17 1.02.146 1.402.089.426-.064 1.207-.493 1.378-.967.172-.474.172-.88.12-.967-.05-.084-.189-.133-.393-.237z"/>
                </svg>
                Import WhatsApp
            </button>
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
            <a href="enquiry_add.php" class="btn">+ Add Enquiry</a>
        </div>
    </div>
    
    <hr>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div style="padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; 
            background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            Enquiry saved successfully!
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

    <!-- Dynamic Filter Form -->
    <div class="card" style="margin-bottom: 20px; padding: 16px;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <div style="flex: 1; min-width: 150px;">
                <label for="filter_date" style="margin-bottom: 6px; font-weight: 600; font-size: 11px;">Filter by Date</label>
                <input type="date" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($_GET['filter_date'] ?? ''); ?>" style="padding: 6px 10px; font-size: 12px;">
            </div>
            
            <div style="flex: 1; min-width: 150px;">
                <label for="filter_source" style="margin-bottom: 6px; font-weight: 600; font-size: 11px;">Filter by Source</label>
                <select id="filter_source" name="filter_source" style="padding: 6px 10px; font-size: 12px;">
                    <option value="">All Sources</option>
                    <option value="WhatsApp" <?php if(($_GET['filter_source'] ?? '') === 'WhatsApp') echo 'selected'; ?>>WhatsApp</option>
                    <option value="Email" <?php if(($_GET['filter_source'] ?? '') === 'Email') echo 'selected'; ?>>Email</option>
                    <option value="Direct" <?php if(($_GET['filter_source'] ?? '') === 'Direct') echo 'selected'; ?>>Direct</option>
                    <option value="Website" <?php if(($_GET['filter_source'] ?? '') === 'Website') echo 'selected'; ?>>Website</option>
                    <option value="Referral" <?php if(($_GET['filter_source'] ?? '') === 'Referral') echo 'selected'; ?>>Referral</option>
                    <option value="Social Media" <?php if(($_GET['filter_source'] ?? '') === 'Social Media') echo 'selected'; ?>>Social Media</option>
                </select>
            </div>



            <div style="flex: 1; min-width: 150px;">
                <label for="filter_status" style="margin-bottom: 6px; font-weight: 600; font-size: 11px;">Filter by Status</label>
                <select id="filter_status" name="filter_status" style="padding: 6px 10px; font-size: 12px;">
                    <option value="">All Statuses</option>
                    <option value="New" <?php if(($_GET['filter_status'] ?? '') === 'New') echo 'selected'; ?>>New</option>
                    <option value="Seen" <?php if(($_GET['filter_status'] ?? '') === 'Seen') echo 'selected'; ?>>Seen</option>
                    <option value="Replied" <?php if(($_GET['filter_status'] ?? '') === 'Replied') echo 'selected'; ?>>Replied</option>
                    <option value="Follow-up" <?php if(($_GET['filter_status'] ?? '') === 'Follow-up') echo 'selected'; ?>>Follow-up</option>
                    <option value="Converted" <?php if(($_GET['filter_status'] ?? '') === 'Converted') echo 'selected'; ?>>Converted</option>
                    <option value="Cancelled" <?php if(($_GET['filter_status'] ?? '') === 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn" style="padding: 7px 16px;">Filter</button>
                <?php if (!empty($_GET['filter_date']) || !empty($_GET['filter_source']) || !empty($_GET['filter_status'])): ?>
                    <a href="enquiry.php" class="btn btn-secondary" style="padding: 7px 16px;">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-card">
        <table style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 4%;">ID</th>
                    <th style="width: 10%;">Customer Name</th>
                    <th style="width: 13%;">Mobile</th>
                    <th style="width: 8%;">Email</th>
                    <th style="width: 12%;">Subject</th>
                    <th style="width: 32%;">Message</th>
                    <th style="width: 6%;">Source</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 15%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($data) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($data)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row["id"]); ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($row["customer_name"]); ?></td>
                        <td style="font-weight: 600;">
                            <?php echo htmlspecialchars($row["mobile"] ?: '-'); ?>
                            <div style="margin-top: 4px;">
                                <?php 
                                $wa_vars = [
                                    'customer' => $row['customer_name'],
                                    'service' => $row['subject']
                                ];
                                echo get_whatsapp_dropdown($row['mobile'], $wa_vars);
                                ?>
                            </div>
                        </td>
                        <td>
                            <div style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; word-break: break-all; font-size: 12px; color: var(--text-primary);">
                                <?php echo htmlspecialchars($row["email"] ?: '-'); ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($row["subject"] ?: '-'); ?></td>
                        <td style="font-size: 12px; color: var(--text-primary); word-break: break-word;">
                            <?php 
                            $desc = $row["description"] ?? '';
                            if (strlen($desc) > 180) {
                                echo htmlspecialchars(substr($desc, 0, 180)) . '...';
                            } else {
                                echo htmlspecialchars($desc ?: '-');
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($row["source"] ?: 'Direct'); ?></td>
                        <td>
                            <div class="inline-status-wrapper" style="position: relative; display: inline-flex; align-items: center; gap: 6px;">
                                <select class="status-select-inline <?php echo strtolower($row['status'] ?: 'new'); ?>" 
                                        data-id="<?php echo $row['id']; ?>" 
                                        onchange="updateEnquiryStatus(this)">
                                    <option value="New" <?php if($row['status'] == 'New') echo 'selected'; ?>>New</option>
                                    <option value="Seen" <?php if($row['status'] == 'Seen') echo 'selected'; ?>>Seen</option>
                                    <option value="Replied" <?php if($row['status'] == 'Replied') echo 'selected'; ?>>Replied</option>
                                    <option value="Follow-up" <?php if($row['status'] == 'Follow-up') echo 'selected'; ?>>Follow-up</option>
                                    <option value="Converted" <?php if($row['status'] == 'Converted') echo 'selected'; ?>>Converted</option>
                                    <option value="Cancelled" <?php if($row['status'] == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                                </select>
                                <span class="spinner-inline" id="spinner-<?php echo $row['id']; ?>" style="display: none; width: 12px; height: 12px; border: 2px solid rgba(0,0,0,0.1); border-top-color: var(--accent-color); border-radius: 50%; animation: spin 0.8s linear infinite;"></span>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <a href="enquiry_view.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px;">View</a>
                                <a href="convert_customer.php?id=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; background: #e8f4fd; color: #1a73e8; border: 1px solid #c5def7;" onclick="return confirm('Convert this enquiry to a saved Customer?');">👤 Add Customer</a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--text-secondary); padding: 40px 0;">
                            No enquiries found. Click "Add Enquiry" to create one.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Import Modal -->
<div id="importModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-dark); border-radius: 16px; width: 100%; max-width: 600px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); position: relative; color: var(--text-main); font-family: sans-serif;">
        <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 18px; color: var(--accent-color); display: flex; align-items: center; gap: 8px;">
            ⚡ Import WhatsApp Enquiry
        </h3>
        
        <div style="margin-bottom: 16px;">
            <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px;">Paste WhatsApp Message Text</label>
            <textarea id="importRawText" oninput="liveParseImport()" placeholder="Paste the message copied from WhatsApp here..." style="width: 100%; min-height: 120px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-dark); border-radius: 8px; padding: 10px; color: var(--text-main); font-family: inherit; font-size: 13px;"></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
            <div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Customer Name</label>
                <input type="text" id="importName" style="width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--border-dark); border-radius: 6px; padding: 8px; color: var(--text-main); font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Mobile Number</label>
                <input type="text" id="importMobile" style="width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--border-dark); border-radius: 6px; padding: 8px; color: var(--text-main); font-size: 13px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 0.7fr 1.3fr; gap: 12px; margin-bottom: 16px;">
            <div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Email Address</label>
                <input type="email" id="importEmail" style="width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--border-dark); border-radius: 6px; padding: 8px; color: var(--text-main); font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Subject</label>
                <input type="text" id="importSubject" style="width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--border-dark); border-radius: 6px; padding: 8px; color: var(--text-main); font-size: 13px;">
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">Requirements / Description</label>
            <textarea id="importDescription" style="width: 100%; min-height: 200px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-dark); border-radius: 8px; padding: 8px; color: var(--text-main); font-size: 13px;"></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button onclick="closeImportModal()" class="btn btn-secondary" style="padding: 10px 18px; font-size: 13px; border-radius: 8px; cursor: pointer; color: var(--text-main);">Cancel</button>
            <button onclick="submitImportedEnquiry()" class="btn" style="background: #10b981; border: none; color: white; padding: 10px 18px; font-size: 13px; border-radius: 8px; cursor: pointer;">Save Enquiry</button>
        </div>
    </div>
</div>

<script>
function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
    document.getElementById('importRawText').value = '';
    document.getElementById('importName').value = '';
    document.getElementById('importMobile').value = '';
    document.getElementById('importEmail').value = '';
    document.getElementById('importSubject').value = '';
    document.getElementById('importDescription').value = '';
    document.getElementById('importRawText').focus();
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

function liveParseImport() {
    const text = document.getElementById('importRawText').value;
    const parsed = parseWhatsAppMessage(text);
    
    document.getElementById('importName').value = parsed.name;
    document.getElementById('importMobile').value = parsed.mobile;
    document.getElementById('importEmail').value = parsed.email;
    document.getElementById('importSubject').value = parsed.subject;
    document.getElementById('importDescription').value = parsed.description;
}

function parseWhatsAppMessage(text) {
    const lines = text.split('\n');
    let name = '';
    let mobile = '';
    let email = '';
    let subject = '';
    let description = '';

    for (let line of lines) {
        line = line.trim();
        if (/^(👤\s*)?(Customer\s+)?Name\s*:\s*(.+)$/i.test(line)) {
            name = line.match(/^(?:👤\s*)?(?:Customer\s+)?Name\s*:\s*(.+)$/i)[1] || line.match(/^(?:👤\s*)?(?:Customer\s+)?Name\s*:\s*(.+)$/i)[3] || '';
            name = name.trim();
        } else if (/^👤\s*(.+)$/i.test(line) && !line.includes(':')) {
            name = line.match(/^👤\s*(.+)$/i)[1].trim();
        }
        if (/^(📞\s*)?(Mobile|Phone)\s*:\s*(.+)$/i.test(line)) {
            mobile = line.match(/^(?:📞\s*)?(?:Mobile|Phone)\s*:\s*(.+)$/i)[3].trim();
        } else if (/^📞\s*(.+)$/i.test(line) && !line.includes(':')) {
            mobile = line.match(/^📞\s*(.+)$/i)[1].trim();
        }
        if (/^(✉\s*)?Email\s*:\s*(.+)$/i.test(line)) {
            email = line.match(/^(?:✉\s*)?Email\s*:\s*(.+)$/i)[2].trim();
        } else if (/^✉\s*(.+)$/i.test(line) && !line.includes(':')) {
            email = line.match(/^✉\s*(.+)$/i)[1].trim();
        }
        if (/^(📌\s*)?(Subject|Interest|Product)\s*:\s*(.+)$/i.test(line)) {
            subject = line.match(/^(?:📌\s*)?(?:Subject|Interest|Product)\s*:\s*(.+)$/i)[3].trim();
        } else if (/^📌\s*(.+)$/i.test(line) && !line.includes(':')) {
            subject = line.match(/^📌\s*(.+)$/i)[1].trim();
        }
        if (/^(📝\s*)?(Details|Description|Message)\s*:\s*(.+)$/i.test(line)) {
            description = line.match(/^(?:📝\s*)?(?:Details|Description|Message)\s*:\s*(.+)$/i)[3].trim();
        } else if (/^📝\s*(.+)$/i.test(line) && !line.includes(':')) {
            description = line.match(/^📝\s*(.+)$/i)[1].trim();
        }
    }
    
    if (!name) {
        const nameMatch = text.match(/(?:name|customer)\s*:\s*([^\n\r]+)/i);
        if (nameMatch) name = nameMatch[1].trim();
    }
    if (!mobile) {
        const mobileMatch = text.match(/(?:mobile|phone|contact)\s*:\s*([^\n\r]+)/i);
        if (mobileMatch) mobile = mobileMatch[1].trim();
    }
    if (!email) {
        const emailMatch = text.match(/(?:email)\s*:\s*([^\n\r]+)/i);
        if (emailMatch) email = emailMatch[1].trim();
    }
    if (!subject) {
        const subjectMatch = text.match(/(?:subject|interest|product)\s*:\s*([^\n\r]+)/i);
        if (subjectMatch) subject = subjectMatch[1].trim();
    }
    if (!description) {
        const descMatch = text.match(/(?:details|description|message|text|query)\s*:\s*([\s\S]+)$/i);
        if (descMatch) description = descMatch[1].trim();
    }

    return { name, mobile, email, subject, description };
}

function submitImportedEnquiry() {
    const name = document.getElementById('importName').value.trim();
    const mobile = document.getElementById('importMobile').value.trim();
    const email = document.getElementById('importEmail').value.trim();
    const subject = document.getElementById('importSubject').value.trim();
    const description = document.getElementById('importDescription').value.trim();

    if (!name) {
        alert('Customer Name is required.');
        return;
    }

    const formData = new FormData();
    formData.append('customer_name', name);
    formData.append('mobile', mobile);
    formData.append('email', email);
    formData.append('subject', subject);
    formData.append('description', description);

    fetch('ajax_import_enquiry.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeImportModal();
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Failed to connect to CRM server.');
    });
}

function updateEnquiryStatus(selectEl) {
    const id = selectEl.getAttribute('data-id');
    const newStatus = selectEl.value;
    const spinner = document.getElementById('spinner-' + id);
    
    if (spinner) spinner.style.display = 'inline-block';
    selectEl.disabled = true;

    const formData = new FormData();
    formData.append('id', id);
    formData.append('status', newStatus);

    fetch('ajax_update_status.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            selectEl.className = 'status-select-inline ' + newStatus.toLowerCase().replace(/\s+/g, '-');
        } else {
            alert('Failed to update status: ' + data.message);
            if (data.current_status) {
                selectEl.value = data.current_status;
            }
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error updating status.');
    })
    .finally(() => {
        if (spinner) spinner.style.display = 'none';
        selectEl.disabled = false;
    });
}
</script>
</body>
</html>
