<?php
// logout.php
require_once 'includes/config.php';
if (isLoggedIn()) {
    logActivity($conn, $_SESSION['user_id'], 'LOGOUT', 'User logged out');
}
session_destroy();
redirect(BASE_URL . '/index.php');
?>
