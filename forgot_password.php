<?php
// forgot_password.php — Self-service password reset via Gmail OTP
require_once 'includes/config.php';
require_once 'includes/otp_helper.php';

// This page doubles as "change password while logged in" and
// "forgot password while logged out" — both use the same OTP flow.
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_step'], $_SESSION['reset_user_id'], $_SESSION['reset_verified_at'], $_SESSION['reset_email_display']);
    redirect(BASE_URL . '/forgot_password.php');
}

// Step tracked via session so a page refresh doesn't lose progress,
// and so the OTP verify step can't be reached without first requesting one.
$step = $_SESSION['reset_step'] ?? 'request'; // request -> verify -> reset
$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── STEP 1: user submits username, we find their linked Gmail and send OTP ──
    if (isset($_POST['action']) && $_POST['action'] === 'request_otp') {
        $username = sanitize($_POST['username'] ?? '');

        if (empty($username)) {
            $error = 'Please enter your username.';
        } else {
            $stmt = $conn->prepare("SELECT id, username, full_name, email, is_active FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Same generic message whether or not the username exists / has
            // no email, so this form can't be used to enumerate accounts.
            $generic = 'If the username is valid and has a linked email, an OTP has been sent.';

            if ($user && $user['is_active'] && !empty($user['email'])) {
                [$sent, $sendErr] = requestOtp($conn, $user, 'password_reset');
                if ($sent) {
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_step'] = 'verify';
                    $step = 'verify';
                    // Mask the email for display, e.g. ju***@gmail.com
                    $parts = explode('@', $user['email']);
                    $_SESSION['reset_email_display'] = substr($parts[0], 0, 2) . str_repeat('*', max(strlen($parts[0]) - 2, 1)) . '@' . $parts[1];
                    $info = $generic;
                } else {
                    $error = $sendErr;
                }
            } else {
                $info = $generic;
            }
        }
    }

    // ── STEP 2: verify the OTP code ──
    if (isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
        $code = trim($_POST['otp'] ?? '');
        $userId = $_SESSION['reset_user_id'] ?? 0;

        if (!$userId) {
            $_SESSION['reset_step'] = 'request';
            redirect(BASE_URL . '/forgot_password.php');
        }

        [$ok, $msg] = verifyOtp($conn, (int) $userId, $code, 'password_reset');
        if ($ok) {
            $_SESSION['reset_step'] = 'reset';
            $_SESSION['reset_verified_at'] = time();
            $step = 'reset';
        } else {
            $step = 'verify';
            $error = $msg;
        }
    }

    // ── STEP 3: set the new password ──
    if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $userId = $_SESSION['reset_user_id'] ?? 0;
        $verifiedAt = $_SESSION['reset_verified_at'] ?? 0;
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // The OTP verification must have happened recently in this same flow
        if (!$userId || !$verifiedAt || (time() - $verifiedAt) > 600) {
            session_unset();
            $error = 'Your session has expired. Please start over.';
            $step = 'request';
        } elseif (strlen($newPassword) < 8) {
            $step = 'reset';
            $error = 'Password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $step = 'reset';
            $error = 'Passwords do not match.';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hashed, $userId);
            $stmt->execute();
            $stmt->close();

            logActivity($conn, (int) $userId, 'PASSWORD_RESET', 'Password changed via Gmail OTP');

            $wasLoggedIn = isLoggedIn() && $_SESSION['user_id'] == $userId;
            $role = $_SESSION['role'] ?? null;

            unset($_SESSION['reset_step'], $_SESSION['reset_user_id'], $_SESSION['reset_verified_at'], $_SESSION['reset_email_display']);

            if ($wasLoggedIn) {
                redirect(dashboardUrlForRole($role) . '?pwchanged=1');
            }

            redirect(BASE_URL . '/index.php?reset=success');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — CollTrace</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Inter', sans-serif;
        min-height: 100vh; display: flex; align-items: center; justify-content: center;
        padding: 24px; position: relative;
        background: #1C1917;
    }

    /* faint campus photo, sitting behind everything — matches index.php */
    body::before {
        content: '';
        position: fixed; inset: 0;
        background-image: url('ceit_bg.jpg');
        background-size: cover; background-position: center; background-repeat: no-repeat;
        opacity: 0.16; z-index: -2;
    }

    /* orange/black contrast wash + dot texture on top of the photo — matches index.php */
    body::after {
        content: '';
        position: fixed; inset: 0;
        background-image: linear-gradient(135deg, rgba(249,115,22,0.45) 0%, rgba(28,25,23,0.55) 45%, rgba(0,0,0,0.85) 100%),
                          radial-gradient(ellipse 80% 60% at 50% 0%, rgba(249,115,22,0.22) 0%, transparent 70%),
                          radial-gradient(rgba(255,255,255,0.035) 1px, transparent 1px);
        background-size: 100% 100%, 100% 100%, 28px 28px;
        z-index: -1;
    }
    .card { width: 100%; max-width: 420px; background: #fff; border-radius: 18px; padding: 36px 32px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); position: relative; }
    h1 { font-size: 20px; font-weight: 800; color: #1C1917; margin-bottom: 4px; }
    .sub { font-size: 13px; color: #78716c; margin-bottom: 22px; }
    label { display: block; font-size: 11px; font-weight: 700; color: #78716c; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 6px; }
    input {
        width: 100%; padding: 11px 14px; border: 1.5px solid #e7e5e4; border-radius: 10px;
        font-size: 14px; margin-bottom: 16px; font-family: inherit; background: #fafaf9;
    }
    input:focus { outline: none; border-color: #F97316; background: #fff; }
    input[name="otp"] { letter-spacing: 8px; font-size: 20px; text-align: center; font-weight: 700; }
    button {
        width: 100%; padding: 12px; background: linear-gradient(135deg, #F97316, #EA580C);
        color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
        cursor: pointer; font-family: inherit;
    }
    .alert { padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
    .alert-err { background: #fff5f5; border: 1.5px solid #fecaca; color: #b91c1c; }
    .alert-info { background: #eff6ff; border: 1.5px solid #bfdbfe; color: #1e40af; }
    .back { display: block; text-align: center; margin-top: 16px; font-size: 13px; color: #78716c; text-decoration: none; }
    .back:hover { color: #F97316; }
    .back-corner {
        position: absolute; top: 18px; right: 18px;
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 50%;
        color: #78716c; background: #f5f5f4; text-decoration: none;
        transition: background .2s, color .2s;
    }
    .back-corner:hover { background: #ffedd5; color: #EA580C; }
</style>
</head>
<body>
<div class="card">
    <?php if (isLoggedIn()): ?>
    <a class="back-corner" href="<?= BASE_URL ?>/profile.php" title="Back to My Information" aria-label="Back to My Information">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
    </a>
    <?php endif; ?>
    <?php if ($step === 'request'): ?>
        <h1>Forgot Password?</h1>
        <div class="sub">Enter your username. We'll send an OTP to your linked email.</div>
        <?php if ($error): ?><div class="alert alert-err"><?= $error ?></div><?php endif; ?>
        <?php if ($info): ?><div class="alert alert-info"><?= $info ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="request_otp">
            <label>Username</label>
            <input type="text" name="username" required autofocus value="<?= isLoggedIn() ? sanitize($_SESSION['username']) : '' ?>">
            <button type="submit">Send OTP</button>
        </form>

    <?php elseif ($step === 'verify'): ?>
        <h1>Enter OTP</h1>
        <div class="sub">We sent a 6-digit code to <strong><?= sanitize($_SESSION['reset_email_display'] ?? 'your email') ?></strong>. It's valid for <?= OTP_EXPIRY_MINUTES ?> minutes.</div>
        <?php if ($error): ?><div class="alert alert-err"><?= $error ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="verify_otp">
            <label>OTP Code</label>
            <input type="text" name="otp" maxlength="<?= OTP_LENGTH ?>" pattern="\d{<?= OTP_LENGTH ?>}" inputmode="numeric" required autofocus>
            <button type="submit">Verify</button>
        </form>
        <a class="back" href="<?= BASE_URL ?>/forgot_password.php?restart=1">Start over</a>
    <?php elseif ($step === 'reset'): ?>
        <h1>New Password</h1>
        <div class="sub">OTP verified. Please enter your new password.</div>
        <?php if ($error): ?><div class="alert alert-err"><?= $error ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <label>New Password</label>
            <input type="password" name="new_password" minlength="8" required autofocus>
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" minlength="8" required>
            <button type="submit">Save Password</button>
        </form>
    <?php endif; ?>

    <a class="back" href="<?= BASE_URL ?>/index.php">Back to Login</a>
</div>
</body>
</html>
