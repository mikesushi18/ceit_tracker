<?php
// index.php - Login Page
require_once 'includes/config.php';

if (isLoggedIn()) {
    redirect(dashboardUrlForRole($_SESSION['role']));
}

$error = '';
$resetSuccess = isset($_GET['reset']) && $_GET['reset'] === 'success';

$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
    username VARCHAR(100) NOT NULL PRIMARY KEY,
    failed_attempts INT NOT NULL DEFAULT 0,
    lock_level INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

function loginLockSeconds(int $lockLevel): int {
    return match ($lockLevel) {
        1 => 60,
        2 => 300,
        default => max(600, 600 * (2 ** max(0, $lockLevel - 3))),
    };
}

function recordFailedLogin(mysqli $conn, string $username): int {
    $stmt = $conn->prepare("SELECT failed_attempts, lock_level FROM login_attempts WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: ['failed_attempts' => 0, 'lock_level' => 0];
    $stmt->close();

    $failedAttempts = (int) $record['failed_attempts'] + 1;
    $lockLevel = (int) $record['lock_level'];
    $threshold = $lockLevel === 0 ? 6 : 3;
    $lockedUntil = null;

    if ($failedAttempts >= $threshold) {
        $lockLevel++;
        $lockedUntil = date('Y-m-d H:i:s', time() + loginLockSeconds($lockLevel));
        $failedAttempts = 0;
    }

    $stmt = $conn->prepare("INSERT INTO login_attempts (username, failed_attempts, lock_level, locked_until) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE failed_attempts = VALUES(failed_attempts), lock_level = VALUES(lock_level), locked_until = VALUES(locked_until)");
    $stmt->bind_param('siis', $username, $failedAttempts, $lockLevel, $lockedUntil);
    $stmt->execute();
    $stmt->close();

    return $lockedUntil ? loginLockSeconds($lockLevel) : 0;
}

function clearFailedLogins(mysqli $conn, string $username): void {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $lockStmt = $conn->prepare("SELECT failed_attempts, lock_level, locked_until FROM login_attempts WHERE username = ?");
        $lockStmt->bind_param('s', $username);
        $lockStmt->execute();
        $lockRecord = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if ($lockRecord && !empty($lockRecord['locked_until']) && strtotime($lockRecord['locked_until']) > time()) {
            $remaining = strtotime($lockRecord['locked_until']) - time();
            $error = 'Too many failed attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).';
            goto render_login;
        }

        $stmt = $conn->prepare("SELECT id, username, password, full_name, role, department_id, is_active FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && $user['is_active']) {
            // NOTE: legacy plaintext passwords (from database.sql sample data,
            // e.g. 'admin123') are auto-upgraded to a bcrypt hash on first
            // successful login, then plaintext comparison is never used again.
            $isHashed = password_get_info($user['password'])['algo'] !== null;
            $valid = $isHashed
                ? password_verify($password, $user['password'])
                : $password === $user['password'];

            if ($valid) {
                if (!$isHashed) {
                    $newHash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->bind_param('si', $newHash, $user['id']);
                    $upd->execute();
                    $upd->close();
                }
                clearFailedLogins($conn, $username);
                loginUser($conn, $user);
                redirect(dashboardUrlForRole($user['role']));
            } else {
                $lockSeconds = recordFailedLogin($conn, $username);
                $error = $lockSeconds > 0
                    ? 'Too many failed attempts. Login is locked for ' . ceil($lockSeconds / 60) . ' minute(s).'
                    : 'Invalid username or password.';
            }
        } else {
            $lockSeconds = recordFailedLogin($conn, $username);
            $error = $lockSeconds > 0
                ? 'Too many failed attempts. Login is locked for ' . ceil($lockSeconds / 60) . ' minute(s).'
                : 'Invalid username or password.';
        }
    }
}
render_login:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollTrace — CEIT File Tracking System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            background: #1C1917;
        }

        /* faint campus photo, sitting behind everything */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url('ceit_bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0.16;
            z-index: -2;
        }

        /* orange/black contrast wash + dot texture on top of the photo */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: linear-gradient(135deg, rgba(249, 116, 22, 0.21) 0%, rgba(28, 25, 23, 0.46) 45%, rgba(0, 0, 0, 0.53) 100%),
                              radial-gradient(ellipse 80% 60% at 50% 0%, rgba(249,115,22,0.22) 0%, transparent 70%),
                              radial-gradient(rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 28px 28px;
            z-index: -1;
        }

        .loading-screen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            backdrop-filter: blur(6px);
            transition: opacity 0.35s ease, visibility 0.35s ease;
        }
        .loading-screen.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .loading-logo {
            width: 120px;
            height: 120px;
            object-fit: contain;
            animation: spin-logo 1s linear infinite;
            filter: drop-shadow(0 10px 24px rgba(0,0,0,0.25));
        }
        @keyframes spin-logo {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ── THE CARD ── */
        .card {
            display: flex;
            width: 100%;
            max-width: 860px;
            min-height: 520px;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 30px 80px rgba(0,0,0,0.55),
                        0 10px 24px rgba(0,0,0,0.35),
                        0 0 0 1px rgba(255,255,255,0.06),
                        inset 0 1px 0 rgba(255,255,255,0.08);
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .card.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── LEFT SIDE ── */
        .left {
            width: 48%;
            background: #231F1B;
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        .left::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 340px; height: 340px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(249,115,22,0.2) 0%, transparent 65%);
            pointer-events: none;
        }
        .left::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -60px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(249,115,22,0.1) 0%, transparent 65%);
            pointer-events: none;
        }
        .dot-grid {
            position: absolute; inset: 0;
            background-image: radial-gradient(rgba(255,255,255,0.05) 1px, transparent 1px);
            background-size: 26px 26px;
            pointer-events: none;
        }

        /* brand */
        .brand { display: flex; align-items: center; gap: 10px; position: relative; z-index: 1; }
        .brand-icon {
            width: 58px; height: 58px; border-radius: 12px;
            overflow: hidden;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: transparent;
        }
        .brand-icon img {
            width: 108%; height: 108%; object-fit: contain; display: block;
            transform: scale(1.04);
        }
        .brand-name { font-size: 20px; font-weight: 800; color: #fff; }
        .brand-name span { color: #FB923C; }

        /* headline */
        .left-headline { position: relative; z-index: 1; margin-top: 8px; }
        .left-headline h2 {
            font-size: 30px; font-weight: 800; color: #fff;
            line-height: 1.15; letter-spacing: -0.4px; margin-bottom: 12px;
            text-wrap: balance;
        }
        .left-headline h2 em {
            font-style: normal;
            background: linear-gradient(90deg, #F97316, #FDBA74);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .left-headline p { font-size: 13px; color: #a8a29e; line-height: 1.7; font-weight: 500; }

        /* feature list */
        .feat-list { display: flex; flex-direction: column; gap: 11px; position: relative; z-index: 1; }
        .feat-item { display: flex; align-items: center; gap: 11px; font-size: 13px; color: #d6d3d1; }
        .feat-icon {
            width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
            background: rgba(249,115,22,0.12);
            border: 1px solid rgba(249,115,22,0.22);
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }

        /* ── RIGHT SIDE ── */
        .right {
            flex: 1;
            background: #fff;
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-title {
            font-size: 22px; font-weight: 800;
            color: #1C1917; margin-bottom: 4px; letter-spacing: -0.3px;
            line-height: 1.2;
        }
        .login-sub { font-size: 13px; color: #78716c; margin-bottom: 26px; font-weight: 500; }

        /* form */
        .form-label {
            display: block; font-size: 11px; font-weight: 700;
            color: #78716c; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.8px;
            font-family: 'Inter', 'Segoe UI', sans-serif;
        }
        .input-wrap { position: relative; margin-bottom: 15px; }
        .input-icon {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%); pointer-events: none;
            display: flex; align-items: center; justify-content: center;
            color: #a8a29e;
        }
        .input-icon svg { width: 16px; height: 16px; }
        .input-wrap input {
            width: 100%; padding: 11px 14px 11px 38px;
            border: 1.5px solid #e7e5e4; border-radius: 10px;
            font-size: 14px; font-family: inherit;
            color: #1C1917; background: #fafaf9; outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-weight: 500;
        }
        .input-wrap input:focus {
            border-color: #F97316;
            box-shadow: 0 0 0 3px rgba(249,115,22,0.1);
            background: #fff;
        }
        .input-wrap input::placeholder { color: #c4b9b0; }
        .toggle-pw {
            position: absolute; right: 11px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #a8a29e; padding: 2px;
            line-height: 1; transition: color 0.2s;
            display: flex; align-items: center; justify-content: center;
        }
        .toggle-pw svg { width: 17px; height: 17px; }
        .toggle-pw:hover { color: #F97316; }

        .btn-login {
            width: 100%; padding: 12px;
            background: linear-gradient(135deg, #F97316, #EA580C);
            color: #fff; border: none; border-radius: 10px;
            font-size: 15px; font-weight: 700; font-family: inherit;
            cursor: pointer;
            letter-spacing: 0.2px;
            box-shadow: 0 4px 14px rgba(249,115,22,0.35);
            transition: all 0.2s; margin-top: 4px;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #EA580C, #C2410C);
            box-shadow: 0 6px 20px rgba(249,115,22,0.45);
            transform: translateY(-1px);
        }
        .btn-login:active { transform: translateY(0); }

        /* error */
        .error {
            display: flex; align-items: center; gap: 8px;
            background: #fff7ed; border: 1px solid #fed7aa; border-left: 4px solid #f97316;
            color: #7c2d12; padding: 12px 14px; border-radius: 10px;
            font-size: 13px; line-height: 1.55; margin-bottom: 16px;
            box-shadow: 0 4px 14px rgba(124,45,18,.08);
        }
        .error svg { width: 18px; height: 18px; flex-shrink: 0; color: #ea580c; }
        .error.success-message { background:#fff7ed;border-color:#fed7aa;color:#9a3412; }

        .card-footer { margin-top: 16px; font-size: 11px; color: #cf6113; }

        .primary-login-label { font-size: 12px; color: #78716c; margin-bottom: 40px; font-weight: 600; }
        .login-switch { width: 100%; padding: 10px 12px; border: 1.5px solid #e7e5e4; border-radius: 10px; background: #fafaf9; color: #57534e; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .2s; }
        .login-switch:hover { border-color: #F97316; color: #EA580C; background: #fff8f0; }
        .primary-login + .login-switch { margin-top: 18px; }
        .legacy-login { margin-top: 16px; }
        .legacy-login.hidden { display: none; }
        .primary-login.hidden, .login-switch.hidden, .continue-email.hidden { display: none; }
        .continue-email { margin-top: 14px; }

        .divider { display: flex; align-items: center; gap: 10px; margin: 18px 0 14px; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e7e5e4; }
        .divider span { font-size: 11px; color: #a8a29e; font-weight: 600; text-transform: uppercase; }

        /* responsive */
        @media (max-width: 680px) {
            .card { flex-direction: column; }
            .left { width: 100%; padding: 28px 24px; }
            .left-headline h2 { font-size: 22px; }
            .right { padding: 28px 24px; }
        }
    </style>
</head>
<body>
<div id="loadingScreen" class="loading-screen" aria-hidden="true">
    <img class="loading-logo" src="ceit_logo.png" alt="Loading CEIT logo">
</div>

<div class="card">

    <!-- LEFT -->
    <div class="left">
        <div class="dot-grid"></div>

        <div class="brand">
            <div class="brand-icon">
                <img src="ceit_logo.png" alt="CEIT Logo">
            </div>
            <div class="brand-name">Coll<span>Trace</span></div>
        </div>

        <div class="left-headline">
            <h2>Track Every<br>Submission,<br><em>Effortlessly.</em></h2>
            <p>College of Engineering &amp; Information Technology - CvSU Main Campus</p>
        </div>

        <div class="feat-list">
            
        </div>
    </div>

    <!-- RIGHT -->
    <div class="right">
        <div class="login-title">Welcome to CollTrace! </div>
        <div class="login-sub">Sign in your CollTrace account</div>

        <?php if ($error): ?>
        <div class="error" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg><span><?= sanitize($error) ?></span></div>
        <?php endif; ?>
        <?php if ($resetSuccess): ?>
        <div class="error success-message" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><span>Your password was changed successfully. You may now log in.</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'google_failed'): ?>
        <div class="error" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg><span>Could not verify your Google account. Please try again.</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
        <div class="error" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span>You are not authorized to access that page.</span></div>
        <?php endif; ?>

        <div id="primaryLogin" class="primary-login <?= $error ? 'hidden' : '' ?>">
            <div class="primary-login-label">Use your CvSU email to sign in with Google.</div>
            <div id="g_id_signin_wrap" style="display:flex;justify-content:center;"></div>
            <div id="googleError" class="error" role="alert" style="display:none;margin-top:12px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg><span id="googleErrorMessage"></span></div>
        </div>

        <button type="button" class="login-switch <?= $error ? 'hidden' : '' ?>" id="loginSwitch" onclick="toggleLegacyLogin()">Login using username and password</button>

        <div id="legacyLogin" class="legacy-login <?= $error ? '' : 'hidden' ?>">
        <form method="POST" autocomplete="on">
            <label class="form-label">Username</label>
            <div class="input-wrap">
                <span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                <input type="text" name="username" id="username"
                       placeholder="Enter username" required autocomplete="username"
                       value="<?= isset($_POST['username']) ? sanitize($_POST['username']) : '' ?>">
            </div>

            <label class="form-label">Password</label>
            <div class="input-wrap">
                <span class="input-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                <input type="password" name="password" id="password"
                       placeholder="Enter password" required autocomplete="current-password">
                <button type="button" class="toggle-pw" id="toggleBtn" onclick="togglePw()">
                    <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div style="text-align:right;margin-top:10px;">
            <a href="<?= BASE_URL ?>/forgot_password.php" style="font-size:12px;color:#78716c;text-decoration:none;font-weight:600;">Forgot password?</a>
        </div>
        <button type="button" class="login-switch continue-email <?= $error ? '' : 'hidden' ?>" id="continueEmail" onclick="toggleLegacyLogin()">Continue as email</button>
        </div>

        <div class="card-footer">CEIT File Tracking System &nbsp;·&nbsp; CollTrace v1.0</div>
    </div>

</div>

<script>
const eyeIcon    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOffIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 11 8 11 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 1 12s4 8 11 8a9.74 9.74 0 0 0 5.39-1.61"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

function togglePw() {
    const pw  = document.getElementById('password');
    const btn = document.getElementById('toggleBtn');
    pw.type   = pw.type === 'password' ? 'text' : 'password';
    btn.innerHTML = pw.type === 'password' ? eyeIcon : eyeOffIcon;
}
function toggleLegacyLogin() {
    const panel = document.getElementById('legacyLogin');
    const primary = document.getElementById('primaryLogin');
    const switchButton = document.getElementById('loginSwitch');
    const continueButton = document.getElementById('continueEmail');
    const hidden = panel.classList.toggle('hidden');
    primary.classList.toggle('hidden', !hidden);
    switchButton.classList.toggle('hidden', !hidden);
    continueButton.classList.toggle('hidden', hidden);
    if (!hidden) document.getElementById('username').focus();
}
document.addEventListener('DOMContentLoaded', () => {
    const u = document.getElementById('username');
    if (!document.getElementById('legacyLogin').classList.contains('hidden') && !u.value) u.focus();
});

window.addEventListener('load', () => {
    const loader = document.getElementById('loadingScreen');
    const card = document.querySelector('.card');

    if (loader) {
        setTimeout(() => {
            loader.classList.add('hidden');
            if (card) {
                setTimeout(() => card.classList.add('visible'), 120);
            }
        }, 1400);
    } else if (card) {
        card.classList.add('visible');
    }
});

// Callback invoked by Google after the user picks/confirms their account.
// `response.credential` is a signed JWT — we send it to the server, which
// verifies it with Google directly before ever trusting it.
async function handleGoogleCredential(response) {
    const errBox = document.getElementById('googleError');
    errBox.style.display = 'none';
    try {
        const res = await fetch('<?= BASE_URL ?>/google_login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'credential=' + encodeURIComponent(response.credential)
        });
        const data = await res.json();
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            document.getElementById('googleErrorMessage').textContent = data.message;
            errBox.style.display = 'flex';
        }
    } catch (e) {
        document.getElementById('googleErrorMessage').textContent = 'Connection problem. Please try again.';
        errBox.style.display = 'flex';
    }
}
</script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<div id="g_id_onload"
     data-client_id="<?= GOOGLE_CLIENT_ID ?>"
     data-callback="handleGoogleCredential"
     data-auto_prompt="false">
</div>
<script>
window.addEventListener('load', () => {
    if (window.google && google.accounts && google.accounts.id) {
        google.accounts.id.renderButton(
            document.getElementById('g_id_signin_wrap'),
            { theme: 'outline', size: 'large', text: 'signin_with', shape: 'pill', width: 280 }
        );
    }
});
</script>
</body>
</html>