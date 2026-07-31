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
$base = '/gugu-app/admin/dashboard.php';
?>
<aside class="sidebar">
  <div class="sidebar-brand">🇷🇼 Gura & Gurisha</div>
  <div class="sidebar-role"><?= htmlspecialchars(adminRoleLabel($role_id)) ?></div>
  <div class="sidebar-portal-name"><?= htmlspecialchars($portalName) ?></div>
  <?php if ($actualRoleId === 1 && $role_id !== 1): ?>
    <div class="sidebar-viewing">Preview only · Super Admin session</div>
  <?php endif; ?>
  <nav>
    <?php if ($role_id === 1): ?>
      <a href="<?= $base ?>?pane=home">Dashboard</a>
      <a href="<?= $base ?>?pane=listings"><strong>Item &amp; Job Approvals</strong></a>
      <a href="<?= $base ?>?pane=payments">Fee payments</a>
      <a href="<?= $base ?>?pane=system-controls">System Controls</a>
      <a href="<?= $base ?>?pane=permissions">Permissions</a>
      <a href="<?= $base ?>?pane=analytics">Financials</a>
      <a href="<?= $base ?>?pane=checklist">Checklist</a>
      <a href="<?= $base ?>?pane=members">Members</a>
      <a href="<?= $base ?>?pane=reports">Reports</a>
      <a href="<?= $base ?>?pane=id-queue">ID verification</a>
      <a href="<?= $base ?>?pane=dashboards">Other dashboards</a>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php elseif ($role_id === 2): ?>
      <a href="<?= $base ?>?view_role=2&amp;view_district=<?= urlencode($portal_view_district ?? '') ?>">Dashboard</a>
      <a href="<?= $base ?>?view_role=2&amp;view_district=<?= urlencode($portal_view_district ?? '') ?>#listings">Review Gurisha</a>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php elseif ($role_id === 3): ?>
      <a href="<?= $base ?>?view_role=3&amp;view_district=<?= urlencode($portal_view_district ?? '') ?>">Dashboard</a>
      <a href="<?= $base ?>?view_role=3&amp;view_district=<?= urlencode($portal_view_district ?? '') ?>#listings">Flagged queue</a>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php else: ?>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php endif; ?>
  </nav>
  <?php if ($actualRoleId === 1 && $role_id !== 1): ?>
    <a class="sidebar-reset-view" href="<?= $base ?>">← Return to Super Admin</a>
  <?php endif; ?>
  <div class="sidebar-foot"><?= htmlspecialchars($nick) ?></div>
</aside>
