<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");

check_auth('admin');

$message = "";
$message_type = "";

// Fetch agents list
$agents_res = mysqli_query($db, "SELECT name FROM users ORDER BY name ASC");

if (isset($_POST["save_enquiry"])) {
    $customer_name = mysqli_real_escape_string($db, $_POST["customer_name"]);
    $mobile = mysqli_real_escape_string($db, $_POST["mobile"]);
    $email = mysqli_real_escape_string($db, $_POST["email"]);
    $subject = mysqli_real_escape_string($db, $_POST["subject"]);
    $description = mysqli_real_escape_string($db, $_POST["description"]);
    $source = mysqli_real_escape_string($db, $_POST["source"]);
    $status = mysqli_real_escape_string($db, $_POST["status"]);
    $assigned_user = mysqli_real_escape_string($db, $_POST["assigned_user"]);
    
    $created_by = $_SESSION['user_name'] ?? 'System';

    if (empty($customer_name)) {
        $message = "Customer Name is required.";
        $message_type = "error";
    } else {
        $check_exist = null;
        if (!empty($mobile)) {
            $mobile_condition = get_mobile_matching_sql($db, 'mobile', $mobile);
            $check_res = mysqli_query($db, "SELECT id FROM enquiries WHERE $mobile_condition LIMIT 1");
            if (mysqli_num_rows($check_res) > 0) {
                $check_exist = mysqli_fetch_assoc($check_res);
            }
        }

        if ($check_exist) {
            $enquiry_id = $check_exist['id'];
            $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text) 
                          VALUES ($enquiry_id, '$mobile', 'incoming', '$description')";
            $update_enq = "UPDATE enquiries SET description = '$description', notified = 0, status = 'New', updated_at = NOW() WHERE id = $enquiry_id";
            
            if (mysqli_query($db, $msg_query) && mysqli_query($db, $update_enq)) {
                header("Location: list.php?success=1");
                exit;
            } else {
                $message = "Error appending message: " . mysqli_error($db);
                $message_type = "error";
            }
        } else {
            $query = "INSERT INTO enquiries (customer_name, mobile, email, subject, description, source, status, assigned_user, created_by) 
                      VALUES ('$customer_name', '$mobile', '$email', '$subject', '$description', '$source', '$status', '$assigned_user', '$created_by')";
            if (mysqli_query($db, $query)) {
                $enquiry_id = mysqli_insert_id($db);
                if (!empty($mobile)) {
                    $msg_query = "INSERT INTO enquiry_messages (enquiry_id, mobile, direction, message_text) 
                                  VALUES ($enquiry_id, '$mobile', 'incoming', '$description')";
                    mysqli_query($db, $msg_query);
                }
                header("Location: list.php?success=1");
                exit;
            } else {
                $message = "Error creating enquiry: " . mysqli_error($db);
                $message_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Enquiry | <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
</head>
<body>

<?php include(__DIR__ . "/../../includes/sidebar.php"); ?>

<div class="main">
    <div class="header">
        <input class="search" placeholder="Search...">
        <span class="notify">🔔</span>
        <a href="../profile/index.php" class="profile-widget">
            <span>👤 Profile</span>
        </a>
    </div>

    <div class="dashboard-title-row">
        <h1>Add New Enquiry</h1>
        <a href="list.php" class="btn btn-secondary">Back to List</a>
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

    <div class="card" style="max-width: 600px; margin: 0 auto; padding: 24px;">
        <div style="margin-bottom: 20px; border-bottom: 1px solid var(--border-dark); padding-bottom: 15px;">
            <label style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">📝 Paste Raw Chat / Enquiry Text</label>
            <textarea id="rawChatText" oninput="parseChatText()" placeholder="Paste raw WhatsApp export or email text here to auto-fill..." style="width: 100%; height: 75px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-dark); border-radius: 8px; padding: 8px; color: var(--text-main); font-family: inherit; font-size: 12px; resize: none; outline: none; transition: border-color 0.15s;"></textarea>
        </div>
        <form method="POST" action="">
            <div class="form-group">
                <label for="customer_name">Customer Name *</label>
                <input type="text" id="customer_name" name="customer_name" required placeholder="e.g. John Doe">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mobile">Mobile Number</label>
                    <input type="text" id="mobile" name="mobile" placeholder="e.g. +1234567890">
                </div>
                <div class="form-group" style="flex: 0.7;">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="e.g. john@example.com">
                </div>
            </div>

            <div class="form-group">
                <label for="subject">Subject / Product Interested</label>
                <input type="text" id="subject" name="subject" placeholder="e.g. Europe Tour Package 7 Days">
            </div>

            <div class="form-group">
                <label for="description">Enquiry Details</label>
                <textarea id="description" name="description" placeholder="Travel details, budget, preferences..." style="min-height: 240px;"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="source">Source</label>
                    <select id="source" name="source">
                        <option value="Direct">Direct / Walk-in</option>
                        <option value="Website">Website</option>
                        <option value="WhatsApp">WhatsApp</option>
                        <option value="Referral">Referral</option>
                        <option value="Social Media">Social Media</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="New">New</option>
                        <option value="Seen">Seen</option>
                        <option value="Replied">Replied</option>
                        <option value="Follow-up">Follow-up</option>
                        <option value="Converted">Converted</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Booked">Booked</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="assigned_user">Assign Agent</label>
                <select id="assigned_user" name="assigned_user">
                    <option value="">-- Unassigned --</option>
                    <?php while ($agent = mysqli_fetch_assoc($agents_res)): ?>
                        <option value="<?php echo htmlspecialchars($agent['name']); ?>" <?php if (isset($_SESSION['user_name']) && $_SESSION['user_name'] === $agent['name']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($agent['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button type="submit" name="save_enquiry" class="btn" style="width: 100%; margin-top: 10px; background: var(--sidebar-link-active-bg);">
                💾 Save Enquiry
            </button>
        </form>
    </div>
</div>

<script>
function parseChatText() {
    const text = document.getElementById('rawChatText').value;
    const parsed = parseWhatsAppMessage(text);
    
    if (parsed.name) document.getElementById('customer_name').value = parsed.name;
    if (parsed.mobile) document.getElementById('mobile').value = parsed.mobile;
    if (parsed.email) document.getElementById('email').value = parsed.email;
    if (parsed.subject) document.getElementById('subject').value = parsed.subject;
    if (parsed.description) document.getElementById('description').value = parsed.description;
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
</script>
</body>
</html>

