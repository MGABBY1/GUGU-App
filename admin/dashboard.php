<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_helpers.php';
adminRequireLogin();

$actual_role_id = (int) $_SESSION['role_id'];
$role_id = $actual_role_id;
$nick = $_SESSION['nickname'] ?? 'Staff';
$flash = portalFlash();

// Admin can inspect District Manager and Moderator dashboards while
// retaining the real System Administrator session and permissions.
// Preview stays sticky in session until Admin clicks “Back to Admin”
// (exit_preview=1 only — never drop back to Admin by accident).
$viewDistrict = '';
if ($actual_role_id === 1) {
    if (isset($_GET['exit_preview'])) {
        portalPreviewClear();
        $role_id = 1;
        $viewDistrict = '';
        // Clean URL after explicit exit so refresh does not re-exit.
        $exitPane = trim((string) ($_GET['pane'] ?? ''));
        $exitUrl = '/gugu-app/admin/dashboard.php';
        if ($exitPane !== '' && $exitPane !== 'home') {
            $exitUrl .= '?pane=' . rawurlencode($exitPane);
        }
        // Clear marketplace portal-view overlay (sessionStorage) then continue.
        $safeExit = htmlspecialchars($exitUrl, ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Exiting…</title>'
            . '<script>'
            . 'try{sessionStorage.removeItem("gugu_portal_view");}catch(e){}'
            . 'location.replace(' . json_encode($exitUrl, JSON_UNESCAPED_SLASHES) . ');'
            . '</script></head><body>'
            . '<p><a href="' . $safeExit . '">Continue to Admin console</a></p>'
            . '</body></html>';
        exit;
    }

    $requestedRole = (int) ($_GET['view_role'] ?? 0);
    $preview = portalPreviewGet();
    if (in_array($requestedRole, [2, 3], true)) {
        $role_id = $requestedRole;
    } elseif ($preview['role'] === 2 || $preview['role'] === 3) {
        // No view_role in URL — stay locked on sticky District/Moderator session.
        $role_id = $preview['role'];
    } else {
        $role_id = 1;
    }

    if ($role_id === 2 || $role_id === 3) {
        $allowedDistricts = portalDistricts();
        $requestedDistrict = trim((string) ($_GET['view_district'] ?? ''));
        if ($requestedDistrict === '' && $preview['district'] !== '') {
            $requestedDistrict = $preview['district'];
        }
        $viewDistrict = in_array($requestedDistrict, $allowedDistricts, true)
            ? $requestedDistrict
            : ($allowedDistricts[0] ?? 'Gasabo');
        portalPreviewSet($role_id, $viewDistrict);

        // Keep preview params visible in the URL so every refresh / back stays on DM.
        $urlRole = (int) ($_GET['view_role'] ?? 0);
        $urlDistrict = trim((string) ($_GET['view_district'] ?? ''));
        if ($urlRole !== $role_id || $urlDistrict !== $viewDistrict) {
            $qs = [
                'view_role' => $role_id,
                'view_district' => $viewDistrict,
            ];
            $pane = trim((string) ($_GET['pane'] ?? ''));
            if ($pane !== '' && $pane !== 'home') {
                $qs['pane'] = $pane;
            }
            header('Location: /gugu-app/admin/dashboard.php?' . http_build_query($qs));
            exit;
        }
    } else {
        portalPreviewClear();
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

// Corner notification bell — payments + members waiting for ID (Admin / DM / Moderator)
$portal_payment_alerts = null;
$portal_member_alerts = null;
$portal_payment_scope = 'Nationwide';
if (in_array($role_id, [1, 2, 3], true)) {
    $dbAlerts = getDB();
    if ($role_id === 2 || $role_id === 3) {
        $portal_payment_scope = $viewDistrict !== ''
            ? $viewDistrict
            : (string) ($_SESSION['admin_district'] ?? $_SESSION['district'] ?? 'Gasabo');
        $scopeDistrict = $portal_payment_scope !== '' ? $portal_payment_scope : null;
        $portal_payment_alerts = $role_id === 2
            ? portalPaymentAlerts($dbAlerts, $scopeDistrict)
            : portalEmptyPaymentAlerts();
        $portal_member_alerts = portalMemberAlerts($dbAlerts, $scopeDistrict);
    } else {
        $portal_payment_alerts = portalPaymentAlerts($dbAlerts, null);
        $portal_member_alerts = portalMemberAlerts($dbAlerts, null);
        $portal_payment_scope = 'Nationwide';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($portalTitle) ?> · Gura & Gurisha</title>
  <link rel="stylesheet" href="/gugu-app/admin/assets/admin.css?v=20260804memberalerts">
  <?php if (in_array((int) $role_id, [1, 2], true) || (int) $actual_role_id === 1): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
  <div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
      <header class="topbar topbar-rw-yellow">
        <div>
          <?php if ((int) $role_id === 2): ?>
            <strong>District Manager portal</strong>
            <span>
              District Operations Hub
              <?php if ($viewDistrict !== ''): ?>
                · <?= htmlspecialchars($viewDistrict) ?>
              <?php elseif (!empty($_SESSION['admin_district'])): ?>
                · <?= htmlspecialchars((string) $_SESSION['admin_district']) ?>
              <?php endif; ?>
            </span>
          <?php elseif ((int) $role_id === 3): ?>
            <strong>Moderator / Support portal</strong>
            <span>
              Trust &amp; Safety Desk
              <?php if ($viewDistrict !== ''): ?>
                · <?= htmlspecialchars($viewDistrict) ?>
              <?php endif; ?>
            </span>
          <?php else: ?>
            <strong><?= htmlspecialchars(adminRoleLabel($role_id)) ?> portal</strong>
            <span>
              <?= htmlspecialchars($nick) ?>
              <?= !empty($_SESSION['email']) ? ' · ' . htmlspecialchars($_SESSION['email']) : '' ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="topbar-actions">
          <?php if (is_array($portal_payment_alerts)): ?>
            <?php portalRenderPaymentBell($portal_payment_alerts, $portal_payment_scope, $portal_member_alerts); ?>
          <?php endif; ?>
          <?php if ($actual_role_id === 1 && ($role_id === 2 || $role_id === 3)): ?>
            <?php
              $switchPane = trim((string) ($_GET['pane'] ?? ''));
              if ($switchPane === 'home') {
                  $switchPane = '';
              }
            ?>
            <form method="get" action="/gugu-app/admin/dashboard.php" class="dashboard-switcher" id="portal-district-switcher" title="Switch Akarere">
              <input type="hidden" name="view_role" value="<?= (int) $role_id ?>">
              <?php if ($switchPane !== ''): ?>
                <input type="hidden" name="pane" value="<?= htmlspecialchars($switchPane) ?>" data-district-pane="1">
              <?php else: ?>
                <input type="hidden" value="" data-district-pane="1">
              <?php endif; ?>
              <label>
                Akarere
                <select name="view_district" id="portal-district-select">
                  <?php foreach (portalDistricts() as $districtOption): ?>
                    <option value="<?= htmlspecialchars($districtOption) ?>" <?= $viewDistrict === $districtOption ? 'selected' : '' ?>>
                      <?= htmlspecialchars($districtOption) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </form>
            <script>
            (function () {
              var form = document.getElementById('portal-district-switcher');
              var select = document.getElementById('portal-district-select');
              if (!form || !select) return;
              select.addEventListener('change', function () {
                var paneInput = form.querySelector('[data-district-pane="1"]');
                var pane = '';
                try {
                  pane = new URL(location.href).searchParams.get('pane') || '';
                } catch (e) {}
                if (pane === 'home') pane = '';
                if (paneInput) {
                  if (pane) {
                    paneInput.setAttribute('name', 'pane');
                    paneInput.value = pane;
                  } else {
                    paneInput.removeAttribute('name');
                    paneInput.value = '';
                  }
                }
                form.submit();
              });
            })();
            </script>
          <?php endif; ?>
          <?php
            $marketHref = '/gugu-app/app/?clear_portal_view=1';
            if ((int) $role_id === 2 || (int) $role_id === 3) {
              $marketDistrict = $viewDistrict !== ''
                ? $viewDistrict
                : (string) ($_SESSION['admin_district'] ?? $_SESSION['district'] ?? '');
              $marketHref = '/gugu-app/app/?as_portal=' . (int) $role_id
                . ($marketDistrict !== '' ? ('&as_district=' . rawurlencode($marketDistrict)) : '');
            }
          ?>
          <a class="btn-link" href="<?= htmlspecialchars($marketHref) ?>">Member marketplace</a>
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
