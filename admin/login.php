<?php
/**
 * Single login entry — staff and members use the React app login page.
 * Staff: phone + email password · Members: phone + OTP
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id']) && in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3], true)) {
    header('Location: /gugu-app/admin/dashboard.php');
    exit;
}

header('Location: /gugu-app/app/?login=1', true, 302);
exit;
