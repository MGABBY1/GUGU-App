<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_helpers.php';
adminRequireLogin();

$actual_role_id = (int) $_SESSION['role_id'];
$role_id = $actual_role_id;
$nick = $_SESSION['nickname'] ?? 'Staff';
$flash = portalFlash();

// Super Admin can inspect District Manager and Moderator dashboards while
// retaining the real System Administrator session and permissions.
$viewDistrict = '';
if ($actual_role_id === 1) {
    $requestedRole = (int) ($_GET['view_role'] ?? 1);
    if (in_array($requestedRole, [1, 2, 3], true)) {
        $role_id = $requestedRole;
    }
    if ($role_id === 2 || $role_id === 3) {
        $allowedDistricts = portalDistricts();
        $requestedDistrict = trim((string) ($_GET['view_district'] ?? ''));
        $viewDistrict = in_array($requestedDistrict, $allowedDistricts, true)
            ? $requestedDistrict
            : ($allowedDistricts[0] ?? 'Gasabo');
    }
}

$portal_role_id = $role_id;
$portal_actual_role_id = $actual_role_id;
$portal_view_district = $viewDistrict;

$portalTitle = match ($role_id) {
    1 => 'System Control Center',
    2 => 'District Operations Hub',
    3 => 'Trust & Safety Desk',
    default => 'Gura & Gurisha Dashboard',
};
$bodyClass = match ($role_id) {
    1 => 'portal-super',
    2 => 'portal-regional',
    3 => 'portal-support',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($portalTitle) ?> · Gura & Gurisha</title>
  <link rel="stylesheet" href="/gugu-app/admin/assets/admin.css?v=20260731roles">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
  <div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
      <header class="topbar<?= $role_id === 1 ? ' topbar-rw-yellow' : '' ?>">
        <div>
          <strong><?= htmlspecialchars(adminRoleLabel($role_id)) ?> portal</strong>
          <span>
            <?= htmlspecialchars($nick) ?>
            <?= !empty($_SESSION['email']) ? ' · ' . htmlspecialchars($_SESSION['email']) : '' ?>
            <?php if ($actual_role_id === 1 && $role_id !== 1): ?>
              · Preview mode (you remain System Administrator)
            <?php endif; ?>
          </span>
        </div>
        <div class="topbar-actions">
          <?php if ($actual_role_id === 1 && $role_id !== 1): ?>
            <a class="btn-link" href="/gugu-app/admin/dashboard.php">← Back to Super Admin</a>
            <?php if ($role_id === 2 || $role_id === 3): ?>
              <form method="get" action="/gugu-app/admin/dashboard.php" class="dashboard-switcher">
                <input type="hidden" name="view_role" value="<?= (int) $role_id ?>">
                <label>
                  Akarere
                  <select name="view_district" onchange="this.form.submit()">
                    <?php foreach (portalDistricts() as $districtOption): ?>
                      <option value="<?= htmlspecialchars($districtOption) ?>" <?= $viewDistrict === $districtOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($districtOption) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <a class="btn-link" href="/gugu-app/app/">Member marketplace</a>
          <a class="btn-link danger" href="/gugu-app/admin/logout.php">Logout</a>
        </div>
      </header>

      <?php if ($flash): ?>
        <div class="portal-flash portal-flash-<?= htmlspecialchars($flash['type']) ?>">
          <?= htmlspecialchars($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <?php
      switch ($role_id) {
          case 1:
              include __DIR__ . '/views/admin_super_view.php';
              break;
          case 2:
              include __DIR__ . '/views/admin_regional_view.php';
              break;
          case 3:
              include __DIR__ . '/views/support_view.php';
              break;
          case 4:
          default:
              include __DIR__ . '/views/member_view.php';
              break;
      }
      ?>
    </main>
  </div>
</body>
</html>
