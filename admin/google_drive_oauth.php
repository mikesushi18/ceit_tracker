<?php
require_once '../includes/config.php';
require_once '../includes/google_drive_backup.php';
requireAdminDashboardAccess();

function driveOAuthRedirect(string $status): void
{
    header('Location: ' . BASE_URL . '/admin/backup_storage.php?oauth=' . rawurlencode($status));
    exit;
}

if (isset($_GET['connect'])) {
    try {
        googleDriveClientSecret();
    } catch (Throwable $error) {
        $_SESSION['google_drive_oauth_error'] = $error->getMessage();
        driveOAuthRedirect('failed');
    }

    $state = bin2hex(random_bytes(32));
    $_SESSION['google_drive_oauth_state'] = $state;
    $parameters = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => googleDriveRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid email https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent select_account',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($parameters));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect') {
    $csrfToken = (string) ($_SESSION['backup_csrf_token'] ?? '');
    if ($csrfToken === '' || !hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        driveOAuthRedirect('invalid_state');
    }
    if (is_file(googleDriveTokenPath()) && !unlink(googleDriveTokenPath())) {
        driveOAuthRedirect('disconnect_failed');
    }
    driveOAuthRedirect('disconnected');
}

if (isset($_GET['error'])) {
    driveOAuthRedirect('cancelled');
}

$state = (string) ($_GET['state'] ?? '');
$expectedState = (string) ($_SESSION['google_drive_oauth_state'] ?? '');
unset($_SESSION['google_drive_oauth_state']);
if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state) || empty($_GET['code'])) {
    driveOAuthRedirect('invalid_state');
}

try {
    $token = googleDriveExchangeCode((string) $_GET['code']);
    if (empty($token['access_token']) || empty($token['refresh_token'])) {
        throw new RuntimeException('Google did not provide offline access. Reconnect and approve the requested access.');
    }
    $userInfo = googleDriveDecodeResponse(googleDriveHttp(
        'https://openidconnect.googleapis.com/v1/userinfo',
        'GET',
        ['Authorization: Bearer ' . $token['access_token']]
    ));
    $email = strtolower(trim((string) ($userInfo['email'] ?? '')));
    if (empty($userInfo['email_verified']) || !isAllowedEmail($email)) {
        throw new RuntimeException('Use a verified CvSU Google account (' . allowedEmailDomainsLabel() . ').');
    }

    googleDriveSaveToken([
        'email' => $email,
        'refresh_token' => $token['refresh_token'],
        'access_token' => $token['access_token'],
        'expires_at' => time() + (int) ($token['expires_in'] ?? 0),
    ]);
    driveOAuthRedirect('connected');
} catch (Throwable $error) {
    $_SESSION['google_drive_oauth_error'] = $error->getMessage();
    driveOAuthRedirect('failed');
}
