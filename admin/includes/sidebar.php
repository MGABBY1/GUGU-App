<?php
$role_id = isset($portal_role_id)
  ? (int) $portal_role_id
  : (int) ($_SESSION['role_id'] ?? 4);
$actualRoleId = isset($portal_actual_role_id)
  ? (int) $portal_actual_role_id
  : (int) ($_SESSION['role_id'] ?? 4);
$nick = $_SESSION['nickname'] ?? 'Staff';
$viewDistrictLabel = trim((string) ($portal_view_district ?? ''));
if ($viewDistrictLabel === '' && in_array($role_id, [2, 3], true)) {
  $viewDistrictLabel = trim((string) ($_SESSION['admin_district'] ?? $_SESSION['district'] ?? ''));
}
require_once __DIR__ . '/management_roles.php';
$roles = guguManagementRoles();
$portalName = match ($role_id) {
    1 => $roles[1]['workspace'],
    2 => $roles[2]['workspace'],
    3 => $roles[3]['workspace'],
    default => 'Dashboard',
};
$base = '/gugu-app/admin/dashboard.php';
$viewQs = '';
if ($actualRoleId === 1 && ($role_id === 2 || $role_id === 3)) {
  $viewQs = 'view_role=' . (int) $role_id
    . '&view_district=' . rawurlencode((string) ($portal_view_district ?? ''))
    . '&';
}
$paneHref = static function (string $pane) use ($base, $viewQs): string {
  $pane = trim($pane);
  if ($pane === '' || $pane === 'home') {
    return $base . ($viewQs !== '' ? ('?' . rtrim($viewQs, '&')) : '');
  }
  return $base . '?' . $viewQs . 'pane=' . rawurlencode($pane);
};
$sidebarFoot = match ($role_id) {
    2 => 'District Manager' . ($viewDistrictLabel !== '' ? (' · ' . $viewDistrictLabel) : ''),
    3 => 'Moderator / Support' . ($viewDistrictLabel !== '' ? (' · ' . $viewDistrictLabel) : ''),
    default => $nick,
};
$marketplaceHref = '/gugu-app/app/';
if ($role_id === 2 || $role_id === 3) {
  $marketplaceHref = '/gugu-app/app/?as_portal=' . (int) $role_id
    . ($viewDistrictLabel !== '' ? ('&as_district=' . rawurlencode($viewDistrictLabel)) : '');
}
?>
<aside class="sidebar">
  <div class="sidebar-brand">🇷🇼 Gura & Gurisha</div>
  <div class="sidebar-role"><?= htmlspecialchars(adminRoleLabel($role_id)) ?></div>
  <div class="sidebar-portal-name"><?= htmlspecialchars($portalName) ?></div>
  <?php if ($role_id === 2 && $viewDistrictLabel !== ''): ?>
    <div class="sidebar-district"><?= htmlspecialchars($viewDistrictLabel) ?></div>
  <?php endif; ?>
  <nav>
    <?php if ($role_id === 1): ?>
      <a href="<?= $base ?>?pane=home">Dashboard</a>
      <a href="<?= $base ?>?pane=item-approvals"><strong>Item Approvals</strong></a>
      <a href="<?= $base ?>?pane=job-approvals"><strong>Job Approvals</strong></a>
      <a href="<?= $base ?>?pane=payments">Fee payments</a>
      <a href="<?= $base ?>?pane=system-controls">System Controls</a>
      <a href="<?= $base ?>?pane=staff"><strong>Staff Management</strong></a>
      <a href="<?= $base ?>?pane=members">Members</a>
      <a href="<?= $base ?>?pane=permissions">Role matrix</a>
      <a href="<?= $base ?>?pane=analytics">Financials</a>
      <a href="<?= $base ?>?pane=checklist">Checklist</a>
      <a href="<?= $base ?>?pane=reports">Reports</a>
      <a href="<?= $base ?>?pane=id-queue">ID verification</a>
      <a href="<?= $base ?>?pane=dashboards">Other dashboards</a>
      <a href="/gugu-app/app/?clear_portal_view=1">Marketplace</a>
    <?php elseif ($role_id === 2): ?>
      <a href="<?= htmlspecialchars($paneHref('home')) ?>">Dashboard</a>
      <a href="<?= htmlspecialchars($paneHref('item-approvals')) ?>"><strong>Item Approvals</strong></a>
      <a href="<?= htmlspecialchars($paneHref('job-approvals')) ?>"><strong>Job Approvals</strong></a>
      <a href="<?= htmlspecialchars($paneHref('members')) ?>">Members</a>
      <a href="<?= htmlspecialchars($paneHref('id-queue')) ?>"><strong>ID verification</strong><?php
        // Badge filled after view loads counts when available — keep label strong for visibility
      ?></a>
      <a href="<?= htmlspecialchars($paneHref('moderators')) ?>">Moderators</a>
      <a href="<?= htmlspecialchars($paneHref('reports')) ?>">Local reports</a>
      <a href="<?= htmlspecialchars($paneHref('checklist')) ?>">Checklist</a>
      <a href="<?= htmlspecialchars($paneHref('escalate')) ?>"><strong>Escalate / limits</strong></a>
      <a href="<?= htmlspecialchars($marketplaceHref) ?>">Marketplace</a>
    <?php elseif ($role_id === 3): ?>
      <a href="<?= $base ?>?<?= $viewQs ?>pane=home">Dashboard</a>
      <a href="<?= $base ?>?<?= htmlspecialchars($viewQs) ?>#listings">Flagged queue</a>
      <a href="<?= $base ?>?<?= htmlspecialchars($viewQs) ?>#id-queue">ID verification</a>
      <a href="<?= htmlspecialchars($marketplaceHref) ?>">Marketplace</a>
    <?php else: ?>
      <a href="/gugu-app/app/">Marketplace</a>
    <?php endif; ?>
  </nav>
  <?php if ($actualRoleId === 1 && $role_id !== 1): ?>
    <a class="sidebar-exit-console" href="<?= $base ?>?exit_preview=1">Admin console</a>
  <?php endif; ?>
  <div class="sidebar-foot"><?= htmlspecialchars($sidebarFoot) ?></div>
</aside>
