<?php
// Load PHPMailer classes manually
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// SMTP configuration constants. Replace these placeholder values with your real Gmail details.
if (!defined('SMTP_EMAIL')) {
    define('SMTP_EMAIL', 'asiqu3@gmail.com'); // Put your Gmail address here
}
if (!defined('SMTP_APP_PASSWORD')) {
    define('SMTP_APP_PASSWORD', 'eejevsdihnolmdqe'); // Put your 16-character Gmail App Password here
}
if (!defined('NOTIFY_EMAIL')) {
    define('NOTIFY_EMAIL', 'asiqu3@gmail.com'); // Put the destination email here (can be same as SMTP_EMAIL)
}

/**
 * Reusable function to send new enquiry email notifications using Gmail SMTP
 * 
 * @param string $customer_name
 * @param string $mobile
 * @param string $subject
 * @param string $source
 * @param string $description
 * @return bool True if mail was sent, false otherwise (errors are logged silently)
 */
function send_enquiry_notification($customer_name, $mobile, $subject, $source, $description) {
    try {
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_EMAIL;
        $mail->Password   = SMTP_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS Encryption
        $mail->Port       = 587;                            // SMTP Port for TLS

        // Timeout settings (in seconds) to avoid page hang on network lag
        $mail->Timeout    = 10;

        // Recipients
        $mail->setFrom(SMTP_EMAIL, 'CRM Notifications');
        $mail->addAddress(NOTIFY_EMAIL);

        // Plain Text Content
        $mail->isHTML(false);
        $mail->Subject = "New Enquiry - " . $customer_name . " (" . $source . ")";
        
        $mail->Body = "A new enquiry has been received.\n\n"
                    . "👤 Name: " . $customer_name . "\n"
                    . "📞 Mobile: " . $mobile . "\n"
                    . "📌 Subject: " . $subject . "\n"
                    . "Source: " . $source . "\n\n"
                    . "📝 Details:\n" . $description;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error silently to XAMPP php error logs
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
