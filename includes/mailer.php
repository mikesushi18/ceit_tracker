<?php
// includes/mailer.php
// Thin wrapper around PHPMailer for sending OTP / notification emails
// through Gmail's SMTP server. Requires SMTP_USER + SMTP_APP_PASSWORD
// to be set in includes/config.php (use an App Password, not your real
// Gmail password): https://myaccount.google.com/apppasswords

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends an email via Gmail SMTP.
 * Returns [true, ''] on success or [false, 'error message'] on failure.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = ''): array {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody !== '' ? $plainBody : strip_tags($htmlBody);

        $mail->send();
        return [true, ''];
    } catch (PHPMailerException $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}
