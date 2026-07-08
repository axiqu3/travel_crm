<?php
define('GMAIL_IMAP_EMAIL', 'asiqu3@gmail.com');
define('GMAIL_IMAP_APP_PASSWORD', 'eejevsdihnolmdqe');

require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

/**
 * Decode mime headers (subjects/names) safely handling base64/quoted-printable transfers
 */
function decode_mime_text($text) {
    if (!$text) return '';
    $decoded = imap_mime_header_decode($text);
    if ($decoded && count($decoded) > 0) {
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return imap_utf8($result);
    }
    return imap_utf8($text);
}

/**
 * Fetch raw part data based on encoding scheme
 */
function get_part_data($imap, $mail_id, $part_number, $part_structure) {
    $data = imap_fetchbody($imap, $mail_id, $part_number);
    if ($part_structure->encoding == 3) { // BASE64
        $data = base64_decode($data);
    } elseif ($part_structure->encoding == 4) { // QUOTED-PRINTABLE
        $data = quoted_printable_decode($data);
    }
    return $data;
}

/**
 * Traverse multi-part structures recursively to extract plain text
 */
function get_multipart_data($imap, $mail_id, $structure, $part_prefix = "") {
    // 1. Look for plain text first
    foreach ($structure->parts as $index => $sub_part) {
        $part_number = $part_prefix . ($index + 1);
        if ($sub_part->type == 0 && strtolower($sub_part->subtype) == 'plain') {
            return get_part_data($imap, $mail_id, $part_number, $sub_part);
        }
        if (!empty($sub_part->parts)) {
            $nested_data = get_multipart_data($imap, $mail_id, $sub_part, $part_number . ".");
            if ($nested_data) return $nested_data;
        }
    }
    // 2. Fall back to html if plain text isn't found and strip HTML tags
    foreach ($structure->parts as $index => $sub_part) {
        $part_number = $part_prefix . ($index + 1);
        if ($sub_part->type == 0 && strtolower($sub_part->subtype) == 'html') {
            $html_data = get_part_data($imap, $mail_id, $part_number, $sub_part);
            return strip_tags($html_data);
        }
    }
    return '';
}

/**
 * Helper to pull text content out of any email message
 */
function get_body_text($imap, $mail_id) {
    $structure = imap_fetchstructure($imap, $mail_id);
    if (empty($structure->parts)) {
        return get_part_data($imap, $mail_id, 1, $structure);
    } else {
        return get_multipart_data($imap, $mail_id, $structure);
    }
}

// Set a shorter IMAP connection timeout (10 seconds)
imap_timeout(IMAP_OPENTIMEOUT, 10);

// Connect to Gmail IMAP (SSL port 993)
$mailbox = "{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX";
$inbox = @imap_open($mailbox, GMAIL_IMAP_EMAIL, GMAIL_IMAP_APP_PASSWORD);

if (!$inbox) {
    $err = imap_last_error();
    @imap_errors(); // Clear error stack to prevent shutdown notices
    @imap_alerts();

    // Detect if the failure was a timeout
    $is_timeout = (stripos($err, 'timeout') !== false || stripos($err, 'timed out') !== false || stripos($err, 'time out') !== false);
    $error_title = $is_timeout ? "Gmail IMAP Connection Timed Out" : "Failed to connect to Gmail IMAP";
    $error_detail = htmlspecialchars($err);

    die("<div style='padding:40px; font-family:sans-serif; background:#fee2e2; border:1px solid #fecaca; border-radius:12px; max-width:600px; margin:40px auto; color:#991b1b;'>
            <h2 style='margin-top:0;'>{$error_title}</h2>
            <p><strong>Error Details:</strong> {$error_detail}</p>
            " . ($is_timeout ? "<p>The connection took too long to establish. Please check your internet connection and verify that Gmail IMAP is accessible.</p>" : "<p>This is likely because the <strong>imap</strong> extension is not enabled in your XAMPP <code>php.ini</code> configuration, or the mail server is unreachable.</p>") . "
            <hr style='border:none; border-top:1px solid #fca5a5; margin:20px 0;'>
            <h4 style='margin-bottom:8px;'>How to enable the IMAP extension on XAMPP (Windows):</h4>
            <ol style='margin-top:0; padding-left:20px;'>
                <li>Open file <code>C:\\xampp\\php\\php.ini</code> in a text editor.</li>
                <li>Search for the line <code>;extension=imap</code>.</li>
                <li>Remove the semicolon (<code>;</code>) from the start to make it <code>extension=imap</code>.</li>
                <li>Save the file.</li>
                <li>Open the XAMPP Control Panel and click <strong>Stop</strong> then <strong>Start</strong> next to Apache.</li>
            </ol>
            <br>
            <a href='list.php' style='display:inline-block; padding:10px 20px; background:#ef4444; color:white; text-decoration:none; border-radius:8px; font-weight:600;'>Return to Hub</a>
         </div>");
}

// Search for UNSEEN (unread) emails
$emails = imap_search($inbox, 'UNSEEN');
$imported_count = 0;

if ($emails) {
    // Process older emails first (Gmail sequence numbers increase with time, so ascending order is older first)
    sort($emails);
    
    // Limit to the most recent 20 unread emails to prevent page hangs
    $emails = array_slice($emails, -20);
    
    foreach ($emails as $mail_id) {
        $header = imap_headerinfo($inbox, $mail_id);
        
        // Sender Address
        $from = $header->from[0];
        $sender_email = $from->mailbox . "@" . $from->host;
        
        // Sender Display Name (Decoded)
        $sender_name = isset($from->personal) ? decode_mime_text($from->personal) : $sender_email;
        
        // Subject Line (Decoded)
        $subject = isset($header->subject) ? decode_mime_text($header->subject) : 'No Subject';
        
        // Plain Body Text (truncated to 500 characters)
        $body = get_body_text($inbox, $mail_id);
        $body = trim($body);
        
        // Escape database inputs safely
        $customer_name_db = mysqli_real_escape_string($db, $sender_name ?: $sender_email);
        $email_db = mysqli_real_escape_string($db, $sender_email);
        $subject_db = mysqli_real_escape_string($db, $subject);
        $description_db = mysqli_real_escape_string($db, substr($body, 0, 500));
        
        // Insert as a new Email Enquiry
        $query = "INSERT INTO enquiries (customer_name, email, subject, description, source, status, created_by, notified) 
                  VALUES ('$customer_name_db', '$email_db', '$subject_db', '$description_db', 'Email', 'New', 'Email Importer', 0)";
        
        if (mysqli_query($db, $query)) {
            $imported_count++;
            // Set flag seen in Gmail
            imap_setflag_full($inbox, $mail_id, "\\Seen");
        }
    }
}

// Clear any residual IMAP errors to prevent PHP request shutdown notices
@imap_errors();
@imap_alerts();

// Close connection
imap_close($inbox);

// Redirect with success payload
header("Location: list.php?email_import=" . $imported_count);
exit;
