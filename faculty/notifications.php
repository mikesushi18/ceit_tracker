<?php
require_once '../includes/config.php';
requireRole('faculty');
redirect(BASE_URL . '/notifications.php');
