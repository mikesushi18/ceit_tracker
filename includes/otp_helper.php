<?php
// includes/otp_helper.php
// Generate, send, and verify one-time codes stored in the `otps` table.
// Run migration_google_otp.sql first to create that table.

require_once __DIR__ . '/mailer.php';

/**
 * Generates a numeric OTP, stores its hash, and emails it to the user.
 * Returns [true, ''] on success, [false, 'reason'] on failure
 * (e.g. rate limit hit, or the account has no Gmail linked).
 */
function requestOtp(mysqli $conn, array $user, string $purpose = 'password_reset'): array {
    if (empty($user['email'])) {
        return [false, 'Walang naka-link na email sa account na ito. Pakikontak ang admin.'];
    }

    // Rate limit: max OTP_MAX_REQUESTS_PER_HOUR requests per user per hour
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM otps
         WHERE user_id = ? AND purpose = ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $stmt->bind_param('is', $user['id'], $purpose);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($count >= OTP_MAX_REQUESTS_PER_HOUR) {
        return [false, 'Sobra na ang OTP requests. Subukan ulit mamaya.'];
    }

    $otp = str_pad((string) random_int(0, (int) str_repeat('9', OTP_LENGTH)), OTP_LENGTH, '0', STR_PAD_LEFT);
    $hash = password_hash($otp, PASSWORD_BCRYPT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);

    $stmt = $conn->prepare(
        "INSERT INTO otps (user_id, code_hash, purpose, expires_at) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('isss', $user['id'], $hash, $purpose, $expiresAt);
    $stmt->execute();
    $stmt->close();

    $purposeLabel = $purpose === 'email_change' ? 'pagpapalit ng email' : 'pagpapalit ng password';
    $subject = "Ang OTP mo — CollTrace";
    $html = "
        <div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto'>
            <h2 style='color:#EA580C'>CollTrace Verification Code</h2>
            <p>Kumusta " . htmlspecialchars($user['full_name']) . ",</p>
            <p>Narito ang One-Time Password (OTP) mo para sa {$purposeLabel}:</p>
            <p style='font-size:32px;font-weight:bold;letter-spacing:6px;color:#1C1917'>{$otp}</p>
            <p>Valid lang ito ng " . OTP_EXPIRY_MINUTES . " minuto. Huwag ibahagi ang code na ito kaninuman.</p>
            <p>Kung hindi ikaw ang humiling nito, i-ignore na lang ang email na ito.</p>
        </div>
    ";

    [$sent, $error] = sendMail($user['email'], $user['full_name'], $subject, $html);
    if (!$sent) {
        return [false, 'Hindi naipadala ang OTP. (' . $error . ')'];
    }

    return [true, ''];
}

/**
 * Verifies an OTP code for the given user + purpose.
 * On success, marks the OTP (and all older ones for that purpose) as used.
 * Returns [true, ''] or [false, 'reason'].
 */
function verifyOtp(mysqli $conn, int $userId, string $code, string $purpose = 'password_reset'): array {
    $stmt = $conn->prepare(
        "SELECT * FROM otps
         WHERE user_id = ? AND purpose = ? AND used = 0
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->bind_param('is', $userId, $purpose);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        return [false, 'Walang aktibong OTP. Mag-request muna ng bago.'];
    }

    if (strtotime($record['expires_at']) < time()) {
        return [false, 'Expired na ang OTP. Mag-request ng bago.'];
    }

    if ($record['attempts'] >= OTP_MAX_ATTEMPTS) {
        return [false, 'Sobra na ang maling attempts. Mag-request ng bagong OTP.'];
    }

    if (!password_verify($code, $record['code_hash'])) {
        $upd = $conn->prepare("UPDATE otps SET attempts = attempts + 1 WHERE id = ?");
        $upd->bind_param('i', $record['id']);
        $upd->execute();
        $upd->close();
        return [false, 'Maling OTP.'];
    }

    $upd = $conn->prepare("UPDATE otps SET used = 1 WHERE user_id = ? AND purpose = ?");
    $upd->bind_param('is', $userId, $purpose);
    $upd->execute();
    $upd->close();

    return [true, ''];
}
