<?php
$role_id = isset($portal_role_id)
  ? (int) $portal_role_id
  : (int) ($_SESSION['role_id'] ?? 4);
$actualRoleId = isset($portal_actual_role_id)
  ? (int) $portal_actual_role_id
  : (int) ($_SESSION['role_id'] ?? 4);
$nick = $_SESSION['nickname'] ?? 'Staff';
require_once __DIR__ . '/management_roles.php';
$roles = guguManagementRoles();
$portalName = match ($role_id) {
    1 => $roles[1]['workspace'],
    2 => $roles[2]['workspace'],
    3 => $roles[3]['workspace'],
    default => 'Dashboard',
};
?>
<aside class="sidebar">
  <div class="sidebar-brand">🇷🇼 Gura & Gurisha</div>
  <div class="sidebar-role"><?= htmlspecialchars(adminRoleLabel($role_id)) ?></div>
  <div class="sidebar-portal-name"><?= htmlspecialchars($portalName) ?></div>
  <?php if ($actualRoleId === 1 && $role_id !== 1): ?>
    <div class="sidebar-viewing">Super Admin preview</div>
  <?php endif; ?>
  <nav>
    <?php if ($role_id === 1): ?>
      <a href="/gugu-app/admin/dashboard.php#home">Dashboard</a>
      <a href="/gugu-app/admin/dashboard.php#system-controls">System Controls</a>
      <a href="/gugu-app/admin/dashboard.php#permissions">Permissions</a>
      <a href="/gugu-app/admin/dashboard.php#analytics">Financials</a>
      <a href="/gugu-app/admin/dashboard.php#dashboards">Other dashboards</a>
      <a href="/gugu-app/admin/dashboard.php#checklist">Checklist</a>
      <a href="/gugu-app/admin/dashboard.php#members">Members</a>
      <a href="/gugu-app/admin/dashboard.php#payments">Payments</a>
      <a href="/gugu-app/admin/dashboard.php#listings">Approvals</a>
      <a href="/gugu-app/admin/dashboard.php#reports">Reports</a>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php elseif ($role_id === 2): ?>
      <a class="active" href="/gugu-app/admin/dashboard.php">Dashboard</a>
      <a href="/gugu-app/admin/dashboard.php#checklist">Checklist</a>
      <a href="/gugu-app/admin/dashboard.php#users">Verify sellers</a>
      <a href="/gugu-app/admin/dashboard.php#listings">Review Gurisha</a>
      <a href="/gugu-app/admin/dashboard.php#reports">Local reports</a>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php elseif ($role_id === 3): ?>
      <a class="active" href="/gugu-app/admin/dashboard.php">Dashboard</a>
      <a href="/gugu-app/admin/dashboard.php#checklist">Checklist</a>
      <a href="/gugu-app/admin/dashboard.php#id-queue">ID verification</a>
      <a href="/gugu-app/admin/dashboard.php#listings">Flagged queue</a>
      <a href="/gugu-app/admin/dashboard.php#reports">Support tickets</a>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php else: ?>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php endif; ?>
  </nav>
  <?php if ($actualRoleId === 1 && $role_id !== 1): ?>
    <a class="sidebar-reset-view" href="/gugu-app/admin/dashboard.php">—Return to Super Admin</a>
  <?php endif; ?>
  <div class="sidebar-foot"><?= htmlspecialchars($nick) ?></div>
</aside>
