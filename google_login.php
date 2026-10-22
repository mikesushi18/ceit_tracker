<?php
// google_login.php — Verifies the Google ID token (JWT) sent from the
// "Sign in with Google" button on index.php, then logs the matching
// local user in. No Google API client library needed — verification is
// done via Google's own tokeninfo endpoint.

require_once 'includes/config.php';
header('Content-Type: application/json');

function jsonFail(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
    jsonFail('No Google credential received.');
}

$idToken = $_POST['credential'];

// Ask Google to verify the token's signature/expiry for us and return its
// decoded claims. This requires internet access from your server.
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$context = stream_context_create(['http' => ['timeout' => 8]]);
$response = @file_get_contents($verifyUrl, false, $context);

if ($response === false) {
    jsonFail('Could not verify the Google account right now. Please try again.', 502);
}

$claims = json_decode($response, true);

if (!$claims || isset($claims['error'])) {
    jsonFail('Invalid or expired Google token.');
}

// Must have been issued for THIS app (prevents token substitution attacks)
if (($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    jsonFail('Google client mismatch. Please try again.');
}

if (($claims['iss'] ?? '') !== 'https://accounts.google.com' && ($claims['iss'] ?? '') !== 'accounts.google.com') {
    jsonFail('Invalid token issuer.');
}

if (empty($claims['email_verified']) || $claims['email_verified'] !== 'true' && $claims['email_verified'] !== true) {
    jsonFail('This Google email is not verified yet.');
}

$email = strtolower(trim($claims['email'] ?? ''));
$googleId = $claims['sub'] ?? '';

if (!isAllowedEmail($email)) {
    jsonFail('Only ' . allowedEmailDomainsLabel() . ' email addresses are accepted for sign-in.');
}

// Find the local account this Gmail is linked to. We do NOT auto-create
// accounts here — accounts are provisioned by an admin (admin/create_user.php)
// with their Gmail attached, then Google Sign-In just becomes an alternate
// login method for that same account.
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    jsonFail('We could not find a CollTrace account linked to this email. Please contact your system administrator for assistance.');
}

if (!$user['is_active']) {
    jsonFail('This account has been deactivated.');
}

// Link the Google account id on first successful Google login (useful for
// audit / future features), but email is what we actually match on.
if (empty($user['google_id'])) {
    $upd = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
    $upd->bind_param('si', $googleId, $user['id']);
    $upd->execute();
    $upd->close();
}

loginUser($conn, $user);

echo json_encode([
    'success' => true,
    'redirect' => dashboardUrlForRole($user['role']),
]);
