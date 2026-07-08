<?php
require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/auth.php";
check_auth("admin");

$id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

// Fetch enquiry
$query = "SELECT * FROM enquiries WHERE id = $id";
$result = mysqli_query($db, $query);
$enquiry = mysqli_fetch_assoc($result);

if (!$enquiry) {
    echo "<div style='padding: 40px; text-align: center; font-family: sans-serif;'><h2>Enquiry not found.</h2><a href='list.php'>Back to list</a></div>";
    exit();
}

// Mark status as 'Seen' if it was 'New'
if ($enquiry["status"] === "New") {
    mysqli_query(
        $db,
        "UPDATE enquiries SET status = 'Seen', updated_at = NOW() WHERE id = $id",
    );
    $enquiry["status"] = "Seen";
}

// Fetch all messages in this conversation chat (grouping by mobile number or matching enquiry_id)
$mobile = trim($enquiry["mobile"] ?? "");
if ($mobile !== "") {
    $mobile_condition = get_mobile_matching_sql($db, "mobile", $mobile);
    $msg_query = "SELECT * FROM enquiry_messages WHERE enquiry_id = $id OR $mobile_condition ORDER BY created_at ASC";
} else {
    $msg_query = "SELECT * FROM enquiry_messages WHERE enquiry_id = $id ORDER BY created_at ASC";
}
$msg_result = mysqli_query($db, $msg_query);
$message_count = mysqli_num_rows($msg_result);

// Calculate step index for journey progress strip
$status = $enquiry["status"] ?? "New";
$is_cancelled = strtolower($status) === "cancelled";
$step_index = 0; // 0 = New, 1 = Seen, 2 = Replied, 3 = Booked
if ($is_cancelled) {
    // Show where progress halted (if they had replies, show Replied (2), otherwise Seen (1))
    $step_index = $message_count > 0 ? 2 : 1;
} else {
    if (strtolower($status) === "seen") {
        $step_index = 1;
    } elseif (in_array(strtolower($status), ["replied", "follow-up"])) {
        $step_index = 2;
    } elseif (in_array(strtolower($status), ["booked", "converted"])) {
        $step_index = 3;
    }
}

// Date formatting helper for chat view
function format_chat_date($datetime)
{
    $time = strtotime($datetime);
    $today = strtotime("today");
    $yesterday = strtotime("yesterday");

    if (date("Y-m-d", $time) === date("Y-m-d", $today)) {
        return "Today";
    } elseif (date("Y-m-d", $time) === date("Y-m-d", $yesterday)) {
        return "Yesterday";
    } else {
        return date("j F Y", $time);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Enquiry Conversation | Travel CRM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script>if(localStorage.getItem("sidebar-locked")==="true")document.documentElement.classList.add("sidebar-pref-locked");</script>
    <script src="../../assets/js/sidebar.js" defer></script>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary-color: #64748b;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --bg-white: #ffffff;
            --bg-light: #f8fafc;
            --bg-gray: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --border-dark: #cbd5e1;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        /* Clean White Theme */
        body {
            animation: fadeIn 0.3s ease-out;
            background: var(--bg-light);
            min-height: 100vh;
            color: var(--text-primary);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Main Container */
        .main {
            background: var(--bg-white);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            margin: 20px;
            padding: 32px;
            min-height: calc(100vh - 40px);
        }

        /* Header */
        .header {
            background: var(--bg-white);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 16px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        /* Dashboard Title Row */
        .dashboard-title-row {
            background: var(--bg-light);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }

        .dashboard-title-row h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        /* Buttons */
        .btn {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: var(--bg-white);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-light);
            border-color: var(--border-dark);
        }

        /* Messaging App Container */
        .messaging-app-container {
            background: var(--bg-white);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            max-width: 900px;
            margin: 0 auto;
            height: 700px;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Header Bar */
        .messaging-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            background: var(--bg-white);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .messaging-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--primary-color);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            box-shadow: var(--shadow-sm);
        }

        .messaging-customer-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .messaging-customer-phone {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Connection Status */
        .connection-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .connection-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success-color);
            animation: pulse 2s infinite;
        }

        .connection-dot.connecting {
            background: var(--warning-color);
            animation: pulse 1s infinite;
        }

        .connection-dot.offline {
            background: var(--danger-color);
            animation: none;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Chat Area */
        .messaging-chat-area {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 20px;
            background: var(--bg-light);
            position: relative;
            scroll-behavior: smooth;
        }

        .messaging-chat-area::-webkit-scrollbar {
            width: 6px;
        }

        .messaging-chat-area::-webkit-scrollbar-track {
            background: var(--bg-gray);
            border-radius: 3px;
        }

        .messaging-chat-area::-webkit-scrollbar-thumb {
            background: var(--border-dark);
            border-radius: 3px;
        }

        .messaging-chat-area::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* Date Separator */
        .date-separator {
            align-self: center;
            background: var(--bg-white);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 12px;
            margin: 12px 0;
            border: 1px solid var(--border-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Chat Bubbles */
        .chat-bubble {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
            position: relative;
            box-shadow: var(--shadow-sm);
            word-wrap: break-word;
            animation: bubbleIn 0.3s ease-out forwards;
            opacity: 0;
            transform: translateY(10px);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        @keyframes bubbleIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bubble-gap-same {
            margin-top: -6px;
        }

        .bubble-gap-diff {
            margin-top: 12px;
        }

        .chat-bubble.incoming {
            align-self: flex-start;
            background: var(--bg-white);
            color: var(--text-primary);
            border-bottom-left-radius: 4px;
            border: 1px solid var(--border-color);
        }

        .chat-bubble.outgoing {
            align-self: flex-end;
            background: var(--primary-color);
            color: white;
            border-bottom-right-radius: 4px;
            box-shadow: var(--shadow-md);
        }

        .bubble-staff-name {
            font-size: 10px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .bubble-media-img {
            max-width: 100%;
            max-height: 250px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            display: block;
            transition: transform 0.2s ease;
        }

        .bubble-media-img:hover {
            transform: scale(1.02);
        }

        .bubble-meta {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
            align-self: flex-end;
            margin-top: 2px;
            font-size: 10px;
            user-select: none;
        }

        .incoming .bubble-meta {
            color: var(--text-muted);
        }

        .outgoing .bubble-meta {
            color: rgba(255, 255, 255, 0.8);
        }

        .bubble-checkmarks {
            color: var(--success-color);
            font-weight: 700;
            font-size: 11px;
        }

        /* Input Bar */
        .messaging-input-bar {
            padding: 16px 24px;
            background: var(--bg-white);
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .messaging-input-bar textarea {
            flex: 1;
            min-height: 24px;
            max-height: 120px;
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 10px 16px;
            font-size: 14px;
            font-family: inherit;
            color: var(--text-primary);
            resize: none;
            outline: none;
            transition: all 0.2s ease;
        }

        .messaging-input-bar textarea:focus {
            border-color: var(--primary-color);
            background: var(--bg-white);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .send-circle-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--primary-color);
            color: #ffffff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }

        .send-circle-btn:hover {
            background: var(--primary-hover);
            transform: scale(1.05);
            box-shadow: var(--shadow-md);
        }

        .send-circle-btn:active {
            transform: scale(0.95);
        }

        .send-circle-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Status Tabs */
        .status-quick-tabs {
            display: flex;
            gap: 8px;
        }

        .status-tab-btn {
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            padding: 6px 14px;
            border-radius: 20px;
            cursor: pointer;
            background: var(--bg-white);
            color: var(--text-secondary);
            transition: all 0.2s ease;
            outline: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-tab-btn:hover {
            background: var(--bg-light);
            color: var(--text-primary);
            border-color: var(--border-dark);
        }

        .status-tab-btn.follow-up.active {
            background: #fef3c7;
            color: #b45309;
            border-color: #fde68a;
        }

        .status-tab-btn.cancelled.active {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fecaca;
        }

        /* Badges */
        .badge {
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
        }

        .badge.new {
            background-color: #fef3c7;
            color: #b45309;
        }

        .badge.seen {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        .badge.replied {
            background-color: #dcfce7;
            color: #166534;
        }

        .badge.follow-up {
            background-color: #f3e8ff;
            color: #7c3aed;
        }

        .badge.cancelled {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        /* Journey Progress */
        .journey-progress-strip {
            position: relative;
            background: var(--bg-white);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 50px;
        }

        .journey-line {
            position: absolute;
            height: 2px;
            background: var(--border-color);
            width: calc(100% - 80px);
            top: 24px;
            left: 40px;
            z-index: 1;
            border-radius: 1px;
        }

        .journey-line-filled {
            position: absolute;
            height: 2px;
            background: var(--primary-color);
            top: 24px;
            left: 40px;
            z-index: 2;
            transition: width 0.4s ease;
            border-radius: 1px;
        }

        .journey-steps {
            display: flex;
            justify-content: space-between;
            width: 100%;
            position: relative;
            z-index: 3;
        }

        .journey-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            cursor: default;
        }

        .step-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--bg-white);
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .journey-step.completed .step-dot {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .journey-step.current .step-dot {
            background: var(--primary-color);
            border-color: var(--primary-color);
            transform: scale(1.2);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2);
        }

        .step-icon {
            font-size: 8px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .step-label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: color 0.3s ease;
        }

        .journey-step.completed .step-label {
            color: var(--primary-color);
        }

        .journey-step.current .step-label {
            color: var(--primary-color);
            font-weight: 700;
        }

        /* Cancelled Banner */
        .journey-cancelled-banner {
            background: #fee2e2;
            border-bottom: 1px solid #fecaca;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #b91c1c;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cancelled-banner-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            font-size: 11px;
            font-weight: bold;
        }

        /* Success Toast */
        .success-toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--success-color);
            color: white;
            padding: 12px 24px;
            border-radius: 30px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 13px;
            z-index: 9999;
            transition: all 0.3s ease;
            opacity: 0;
            pointer-events: none;
        }

        .success-toast.active {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        .toast-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            font-weight: bold;
        }

        /* Lightbox */
        .lightbox-overlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .lightbox-overlay.active {
            display: flex;
            opacity: 1;
        }

        .lightbox-content {
            max-width: 90%;
            max-height: 90%;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .lightbox-overlay.active .lightbox-content {
            transform: scale(1);
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            user-select: none;
            transition: all 0.2s ease;
        }

        .lightbox-close:hover {
            color: var(--primary-color);
        }

        /* Attachment Styles */
        .bubble-attachment-card {
            display: flex;
            align-items: center;
            background: var(--bg-light);
            border-radius: 8px;
            padding: 10px 14px;
            gap: 10px;
            margin-bottom: 6px;
            min-width: 200px;
            max-width: 100%;
            border: 1px solid var(--border-color);
        }

        .outgoing .bubble-attachment-card {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .attachment-icon-wrapper {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: var(--primary-color);
            border-radius: 8px;
        }

        .attachment-icon-wrapper svg {
            color: white;
        }

        .attachment-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .attachment-name {
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .incoming .attachment-name {
            color: var(--text-primary);
        }

        .outgoing .attachment-name {
            color: white;
        }

        .attachment-size {
            font-size: 10px;
        }

        .incoming .attachment-size {
            color: var(--text-secondary);
        }

        .outgoing .attachment-size {
            color: rgba(255, 255, 255, 0.8);
        }

        .attachment-download-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
            text-decoration: none;
        }

        .incoming .attachment-download-btn {
            color: var(--primary-color);
        }

        .incoming .attachment-download-btn:hover {
            background: var(--bg-light);
        }

        .outgoing .attachment-download-btn {
            color: white;
            background: rgba(255, 255, 255, 0.2);
        }

        .outgoing .attachment-download-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Attachment Button */
        .attachment-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 10px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .attachment-btn:hover {
            background: var(--bg-light);
            color: var(--primary-color);
        }

        /* Input Preview Bar */
        .input-preview-bar {
            display: none;
            align-items: center;
            background: var(--bg-light);
            padding: 10px 24px;
            border-top: 1px solid var(--border-color);
            gap: 10px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .preview-chip {
            display: flex;
            align-items: center;
            background: var(--bg-white);
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: 20px;
            gap: 6px;
            max-width: 250px;
        }

        .preview-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 600;
            color: var(--text-primary);
        }

        .preview-remove {
            color: var(--danger-color);
            cursor: pointer;
            font-weight: 700;
            display: flex;
            align-items: center;
            font-size: 14px;
            line-height: 1;
            transition: color 0.2s ease;
        }

        .preview-remove:hover {
            color: #b91c1c;
        }

        /* Voice Player */
        .voice-player-container {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin: 6px 0;
            min-width: 250px;
        }

        .voice-audio-element {
            width: 100%;
            height: 40px;
            border-radius: 20px;
            outline: none;
        }

        .voice-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            padding: 0 4px;
            opacity: 0.8;
            font-weight: 500;
        }

        .chat-bubble.outgoing .voice-meta-row {
            color: rgba(255, 255, 255, 0.9);
        }

        .chat-bubble.incoming .voice-meta-row {
            color: var(--text-secondary);
        }

        /* Loading Skeleton */
        .skeleton-bubble {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeletonLoading 1.5s infinite;
            border-radius: 16px;
            height: 50px;
            width: 70%;
        }

        @keyframes skeletonLoading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Typing Indicator */
        .typing-indicator {
            display: flex;
            gap: 4px;
            padding: 10px 14px;
            background: var(--bg-white);
            border-radius: 16px;
            width: fit-content;
            margin-left: 16px;
            border: 1px solid var(--border-color);
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            background: var(--primary-color);
            border-radius: 50%;
            animation: typingBounce 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(1) { animation-delay: 0s; }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typingBounce {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main {
                margin: 10px;
                padding: 16px;
            }

            .messaging-app-container {
                height: calc(100vh - 200px);
                border-radius: 12px;
            }

            .messaging-header {
                padding: 12px 16px;
            }

            .messaging-chat-area {
                padding: 16px;
            }

            .chat-bubble {
                max-width: 85%;
                padding: 10px 14px;
            }

            .messaging-input-bar {
                padding: 12px 16px;
            }

            .journey-progress-strip {
                padding: 10px 20px;
            }

            .step-dot {
                width: 18px;
                height: 18px;
            }

            .step-label {
                font-size: 8px;
            }
        }

        /* Accessibility: Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            body, .chat-bubble, .success-toast, .lightbox-content,
            .journey-line-filled, .status-tab-btn, .send-circle-btn {
                animation: none !important;
                transition: none !important;
            }

            .chat-bubble {
                opacity: 1 !important;
                transform: none !important;
            }

            .journey-step.current .step-dot {
                animation: none !important;
            }
        }

        /* Sending Spinner */
        .sending-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Scroll to Bottom Button */
        .scroll-to-bottom {
            position: absolute;
            bottom: 70px;
            right: 16px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-md);
            transition: all 0.2s ease;
            z-index: 5;
        }

        .scroll-to-bottom:hover {
            transform: scale(1.1);
            background: var(--primary-hover);
        }

        .scroll-to-bottom.visible {
            display: flex;
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

<div class="main">


    <!-- Sticky Dashboard Title Action Row -->
    <div class="dashboard-title-row">
        <div>
            <h1 style="margin: 0; font-size: 20px; color: var(--text-primary);">
                <?php echo htmlspecialchars($enquiry["customer_name"]); ?>
            </h1>
        </div>
        <div class="time-btns" style="display: flex; gap: 8px;">
            <?php if (($enquiry["status"] ?? "") !== "Booked"): ?>
                <a href="add_booking.php?customer_name=<?php echo urlencode(
                    $enquiry["customer_name"],
                ); ?>&mobile=<?php echo urlencode(
    $enquiry["mobile"],
); ?>&email=<?php echo urlencode(
    $enquiry["email"],
); ?>&enquiry_id=<?php echo $enquiry[
    "id"
]; ?>" class="btn" style="background: #10b981; border: none; color: white;">+ Add Booking</a>
            <?php endif; ?>
            <a href="../master/add.php?name=<?php echo urlencode($enquiry['customer_name']); ?>&mobile=<?php echo urlencode($enquiry['mobile']); ?>&email=<?php echo urlencode($enquiry['email']); ?>" class="btn" style="background: #3b82f6; border: none; color: white;">👤 Add Customer</a>
            <a href="list.php" class="btn btn-secondary">Back to List</a>
        </div>
    </div>

    <hr style="margin-bottom: 20px; border-color: var(--border-dark);">

    <?php if (isset($_GET["booking_added"]) && $_GET["booking_added"] == 1): ?>
        <div style="max-width: 800px; margin: 0 auto 20px auto; padding: 12px 20px; border-radius: 12px; font-weight: 600; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
            Booking added successfully!
        </div>
    <?php endif; ?>

    <!-- ── MESSAGING APP VIEW ── -->
    <div class="messaging-app-container">
        <!-- Header Bar -->
        <div class="messaging-header">
            <div class="messaging-header-info">
                <!-- Circular Avatar -->
                <div class="messaging-avatar">
                    <?php
                    $initials = "";
                    if (!empty($enquiry["customer_name"])) {
                        $parts = explode(" ", $enquiry["customer_name"]);
                        $initials = strtoupper(substr($parts[0], 0, 1));
                        if (count($parts) > 1) {
                            $initials .= strtoupper(substr($parts[1], 0, 1));
                        }
                    }
                    echo htmlspecialchars($initials ?: "U");
                    ?>
                </div>
                <!-- Customer Details -->
                <div class="messaging-header-text">
                    <div class="messaging-header-name-row">
                        <span class="messaging-customer-name"><?php echo htmlspecialchars(
                            $enquiry["customer_name"],
                        ); ?></span>
                        <?php if (
                            strtolower($enquiry["source"] ?? "") === "whatsapp"
                        ): ?>
                            <span class="source-icon whatsapp" title="WhatsApp">💬</span>
                        <?php elseif (
                            strtolower($enquiry["source"] ?? "") === "email"
                        ): ?>
                            <span class="source-icon email" title="Email">✉</span>
                        <?php else: ?>
                            <span class="source-icon direct" title="Direct">👤</span>
                        <?php endif; ?>
                    </div>
                    <span class="messaging-customer-phone"><?php echo htmlspecialchars(
                        $enquiry["mobile"] ?: "No Mobile",
                    ); ?></span>
                </div>
            </div>

            <!-- Header Actions / Status Badge Inline -->
            <div class="messaging-header-actions" style="display: flex; align-items: center; gap: 10px;">
                <div class="connection-status">
                    <div class="connection-dot" id="connectionDot"></div>
                    <span id="connectionText">Live</span>
                </div>
                <div class="status-quick-tabs">
                    <button onclick="updateChatStatus(<?php echo $enquiry[
                        "id"
                    ]; ?>, 'Follow-up')" class="status-tab-btn follow-up <?php echo strtolower(
    $enquiry["status"] ?? "",
) === "follow-up"
    ? "active"
    : ""; ?>" title="Mark as Follow-up">
                        📌 Follow-up
                    </button>
                    <button onclick="updateChatStatus(<?php echo $enquiry[
                        "id"
                    ]; ?>, 'Cancelled')" class="status-tab-btn cancelled <?php echo strtolower(
    $enquiry["status"] ?? "",
) === "cancelled"
    ? "active"
    : ""; ?>" title="Mark as Cancelled">
                        ✕ Cancel
                    </button>
                </div>
                <span id="chat_status_badge" class="badge <?php echo strtolower(
                    $enquiry["status"] ?: "new",
                ); ?>">
                    <?php echo htmlspecialchars($enquiry["status"] ?: "New"); ?>
                </span>
            </div>
        </div>

        <!-- Journey Progress Strip or Cancel Banner -->
        <div id="journeyProgressContainer">
            <?php if (!$is_cancelled): ?>
                <div class="journey-progress-strip">
                    <div class="journey-line"></div>
                    <div class="journey-line-filled" style="width: calc((100% - 120px) * <?php echo $step_index /
                        3; ?>);"></div>
                    <div class="journey-steps">
                        <div class="journey-step <?php echo $step_index >= 0
                            ? "completed"
                            : ""; ?> <?php echo $step_index === 0
     ? "current"
     : ""; ?>">
                            <div class="step-dot">
                                <?php if (
                                    $step_index === 0
                                ): ?><span class="step-icon">✈</span><?php endif; ?>
                            </div>
                            <span class="step-label">New</span>
                        </div>
                        <div class="journey-step <?php echo $step_index >= 1
                            ? "completed"
                            : ""; ?> <?php echo $step_index === 1
     ? "current"
     : ""; ?>">
                            <div class="step-dot">
                                <?php if (
                                    $step_index === 1
                                ): ?><span class="step-icon">✈</span><?php endif; ?>
                            </div>
                            <span class="step-label">Seen</span>
                        </div>
                        <div class="journey-step <?php echo $step_index >= 2
                            ? "completed"
                            : ""; ?> <?php echo $step_index === 2
     ? "current"
     : ""; ?>">
                            <div class="step-dot">
                                <?php if (
                                    $step_index === 2
                                ): ?><span class="step-icon">✈</span><?php endif; ?>
                            </div>
                            <span class="step-label">Replied</span>
                        </div>
                        <div class="journey-step <?php echo $step_index >= 3
                            ? "completed"
                            : ""; ?> <?php echo $step_index === 3
     ? "current"
     : ""; ?>">
                            <div class="step-dot">
                                <?php if (
                                    $step_index === 3
                                ): ?><span class="step-icon">✈</span><?php endif; ?>
                            </div>
                            <span class="step-label">Booked</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="journey-cancelled-banner">
                    <span class="cancelled-banner-icon">✕</span>
                    <span class="cancelled-banner-text">This enquiry has been cancelled</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Chat Scroll Area -->
        <div id="chatContainer" class="messaging-chat-area">
            <button id="scrollToBottom" class="scroll-to-bottom" onclick="scrollToBottom()" title="Scroll to bottom">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                    <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                </svg>
            </button>
            <?php if (mysqli_num_rows($msg_result) > 0): ?>
                <?php
                $last_date = null;
                $last_direction = null;
                ?>
                <?php while ($msg = mysqli_fetch_assoc($msg_result)): ?>
                    <?php
                    // Render date separator if the message date changed
                    $msg_date = date("Y-m-d", strtotime($msg["created_at"]));
                    if ($msg_date !== $last_date) {
                        $formatted_date = format_chat_date($msg["created_at"]);
                        echo '<div class="date-separator">' .
                            htmlspecialchars($formatted_date) .
                            "</div>";
                        $last_date = $msg_date;
                    }

                    $is_incoming = $msg["direction"] === "incoming";
                    $bubble_class = $is_incoming ? "incoming" : "outgoing";
                    $is_same_sender = $msg["direction"] === $last_direction;
                    $gap_class = $is_same_sender
                        ? "bubble-gap-same"
                        : "bubble-gap-diff";
                    $last_direction = $msg["direction"];
                    ?>
                    <!-- Message Bubble -->
                    <div class="chat-bubble <?php echo $bubble_class; ?> <?php echo $gap_class; ?>" data-msg-id="<?php echo $msg[
    "id"
]; ?>" data-msg-date="<?php echo date(
    "Y-m-d",
    strtotime($msg["created_at"]),
); ?>" data-msg-direction="<?php echo $msg["direction"]; ?>">
                        <?php if (!$is_incoming && !empty($msg["sent_by"])): ?>
                            <span class="bubble-staff-name"><?php echo htmlspecialchars(
                                $msg["sent_by"],
                            ); ?></span>
                        <?php endif; ?>

                        <?php
                        $media_type = $msg["media_type"] ?? "none";
                        if (
                            $media_type === "none" &&
                            !empty($msg["media_path"])
                        ) {
                            $media_type = "image"; // fallback
                        }
                        ?>

                        <?php if ($media_type === "image"): ?>
                            <img src="../../<?php echo htmlspecialchars(
                                $msg["media_path"],
                            ); ?>"
                                 class="bubble-media-img"
                                 onclick="openLightbox(this.src)" />
                        <?php elseif ($media_type === "document"): ?>
                            <div class="bubble-attachment-card">
                                <div class="attachment-icon-wrapper">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="color: #ef4444;">
                                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14H8v-2h2v2zm3-4h-3v-2h3v2zm3-4H8V7h8v2z"/>
                                    </svg>
                                </div>
                                <div class="attachment-info">
                                    <span class="attachment-name" title="<?php echo htmlspecialchars(
                                        $msg["original_filename"],
                                    ); ?>">
                                        <?php echo htmlspecialchars(
                                            $msg["original_filename"] ?:
                                            "document.pdf",
                                        ); ?>
                                    </span>
                                    <span class="attachment-size">PDF Document</span>
                                </div>
                                <a href="../../<?php echo htmlspecialchars(
                                    $msg["media_path"],
                                // Note: we close properly
                                ); ?>" download="<?php echo htmlspecialchars(
    $msg["original_filename"] ?: "document.pdf",
); ?>" class="attachment-download-btn" title="Download">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>
                                    </svg>
                                </a>
                            </div>
                        <?php elseif ($media_type === "voice" || $media_type === "audio"): ?>
                            <div class="voice-player-container">
                                <audio controls class="voice-audio-element">
                                    <source src="../../<?php echo htmlspecialchars($msg['media_path']); ?>" type="audio/ogg">
                                    <source src="../../<?php echo htmlspecialchars($msg['media_path']); ?>" type="audio/mpeg">
                                    Your browser does not support the audio element.
                                </audio>
                                <div class="voice-meta-row">
                                    <span><?php echo ($media_type === 'voice') ? 'Voice Note' : 'Audio File'; ?></span>
                                    <?php if (!empty($msg['media_duration'])): ?>
                                        <span><?php 
                                            $dur = intval($msg['media_duration']);
                                            echo floor($dur / 60) . ':' . str_pad($dur % 60, 2, '0', STR_PAD_LEFT);
                                        ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        $is_image_placeholder =
                            $media_type === "image" &&
                            $msg["message_text"] === "[Image]";
                        $is_doc_placeholder =
                            $media_type === "document" &&
                            strpos($msg["message_text"], "[Document:") === 0;
                        $is_voice_placeholder =
                            ($media_type === "voice" && $msg["message_text"] === "[Voice Note]") ||
                            ($media_type === "audio" && $msg["message_text"] === "[Audio File]");
                        if (
                            !$is_image_placeholder &&
                            !$is_doc_placeholder &&
                            !$is_voice_placeholder &&
                            !empty($msg["message_text"])
                        ): ?>
                            <p style="margin: 0; white-space: pre-wrap;"><?php echo htmlspecialchars(
                                $msg["message_text"],
                            ); ?></p>
                        <?php endif;
                        ?>

                        <div class="bubble-meta">
                            <span><?php echo date(
                                "g:i A",
                                strtotime($msg["created_at"]),
                            ); ?></span>
                            <?php if (!$is_incoming): ?>
                                <span class="bubble-checkmarks">✓✓</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Fallback: show description if messages are empty (old entries) -->
                <div class="chat-bubble incoming bubble-gap-diff">
                    <p style="margin: 0; white-space: pre-wrap;"><?php echo htmlspecialchars(
                        $enquiry["description"] ?: "No messages yet",
                    ); ?></p>
                    <div class="bubble-meta">
                        <span><?php echo date(
                            "g:i A",
                            strtotime($enquiry["created_at"]),
                        ); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- File attachment preview bar -->
        <div id="inputPreviewBar" class="input-preview-bar">
            <span style="color: var(--text-secondary);">Attachment:</span>
            <div class="preview-chip">
                <span id="previewFilename" class="preview-name">file.pdf</span>
                <span onclick="clearSelectedFile()" class="preview-remove" title="Remove">&times;</span>
            </div>
        </div>

        <!-- Reply Input Bar at the Bottom -->
        <?php if (!empty($enquiry["mobile"])): ?>
            <div class="messaging-input-bar">
                <form id="chatReplyForm" onsubmit="submitChatReply(event)" style="display: flex; width: 100%; gap: 0; align-items: flex-end;" enctype="multipart/form-data">
                    <input type="hidden" name="enquiry_id" value="<?php echo $enquiry[
                        "id"
                    ]; ?>">
                    <input type="file" id="attachmentFile" name="attachment_file" accept="image/*, application/pdf" style="display: none;" onchange="handleFileSelected(this)">

                    <button type="button" class="attachment-btn" onclick="document.getElementById('attachmentFile').click()" title="Attach file">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                            <path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-3.87 3.13-7 7-7s7 3.13 7 7v9.5c0 1.38-1.12 2.5-2.5 2.5s-2.5-1.12-2.5-2.5V6H13v8.5c0 .55.45 1 1 1s1-.45 1-1V6c0-2.48-2.02-4.5-4.5-4.5S6 3.52 6 6v11.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/>
                        </svg>
                    </button>

                    <textarea id="replyMessageText" name="reply_message" placeholder="Type a message..." required rows="1" onkeydown="handleInputKeydown(event)"></textarea>

                    <button type="submit" id="btnSendReply" class="send-circle-btn" title="Send message">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </form>
            </div>
            <!-- Reply Error Container -->
            <div id="replyErrorMessage" style="display: none; padding: 8px 16px; background: #fee2e2; border-top: 1px solid #fecaca; color: #991b1b; font-size: 11px; font-weight: 600;"></div>
        <?php endif; ?>
    </div>
</div>

<script>
// Optimized polling with adaptive intervals
let pollIntervalId = null;
let currentPollInterval = 2000; // Start with 2 seconds
let isPollingActive = false;
let lastSuccessfulPoll = Date.now();

window.onload = function() {
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer) {
        // Smooth scroll to bottom on load
        setTimeout(() => {
            chatContainer.scrollTo({
                top: chatContainer.scrollHeight,
                behavior: 'smooth'
            });
        }, 100);
    }

    // Auto-expanding textarea height on typing
    const tx = document.getElementById('replyMessageText');
    if (tx) {
        tx.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }

    // Initialize connection status
    updateConnectionStatus('connecting');
    
    // Start optimized polling
    startOptimizedPolling();
};

// Connection status management
function updateConnectionStatus(status) {
    const dot = document.getElementById('connectionDot');
    const text = document.getElementById('connectionText');
    
    if (!dot || !text) return;
    
    dot.className = 'connection-dot';
    
    switch(status) {
        case 'connected':
            dot.classList.add('connected');
            text.textContent = 'Live';
            break;
        case 'connecting':
            dot.classList.add('connecting');
            text.textContent = 'Connecting...';
            break;
        case 'offline':
            dot.classList.add('offline');
            text.textContent = 'Offline';
            break;
    }
}

// Optimized polling with adaptive intervals
function startOptimizedPolling() {
    if (isPollingActive) return;
    isPollingActive = true;
    
    // Initial poll
    pollMessages();
    
    // Set up adaptive polling
    scheduleNextPoll();
}

function scheduleNextPoll() {
    if (!isPollingActive) return;
    
    // Adaptive interval based on success rate
    const timeSinceLastSuccess = Date.now() - lastSuccessfulPoll;
    
    if (timeSinceLastSuccess > 30000) {
        // No success for 30 seconds, slow down to 5 seconds
        currentPollInterval = 5000;
    } else if (timeSinceLastSuccess > 10000) {
        // No success for 10 seconds, use 3 seconds
        currentPollInterval = 3000;
    } else {
        // Recent success, use fast 1 second polling
        currentPollInterval = 1000;
    }
    
    pollIntervalId = setTimeout(() => {
        pollMessages();
        scheduleNextPoll();
    }, currentPollInterval);
}

function stopOptimizedPolling() {
    isPollingActive = false;
    if (pollIntervalId) {
        clearTimeout(pollIntervalId);
        pollIntervalId = null;
    }
}

function handleInputKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        const btn = document.getElementById('btnSendReply');
        if (btn) {
            btn.click();
        }
    }
}

function handleFileSelected(input) {
    const previewBar = document.getElementById('inputPreviewBar');
    const previewFilename = document.getElementById('previewFilename');
    const messageInput = document.getElementById('replyMessageText');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        previewFilename.textContent = file.name;
        previewBar.style.display = 'flex';

        // Remove required attribute from textarea so caption is optional
        messageInput.removeAttribute('required');
        messageInput.placeholder = 'Add a caption...';
    } else {
        clearSelectedFile();
    }
}

function clearSelectedFile() {
    const fileInput = document.getElementById('attachmentFile');
    const previewBar = document.getElementById('inputPreviewBar');
    const messageInput = document.getElementById('replyMessageText');

    if (fileInput) {
        fileInput.value = '';
    }
    previewBar.style.display = 'none';

    // Restore required attribute and original placeholder
    messageInput.setAttribute('required', 'required');
    messageInput.placeholder = 'Type a message...';
}

function submitChatReply(event) {
    event.preventDefault();
    const messageInput = document.getElementById('replyMessageText');
    const message = messageInput.value.trim();
    const errorEl = document.getElementById('replyErrorMessage');
    const sendBtn = document.getElementById('btnSendReply');

    const fileInput = document.getElementById('attachmentFile');
    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

    if (!message && !hasFile) return;

    errorEl.style.display = 'none';
    sendBtn.disabled = true;
    const origContent = sendBtn.innerHTML;
    sendBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" class="sending-spinner"><path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/></path></svg>';

    const formData = new FormData(document.getElementById('chatReplyForm'));

    fetch('send_reply.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        sendBtn.disabled = false;
        sendBtn.innerHTML = origContent;
        if (data.success) {
            messageInput.value = '';
            clearSelectedFile();

            // Append reply message
            if (data.data) {
                appendMessageBubble(data.data);
            }

            // Auto-scroll to bottom with smooth animation
            const chatContainer = document.getElementById('chatContainer');
            if (chatContainer) {
                chatContainer.scrollTo({
                    top: chatContainer.scrollHeight,
                    behavior: 'smooth'
                });
            }

            // Update progress strip to 'Replied'
            updateJourneyProgressUI('Replied');

            // Update status badge to 'Replied'
            const statusBadge = document.getElementById('chat_status_badge');
            if (statusBadge) {
                statusBadge.textContent = 'Replied';
                statusBadge.className = 'badge replied';
            }

            // Remove active state from quick status tabs
            const followUpBtn = document.querySelector('.status-tab-btn.follow-up');
            const cancelledBtn = document.querySelector('.status-tab-btn.cancelled');
            if (followUpBtn) followUpBtn.classList.remove('active');
            if (cancelledBtn) cancelledBtn.classList.remove('active');

            showSuccessToast('Reply sent successfully!');
            setTimeout(() => {
                const toast = document.getElementById('successToast');
                if (toast) toast.classList.remove('active');
            }, 2000);
        } else {
            errorEl.textContent = data.message;
            errorEl.style.display = 'block';
        }
    })
    .catch(err => {
        sendBtn.disabled = false;
        sendBtn.innerHTML = origContent;
        errorEl.textContent = 'Failed to connect to server.';
        errorEl.style.display = 'block';
    });
}

function showSuccessToast(message) {
    const toast = document.getElementById('successToast');
    const msgEl = document.getElementById('toastMessage');
    if (toast && msgEl) {
        msgEl.textContent = message;
        toast.classList.add('active');
    }
}

function updateChatStatus(id, status) {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('status', status);

    fetch('ajax_update_status.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update badge UI
            const statusBadge = document.getElementById('chat_status_badge');
            if (statusBadge) {
                statusBadge.textContent = status;
                statusBadge.className = 'badge ' + status.toLowerCase();
            }

            // Update button states
            const followUpBtn = document.querySelector('.status-tab-btn.follow-up');
            const cancelledBtn = document.querySelector('.status-tab-btn.cancelled');
            if (followUpBtn) {
                if (status.toLowerCase() === 'follow-up') {
                    followUpBtn.classList.add('active');
                } else {
                    followUpBtn.classList.remove('active');
                }
            }
            if (cancelledBtn) {
                if (status.toLowerCase() === 'cancelled') {
                    cancelledBtn.classList.add('active');
                } else {
                    cancelledBtn.classList.remove('active');
                }
            }

            // Hide messaging input bar if status is cancelled
            const messagingInputBar = document.querySelector('.messaging-input-bar');
            if (messagingInputBar) {
                if (status.toLowerCase() === 'cancelled') {
                    messagingInputBar.style.display = 'none';
                } else {
                    messagingInputBar.style.display = 'flex'; // Or original display style
                }
            }

            // Update progress strip UI dynamically
            updateJourneyProgressUI(status);

            showSuccessToast('Status updated to ' + status + '!');
            setTimeout(() => {
                const toast = document.getElementById('successToast');
                if (toast) toast.classList.remove('active');
            }, 2000);
        } else {
            alert('Error updating status: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Failed to connect to CRM server.');
    });
}

// ── REAL-TIME POLLING & DOM UTILS ──

function getLastMessageId() {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer) return 0;
    const bubbles = chatContainer.querySelectorAll('.chat-bubble');
    if (bubbles.length === 0) return 0;
    const lastBubble = bubbles[bubbles.length - 1];
    return parseInt(lastBubble.getAttribute('data-msg-id') || 0);
}

function isScrolledNearBottom(el, threshold = 100) {
    return (el.scrollHeight - el.scrollTop - el.clientHeight) <= threshold;
}

function scrollToBottomSmooth(el) {
    el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
}

function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g,
        tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
    );
}

function formatChatTimeStr(datetime) {
    const t = datetime.split(' ')[1];
    const hours = parseInt(t.split(':')[0]);
    const minutes = t.split(':')[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours % 12 || 12;
    return `${displayHours}:${minutes} ${ampm}`;
}

function formatChatDateStr(datetime) {
    const dateStr = datetime.split(' ')[0];
    const d = new Date(dateStr);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    const todayStr = today.toISOString().split('T')[0];
    const yesterdayStr = yesterday.toISOString().split('T')[0];

    if (dateStr === todayStr) {
        return 'Today';
    } else if (dateStr === yesterdayStr) {
        return 'Yesterday';
    } else {
        const options = { day: 'numeric', month: 'long', year: 'numeric' };
        return d.toLocaleDateString('en-US', options);
    }
}

function appendMessageBubble(msg) {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer) return;

    // Deduplicate
    if (chatContainer.querySelector(`[data-msg-id="${msg.id}"]`)) {
        return;
    }

    const isIncoming = (msg.direction === 'incoming');
    const bubbleClass = isIncoming ? 'incoming' : 'outgoing';

    const bubbles = chatContainer.querySelectorAll('.chat-bubble');
    let lastBubble = bubbles[bubbles.length - 1];
    let gapClass = 'bubble-gap-diff';
    let lastDirection = null;
    let lastDateStr = null;

    if (lastBubble) {
        lastDirection = lastBubble.getAttribute('data-msg-direction');
        lastDateStr = lastBubble.getAttribute('data-msg-date');
    }

    if (lastDirection === msg.direction) {
        gapClass = 'bubble-gap-same';
    }

    // Date Separator Check
    const msgDateStr = msg.created_at.split(' ')[0];
    if (lastDateStr && lastDateStr !== msgDateStr) {
        const dateSeparator = document.createElement('div');
        dateSeparator.className = 'date-separator';
        dateSeparator.textContent = formatChatDateStr(msg.created_at);
        chatContainer.appendChild(dateSeparator);
    }

    // Create bubble
    const bubbleDiv = document.createElement('div');
    bubbleDiv.className = `chat-bubble ${bubbleClass} ${gapClass}`;
    bubbleDiv.setAttribute('data-msg-id', msg.id);
    bubbleDiv.setAttribute('data-msg-date', msgDateStr);
    bubbleDiv.setAttribute('data-msg-direction', msg.direction);

    let htmlContent = '';

    if (!isIncoming && msg.sent_by) {
        htmlContent += `<span class="bubble-staff-name">${escapeHTML(msg.sent_by)}</span>`;
    }

    const mediaType = msg.media_type || 'none';
    if (mediaType === 'image') {
        htmlContent += `<img src="../../${escapeHTML(msg.media_path)}" class="bubble-media-img" onclick="openLightbox(this.src)" />`;
    } else if (mediaType === 'document') {
        htmlContent += `
            <div class="bubble-attachment-card">
                <div class="attachment-icon-wrapper">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="color: #ef4444;">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14H8v-2h2v2zm3-4h-3v-2h3v2zm3-4H8V7h8v2z"/>
                    </svg>
                </div>
                <div class="attachment-info">
                    <span class="attachment-name" title="${escapeHTML(msg.original_filename)}">
                        ${escapeHTML(msg.original_filename || 'document.pdf')}
                    </span>
                    <span class="attachment-size">PDF Document</span>
                </div>
                <a href="../../${escapeHTML(msg.media_path)}" download="${escapeHTML(msg.original_filename || 'document.pdf')}" class="attachment-download-btn" title="Download">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>
                    </svg>
                </a>
            </div>
        `;
    } else if (mediaType === 'voice' || mediaType === 'audio') {
        const formattedDuration = msg.media_duration ? 
            `${Math.floor(msg.media_duration / 60)}:${String(msg.media_duration % 60).padStart(2, '0')}` : '';
        htmlContent += `
            <div class="voice-player-container">
                <audio controls class="voice-audio-element">
                    <source src="../../${escapeHTML(msg.media_path)}" type="audio/ogg">
                    <source src="../../${escapeHTML(msg.media_path)}" type="audio/mpeg">
                    Your browser does not support the audio element.
                </audio>
                <div class="voice-meta-row">
                    <span>${mediaType === 'voice' ? 'Voice Note' : 'Audio File'}</span>
                    ${formattedDuration ? `<span>${formattedDuration}</span>` : ''}
                </div>
            </div>
        `;
    }

    const isImagePlaceholder = (mediaType === 'image' && msg.message_text === '[Image]');
    const isDocPlaceholder = (mediaType === 'document' && msg.message_text.indexOf('[Document:') === 0);
    const isVoicePlaceholder = (mediaType === 'voice' && msg.message_text === '[Voice Note]') ||
                                (mediaType === 'audio' && msg.message_text === '[Audio File]');
    if (!isImagePlaceholder && !isDocPlaceholder && !isVoicePlaceholder && msg.message_text) {
        htmlContent += `<p style="margin: 0; white-space: pre-wrap;">${escapeHTML(msg.message_text)}</p>`;
    }

    const timeStr = formatChatTimeStr(msg.created_at);
    htmlContent += `
        <div class="bubble-meta">
            <span>${timeStr}</span>
            ${!isIncoming ? '<span class="bubble-checkmarks">✓✓</span>' : ''}
        </div>
    `;

    bubbleDiv.innerHTML = htmlContent;
    chatContainer.appendChild(bubbleDiv);
}

function updateJourneyProgressUI(status) {
    const container = document.getElementById('journeyProgressContainer');
    if (!container) return;

    if (status.toLowerCase() === 'cancelled') {
        container.innerHTML = `
            <div class="journey-cancelled-banner">
                <span class="cancelled-banner-icon">✕</span>
                <span class="cancelled-banner-text">This enquiry has been cancelled</span>
            </div>
        `;
    } else {
        let stepIndex = 0;
        if (status.toLowerCase() === 'seen') {
            stepIndex = 1;
        } else if (['replied', 'follow-up'].includes(status.toLowerCase())) {
            stepIndex = 2;
        } else if (['booked', 'converted'].includes(status.toLowerCase())) {
            stepIndex = 3;
        }

        container.innerHTML = `
            <div class="journey-progress-strip">
                <div class="journey-line"></div>
                <div class="journey-line-filled" style="width: calc((100% - 120px) * ${stepIndex / 3});"></div>
                <div class="journey-steps">
                    <div class="journey-step ${stepIndex >= 0 ? 'completed' : ''} ${stepIndex === 0 ? 'current' : ''}">
                        <div class="step-dot">
                            ${stepIndex === 0 ? '<span class="step-icon">✈</span>' : ''}
                        </div>
                        <span class="step-label">New</span>
                    </div>
                    <div class="journey-step ${stepIndex >= 1 ? 'completed' : ''} ${stepIndex === 1 ? 'current' : ''}">
                        <div class="step-dot">
                            ${stepIndex === 1 ? '<span class="step-icon">✈</span>' : ''}
                        </div>
                        <span class="step-label">Seen</span>
                    </div>
                    <div class="journey-step ${stepIndex >= 2 ? 'completed' : ''} ${stepIndex === 2 ? 'current' : ''}">
                        <div class="step-dot">
                            ${stepIndex === 2 ? '<span class="step-icon">✈</span>' : ''}
                        </div>
                        <span class="step-label">Replied</span>
                    </div>
                    <div class="journey-step ${stepIndex >= 3 ? 'completed' : ''} ${stepIndex === 3 ? 'current' : ''}">
                        <div class="step-dot">
                            ${stepIndex === 3 ? '<span class="step-icon">✈</span>' : ''}
                        </div>
                        <span class="step-label">Booked</span>
                    </div>
                </div>
            </div>
        `;
    }
}

function pollMessages() {
    const enquiryId = <?php echo $enquiry["id"]; ?>;
    const sinceId = getLastMessageId();

    // Update connection status to connecting
    updateConnectionStatus('connecting');

    fetch(`poll_messages.php?enquiry_id=${enquiryId}&since_id=${sinceId}`, {
        cache: 'no-store'
    })
    .then(res => {
        if (!res.ok) throw new Error('Network response was not ok');
        return res.json();
    })
    .then(data => {
        // Update connection status to connected on success
        updateConnectionStatus('connected');
        lastSuccessfulPoll = Date.now();

        if (data.success && data.messages && data.messages.length > 0) {
            const chatContainer = document.getElementById('chatContainer');
            const nearBottom = chatContainer ? isScrolledNearBottom(chatContainer) : false;

            let hasIncoming = false;

            data.messages.forEach(msg => {
                appendMessageBubble(msg);
                if (msg.direction === 'incoming') {
                    hasIncoming = true;
                }
            });

            // Auto-scroll with smooth animation
            if (chatContainer && (nearBottom || !hasIncoming)) {
                chatContainer.scrollTo({
                    top: chatContainer.scrollHeight,
                    behavior: 'smooth'
                });
            }

            if (hasIncoming) {
                const badge = document.getElementById('chat_status_badge');
                if (badge && badge.textContent.trim() !== 'Seen' && badge.textContent.trim() !== 'Replied' && badge.textContent.trim() !== 'Booked') {
                    badge.textContent = 'Seen';
                    badge.className = 'badge seen';
                    updateJourneyProgressUI('Seen');

                    // Remove active state from quick status tabs
                    const followUpBtn = document.querySelector('.status-tab-btn.follow-up');
                    const cancelledBtn = document.querySelector('.status-tab-btn.cancelled');
                    if (followUpBtn) followUpBtn.classList.remove('active');
                    if (cancelledBtn) cancelledBtn.classList.remove('active');
                }

                // Quietly set status to Seen in database since page is open
                const formData = new FormData();
                formData.append('id', enquiryId);
                formData.append('status', 'Seen');
                fetch('ajax_update_status.php', {
                    method: 'POST',
                    body: formData
                }).catch(err => console.error('Quiet status update error:', err));
            }
        }
    })
    .catch(err => {
        console.error('Polling error:', err);
        // Update connection status to offline on error
        updateConnectionStatus('offline');
    });
}

// Visibility change handler for optimized polling
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopOptimizedPolling();
    } else {
        // Immediate poll when tab becomes visible
        pollMessages();
        startOptimizedPolling();
    }
});

// Page unload handler
window.addEventListener('beforeunload', () => {
    stopOptimizedPolling();
});

// Scroll to bottom function
function scrollToBottom() {
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer) {
        chatContainer.scrollTo({
            top: chatContainer.scrollHeight,
            behavior: 'smooth'
        });
    }
}

// Monitor scroll position to show/hide scroll-to-bottom button
function monitorScrollPosition() {
    const chatContainer = document.getElementById('chatContainer');
    const scrollBtn = document.getElementById('scrollToBottom');
    
    if (!chatContainer || !scrollBtn) return;
    
    const checkScroll = () => {
        const isNearBottom = isScrolledNearBottom(chatContainer, 100);
        
        if (isNearBottom) {
            scrollBtn.classList.remove('visible');
        } else {
            scrollBtn.classList.add('visible');
        }
    };
    
    chatContainer.addEventListener('scroll', checkScroll);
    checkScroll(); // Initial check
}

// Initialize scroll monitoring
setTimeout(monitorScrollPosition, 500);

function openLightbox(src) {
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    if (lightbox && lightboxImg) {
        lightboxImg.src = src;
        lightbox.classList.add('active');
    }
}

function closeLightbox() {
    const lightbox = document.getElementById('lightbox');
    if (lightbox) {
        lightbox.classList.remove('active');
    }
}
</script>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox-overlay" onclick="closeLightbox()">
    <span class="lightbox-close">&times;</span>
    <img id="lightbox-img" class="lightbox-content" onclick="event.stopPropagation()">
</div>

<!-- Success Toast -->
<div id="successToast" class="success-toast">
    <span class="toast-icon">✓</span>
    <span id="toastMessage">Success</span>
</div>

</body>
</html>

