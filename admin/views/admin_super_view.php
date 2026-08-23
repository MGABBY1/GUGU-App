<?php
/**
 * Role 1 ? Admin (platform owner) ? Nationwide
 * Focus: System Controls, Permissions, Financial Analytics; open any dashboard
 */
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/management_roles.php';
require_once __DIR__ . '/../../config/app.php';

$db = getDB();
$selfId = (int) $_SESSION['user_id'];
$districts = portalDistricts();
$mgmt = guguManagementRoles()[1];
$mgmtRoles = guguManagementRoles();

$users = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$reports = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status IN ("open","reviewing")')->fetchColumn();
$staffCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE role_id BETWEEN 1 AND 3')->fetchColumn();
$byRole = $db->query('SELECT role_id, COUNT(*) c FROM users GROUP BY role_id ORDER BY role_id')->fetchAll();

// Separate businesses: Items (Gurisha) vs Jobs (Akazi)
$itemStream = portalBusinessStream($db, 'item');
$jobStream = portalBusinessStream($db, 'job');
$active = (int) $itemStream['active'] + (int) $jobStream['active'];
$review = (int) $itemStream['review'] + (int) $jobStream['review'];
$listingTotal = (int) $itemStream['total'] + (int) $jobStream['total'];
$paidPending = (int) $itemStream['paid_pending'] + (int) $jobStream['paid_pending'];
$unpaidPending = (int) $itemStream['unpaid_pending'] + (int) $jobStream['unpaid_pending'];
$feeIncome = (int) $itemStream['fee_income'] + (int) $jobStream['fee_income'];
$feeIncomeMonth = (int) $itemStream['fee_income_month'] + (int) $jobStream['fee_income_month'];
$unpaidValue = (int) $itemStream['unpaid_value'] + (int) $jobStream['unpaid_value'];
$paidCount = (int) $itemStream['paid_count'] + (int) $jobStream['paid_count'];
$itemFee = (int) $itemStream['fee'];
$jobFee = (int) $jobStream['fee'];
$paymentAlerts = $portal_payment_alerts ?? portalPaymentAlerts($db, null);

$managementUsers = $db->query('
  SELECT u.id, u.nickname, u.phone, u.email, u.district, u.role_id, u.account_status, u.admin_district,
         (SELECT COUNT(*) FROM admin_audit_logs a
          WHERE a.actor_id = u.id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS actions_30d,
         (SELECT COUNT(*) FROM admin_audit_logs a
          WHERE a.actor_id = u.id AND a.action = "moderate-listing"
            AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS moderated_30d,
         (SELECT COUNT(*) FROM admin_audit_logs a
          WHERE a.actor_id = u.id AND a.action = "resolve-report"
            AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS reports_30d
  FROM users u WHERE u.role_id BETWEEN 1 AND 3
  ORDER BY u.role_id ASC, u.id ASC
')->fetchAll();

// Marketplace members only ? never staff / Super Admin / management accounts
$memberSql = '
  FROM users
  WHERE role_id = 4
    AND COALESCE(account_kind, "member") = "member"
    AND LOWER(COALESCE(nickname, "")) NOT LIKE "%super admin%"
    AND LOWER(COALESCE(nickname, "")) NOT LIKE "%systemadmin%"
    AND phone NOT IN ("+250781111111", "+250782222222", "+250783333333", "+250790000001")
';
$members = (int) $db->query('SELECT COUNT(*)' . $memberSql)->fetchColumn();
$memberUsers = $db->query('
  SELECT id, nickname, phone, email, full_name, province, district, sector,
         role_id, account_status, admin_district, id_status, id_number,
         id_verified_at, created_at, updated_at
  ' . $memberSql . '
  ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
  LIMIT 200
')->fetchAll();

$revenueByDistrict = $db->query('
  SELECT district, business_type,
         COALESCE(SUM(announce_fee_rwf),0) AS revenue, COUNT(*) AS paid_posts
  FROM listings
  WHERE payment_status = "paid" AND district IS NOT NULL AND district <> ""
  GROUP BY district, business_type
  ORDER BY revenue DESC
  LIMIT 24
')->fetchAll();
$revenueByBusiness = $db->query('
  SELECT business_type,
         COALESCE(SUM(announce_fee_rwf),0) AS revenue,
         COUNT(*) AS paid_posts
  FROM listings
  WHERE payment_status = "paid"
  GROUP BY business_type
')->fetchAll(PDO::FETCH_ASSOC);

// Last 6 months ? Item vs Job fee trend for charts
$monthLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $monthLabels[] = date('Y-m', strtotime("first day of -{$i} month"));
}
$monthlyRaw = $db->query('
  SELECT DATE_FORMAT(COALESCE(paid_at, created_at), "%Y-%m") AS ym,
         business_type,
         COALESCE(SUM(announce_fee_rwf), 0) AS revenue
  FROM listings
  WHERE payment_status = "paid"
    AND COALESCE(paid_at, created_at) >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), "%Y-%m-01")
  GROUP BY ym, business_type
  ORDER BY ym ASC
')->fetchAll(PDO::FETCH_ASSOC);
$monthlyItem = array_fill_keys($monthLabels, 0);
$monthlyJob = array_fill_keys($monthLabels, 0);
foreach ($monthlyRaw as $row) {
    $ym = (string) ($row['ym'] ?? '');
    if (!isset($monthlyItem[$ym])) {
        continue;
    }
    if (($row['business_type'] ?? '') === 'job') {
        $monthlyJob[$ym] = (int) $row['revenue'];
    } else {
        $monthlyItem[$ym] = (int) $row['revenue'];
    }
}

// District totals for bar chart (top 8)
$districtTotals = [];
foreach ($revenueByDistrict as $row) {
    $d = (string) ($row['district'] ?? '');
    if ($d === '') {
        continue;
    }
    if (!isset($districtTotals[$d])) {
        $districtTotals[$d] = ['item' => 0, 'job' => 0, 'total' => 0];
    }
    $bt = (($row['business_type'] ?? '') === 'job') ? 'job' : 'item';
    $rev = (int) $row['revenue'];
    $districtTotals[$d][$bt] += $rev;
    $districtTotals[$d]['total'] += $rev;
}
uasort($districtTotals, static fn($a, $b) => $b['total'] <=> $a['total']);
$districtTotals = array_slice($districtTotals, 0, 8, true);

$financeCharts = [
    'itemRevenue' => (int) $itemStream['fee_income'],
    'jobRevenue' => (int) $jobStream['fee_income'],
    'itemMonth' => (int) $itemStream['fee_income_month'],
    'jobMonth' => (int) $jobStream['fee_income_month'],
    'months' => array_map(static fn($ym) => date('M Y', strtotime($ym . '-01')), $monthLabels),
    'itemMonthly' => array_values($monthlyItem),
    'jobMonthly' => array_values($monthlyJob),
    'districts' => array_keys($districtTotals),
    'districtItem' => array_map(static fn($r) => (int) $r['item'], array_values($districtTotals)),
    'districtJob' => array_map(static fn($r) => (int) $r['job'], array_values($districtTotals)),
];
$moderators = array_values(array_filter($managementUsers, static fn($u) => (int)$u['role_id'] === 3));
$districtManagers = array_values(array_filter($managementUsers, static fn($u) => (int)$u['role_id'] === 2));
$openReports = $db->query('SELECT id, target_type, target_id, reason, details, status, created_at FROM reports WHERE status IN ("open","reviewing") ORDER BY created_at DESC LIMIT 40')->fetchAll();

// Seed a few demo reports when empty so Admin graphs are visible locally
try {
    $reportTotalNow = (int) $db->query('SELECT COUNT(*) FROM reports')->fetchColumn();
    if ($reportTotalNow === 0) {
        $listingIds = $db->query('SELECT id FROM listings ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
        $memberIds = $db->query('SELECT id FROM users WHERE role_id = 4 ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
        $reporter = (int) ($memberIds[0] ?? 0);
        $samples = [
            ['listing', (int) ($listingIds[0] ?? 1), 'Spam / fake listing', 'Looks like a scam price', 'open', 5],
            ['listing', (int) ($listingIds[1] ?? $listingIds[0] ?? 1), 'Wrong category', 'Posted under wrong section', 'open', 3],
            ['user', (int) ($memberIds[1] ?? $memberIds[0] ?? 1), 'Harassment', 'Rude chat messages', 'reviewing', 2],
            ['listing', (int) ($listingIds[2] ?? $listingIds[0] ?? 1), 'Prohibited item', 'Item not allowed', 'resolved', 12],
            ['chat', (int) ($listingIds[0] ?? 1), 'Fraud suspicion', 'Asked for payment outside app', 'dismissed', 8],
            ['listing', (int) ($listingIds[0] ?? 1), 'Spam / fake listing', 'Duplicate post', 'open', 1],
            ['user', (int) ($memberIds[2] ?? $memberIds[0] ?? 1), 'Impersonation', 'Fake profile name', 'resolved', 20],
        ];
        $ins = $db->prepare('
            INSERT INTO reports (reporter_id, target_type, target_id, reason, details, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY))
        ');
        foreach ($samples as $s) {
            $days = max(0, (int) $s[5]);
            $ins->execute([
                $reporter ?: null,
                $s[0],
                max(1, $s[1]),
                $s[2],
                $s[3],
                $s[4],
                $days,
                $days,
            ]);
        }
        $openReports = $db->query('SELECT id, target_type, target_id, reason, details, status, created_at FROM reports WHERE status IN ("open","reviewing") ORDER BY created_at DESC LIMIT 40')->fetchAll();
        $reports = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status IN ("open","reviewing")')->fetchColumn();
    }
} catch (Throwable $e) {
    // keep empty if seed fails
}

$reportOpen = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status = "open"')->fetchColumn();
$reportReviewing = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status = "reviewing"')->fetchColumn();
$reportResolved = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status = "resolved"')->fetchColumn();
$reportDismissed = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status = "dismissed"')->fetchColumn();
$reportTotal = $reportOpen + $reportReviewing + $reportResolved + $reportDismissed;
$reportNeedsAction = $reportOpen + $reportReviewing;
$reportHandledMonth = (int) $db->query('
  SELECT COUNT(*) FROM reports
  WHERE status IN ("resolved","dismissed")
    AND COALESCE(updated_at, created_at) >= DATE_FORMAT(NOW(), "%Y-%m-01")
')->fetchColumn();

// Where members pointed the report (GUGU target types)
$reportListingCount = (int) $db->query('SELECT COUNT(*) FROM reports WHERE target_type = "listing"')->fetchColumn();
$reportUserCount = (int) $db->query('SELECT COUNT(*) FROM reports WHERE target_type = "user"')->fetchColumn();
$reportChatCount = (int) $db->query('SELECT COUNT(*) FROM reports WHERE target_type = "chat"')->fetchColumn();

// Listing reports split: Items (Gurisha) vs Jobs (Akazi)
$jobCatId = function_exists('guguJobCategoryId') ? (int) guguJobCategoryId() : 11;
$reportItemListings = 0;
$reportJobListings = 0;
try {
    $reportItemListings = (int) $db->query("
      SELECT COUNT(*) FROM reports r
      LEFT JOIN listings l ON r.target_type = 'listing' AND l.id = r.target_id
      WHERE r.target_type = 'listing'
        AND (l.business_type = 'item' OR (l.id IS NOT NULL AND COALESCE(l.category_id,0) <> {$jobCatId}))
    ")->fetchColumn();
    $reportJobListings = (int) $db->query("
      SELECT COUNT(*) FROM reports r
      LEFT JOIN listings l ON r.target_type = 'listing' AND l.id = r.target_id
      WHERE r.target_type = 'listing'
        AND (l.business_type = 'job' OR COALESCE(l.category_id,0) = {$jobCatId})
    ")->fetchColumn();
} catch (Throwable $e) {
    $reportItemListings = $reportListingCount;
    $reportJobListings = 0;
}

$reportByReasonRows = $db->query('
  SELECT reason, COUNT(*) AS c FROM reports GROUP BY reason ORDER BY c DESC LIMIT 8
')->fetchAll(PDO::FETCH_ASSOC);
$topReason = $reportByReasonRows[0]['reason'] ?? null;
$topReasonCount = (int) ($reportByReasonRows[0]['c'] ?? 0);

// Akarere with most open/reviewing reports (nationwide Admin view)
$reportByDistrictRows = [];
try {
    $reportByDistrictRows = $db->query("
      SELECT district, COUNT(*) AS c FROM (
        SELECT COALESCE(l.district, u.district, 'Unknown') AS district
        FROM reports r
        LEFT JOIN listings l ON r.target_type = 'listing' AND l.id = r.target_id
        LEFT JOIN users u ON r.target_type = 'user' AND u.id = r.target_id
        WHERE r.status IN ('open','reviewing')
      ) t
      GROUP BY district
      ORDER BY c DESC
      LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $reportByDistrictRows = [];
}

$reportMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $reportMonths[] = date('Y-m', strtotime("-{$i} months"));
}
$reportMonthCreated = array_fill_keys($reportMonths, 0);
$reportMonthClosed = array_fill_keys($reportMonths, 0);
foreach ($db->query('
  SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym, COUNT(*) AS c
  FROM reports
  WHERE created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), "%Y-%m-01")
  GROUP BY ym
') as $row) {
    if (isset($reportMonthCreated[$row['ym']])) {
        $reportMonthCreated[$row['ym']] = (int) $row['c'];
    }
}
foreach ($db->query('
  SELECT DATE_FORMAT(COALESCE(updated_at, created_at), "%Y-%m") AS ym, COUNT(*) AS c
  FROM reports
  WHERE status IN ("resolved","dismissed")
    AND COALESCE(updated_at, created_at) >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), "%Y-%m-01")
  GROUP BY ym
') as $row) {
    if (isset($reportMonthClosed[$row['ym']])) {
        $reportMonthClosed[$row['ym']] = (int) $row['c'];
    }
}
$createdThisMonth = (int) ($reportMonthCreated[date('Y-m')] ?? 0);
$closedThisMonth = (int) ($reportMonthClosed[date('Y-m')] ?? 0);

// Plain-language takeaways for Admin (Trust & Safety)
if ($reportNeedsAction === 0) {
    $insightStatus = 'Queue clear ? no member reports waiting for Admin or Moderator action.';
} elseif ($reportOpen > $reportReviewing) {
    $insightStatus = $reportOpen . ' new report(s) still unopened. Start with Open, then mark Reviewing while you check the listing/member.';
} else {
    $insightStatus = $reportReviewing . ' report(s) already under review. Finish Resolve (real problem) or Dismiss (false alarm).';
}

if ($reportListingCount >= $reportUserCount && $reportListingCount >= $reportChatCount) {
    $insightTarget = 'Most flags are on marketplace posts (Gurisha items / Akazi jobs). Check Item & Job Approvals and remove spam or ban the seller if fraud.';
} elseif ($reportUserCount >= $reportChatCount) {
    $insightTarget = 'Most flags are on member profiles. Review the member, ID status, and suspend/ban if Trust & Safety rules are broken.';
} else {
    $insightTarget = 'Most flags are on chat. Look for off-app MoMo pressure or harassment, then warn or ban the member.';
}

if ($createdThisMonth > $closedThisMonth) {
    $insightTrend = 'This month members sent more reports (' . $createdThisMonth . ') than staff closed (' . $closedThisMonth . '). Queue pressure is rising ? clear the open queue.';
} elseif ($createdThisMonth === 0 && $closedThisMonth === 0) {
    $insightTrend = 'No new report activity this month yet. Keep watching; members report from the app when they see spam or fraud.';
} else {
    $insightTrend = 'Staff are keeping up: ' . $closedThisMonth . ' handled vs ' . $createdThisMonth . ' new this month. Healthy Trust & Safety pace.';
}

if ($topReason) {
    $insightReason = 'Top complaint: ?' . $topReason . '? (' . $topReasonCount . '). Use this to spot patterns ? e.g. spam posts need faster reject; fraud needs ban.';
} else {
    $insightReason = 'No reasons yet. When members tap Report in the app, their reason appears here for Admin patterns.';
}

$hotDistrict = $reportByDistrictRows[0]['district'] ?? null;
$hotDistrictCount = (int) ($reportByDistrictRows[0]['c'] ?? 0);
if ($hotDistrict && $hotDistrictCount > 0) {
    $insightDistrict = $hotDistrict . ' has the most waiting reports (' . $hotDistrictCount . '). District Manager / Moderator for that Akarere should prioritize local review.';
} else {
    $insightDistrict = 'No open reports mapped to an Akarere yet. When the queue has items, this shows which district needs Trust & Safety focus.';
}

$reportCharts = [
    'statusLabels' => ['Open', 'Reviewing', 'Resolved', 'Dismissed'],
    'statusCounts' => [$reportOpen, $reportReviewing, $reportResolved, $reportDismissed],
    'targetLabels' => ['Post', 'Member', 'Chat'],
    'targetCounts' => [$reportListingCount, $reportUserCount, $reportChatCount],
    'bizLabels' => ['Items', 'Jobs'],
    'bizCounts' => [$reportItemListings, $reportJobListings],
    'reasonLabels' => array_map(static fn($r) => (string) $r['reason'], $reportByReasonRows),
    'reasonCounts' => array_map(static fn($r) => (int) $r['c'], $reportByReasonRows),
    'months' => array_map(static fn($ym) => date('M', strtotime($ym . '-01')), $reportMonths),
    'monthCreated' => array_values($reportMonthCreated),
    'monthClosed' => array_values($reportMonthClosed),
    'districtLabels' => array_map(static fn($r) => (string) $r['district'], $reportByDistrictRows),
    'districtCounts' => array_map(static fn($r) => (int) $r['c'], $reportByDistrictRows),
];

$idData = portalIdVerificationData($db);
$idPending = (int) $idData['pending'];
$idApproved = (int) $idData['approved'];
$idRejected = (int) $idData['rejected'];
$idQueue = $idData['queue'];

$checklistScope = 'nationwide';
$roleOptions = guguManagementRoleOptions();
// Staff edits: only you are Admin (role 1) ? do not assign Admin to others
$staffAssignOptions = [
    2 => 'District Manager',
    3 => 'Moderator / Support',
    4 => 'Member',
];
$smsConfigured = defined('GUGU_SMS_API_URL') && GUGU_SMS_API_URL !== '';
$fee = $itemFee; // legacy alias (item fee)

function adminSectionUserRow(array $u, int $selfId, array $districts, array $roleOptions): void {
    $uid = (int) $u['id'];
    $isSelf = $uid === $selfId;
    $rid = (int) $u['role_id'];
    ?>
    <tr>
      <td>
        <strong><?= htmlspecialchars($u['nickname'] ?: 'User') ?></strong>
        <?php if (!empty($u['email'])): ?><br><small class="muted"><?= htmlspecialchars($u['email']) ?></small><?php endif; ?>
      </td>
      <td><?= htmlspecialchars($u['phone']) ?></td>
      <td>
        <?= htmlspecialchars($u['district'] ?: '?') ?>
        <?php if (in_array($rid, [2, 3], true) && !empty($u['admin_district'])): ?>
          <br><small class="muted">Scope: <?= htmlspecialchars($u['admin_district']) ?></small>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isSelf || $rid === 1): ?>
          <?= htmlspecialchars(adminRoleLabel($rid)) ?>
        <?php else: ?>
          <form method="post" action="/gugu-app/admin/actions.php" class="portal-row-form">
            <input type="hidden" name="action" value="set-role">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <select name="role_id" onchange="var d=this.form.querySelector('[name=admin_district]'); if(d) d.style.display=(this.value==='2'||this.value==='3')?'inline-block':'none';">
              <?php foreach ($roleOptions as $optId => $label): ?>
                <option value="<?= (int)$optId ?>" <?= $rid === (int)$optId ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="admin_district" style="<?= in_array($rid, [2, 3], true) ? '' : 'display:none' ?>">
              <?php foreach ($districts as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>" <?= ($u['admin_district'] ?? '') === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-sm">Save role</button>
          </form>
        <?php endif; ?>
      </td>
      <td>
        <strong><?= (int)($u['actions_30d'] ?? 0) ?></strong> actions
        <br><small class="muted">
          <?= (int)($u['moderated_30d'] ?? 0) ?> listings ?
          <?= (int)($u['reports_30d'] ?? 0) ?> reports
        </small>
      </td>
      <td><span class="status-pill"><?= htmlspecialchars($u['account_status']) ?></span></td>
      <td class="portal-actions">
        <?php if (in_array($rid, [2, 3], true)): ?>
          <a class="btn-sm" href="/gugu-app/admin/dashboard.php?view_role=<?= $rid ?>&amp;view_district=<?= urlencode($u['admin_district'] ?: $u['district']) ?>">
            Open dashboard
          </a>
        <?php endif; ?>
        <?php if ($isSelf): ?>
          <span class="muted">You</span>
        <?php else: ?>
          <form method="post" action="/gugu-app/admin/actions.php" class="portal-row-form">
            <input type="hidden" name="action" value="set-status">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <select name="account_status">
              <?php foreach (['active', 'suspended', 'banned'] as $st): ?>
                <option value="<?= $st ?>" <?= ($u['account_status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-sm">Update</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php
}
?>

<div class="admin-shell" id="admin-shell">

  <!-- HOME: Admin command cards -->
  <section class="admin-pane is-active" data-pane="home" id="home">
    <section class="panel portal-hero portal-hero-super admin-owner-hero">
      <div class="rw-flag-bar" aria-hidden="true">
        <span class="rw-blue"></span>
        <span class="rw-yellow"></span>
        <span class="rw-green"></span>
      </div>
      <div class="admin-owner-hero-inner">
        <div class="portal-hero-text">
          <span class="portal-kicker">Admin &middot; Nationwide control</span>
          <h2>Operations overview</h2>
          <p>Monitor queues, revenue, and team access from one place. Open a module below to take action.</p>
        </div>
        <div class="admin-kpi-strip admin-owner-stats-6" role="list">
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Users</span>
            <strong class="admin-kpi-value"><?= $users ?></strong>
          </div>
          <div class="admin-kpi<?= (int) $itemStream['review'] > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Item queue</span>
            <strong class="admin-kpi-value"><?= (int) $itemStream['review'] ?></strong>
          </div>
          <div class="admin-kpi<?= (int) $jobStream['review'] > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Job queue</span>
            <strong class="admin-kpi-value"><?= (int) $jobStream['review'] ?></strong>
          </div>
          <div class="admin-kpi<?= $idPending > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">ID pending</span>
            <strong class="admin-kpi-value"><?= $idPending ?></strong>
          </div>
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Item fees</span>
            <strong class="admin-kpi-value"><?= number_format((int) $itemStream['fee_income']) ?></strong>
            <span class="admin-kpi-unit">RWF</span>
          </div>
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Job fees</span>
            <strong class="admin-kpi-value"><?= number_format((int) $jobStream['fee_income']) ?></strong>
            <span class="admin-kpi-unit">RWF</span>
          </div>
        </div>
      </div>
    </section>

    <?php portalRenderPaymentNotifications($paymentAlerts, 'Nationwide'); ?>

    <div class="admin-group">
      <header class="admin-group-head">
        <span class="admin-group-index">01</span>
        <div>
          <h3 class="admin-group-title">Approval queues</h3>
          <p class="admin-group-sub">Items (Gurisha) and Jobs (Akazi) are separate businesses ? review each stream on its own.</p>
        </div>
      </header>
      <div class="admin-command-grid admin-command-grid-2">
        <button type="button" class="admin-cmd-card tone-blue<?= (int) $itemStream['review'] > 0 ? ' has-queue' : '' ?>" data-open="item-approvals">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">IT</span>
            <span class="admin-cmd-tag">Gurisha</span>
          </div>
          <h3>Item approvals</h3>
          <p>Marketplace listings awaiting review. Fee: <?= number_format($itemFee) ?> RWF per announce.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric<?= (int) $itemStream['review'] > 0 ? ' is-hot' : '' ?>">
              <span>Waiting</span><strong><?= (int) $itemStream['review'] ?></strong>
            </div>
            <div class="admin-metric">
              <span>Paid ready</span><strong><?= (int) $itemStream['paid_pending'] ?></strong>
            </div>
            <div class="admin-metric">
              <span>Revenue</span><strong><?= number_format((int) $itemStream['fee_income']) ?></strong>
            </div>
          </div>
          <span class="admin-cmd-go"><span>Open item approvals</span><span aria-hidden="true">&rarr;</span></span>
        </button>

        <button type="button" class="admin-cmd-card tone-green<?= (int) $jobStream['review'] > 0 ? ' has-queue' : '' ?>" data-open="job-approvals">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">JB</span>
            <span class="admin-cmd-tag">Akazi</span>
          </div>
          <h3>Job approvals</h3>
          <p>Job announcements awaiting review. Fee: <?= number_format($jobFee) ?> RWF per announce.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric<?= (int) $jobStream['review'] > 0 ? ' is-hot' : '' ?>">
              <span>Waiting</span><strong><?= (int) $jobStream['review'] ?></strong>
            </div>
            <div class="admin-metric">
              <span>Paid ready</span><strong><?= (int) $jobStream['paid_pending'] ?></strong>
            </div>
            <div class="admin-metric">
              <span>Revenue</span><strong><?= number_format((int) $jobStream['fee_income']) ?></strong>
            </div>
          </div>
          <span class="admin-cmd-go"><span>Open job approvals</span><span aria-hidden="true">&rarr;</span></span>
        </button>
      </div>
    </div>

    <div class="admin-group">
      <header class="admin-group-head">
        <span class="admin-group-index">02</span>
        <div>
          <h3 class="admin-group-title">Platform controls</h3>
          <p class="admin-group-sub">Configure payments, manage staff roles, and review nationwide financial performance.</p>
        </div>
      </header>
      <div class="admin-command-grid">
        <button type="button" class="admin-cmd-card tone-blue" data-open="system-controls">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">SC</span>
            <span class="admin-cmd-tag">System</span>
          </div>
          <h3>System controls</h3>
          <p>MoMo settlement, announce fees, and SMS login settings.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Fee</span><strong><?= number_format($fee) ?></strong></div>
            <div class="admin-metric"><span>MoMo</span><strong><?= htmlspecialchars(GUGU_MOMO_NUMBER) ?></strong></div>
            <div class="admin-metric"><span>Mode</span><strong><?= GUGU_MOMO_SANDBOX ? 'Sandbox' : 'Live' ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open system controls</span><span aria-hidden="true">&rarr;</span></span>
        </button>

        <button type="button" class="admin-cmd-card tone-green" data-open="staff">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">ST</span>
            <span class="admin-cmd-tag">Staff</span>
          </div>
          <h3>Staff management</h3>
          <p>Create and manage District Managers and Moderators.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Total staff</span><strong><?= $staffCount ?></strong></div>
            <div class="admin-metric"><span>Managers</span><strong><?= count($districtManagers) ?></strong></div>
            <div class="admin-metric"><span>Moderators</span><strong><?= count($moderators) ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open staff</span><span aria-hidden="true">&rarr;</span></span>
        </button>

        <button type="button" class="admin-cmd-card tone-blue" data-open="permissions">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">PM</span>
            <span class="admin-cmd-tag">Roles</span>
          </div>
          <h3>Permission matrix</h3>
          <p>What Admin, District Manager, and Moderator can access.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Roles</span><strong>1?3</strong></div>
            <div class="admin-metric"><span>Scope</span><strong>Read-only</strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open permissions</span><span aria-hidden="true">&rarr;</span></span>
        </button>

        <button type="button" class="admin-cmd-card tone-yellow" data-open="analytics">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">FX</span>
            <span class="admin-cmd-tag">Finance</span>
          </div>
          <h3>Financial analytics</h3>
          <p>Nationwide announce-fee revenue across Items and Jobs.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Total</span><strong><?= number_format($feeIncome) ?></strong></div>
            <div class="admin-metric"><span>This month</span><strong><?= number_format($feeIncomeMonth) ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open analytics</span><span aria-hidden="true">&rarr;</span></span>
        </button>

        <button type="button" class="admin-cmd-card tone-blue" data-open="dashboards">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">DB</span>
            <span class="admin-cmd-tag">Access</span>
          </div>
          <h3>Other dashboards</h3>
          <p>Open District Manager or Moderator portals from here.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>District</span><strong>Regional</strong></div>
            <div class="admin-metric"><span>Moderator</span><strong>Local</strong></div>
          </div>
          <span class="admin-cmd-go"><span>Choose dashboard</span><span aria-hidden="true">&rarr;</span></span>
        </button>
      </div>
    </div>

    <div class="admin-group">
      <header class="admin-group-head">
        <span class="admin-group-index">03</span>
        <div>
          <h3 class="admin-group-title">Daily operations</h3>
          <p class="admin-group-sub">Trust queues, member oversight, payments, and live marketplace checks.</p>
        </div>
      </header>
      <div class="admin-command-grid">
        <button type="button" class="admin-cmd-card tone-blue" data-open="checklist">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">CL</span>
            <span class="admin-cmd-tag">Routine</span>
          </div>
          <h3>Admin checklist</h3>
          <p>Daily flow: queues, MoMo confirm, approvals, and reports.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric<?= $review > 0 ? ' is-hot' : '' ?>"><span>Queue</span><strong><?= $review ?></strong></div>
            <div class="admin-metric<?= $reports > 0 ? ' is-hot' : '' ?>"><span>Reports</span><strong><?= $reports ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open checklist</span><span aria-hidden="true">&rarr;</span></span>
        </button>
        <button type="button" class="admin-cmd-card tone-yellow<?= $idPending > 0 ? ' has-queue' : '' ?>" data-open="id-queue">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">ID</span>
            <span class="admin-cmd-tag">Trust</span>
          </div>
          <h3>ID verification</h3>
          <p>National ID photos waiting for Admin approve or reject.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric<?= $idPending > 0 ? ' is-hot' : '' ?>"><span>Waiting</span><strong><?= $idPending ?></strong></div>
            <div class="admin-metric"><span>Approved</span><strong><?= $idApproved ?></strong></div>
            <div class="admin-metric"><span>Rejected</span><strong><?= $idRejected ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open ID queue</span><span aria-hidden="true">&rarr;</span></span>
        </button>
        <button type="button" class="admin-cmd-card tone-yellow" data-open="members">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">MB</span>
            <span class="admin-cmd-tag">Members</span>
          </div>
          <h3>Members</h3>
          <p>Marketplace buyers and sellers only ? staff accounts stay separate.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Members</span><strong><?= $members ?></strong></div>
            <div class="admin-metric<?= $idPending > 0 ? ' is-hot' : '' ?>"><span>ID pending</span><strong><?= $idPending ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open members</span><span aria-hidden="true">&rarr;</span></span>
        </button>
        <button type="button" class="admin-cmd-card tone-yellow" data-open="payments">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">RW</span>
            <span class="admin-cmd-tag">Payments</span>
          </div>
          <h3>Fee payments</h3>
          <p>Items <?= number_format($itemFee) ?> RWF ? Jobs <?= number_format($jobFee) ?> RWF ? track unpaid announces.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Item unpaid</span><strong><?= (int) $itemStream['unpaid_pending'] ?></strong></div>
            <div class="admin-metric"><span>Job unpaid</span><strong><?= (int) $jobStream['unpaid_pending'] ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open payments</span><span aria-hidden="true">&rarr;</span></span>
        </button>
        <button type="button" class="admin-cmd-card tone-green<?= $reportNeedsAction > 0 ? ' has-queue' : '' ?>" data-open="reports">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">RP</span>
            <span class="admin-cmd-tag">Safety</span>
          </div>
          <h3>Community reports</h3>
          <p>Member flags on posts, profiles, and chat for Trust &amp; Safety.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric<?= $reportNeedsAction > 0 ? ' is-hot' : '' ?>"><span>Queue</span><strong><?= $reportNeedsAction ?></strong></div>
            <div class="admin-metric"><span>Resolved</span><strong><?= $reportResolved ?></strong></div>
            <div class="admin-metric"><span>This month</span><strong><?= $reportHandledMonth ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open reports</span><span aria-hidden="true">&rarr;</span></span>
        </button>
        <a class="admin-cmd-card tone-blue" href="/gugu-app/app/" target="_blank" rel="noopener">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">MK</span>
            <span class="admin-cmd-tag">Live</span>
          </div>
          <h3>Marketplace</h3>
          <p>Inspect live items, jobs, and chats in the public member app.</p>
          <div class="admin-cmd-metrics">
            <div class="admin-metric"><span>Live</span><strong><?= $active ?></strong></div>
            <div class="admin-metric"><span>All listings</span><strong><?= $listingTotal ?></strong></div>
          </div>
          <span class="admin-cmd-go"><span>Open marketplace</span><span aria-hidden="true">&rarr;</span></span>
        </a>
      </div>
    </div>
  </section>

  <!-- SECTION: Daily checklist -->
  <section class="admin-pane" data-pane="checklist" id="pane-checklist">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Daily routine</span>
      <h2>Admin checklist</h2>
    </header>
    <?php require __DIR__ . '/../includes/daily_checklist.php'; ?>
  </section>

  <!-- SECTION: Staff Management System -->
  <section class="admin-pane" data-pane="staff" id="staff">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Management users</span>
      <h2>Staff Management System</h2>
      <p class="admin-pane-sub">
        Staff / management accounts only (roles 1?3) ? not marketplace members.
        <strong><?= $staffCount ?></strong> staff
        (<?= count($districtManagers) ?> District Managers &middot; <?= count($moderators) ?> Moderators).
      </p>
    </header>

    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">1 &middot; Admin</span>
        <span class="chip chip-green">2 &middot; District Manager &middot; <?= count($districtManagers) ?></span>
        <span class="chip chip-yellow">3 &middot; Moderator &middot; <?= count($moderators) ?></span>
        <button type="button" class="btn-sm" data-open="permissions">Role matrix &rarr;</button>
        <button type="button" class="btn-sm" data-open="members">Members only &rarr;</button>
      </div>

      <h3 class="panel-subhead">Create staff account</h3>
      <p class="hint">
        Phone, nickname, password, role, and Akarere.
        If the phone already exists, that account is promoted (set password to reset login).
      </p>
      <form method="post" action="/gugu-app/admin/actions.php" class="portal-settings-form">
        <input type="hidden" name="action" value="promote-staff">
        <div class="portal-form-grid">
          <label>Phone
            <input type="text" name="phone" required placeholder="078XXXXXXX" autocomplete="tel">
          </label>
          <label>Nickname
            <input type="text" name="nickname" required placeholder="e.g. GasaboManager" maxlength="50">
          </label>
          <label>Login password
            <input type="text" name="password" required placeholder="Min 6 characters" minlength="6" autocomplete="new-password">
          </label>
          <label>Role
            <select name="role_id" required>
              <option value="2">District Manager</option>
              <option value="3">Moderator / Support</option>
            </select>
          </label>
          <label>Akarere (scope)
            <select name="admin_district" required>
              <?php foreach ($districts as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <button type="submit" class="btn-sm ok">Create staff account</button>
        <p class="hint" style="margin-top:10px">
          Staff login: marketplace &rarr; <strong>System Controller / Staff</strong> &rarr; phone + this password.
        </p>
      </form>
    </section>

    <section class="panel">
      <h4 class="panel-subhead">Staff accounts ? change role or status</h4>
      <p class="hint" style="margin-top:0">Management users only. Marketplace members are in the Members card.</p>
      <?php if (!$managementUsers): ?>
        <p class="hint">No management users found</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Phone</th><th>District</th><th>Role</th><th>30-day performance</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($managementUsers as $u): ?>
              <?php adminSectionUserRow($u, $selfId, $districts, $staffAssignOptions); ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: Permission Controls (role matrix only ? no staff table duplicate) -->
  <section class="admin-pane" data-pane="permissions" id="permissions">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Permission Controls</span>
      <h2>Role matrix</h2>
      <p class="admin-pane-sub">
        What each management role can do. To create or edit staff accounts, use
        <button type="button" class="btn-sm" data-open="staff">Staff Management</button>.
      </p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">1 &middot; Admin</span>
        <span class="chip chip-green">2 &middot; District Manager</span>
        <span class="chip chip-yellow">3 &middot; Moderator / Support</span>
        <span class="chip">4 &middot; Member (marketplace)</span>
      </div>
      <div class="table-wrap">
        <table class="mgmt-matrix">
          <thead>
            <tr><th>Role</th><th>Name</th><th>Workspace</th><th>Responsibilities</th></tr>
          </thead>
          <tbody>
            <?php foreach ($mgmtRoles as $id => $r): ?>
              <tr>
                <td><strong><?= (int) $id ?></strong></td>
                <td><?= htmlspecialchars($r['role']) ?></td>
                <td><?= htmlspecialchars($r['workspace']) ?></td>
                <td>
                  <ul class="portal-duties" style="margin:0;padding-left:16px">
                    <?php foreach ($r['responsibilities'] as $item): ?>
                      <li><?= htmlspecialchars($item) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:14px">
        <button type="button" class="btn-sm ok" data-open="staff">Open Staff Management &rarr;</button>
        <button type="button" class="btn-sm" data-open="members">Open Members &rarr;</button>
      </p>
    </section>
  </section>

  <!-- SECTION: Members only (marketplace) -->
  <section class="admin-pane" data-pane="members" id="members">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Marketplace people</span>
      <h2>Members</h2>
      <p class="admin-pane-sub">
        Buyers &amp; sellers only &mdash; <strong><?= $members ?></strong> marketplace members.
        No Admin / Super Admin / staff here.
        Manage staff in <button type="button" class="btn-sm" data-open="staff">Staff Management</button>.
      </p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-yellow">4 &middot; Member &middot; <?= $members ?></span>
        <span class="chip <?= $idPending > 0 ? 'chip-yellow' : 'chip-green' ?>">ID pending &middot; <?= $idPending ?></span>
        <button type="button" class="btn-sm warn" data-open="id-queue">ID verification &rarr;</button>
        <button type="button" class="btn-sm" data-open="staff">Staff Management &rarr;</button>
      </div>

      <h4 class="panel-subhead">Marketplace members only</h4>
      <?php if (!$memberUsers): ?>
        <p class="hint">No members yet</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Name</th><th>Phone</th><th>District</th><th>Sector</th>
              <th>ID status</th><th>Status</th><th>Updated</th><th>Promote / Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($memberUsers as $u):
              $uid = (int) $u['id'];
              $rid = (int) $u['role_id'];
              $idSt = (string) ($u['id_status'] ?? 'none');
              $idPill = $idSt === 'approved' ? 'status-pill status-ok'
                : ($idSt === 'pending' ? 'status-pill status-warn'
                : ($idSt === 'rejected' ? 'status-pill status-bad' : 'status-pill'));
              $sector = trim((string) ($u['sector'] ?? ''));
              $idNum = trim((string) ($u['id_number'] ?? ''));
              $updatedAt = $u['updated_at'] ?? $u['created_at'] ?? '';
            ?>
              <tr>
                <td>#<?= $uid ?></td>
                <td><strong><?= htmlspecialchars($u['nickname'] ?: 'User') ?></strong></td>
                <td><?= htmlspecialchars($u['phone']) ?></td>
                <td><?= htmlspecialchars($u['district'] ?: '?') ?></td>
                <td><?= htmlspecialchars($sector !== '' ? $sector : '?') ?></td>
                <td>
                  <span class="<?= $idPill ?>"><?= htmlspecialchars(portalIdStatusLabel($idSt)) ?></span>
                  <?php if ($idNum !== ''): ?>
                    <br><small class="muted"><?= htmlspecialchars($idNum) ?></small>
                  <?php endif; ?>
                </td>
                <td><span class="status-pill"><?= htmlspecialchars($u['account_status'] ?? 'active') ?></span></td>
                <td><small class="muted"><?= $updatedAt !== '' ? htmlspecialchars(date('d M Y H:i', strtotime((string) $updatedAt))) : '?' ?></small></td>
                <td class="portal-actions">
                  <form method="post" action="/gugu-app/admin/actions.php" class="portal-row-form">
                    <input type="hidden" name="action" value="set-role">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <select name="role_id" onchange="var d=this.form.querySelector('[name=admin_district]'); if(d) d.style.display=(this.value==='2'||this.value==='3')?'inline-block':'none';">
                      <?php foreach ($staffAssignOptions as $optId => $label): ?>
                        <option value="<?= (int)$optId ?>" <?= $rid === (int)$optId ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <select name="admin_district" style="display:none">
                      <?php foreach ($districts as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-sm">Save</button>
                  </form>
                  <form method="post" action="/gugu-app/admin/actions.php" class="portal-row-form">
                    <input type="hidden" name="action" value="set-status">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <select name="account_status">
                      <?php foreach (['active', 'suspended', 'banned'] as $st): ?>
                        <option value="<?= $st ?>" <?= ($u['account_status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-sm">Update</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: Payments -->
  <section class="admin-pane" data-pane="payments" id="payments">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Two businesses</span>
      <h2>How Admin earns (payments)</h2>
      <p class="admin-pane-sub">Items and Jobs are billed separately ? check each queue on its own.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">Item fee &middot; <?= $itemFee ?> RWF</span>
        <span class="chip chip-green">Job fee &middot; <?= $jobFee ?> RWF</span>
        <span class="chip chip-yellow">Unpaid total &middot; <?= $unpaidPending ?></span>
        <span class="chip">Income &middot; <?= number_format($feeIncome) ?> RWF</span>
      </div>
      <div class="admin-command-grid" style="margin-bottom:14px">
        <div class="admin-cmd-card tone-blue" style="cursor:default">
          <span class="admin-cmd-tag">Gurisha</span>
          <h3>Item earnings</h3>
          <ul class="admin-cmd-meta">
            <li><span>Waiting</span><strong><?= (int) $itemStream['review'] ?></strong></li>
            <li><span>Revenue</span><strong><?= number_format((int) $itemStream['fee_income']) ?> RWF</strong></li>
          </ul>
          <button type="button" class="btn-sm" data-open="item-approvals">Open Item Approvals</button>
        </div>
        <div class="admin-cmd-card tone-green" style="cursor:default">
          <span class="admin-cmd-tag">Akazi</span>
          <h3>Job earnings</h3>
          <ul class="admin-cmd-meta">
            <li><span>Waiting</span><strong><?= (int) $jobStream['review'] ?></strong></li>
            <li><span>Revenue</span><strong><?= number_format((int) $jobStream['fee_income']) ?> RWF</strong></li>
          </ul>
          <button type="button" class="btn-sm ok" data-open="job-approvals">Open Job Approvals</button>
        </div>
      </div>
      <ul class="portal-duties">
        <li><strong>Items</strong> cost <?= $itemFee ?> RWF each; <strong>Jobs</strong> cost <?= $jobFee ?> RWF each.</li>
        <li>Member pays MoMo to <strong><?= htmlspecialchars(GUGU_MOMO_NAME) ?></strong> &middot; <code><?= htmlspecialchars(GUGU_MOMO_NUMBER) ?></code></li>
        <li>Post stays <strong>Pending</strong> until you <strong>Mark paid</strong> then <strong>Approve</strong> in the matching queue.</li>
      </ul>
    </section>
  </section>

  <!-- SECTION: Item Approvals (Gurisha) -->
  <section class="admin-pane" data-pane="item-approvals" id="item-approvals">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Gurisha marketplace</span>
      <h2>Item Approvals</h2>
      <p class="admin-pane-sub">
        Marketplace items only (no jobs). Fee <?= $itemFee ?> RWF &rarr; Mark paid &rarr; Approve.
        Queue <strong><?= (int) $itemStream['review'] ?></strong>
        &middot; Ready <strong><?= (int) $itemStream['paid_pending'] ?></strong>
        &middot; Live <strong><?= (int) $itemStream['active'] ?></strong>
        &middot; Revenue <strong><?= number_format((int) $itemStream['fee_income']) ?> RWF</strong>
      </p>
    </header>
    <?php portalRenderBusinessApprovals($itemStream); ?>
  </section>

  <!-- SECTION: Job Approvals (Akazi) -->
  <section class="admin-pane" data-pane="job-approvals" id="job-approvals">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Akazi jobs</span>
      <h2>Job announcement approvals</h2>
      <p class="admin-pane-sub">
        Akazi job announcements only (no marketplace items). Fee <?= $jobFee ?> RWF &rarr; Mark paid &rarr; Approve.
        Queue <strong><?= (int) $jobStream['review'] ?></strong>
        &middot; Ready <strong><?= (int) $jobStream['paid_pending'] ?></strong>
        &middot; Live <strong><?= (int) $jobStream['active'] ?></strong>
        &middot; Revenue <strong><?= number_format((int) $jobStream['fee_income']) ?> RWF</strong>
      </p>
    </header>
    <?php portalRenderBusinessApprovals($jobStream); ?>
  </section>

  <!-- SECTION: Reports (community) -->
  <section class="admin-pane" data-pane="reports" id="reports">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Trust &amp; Safety</span>
      <h2>Community reports</h2>
      <p class="admin-pane-sub">Queue <?= $reportNeedsAction ?> &middot; Resolved <?= $reportResolved ?> &middot; This month <?= $reportHandledMonth ?></p>
    </header>

    <section class="panel reports-dash-panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="stats admin-owner-stats admin-owner-stats-6 reports-stats">
        <div class="stat"><strong><?= $reportOpen ?></strong><span>Open</span></div>
        <div class="stat"><strong><?= $reportReviewing ?></strong><span>Reviewing</span></div>
        <div class="stat"><strong><?= $reportNeedsAction ?></strong><span>Queue</span></div>
        <div class="stat"><strong><?= $reportResolved ?></strong><span>Resolved</span></div>
        <div class="stat"><strong><?= $reportDismissed ?></strong><span>Dismissed</span></div>
        <div class="stat"><strong><?= $reportHandledMonth ?></strong><span>This month</span></div>
      </div>
    </section>

    <section class="panel finance-panel reports-charts-panel">
      <div class="finance-chart-grid reports-chart-grid">
        <div class="finance-chart-card">
          <h4 class="panel-subhead">Status</h4>
          <div class="finance-chart-wrap finance-chart-wrap-donut">
            <canvas id="chart-report-status" aria-label="Reports by status"></canvas>
          </div>
        </div>
        <div class="finance-chart-card">
          <h4 class="panel-subhead">Target</h4>
          <div class="finance-chart-wrap finance-chart-wrap-donut">
            <canvas id="chart-report-target" aria-label="Reports by target"></canvas>
          </div>
        </div>
        <div class="finance-chart-card">
          <h4 class="panel-subhead">Items vs Jobs</h4>
          <div class="finance-chart-wrap finance-chart-wrap-donut">
            <canvas id="chart-report-biz" aria-label="Item vs Job reports"></canvas>
          </div>
        </div>
        <div class="finance-chart-card">
          <h4 class="panel-subhead">Akarere</h4>
          <div class="finance-chart-wrap finance-chart-wrap-bar">
            <canvas id="chart-report-district" aria-label="Reports by district"></canvas>
          </div>
        </div>
        <div class="finance-chart-card finance-chart-card-wide">
          <h4 class="panel-subhead">6-month trend</h4>
          <div class="finance-chart-wrap">
            <canvas id="chart-report-trend" aria-label="Reports trend"></canvas>
          </div>
        </div>
        <div class="finance-chart-card finance-chart-card-full">
          <h4 class="panel-subhead">Top reasons</h4>
          <div class="finance-chart-wrap finance-chart-wrap-bar">
            <canvas id="chart-report-reasons" aria-label="Top report reasons"></canvas>
          </div>
        </div>
      </div>
      <script type="application/json" id="reports-charts-data"><?= json_encode($reportCharts, JSON_UNESCAPED_UNICODE) ?></script>
    </section>

    <section class="panel" id="reports-queue">
      <h3 class="panel-subhead" style="margin-top:0">Open queue</h3>
      <?php if (!$openReports): ?>
        <p class="hint">No open reports</p>
      <?php else: ?>
      <div class="table-wrap"><table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Target</th>
            <th>Reason</th>
            <th>Status</th>
            <th>When</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($openReports as $r):
          $st = (string) ($r['status'] ?? 'open');
          $type = (string) ($r['target_type'] ?? 'listing');
          $typeLabel = match ($type) {
              'user' => 'Member',
              'chat' => 'Chat',
              default => 'Post',
          };
          $stLabel = $st === 'reviewing' ? 'Reviewing' : 'Open';
        ?>
          <tr>
            <td>#<?= (int) $r['id'] ?></td>
            <td>
              <strong><?= htmlspecialchars($typeLabel) ?></strong>
              <br><small class="muted">#<?= (int) $r['target_id'] ?></small>
            </td>
            <td>
              <?= htmlspecialchars($r['reason']) ?>
              <?php if (!empty($r['details'])): ?><br><small class="muted"><?= htmlspecialchars($r['details']) ?></small><?php endif; ?>
            </td>
            <td><span class="status-pill status-warn"><?= htmlspecialchars($stLabel) ?></span></td>
            <td><small class="muted"><?= htmlspecialchars(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></small></td>
            <td class="portal-actions">
              <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'resolved'], 'Resolve', 'btn-sm ok') ?>
              <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'dismissed'], 'Dismiss', 'btn-sm') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: ID pending verification -->
  <section class="admin-pane" data-pane="id-queue" id="id-queue">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Safety &amp; platform</span>
      <h2>Member ID verification</h2>
      <p class="admin-pane-sub">
        <?= $idPending ?> waiting &middot; <?= $idApproved ?> approved &middot; <?= $idRejected ?> rejected.
        Review each national ID photo, then Approve or Reject.
      </p>
    </header>
    <?php portalRenderIdVerificationQueue($idData, 'Nationwide'); ?>
  </section>

  <!-- SECTION: System Controls -->
  <section class="admin-pane" data-pane="system-controls" id="system-controls">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">System Controls</span>
      <h2>Payment gateway &amp; platform config</h2>
      <p class="admin-pane-sub">MoMo receive number, announce fees, and login text messages. Changes save immediately.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">Item fee &middot; <?= $itemFee ?> RWF</span>
        <span class="chip chip-green">Job fee &middot; <?= $jobFee ?> RWF</span>
        <span class="chip chip-yellow">MoMo &middot; <?= htmlspecialchars(GUGU_MOMO_NUMBER) ?></span>
        <span class="chip"><?= GUGU_MOMO_SANDBOX ? 'Sandbox' : 'Live' ?></span>
        <span class="chip <?= $smsConfigured ? 'chip-green' : 'chip-yellow' ?>">
          Login SMS &middot; <?= $smsConfigured ? 'On (real texts)' : 'Off (local OK)' ?>
        </span>
      </div>
      <form method="post" action="/gugu-app/admin/actions.php" class="portal-settings-form">
        <input type="hidden" name="action" value="save-system-settings">
        <h4 class="panel-subhead">Mobile Money Gateway</h4>
        <div class="portal-form-grid">
          <label>Receiver name
            <input type="text" name="momo_name" required value="<?= htmlspecialchars(GUGU_MOMO_NAME) ?>">
          </label>
          <label>MoMo number
            <input type="text" name="momo_number" required value="<?= htmlspecialchars(GUGU_MOMO_NUMBER) ?>" placeholder="07XXXXXXXX">
          </label>
          <label>Item announce fee (RWF)
            <input type="number" name="item_announce_fee_rwf" min="0" step="100" required value="<?= $itemFee ?>">
          </label>
          <label>Job announce fee (RWF)
            <input type="number" name="job_announce_fee_rwf" min="0" step="100" required value="<?= $jobFee ?>">
          </label>
          <label class="portal-check">
            <input type="checkbox" name="momo_sandbox" value="1" <?= GUGU_MOMO_SANDBOX ? 'checked' : '' ?>>
            Sandbox mode (testing)
          </label>
        </div>
        <h4 class="panel-subhead">Login SMS (OTP codes)</h4>
        <p class="hint" style="margin:0 0 12px">
          These fields send the <strong>6-digit login code</strong> to a member&rsquo;s phone.
          On your local XAMPP computer you can leave them empty &mdash; the code appears on the login screen instead.
          Fill them in only when GUGU goes live with a real SMS provider (e.g. Africa&rsquo;s Talking).
        </p>
        <div class="portal-form-grid">
          <label>SMS provider URL <small class="muted">(optional for now)</small>
            <input type="url" name="sms_api_url" value="<?= htmlspecialchars(GUGU_SMS_API_URL) ?>" placeholder="Leave empty for local testing">
          </label>
          <label>SMS API key <small class="muted">(optional for now)</small>
            <input type="password" name="sms_api_key" value="" placeholder="<?= $smsConfigured ? 'Leave blank to keep current key' : 'Leave empty for local testing' ?>" autocomplete="new-password">
          </label>
          <label>Sender name on the text
            <input type="text" name="sms_sender" value="<?= htmlspecialchars(GUGU_SMS_SENDER) ?>" maxlength="11" placeholder="GuraGuri">
          </label>
        </div>
        <button type="submit" class="btn-sm ok">Save System Controls</button>
      </form>
      <p class="hint" style="margin-top:14px">Database backup: export <code>GUGUapDB</code> from phpMyAdmin regularly.</p>
    </section>
  </section>

  <!-- SECTION: Global Financial Analytics -->
  <section class="admin-pane" data-pane="analytics" id="analytics">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Global Financial Analytics</span>
      <h2>Platform revenue</h2>
      <p class="admin-pane-sub">Clean graphs for Item fees vs Job fees ? nationwide.</p>
    </header>

    <section class="panel finance-panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>

      <div class="stats admin-owner-stats finance-stats">
        <div class="stat"><strong><?= number_format($feeIncome) ?></strong><span>Combined revenue</span></div>
        <div class="stat"><strong><?= number_format((int) $itemStream['fee_income']) ?></strong><span>Item fees (RWF)</span></div>
        <div class="stat"><strong><?= number_format((int) $jobStream['fee_income']) ?></strong><span>Job fees (RWF)</span></div>
        <div class="stat"><strong><?= number_format($feeIncomeMonth) ?></strong><span>This month</span></div>
      </div>

      <div class="finance-chart-grid">
        <div class="finance-chart-card">
          <h4 class="panel-subhead">Items vs Jobs</h4>
          <p class="finance-chart-hint">Share of all paid announce fees</p>
          <div class="finance-chart-wrap finance-chart-wrap-donut">
            <canvas id="chart-business-split" aria-label="Items vs Jobs revenue"></canvas>
          </div>
        </div>
        <div class="finance-chart-card finance-chart-card-wide">
          <h4 class="panel-subhead">Fee trend (6 months)</h4>
          <p class="finance-chart-hint">Monthly Item and Job earnings</p>
          <div class="finance-chart-wrap">
            <canvas id="chart-fee-trend" aria-label="Monthly fee trend"></canvas>
          </div>
        </div>
        <div class="finance-chart-card finance-chart-card-full">
          <h4 class="panel-subhead">Top districts</h4>
          <p class="finance-chart-hint">Paid fees by Akarere ? Items and Jobs</p>
          <div class="finance-chart-wrap finance-chart-wrap-bar">
            <canvas id="chart-district-fees" aria-label="District fee revenue"></canvas>
          </div>
        </div>
      </div>

      <script type="application/json" id="finance-charts-data"><?= json_encode($financeCharts, JSON_UNESCAPED_UNICODE) ?></script>

      <h4 class="panel-subhead">Revenue by business</h4>
      <?php if (!$revenueByBusiness): ?>
        <p class="hint">No paid fees yet</p>
      <?php else: ?>
      <div class="table-wrap" style="margin-bottom:16px">
        <table>
          <thead>
            <tr><th>Business</th><th>Paid posts</th><th>Revenue (RWF)</th><th>This month</th><th>Queue</th></tr>
          </thead>
          <tbody>
            <?php foreach (['item' => $itemStream, 'job' => $jobStream] as $bt => $stream): ?>
              <tr>
                <td><strong><?= htmlspecialchars(guguBusinessLabel($bt)) ?></strong></td>
                <td><?= (int) $stream['paid_count'] ?></td>
                <td><strong><?= number_format((int) $stream['fee_income']) ?></strong></td>
                <td><?= number_format((int) $stream['fee_income_month']) ?></td>
                <td>
                  <button type="button" class="btn-sm" data-open="<?= $bt === 'job' ? 'job-approvals' : 'item-approvals' ?>">
                    Open <?= htmlspecialchars(guguBusinessLabel($bt)) ?> queue
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td><strong>Combined</strong></td>
              <td><?= $paidCount ?></td>
              <td><strong><?= number_format($feeIncome) ?></strong></td>
              <td><?= number_format($feeIncomeMonth) ?></td>
              <td><button type="button" class="btn-sm warn" data-open="payments">Payments</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <h4 class="panel-subhead">Revenue by district &amp; business</h4>
      <?php if (!$revenueByDistrict): ?>
        <p class="hint">No paid fees yet</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>District</th><th>Business</th><th>Paid posts</th><th>Revenue (RWF)</th></tr>
          </thead>
          <tbody>
            <?php foreach ($revenueByDistrict as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['district']) ?></td>
                <td><?= htmlspecialchars(guguBusinessLabel((string) ($row['business_type'] ?? 'item'))) ?></td>
                <td><?= (int) $row['paid_posts'] ?></td>
                <td><strong><?= number_format((int) $row['revenue']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: Open any dashboard -->
  <section class="admin-pane" data-pane="dashboards" id="dashboards">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Dashboard access</span>
      <h2>Open District or Moderator dashboard</h2>
      <p class="admin-pane-sub">Same portal look ? you keep Admin power while inspecting their screen.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="admin-command-grid">
        <a class="admin-cmd-card tone-green" href="/gugu-app/admin/dashboard.php?view_role=2&amp;view_district=Gasabo">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">??</span>
            <span class="admin-cmd-tag">Regional</span>
          </div>
          <h3>District Manager dashboard</h3>
          <p>District Operations Hub ? regional listings, sellers, reports.</p>
          <span class="admin-cmd-go">Open District view ?</span>
        </a>
        <a class="admin-cmd-card tone-yellow" href="/gugu-app/admin/dashboard.php?view_role=3&amp;view_district=Gasabo">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">??</span>
            <span class="admin-cmd-tag">Local</span>
          </div>
          <h3>Moderator dashboard</h3>
          <p>Trust &amp; Safety Desk ? flagged queue, ID review, tickets.</p>
          <span class="admin-cmd-go">Open Moderator view ?</span>
        </a>
      </div>
      <p class="hint" style="margin-top:14px">Open a District Manager or Moderator portal to work in that workspace. Use <strong>Admin console</strong> in the sidebar when you want to leave.</p>
    </section>
  </section>

</div>

<script>
(function () {
  var shell = document.getElementById('admin-shell');
  if (!shell) return;

  var alias = {
    home: 'home',
    checklist: 'checklist',
    permissions: 'permissions',
    management: 'staff',
    members: 'members',
    people: 'members',
    payments: 'payments',
    listings: 'item-approvals',
    'item-approvals': 'item-approvals',
    items: 'item-approvals',
    'job-approvals': 'job-approvals',
    jobs: 'job-approvals',
    reports: 'reports',
    'id-queue': 'id-queue',
    'id-verification': 'id-queue',
    id: 'id-queue',
    'system-controls': 'system-controls',
    settings: 'system-controls',
    'system-settings': 'system-controls',
    analytics: 'analytics',
    financials: 'analytics',
    dashboards: 'dashboards',
    users: 'members',
    'management-system': 'staff',
    staff: 'staff',
    'staff-management': 'staff'
  };

  function currentPaneKey() {
    try {
      var q = new URLSearchParams(location.search || '');
      if (q.get('pane')) return q.get('pane');
    } catch (e) {}
    return (location.hash || '#home').replace(/^#/, '') || 'home';
  }

  var financeCharts = { business: null, trend: null, district: null };
  var reportCharts = { status: null, target: null, biz: null, district: null, trend: null, reasons: null };

  function destroyFinanceCharts() {
    Object.keys(financeCharts).forEach(function (k) {
      if (financeCharts[k]) {
        financeCharts[k].destroy();
        financeCharts[k] = null;
      }
    });
  }

  function destroyReportCharts() {
    Object.keys(reportCharts).forEach(function (k) {
      if (reportCharts[k]) {
        reportCharts[k].destroy();
        reportCharts[k] = null;
      }
    });
  }

  function initReportCharts() {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('reports-charts-data');
    if (!el) return;
    var data;
    try { data = JSON.parse(el.textContent || '{}'); } catch (e) { return; }

    destroyReportCharts();

    var green = '#20603D';
    var green2 = '#2E8B57';
    var green3 = '#3DAA6D';
    var green4 = '#7BC49A';
    var green5 = '#A8D5B5';
    var ink = '#145A32';
    var grid = 'rgba(32, 96, 61, 0.10)';
    var donutOpts = {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, color: ink, font: { weight: '600', size: 11 } } }
      }
    };

    var statusCtx = document.getElementById('chart-report-status');
    if (statusCtx) {
      reportCharts.status = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: data.statusLabels || [],
          datasets: [{
            data: data.statusCounts || [],
            backgroundColor: [green3, green2, green, green5],
            borderWidth: 0,
            hoverOffset: 5
          }]
        },
        options: donutOpts
      });
    }

    var targetCtx = document.getElementById('chart-report-target');
    if (targetCtx) {
      reportCharts.target = new Chart(targetCtx, {
        type: 'doughnut',
        data: {
          labels: data.targetLabels || ['Post', 'Member', 'Chat'],
          datasets: [{
            data: data.targetCounts || [0, 0, 0],
            backgroundColor: [green, green2, green4],
            borderWidth: 0,
            hoverOffset: 5
          }]
        },
        options: donutOpts
      });
    }

    var bizCtx = document.getElementById('chart-report-biz');
    if (bizCtx) {
      reportCharts.biz = new Chart(bizCtx, {
        type: 'doughnut',
        data: {
          labels: data.bizLabels || ['Items', 'Jobs'],
          datasets: [{
            data: data.bizCounts || [0, 0],
            backgroundColor: [green, green3],
            borderWidth: 0,
            hoverOffset: 5
          }]
        },
        options: donutOpts
      });
    }

    var distCtx = document.getElementById('chart-report-district');
    if (distCtx) {
      var dLabels = data.districtLabels || [];
      var dCounts = data.districtCounts || [];
      if (!dLabels.length) { dLabels = ['-']; dCounts = [0]; }
      reportCharts.district = new Chart(distCtx, {
        type: 'bar',
        data: {
          labels: dLabels,
          datasets: [{
            label: 'Queue',
            data: dCounts,
            backgroundColor: green2,
            borderRadius: 6,
            maxBarThickness: 26
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { beginAtZero: true, ticks: { precision: 0, color: '#64748B' }, grid: { color: grid } },
            y: { grid: { display: false }, ticks: { color: ink, font: { weight: '700', size: 11 } } }
          }
        }
      });
    }

    var trendCtx = document.getElementById('chart-report-trend');
    if (trendCtx) {
      reportCharts.trend = new Chart(trendCtx, {
        type: 'line',
        data: {
          labels: data.months || [],
          datasets: [
            {
              label: 'New',
              data: data.monthCreated || [],
              borderColor: green3,
              backgroundColor: 'rgba(61, 170, 109, 0.15)',
              fill: true,
              tension: 0.35,
              pointRadius: 3,
              pointBackgroundColor: green3
            },
            {
              label: 'Closed',
              data: data.monthClosed || [],
              borderColor: green,
              backgroundColor: 'rgba(32, 96, 61, 0.12)',
              fill: true,
              tension: 0.35,
              pointRadius: 3,
              pointBackgroundColor: green
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, color: ink, font: { weight: '600' } } }
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#64748B' } },
            y: { beginAtZero: true, ticks: { precision: 0, color: '#64748B' }, grid: { color: grid } }
          }
        }
      });
    }

    var reasonCtx = document.getElementById('chart-report-reasons');
    if (reasonCtx) {
      var rLabels = data.reasonLabels || [];
      var rCounts = data.reasonCounts || [];
      if (!rLabels.length) { rLabels = ['-']; rCounts = [0]; }
      reportCharts.reasons = new Chart(reasonCtx, {
        type: 'bar',
        data: {
          labels: rLabels,
          datasets: [{
            label: 'Reports',
            data: rCounts,
            backgroundColor: green,
            borderRadius: 6,
            maxBarThickness: 32
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { beginAtZero: true, ticks: { precision: 0, color: '#64748B' }, grid: { color: grid } },
            y: { grid: { display: false }, ticks: { color: ink, font: { weight: '600', size: 11 } } }
          }
        }
      });
    }
  }

  function initFinanceCharts() {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('finance-charts-data');
    if (!el) return;
    var data;
    try { data = JSON.parse(el.textContent || '{}'); } catch (e) { return; }

    destroyFinanceCharts();

    var blue = '#00A1DE';
    var green = '#20603D';
    var yellow = '#E8B800';
    var ink = '#0A4A66';
    var grid = 'rgba(10, 74, 102, 0.08)';
    var money = function (v) {
      try { return Number(v || 0).toLocaleString() + ' RWF'; } catch (e) { return v + ' RWF'; }
    };

    var bizCtx = document.getElementById('chart-business-split');
    if (bizCtx) {
      financeCharts.business = new Chart(bizCtx, {
        type: 'doughnut',
        data: {
          labels: ['Items', 'Jobs'],
          datasets: [{
            data: [data.itemRevenue || 0, data.jobRevenue || 0],
            backgroundColor: [blue, green],
            borderWidth: 0,
            hoverOffset: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '62%',
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, color: ink, font: { weight: '600' } } },
            tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + money(c.raw); } } }
          }
        }
      });
    }

    var trendCtx = document.getElementById('chart-fee-trend');
    if (trendCtx) {
      financeCharts.trend = new Chart(trendCtx, {
        type: 'line',
        data: {
          labels: data.months || [],
          datasets: [
            {
              label: 'Item fees',
              data: data.itemMonthly || [],
              borderColor: blue,
              backgroundColor: 'rgba(0, 161, 222, 0.12)',
              fill: true,
              tension: 0.35,
              pointRadius: 3,
              pointBackgroundColor: blue
            },
            {
              label: 'Job fees',
              data: data.jobMonthly || [],
              borderColor: green,
              backgroundColor: 'rgba(32, 96, 61, 0.12)',
              fill: true,
              tension: 0.35,
              pointRadius: 3,
              pointBackgroundColor: green
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, color: ink, font: { weight: '600' } } },
            tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + money(c.raw); } } }
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#64748B', font: { size: 11 } } },
            y: {
              beginAtZero: true,
              grid: { color: grid },
              ticks: {
                color: '#64748B',
                font: { size: 11 },
                callback: function (v) { return Number(v).toLocaleString(); }
              }
            }
          }
        }
      });
    }

    var distCtx = document.getElementById('chart-district-fees');
    if (distCtx) {
      financeCharts.district = new Chart(distCtx, {
        type: 'bar',
        data: {
          labels: data.districts || [],
          datasets: [
            {
              label: 'Items',
              data: data.districtItem || [],
              backgroundColor: blue,
              borderRadius: 6,
              maxBarThickness: 28
            },
            {
              label: 'Jobs',
              data: data.districtJob || [],
              backgroundColor: green,
              borderRadius: 6,
              maxBarThickness: 28
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, color: ink, font: { weight: '600' } } },
            tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + money(c.raw); } } }
          },
          scales: {
            x: { stacked: true, grid: { display: false }, ticks: { color: '#64748B', font: { size: 11 } } },
            y: {
              stacked: true,
              beginAtZero: true,
              grid: { color: grid },
              ticks: {
                color: '#64748B',
                callback: function (v) { return Number(v).toLocaleString(); }
              }
            }
          }
        }
      });
    }
  }

  var STACK_KEY = 'gugu_admin_pane_stack';

  function readStack() {
    try {
      var s = JSON.parse(sessionStorage.getItem(STACK_KEY) || '[]');
      return Array.isArray(s) ? s : [];
    } catch (e) {
      return [];
    }
  }

  function writeStack(stack) {
    try {
      sessionStorage.setItem(STACK_KEY, JSON.stringify(stack));
    } catch (e) {}
  }

  function getActivePane() {
    var el = shell.querySelector('.admin-pane.is-active');
    return el ? (el.getAttribute('data-pane') || 'home') : 'home';
  }

  function updateBackLabels() {
    var hasPrev = readStack().length > 0;
    var html = hasPrev ? '&larr; Back' : '&larr; Dashboard';
    shell.querySelectorAll('.admin-back').forEach(function (btn) {
      btn.innerHTML = html;
      btn.setAttribute('aria-label', hasPrev ? 'Back to previous page' : 'Back to Dashboard');
    });
  }

  function openPane(key, opts) {
    opts = opts || {};
    key = alias[key] || 'home';
    if (!shell.querySelector('[data-pane="' + key + '"]')) key = 'home';

    var from = getActivePane();
    // Remember previous pane so Back returns there (e.g. Checklist ? Queue ? Back)
    if (!opts.back && !opts.initial && from && from !== key) {
      var stack = readStack();
      if (stack[stack.length - 1] !== from) {
        stack.push(from);
        if (stack.length > 30) stack = stack.slice(-30);
        writeStack(stack);
      }
    }

    shell.querySelectorAll('.admin-pane').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-pane') === key);
    });
    try {
      var url = new URL(location.href);
      if (key === 'home') url.searchParams.delete('pane');
      else url.searchParams.set('pane', key);
      url.hash = '';
      if (history.replaceState) history.replaceState({ pane: key }, '', url.pathname + url.search);
    } catch (e) {}
    window.scrollTo(0, 0);
    updateBackLabels();
    if (key === 'analytics') {
      setTimeout(initFinanceCharts, 40);
    }
    if (key === 'reports') {
      setTimeout(initReportCharts, 40);
    }
  }

  function goBack() {
    var stack = readStack();
    var prev = stack.pop() || 'home';
    writeStack(stack);
    openPane(prev, { back: true });
  }

  shell.addEventListener('click', function (e) {
    var backBtn = e.target.closest('.admin-back, [data-back]');
    if (backBtn && shell.contains(backBtn)) {
      e.preventDefault();
      goBack();
      return;
    }
    var btn = e.target.closest('[data-open]');
    if (!btn || !shell.contains(btn)) return;
    e.preventDefault();
    openPane(btn.getAttribute('data-open'));
  });

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    var pane = '';
    try {
      if (href.indexOf('pane=') !== -1) {
        pane = new URL(href, location.origin).searchParams.get('pane') || '';
      } else if (href.charAt(0) === '#') {
        pane = href.replace(/^#/, '');
      }
    } catch (err) {}
    if (!pane || !alias[pane]) return;
    if (href.indexOf('view_role=') !== -1) return;
    e.preventDefault();
    openPane(pane);
  });

  // Fresh Admin visit: clear stale back-stack unless returning to a deep link mid-session
  try {
    if (!sessionStorage.getItem('gugu_admin_nav_alive')) {
      writeStack([]);
      sessionStorage.setItem('gugu_admin_nav_alive', '1');
    }
  } catch (e) {}

  openPane(currentPaneKey(), { initial: true });
  window.addEventListener('hashchange', function () {
    openPane(currentPaneKey());
  });
})();
</script>
