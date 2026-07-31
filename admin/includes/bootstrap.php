<?php
/**
 * GUGU Staff Portal — session bootstrap
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/helpers.php';

function adminRequireLogin(): void {
    if (empty($_SESSION['user_id']) || empty($_SESSION['role_id'])) {
        header('Location: /gugu-app/app/?login=1');
        exit;
    }
    $role = (int) $_SESSION['role_id'];
    if ($role < 1 || $role > 3) {
        header('Location: /gugu-app/app/');
        exit;
    }
}

function adminRoleLabel(int $roleId): string {
    return match ($roleId) {
        1 => 'System Administrator (Super Admin)',
        2 => 'District Manager',
        3 => 'Moderator / Support',
        4 => 'Member',
        default => 'Guest',
    };
}
