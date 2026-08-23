<?php
/**
 * Role 2 · District Manager — District Operations Hub
 * Scope: one Akarere only. Activate/suspend members & Moderators.
 * Mark paid → Approve (Admin earns). Reject spam. Handle local reports.
 * Cannot change Admin / other DMs. Cannot ban — escalate to Admin.
 */
require_once __DIR__ . '/../includes/portal_helpers.php';

$db = getDB();
$selfId = (int) $_SESSION['user_id'];
$district = !empty($portal_view_district)
  ? $portal_view_district
  : ($_SESSION['admin_district'] ?: ($_SESSION['district'] ?? 'Gasabo'));
$isAdminPreview = ((int) ($portal_actual_role_id ?? 0) === 1);

$itemStream = portalBusinessStreamForDistrictHub($db, 'item', (string) $district);
$jobStream = portalBusinessStreamForDistrictHub($db, 'job', (string) $district);
// Real District Managers can only act inside their Akarere; Admin preview can manage all.
$itemStream['action_district'] = $isAdminPreview ? '' : (string) $district;
$jobStream['action_district'] = $isAdminPreview ? '' : (string) $district;
$review = (int) $itemStream['review'] + (int) $jobStream['review'];
$paidPending = (int) $itemStream['paid_pending'] + (int) $jobStream['paid_pending'];
$unpaidPending = (int) $itemStream['unpaid_pending'] + (int) $jobStream['unpaid_pending'];
$active = (int) $itemStream['active'] + (int) $jobStream['active'];
$feeIncome = (int) $itemStream['fee_income'] + (int) $jobStream['fee_income'];
$feeIncomeMonth = (int) $itemStream['fee_income_month'] + (int) $jobStream['fee_income_month'];

$stmt = $db->prepare('
  SELECT COUNT(*) FROM users
  WHERE role_id = 4
    AND COALESCE(account_kind, "member") = "member"
    AND district = ?
');
$stmt->execute([$district]);
$membersCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare('
  SELECT COUNT(*) FROM users
  WHERE role_id = 4 AND district = ? AND account_status = "suspended"
');
$stmt->execute([$district]);
$membersSuspended = (int) $stmt->fetchColumn();

$stmt = $db->prepare('
  SELECT id, nickname, phone, full_name, email, province, district, sector,
         role_id, account_status, id_status, id_number, id_document_path,
         id_verified_at, id_reject_reason, created_at, updated_at
  FROM users
  WHERE role_id = 4
    AND COALESCE(account_kind, "member") = "member"
    AND district = ?
  ORDER BY FIELD(account_status, "suspended", "active", "banned"),
           COALESCE(updated_at, created_at) DESC, id DESC
  LIMIT 120
');
$stmt->execute([$district]);
$localMembers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$membersIdSubmitted = count(array_filter($localMembers, static function ($u) {
    $st = (string) ($u['id_status'] ?? 'none');
    return in_array($st, ['pending', 'approved', 'rejected'], true)
        || trim((string) ($u['id_document_path'] ?? '')) !== '';
}));
$membersIdPending = count(array_filter($localMembers, static fn($u) => ($u['id_status'] ?? '') === 'pending'));
$membersIdApproved = count(array_filter($localMembers, static fn($u) => ($u['id_status'] ?? '') === 'approved'));

$stmt = $db->prepare('
  SELECT u.id, u.nickname, u.full_name, u.phone, u.email, u.account_status,
         u.district, u.admin_district, u.created_at, u.updated_at,
         COALESCE(NULLIF(u.admin_district, ""), u.district) AS managed_district,
         (SELECT COUNT(*) FROM admin_audit_logs a
          WHERE a.actor_id = u.id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS actions_30d,
         (SELECT COUNT(*) FROM admin_audit_logs a
          WHERE a.actor_id = u.id AND a.action = "moderate-listing"
            AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS moderated_30d,
         (SELECT COUNT(*) FROM admin_audit_logs a
          WHERE a.actor_id = u.id AND a.action = "resolve-report"
            AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS reports_30d
  FROM users u
  WHERE u.role_id = 3
    AND COALESCE(NULLIF(u.admin_district, ""), u.district) = ?
  ORDER BY FIELD(u.account_status, "suspended", "active", "banned"),
           actions_30d DESC, u.nickname
');
$stmt->execute([$district]);
$moderators = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$moderatorsActive = count(array_filter($moderators, static fn($m) => ($m['account_status'] ?? '') === 'active'));
$moderatorsSuspended = count(array_filter($moderators, static fn($m) => ($m['account_status'] ?? '') === 'suspended'));
$moderatorsCount = count($moderators);

$stmt = $db->prepare('
  SELECT COUNT(*) FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  WHERE r.status IN ("open","reviewing") AND (l.district = ? OR u.district = ?)
');
$stmt->execute([$district, $district]);
$localReports = (int) $stmt->fetchColumn();
$reports = $localReports;
$checklistScope = $district;

$reportDistrictSql = '
  FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  WHERE (l.district = ? OR u.district = ?)
';
$reportDistrictParams = [$district, $district];

$dmReportCount = static function (string $extraSql = '', array $extraParams = []) use ($db, $reportDistrictSql, $reportDistrictParams): int {
    try {
        $stmt = $db->prepare('SELECT COUNT(*) ' . $reportDistrictSql . $extraSql);
        $stmt->execute(array_merge($reportDistrictParams, $extraParams));
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
};

$dmReportOpen = $dmReportCount(' AND r.status = ?', ['open']);
$dmReportReviewing = $dmReportCount(' AND r.status = ?', ['reviewing']);
$dmReportResolved = $dmReportCount(' AND r.status = ?', ['resolved']);
$dmReportDismissed = $dmReportCount(' AND r.status = ?', ['dismissed']);
$dmReportQueue = $dmReportOpen + $dmReportReviewing;
$dmReportListing = $dmReportCount(' AND r.target_type = ?', ['listing']);
$dmReportUser = $dmReportCount(' AND r.target_type = ?', ['user']);
$dmReportChat = $dmReportCount(' AND r.target_type = ?', ['chat']);

$dmReportItem = 0;
$dmReportJob = 0;
try {
    $stmt = $db->prepare("
      SELECT COUNT(*) FROM reports r
      INNER JOIN listings l ON r.target_type = 'listing' AND l.id = r.target_id
      WHERE l.district = ?
        AND l.business_type = 'item'
    ");
    $stmt->execute([$district]);
    $dmReportItem = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
      SELECT COUNT(*) FROM reports r
      INNER JOIN listings l ON r.target_type = 'listing' AND l.id = r.target_id
      WHERE l.district = ?
        AND l.business_type = 'job'
    ");
    $stmt->execute([$district]);
    $dmReportJob = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $dmReportItem = 0;
    $dmReportJob = 0;
}

$dmReportReasons = [];
try {
    $stmt = $db->prepare('
      SELECT r.reason, COUNT(*) AS c
      ' . $reportDistrictSql . '
      GROUP BY r.reason
      ORDER BY c DESC
      LIMIT 6
    ');
    $stmt->execute($reportDistrictParams);
    $dmReportReasons = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $dmReportReasons = [];
}

$dmReportMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $dmReportMonths[] = date('Y-m', strtotime("-{$i} months"));
}
$dmReportMonthCreated = array_fill_keys($dmReportMonths, 0);
$dmReportMonthClosed = array_fill_keys($dmReportMonths, 0);
try {
    $stmt = $db->prepare('
      SELECT DATE_FORMAT(r.created_at, "%Y-%m") AS ym, COUNT(*) AS c
      ' . $reportDistrictSql . '
        AND r.created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), "%Y-%m-01")
      GROUP BY ym
    ');
    $stmt->execute($reportDistrictParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($dmReportMonthCreated[$row['ym']])) {
            $dmReportMonthCreated[$row['ym']] = (int) $row['c'];
        }
    }
    $stmt = $db->prepare('
      SELECT DATE_FORMAT(COALESCE(r.updated_at, r.created_at), "%Y-%m") AS ym, COUNT(*) AS c
      ' . $reportDistrictSql . '
        AND r.status IN ("resolved","dismissed")
        AND COALESCE(r.updated_at, r.created_at) >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), "%Y-%m-01")
      GROUP BY ym
    ');
    $stmt->execute($reportDistrictParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($dmReportMonthClosed[$row['ym']])) {
            $dmReportMonthClosed[$row['ym']] = (int) $row['c'];
        }
    }
} catch (Throwable $e) {
    // keep zeros
}

$dmReportHandledMonth = (int) ($dmReportMonthClosed[date('Y-m')] ?? 0);

$stmt = $db->prepare('
  SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status, r.created_at,
         l.title AS listing_title, l.business_type, l.category_id, l.district AS listing_district,
         u.nickname AS user_nickname, u.phone AS user_phone,
         rep.nickname AS reporter_name, rep.phone AS reporter_phone
  FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  LEFT JOIN users rep ON rep.id = r.reporter_id
  WHERE r.status IN ("open","reviewing") AND (l.district = ? OR u.district = ?)
  ORDER BY FIELD(r.status, "open", "reviewing"), r.created_at DESC
  LIMIT 60
');
$stmt->execute([$district, $district]);
$openReports = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmt = $db->prepare('
  SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status, r.updated_at, r.created_at,
         l.title AS listing_title, l.business_type,
         u.nickname AS user_nickname
  FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  WHERE r.status IN ("resolved","dismissed") AND (l.district = ? OR u.district = ?)
  ORDER BY COALESCE(r.updated_at, r.created_at) DESC
  LIMIT 15
');
$stmt->execute([$district, $district]);
$closedReports = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dmReportCharts = [
    'status' => [
        'labels' => ['Open', 'Reviewing', 'Resolved', 'Dismissed'],
        'data' => [$dmReportOpen, $dmReportReviewing, $dmReportResolved, $dmReportDismissed],
    ],
    'target' => [
        'labels' => ['Listing', 'User', 'Chat'],
        'data' => [$dmReportListing, $dmReportUser, $dmReportChat],
    ],
    'biz' => [
        'labels' => ['Items', 'Jobs'],
        'data' => [$dmReportItem, $dmReportJob],
    ],
    'trend' => [
        'labels' => array_map(static fn($ym) => date('M Y', strtotime($ym . '-01')), $dmReportMonths),
        'created' => array_values($dmReportMonthCreated),
        'closed' => array_values($dmReportMonthClosed),
    ],
    'reasons' => [
        'labels' => array_map(static fn($r) => (string) ($r['reason'] ?? 'Other'), $dmReportReasons),
        'data' => array_map(static fn($r) => (int) ($r['c'] ?? 0), $dmReportReasons),
    ],
];

$navBase = '/gugu-app/admin/dashboard.php';
// Sticky Admin preview query on every card / link (never drop district session).
$navQs = $isAdminPreview
  ? ('view_role=2&view_district=' . rawurlencode($district) . '&')
  : '';
$dmNavHref = static function (string $pane) use ($navBase, $navQs): string {
    $pane = trim($pane);
    if ($pane === '' || $pane === 'home') {
        return $navBase . ($navQs !== '' ? ('?' . rtrim($navQs, '&')) : '');
    }
    return $navBase . '?' . $navQs . 'pane=' . rawurlencode($pane);
};
?>
<div class="admin-shell dm-shell" id="dm-shell"
     data-preview="<?= $isAdminPreview ? '1' : '0' ?>"
     data-view-role="<?= $isAdminPreview ? '2' : '' ?>"
     data-view-district="<?= htmlspecialchars($district, ENT_QUOTES) ?>">

  <!-- HOME -->
  <section class="admin-pane is-active" data-pane="home" id="home">
    <section class="panel portal-hero portal-hero-regional admin-owner-hero">
      <div class="rw-flag-bar" aria-hidden="true">
        <span class="rw-blue"></span>
        <span class="rw-yellow"></span>
        <span class="rw-green"></span>
      </div>
      <div class="admin-owner-hero-inner">
        <div class="portal-hero-text">
          <span class="portal-kicker">Role 2 · District Manager</span>
          <h2><?= htmlspecialchars($district) ?></h2>
          <p>One Akarere · Fees → Admin · Bans → Admin</p>
        </div>
        <div class="admin-kpi-strip admin-owner-stats-6" role="list">
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Members</span>
            <strong class="admin-kpi-value"><?= $membersCount ?></strong>
          </div>
          <div class="admin-kpi<?= (int) $itemStream['review'] > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Item queue</span>
            <strong class="admin-kpi-value"><?= (int) $itemStream['review'] ?></strong>
          </div>
          <div class="admin-kpi<?= (int) $jobStream['review'] > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Job queue</span>
            <strong class="admin-kpi-value"><?= (int) $jobStream['review'] ?></strong>
          </div>
          <div class="admin-kpi<?= $localReports > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Reports</span>
            <strong class="admin-kpi-value"><?= $localReports ?></strong>
          </div>
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Fees (month)</span>
            <strong class="admin-kpi-value"><?= number_format($feeIncomeMonth) ?></strong>
            <span class="admin-kpi-unit">RWF</span>
          </div>
          <div class="admin-kpi<?= $membersIdPending > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">ID pending</span>
            <strong class="admin-kpi-value"><?= (int) $membersIdPending ?></strong>
          </div>
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Live posts</span>
            <strong class="admin-kpi-value"><?= $active ?></strong>
          </div>
        </div>
      </div>
    </section>

    <section class="dm-board" aria-label="Allowed duties">
      <header class="dm-board-head">
        <span class="dm-board-badge is-allow">Allowed</span>
        <h3>Your duties · <?= htmlspecialchars($district) ?></h3>
      </header>
      <div class="dm-duty-grid">
        <article class="dm-duty-card is-scope">
          <span class="dm-duty-label">Scope</span>
          <h4><?= htmlspecialchars($district) ?></h4>
          <p>Work only in your assigned district</p>
        </article>

        <a class="dm-duty-card<?= $membersIdPending > 0 ? ' is-alert' : '' ?>" href="<?= htmlspecialchars($dmNavHref('id-queue')) ?>" data-open="id-queue">
          <span class="dm-duty-label">ID verification</span>
          <h4>Approve / reject IDs</h4>
          <p>Members in your Akarere waiting for your response</p>
          <div class="dm-duty-meta">
            <span><?= (int) $membersIdPending ?> pending</span>
            <span><?= (int) $membersIdApproved ?> approved</span>
          </div>
        </a>

        <a class="dm-duty-card" href="<?= htmlspecialchars($dmNavHref('members')) ?>" data-open="members">
          <span class="dm-duty-label">Members</span>
          <h4>Activate / suspend</h4>
          <p>Members in your Akarere</p>
          <div class="dm-duty-meta">
            <span><?= $membersCount ?> members</span>
            <span><?= $membersSuspended ?> suspended</span>
          </div>
        </a>

        <a class="dm-duty-card" href="<?= htmlspecialchars($dmNavHref('moderators')) ?>" data-open="moderators">
          <span class="dm-duty-label">Moderators</span>
          <h4>Activate / suspend</h4>
          <p>Moderators in your Akarere</p>
          <div class="dm-duty-meta">
            <span><?= (int) $moderatorsCount ?> assigned</span>
            <?php if ($moderatorsSuspended > 0): ?>
              <span><?= (int) $moderatorsSuspended ?> suspended</span>
            <?php elseif ($moderatorsActive > 0): ?>
              <span><?= (int) $moderatorsActive ?> active</span>
            <?php else: ?>
              <span>Ask Admin to assign</span>
            <?php endif; ?>
          </div>
        </a>

        <a class="dm-duty-card<?= (int) $itemStream['review'] > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($dmNavHref('item-approvals')) ?>" data-open="item-approvals">
          <span class="dm-duty-label">Items</span>
          <h4>MoMo → Mark paid → Approve</h4>
          <p>Reject spam · Gurisha</p>
          <div class="dm-duty-meta">
            <span><?= (int) $itemStream['review'] ?> waiting</span>
            <span><?= (int) $itemStream['paid_pending'] ?> paid</span>
            <span><?= (int) $itemStream['unpaid_pending'] ?> unpaid</span>
          </div>
        </a>

        <a class="dm-duty-card<?= (int) $jobStream['review'] > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($dmNavHref('job-approvals')) ?>" data-open="job-approvals">
          <span class="dm-duty-label">Jobs</span>
          <h4>MoMo → Mark paid → Approve</h4>
          <p>Reject spam · Akazi</p>
          <div class="dm-duty-meta">
            <span><?= (int) $jobStream['review'] ?> waiting</span>
            <span><?= (int) $jobStream['paid_pending'] ?> paid</span>
            <span><?= (int) $jobStream['unpaid_pending'] ?> unpaid</span>
          </div>
        </a>

        <a class="dm-duty-card is-fees" href="<?= htmlspecialchars($dmNavHref('item-approvals')) ?>" data-open="item-approvals">
          <span class="dm-duty-label">Fees</span>
          <h4>Credited to Admin</h4>
          <p>Approve → fee goes to Admin</p>
          <div class="dm-duty-meta">
            <span>Month <?= number_format($feeIncomeMonth) ?> RWF</span>
            <span>All-time <?= number_format($feeIncome) ?> RWF</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $localReports > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($dmNavHref('reports')) ?>" data-open="reports">
          <span class="dm-duty-label">Reports</span>
          <h4>Local reports</h4>
          <p>Listing / user flags in district</p>
          <div class="dm-duty-meta">
            <span><?= $localReports ?> open</span>
          </div>
        </a>

        <a class="dm-duty-card" href="<?= htmlspecialchars($dmNavHref('checklist')) ?>" data-open="checklist">
          <span class="dm-duty-label">Routine</span>
          <h4>Daily checklist</h4>
          <p>Queue → MoMo → Approve → Reports</p>
          <div class="dm-duty-meta">
            <span><?= $review ?> queue</span>
            <span><?= $unpaidPending ?> unpaid</span>
          </div>
        </a>

        <a class="dm-duty-card is-ban" href="<?= htmlspecialchars($dmNavHref('escalate')) ?>" data-open="escalate">
          <span class="dm-duty-label">Ban prep</span>
          <h4>Suspend → escalate</h4>
          <p>Then send Admin ID, phone, reason</p>
        </a>
      </div>
    </section>

    <section class="dm-board" aria-label="Daily order">
      <header class="dm-board-head">
        <span class="dm-board-badge is-order">Daily order</span>
        <h3>Work in this sequence</h3>
      </header>
      <div class="dm-order-grid">
        <button type="button" class="dm-order-card" id="dm-order-alerts" title="Open Alerts in the top bar">
          <em>1</em>
          <strong>Alerts</strong>
          <span>Items &amp; Jobs · paid / unpaid</span>
        </button>
        <a class="dm-order-card" href="<?= htmlspecialchars($dmNavHref('item-approvals')) ?>" data-open="item-approvals">
          <em>2</em>
          <strong>Confirm MoMo</strong>
          <span>Mark paid when payment arrives</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($dmNavHref('item-approvals')) ?>" data-open="item-approvals">
          <em>3</em>
          <strong>Approve</strong>
          <span>Publish good posts · Admin earns</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($dmNavHref('item-approvals')) ?>" data-open="item-approvals">
          <em>4</em>
          <strong>Reject spam</strong>
          <span>Fake Items &amp; Jobs</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($dmNavHref('members')) ?>" data-open="members">
          <em>5</em>
          <strong>Members / Mods</strong>
          <span>Activate or suspend locally</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($dmNavHref('reports')) ?>" data-open="reports">
          <em>6</em>
          <strong>Reports</strong>
          <span>Local listing / user flags</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($dmNavHref('escalate')) ?>" data-open="escalate">
          <em>7</em>
          <strong>Escalate bans</strong>
          <span>Only if needed → Admin</span>
        </a>
      </div>
    </section>
  </section>

  <section class="admin-pane" data-pane="checklist" id="pane-checklist">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Daily routine · <?= htmlspecialchars($district) ?></span>
      <h2>District checklist</h2>
    </header>
    <?php
      $idPending = (int) $membersIdPending;
      $idQueue = array_values(array_filter($localMembers, static fn($u) => ($u['id_status'] ?? '') === 'pending'));
      require __DIR__ . '/../includes/daily_checklist.php';
    ?>
  </section>

  <section class="admin-pane" data-pane="item-approvals" id="pane-item-approvals">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Gurisha · <?= htmlspecialchars($district) ?></span>
      <h2>Item approvals</h2>
      <p class="admin-pane-sub">Mark paid after MoMo, then Approve. Reject spam / fakes. Fees credited to Admin.</p>
    </header>
    <?php portalRenderBusinessApprovals($itemStream); ?>
  </section>

  <section class="admin-pane" data-pane="job-approvals" id="pane-job-approvals">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Akazi · <?= htmlspecialchars($district) ?></span>
      <h2>Job announcement approvals</h2>
      <p class="admin-pane-sub">Akazi job announcements only (not marketplace items). Mark paid after MoMo, then Approve. Fees credited to Admin.</p>
    </header>
    <?php portalRenderBusinessApprovals($jobStream); ?>
  </section>

  <section class="admin-pane" data-pane="members" id="pane-members">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Members · <?= htmlspecialchars($district) ?></span>
      <h2>Activate or suspend members</h2>
      <p class="admin-pane-sub">
        Sector (Umurenge) and ID update automatically from the member app.
        When a member submits an ID photo, the photo and number appear in the ID column.
      </p>
    </header>
    <section class="panel dm-members-panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">Members · <?= $membersCount ?></span>
        <span class="chip chip-yellow">Suspended · <?= $membersSuspended ?></span>
        <span class="chip chip-green">ID photo submitted · <?= (int) $membersIdSubmitted ?></span>
        <span class="chip">ID approved · <?= (int) $membersIdApproved ?></span>
        <?php if ($membersIdPending > 0): ?>
          <span class="chip chip-yellow">ID pending · <?= (int) $membersIdPending ?></span>
        <?php endif; ?>
        <span class="chip">No ban power</span>
      </div>
      <?php if (!$localMembers): ?>
        <p class="hint">No members in <?= htmlspecialchars($district) ?> yet.</p>
      <?php else:
        $uploadBase = defined('UPLOAD_URL') ? UPLOAD_URL : '/gugu-app/public/uploads/';
      ?>
      <div class="table-wrap dm-members-table-wrap">
        <table class="dm-members-table">
          <thead>
            <tr>
              <th>Member</th>
              <th>Phone</th>
              <th>Sector (Umurenge)</th>
              <th>ID</th>
              <th>Status</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($localMembers as $u):
            $uid = (int) $u['id'];
            if ($uid === $selfId) continue;
            $sector = trim((string) ($u['sector'] ?? ''));
            $idSt = (string) ($u['id_status'] ?? 'none');
            $idLabel = portalIdStatusLabel($idSt);
            $idNum = trim((string) ($u['id_number'] ?? ''));
            $idDoc = trim((string) ($u['id_document_path'] ?? ''));
            $idDocUrl = $idDoc !== '' ? $uploadBase . ltrim($idDoc, '/') : '';
            $hasIdPhoto = $idDocUrl !== '';
            $idPill = $idSt === 'approved' ? 'dm-id-pill is-ok'
              : ($idSt === 'pending' ? 'dm-id-pill is-warn'
              : ($idSt === 'rejected' ? 'dm-id-pill is-bad' : 'dm-id-pill'));
            $acct = (string) ($u['account_status'] ?? 'active');
            $acctPill = $acct === 'active' ? 'status-pill status-ok'
              : ($acct === 'suspended' ? 'status-pill status-warn' : 'status-pill status-bad');
            $updatedAt = $u['updated_at'] ?? $u['created_at'] ?? '';
          ?>
            <tr>
              <td>
                <div class="dm-member-cell">
                  <strong><?= htmlspecialchars($u['nickname'] ?: 'Member') ?></strong>
                  <?php if (!empty($u['full_name']) && ($u['full_name'] !== $u['nickname'])): ?>
                    <span><?= htmlspecialchars((string) $u['full_name']) ?></span>
                  <?php endif; ?>
                  <span class="muted">#<?= $uid ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars((string) ($u['phone'] ?? '')) ?></td>
              <td>
                <div class="dm-member-cell">
                  <?php if ($sector !== ''): ?>
                    <strong><?= htmlspecialchars($sector) ?></strong>
                    <span><?= htmlspecialchars((string) ($u['district'] ?? $district)) ?></span>
                  <?php else: ?>
                    <strong class="is-empty">Not set yet</strong>
                    <span>Updates when member saves location</span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="dm-id-cell">
                <div class="dm-id-block">
                  <?php if ($hasIdPhoto): ?>
                    <a class="dm-id-photo" href="<?= htmlspecialchars($idDocUrl) ?>" target="_blank" rel="noreferrer" title="Open ID photo">
                      <img src="<?= htmlspecialchars($idDocUrl) ?>" alt="ID photo for <?= htmlspecialchars($u['nickname'] ?: ('member #' . $uid)) ?>">
                      <span>View photo</span>
                    </a>
                  <?php endif; ?>
                  <div class="dm-member-cell">
                    <span class="<?= $idPill ?>"><?= htmlspecialchars($idLabel) ?></span>
                    <?php if ($idNum !== ''): ?>
                      <span class="dm-id-num"><?= htmlspecialchars($idNum) ?></span>
                    <?php elseif ($idSt === 'none' && !$hasIdPhoto): ?>
                      <span>No ID submitted</span>
                    <?php elseif ($idSt === 'pending'): ?>
                      <span>Waiting review</span>
                    <?php elseif ($idSt === 'rejected' && !empty($u['id_reject_reason'])): ?>
                      <span><?= htmlspecialchars(mb_strimwidth((string) $u['id_reject_reason'], 0, 40, '…')) ?></span>
                    <?php elseif ($hasIdPhoto && $idNum === ''): ?>
                      <span>Photo on file · number missing</span>
                    <?php endif; ?>
                    <?php if ($hasIdPhoto && !empty($u['id_verified_at']) && $idSt === 'approved'): ?>
                      <span>Verified <?= htmlspecialchars(date('d M Y', strtotime((string) $u['id_verified_at']))) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><span class="<?= $acctPill ?>"><?= htmlspecialchars(ucfirst($acct)) ?></span></td>
              <td><small class="muted"><?= $updatedAt !== '' ? htmlspecialchars(date('d M Y H:i', strtotime((string) $updatedAt))) : '—' ?></small></td>
              <td class="portal-actions">
                <?php if ($idSt === 'pending'): ?>
                  <?= portalActionForm('review-id', ['user_id' => $uid, 'id_status' => 'approved', 'return_pane' => 'members'], 'Approve ID', 'btn-sm ok') ?>
                  <?= portalActionForm('review-id', [
                    'user_id' => $uid,
                    'id_status' => 'rejected',
                    'id_reject_reason' => 'Unclear document — please resubmit',
                    'return_pane' => 'members',
                  ], 'Reject ID', 'btn-sm danger') ?>
                <?php endif; ?>
                <?= portalActionForm('set-status', ['user_id' => $uid, 'account_status' => 'active', 'return_pane' => 'members'], 'Activate', 'btn-sm ok') ?>
                <?= portalActionForm('set-status', ['user_id' => $uid, 'account_status' => 'suspended', 'return_pane' => 'members'], 'Suspend', 'btn-sm danger') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </section>

  <section class="admin-pane" data-pane="id-queue" id="pane-id-queue">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Members · <?= htmlspecialchars($district) ?></span>
      <h2>ID verification</h2>
      <p class="admin-pane-sub">
        Members who registered and submitted a national ID are waiting for your Approve or Reject.
      </p>
    </header>
    <?php
      $dmIdData = portalIdVerificationData(getDB(), $district);
      portalRenderIdVerificationQueue($dmIdData, $district);
    ?>
  </section>

  <section class="admin-pane" data-pane="moderators" id="pane-moderators">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Moderator / Support · <?= htmlspecialchars($district) ?></span>
      <h2>Local Moderators</h2>
      <p class="admin-pane-sub">
        Activate or suspend Moderators assigned to this Akarere.
        You cannot change Admin or other District Managers.
      </p>
    </header>

    <section class="panel dm-mods-stats">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="dm-mods-kpis" role="list">
        <div class="dm-mods-kpi" role="listitem">
          <span>Assigned</span><strong><?= (int) $moderatorsCount ?></strong>
        </div>
        <div class="dm-mods-kpi<?= $moderatorsActive > 0 ? ' is-ok' : '' ?>" role="listitem">
          <span>Active</span><strong><?= (int) $moderatorsActive ?></strong>
        </div>
        <div class="dm-mods-kpi<?= $moderatorsSuspended > 0 ? ' is-hot' : '' ?>" role="listitem">
          <span>Suspended</span><strong><?= (int) $moderatorsSuspended ?></strong>
        </div>
        <div class="dm-mods-kpi" role="listitem">
          <span>Akarere</span><strong><?= htmlspecialchars($district) ?></strong>
        </div>
      </div>
    </section>

    <section class="dm-mods-limits" aria-label="What you can manage">
      <article class="dm-mods-limit is-allow">
        <strong>Allowed</strong>
        <span>Activate / Suspend Moderators in <?= htmlspecialchars($district) ?> only</span>
      </article>
      <article class="dm-mods-limit is-block">
        <strong>Blocked</strong>
        <span>Admin accounts · other District Managers · create / reassign staff</span>
      </article>
    </section>

    <section class="panel dm-mods-panel">
      <?php if (!$moderators): ?>
        <div class="dm-mods-empty">
          <span class="dm-mods-empty-badge">No desk yet</span>
          <h3>No Moderator assigned to <?= htmlspecialchars($district) ?></h3>
          <p>
            Local Moderators are created by Admin in Staff Management, then scoped to this Akarere.
            After that, you can Activate or Suspend them here.
          </p>
          <ol class="dm-mods-steps">
            <li>Admin opens <strong>Staff Management</strong></li>
            <li>Creates a <strong>Moderator / Support</strong> account</li>
            <li>Sets district scope to <strong><?= htmlspecialchars($district) ?></strong></li>
            <li>They appear here for Activate / Suspend</li>
          </ol>
          <?php if ($isAdminPreview): ?>
            <div class="dm-mods-empty-actions">
              <button type="button" class="btn-sm" data-open="home">Back to district home</button>
            </div>
          <?php else: ?>
            <p class="dm-mods-ask">Ask Admin to create one in Staff Management for <?= htmlspecialchars($district) ?>.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <header class="dm-mods-table-head">
          <div>
            <h3>Assigned Moderators</h3>
            <p>Activity counts cover the last 30 days in <?= htmlspecialchars($district) ?>.</p>
          </div>
          <span class="dm-mods-count"><?= (int) $moderatorsCount ?> moderator<?= $moderatorsCount === 1 ? '' : 's' ?></span>
        </header>
        <div class="table-wrap dm-mods-table-wrap">
          <table class="dm-mods-table">
            <thead>
              <tr>
                <th>Moderator</th>
                <th>Phone</th>
                <th>Scope</th>
                <th>Status</th>
                <th>Actions 30d</th>
                <th>Listings</th>
                <th>Reports</th>
                <th>Updated</th>
                <th>Manage</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($moderators as $mod):
              $uid = (int) $mod['id'];
              $status = (string) ($mod['account_status'] ?? 'active');
              $statusClass = $status === 'active' ? 'status-ok'
                : ($status === 'suspended' ? 'status-warn' : 'status-bad');
              $scope = trim((string) ($mod['managed_district'] ?? $district));
              $updatedAt = $mod['updated_at'] ?? $mod['created_at'] ?? '';
              $fullName = trim((string) ($mod['full_name'] ?? ''));
            ?>
              <tr>
                <td>
                  <div class="dm-member-cell">
                    <strong><?= htmlspecialchars($mod['nickname'] ?: 'Moderator') ?></strong>
                    <?php if ($fullName !== '' && $fullName !== ($mod['nickname'] ?? '')): ?>
                      <span><?= htmlspecialchars($fullName) ?></span>
                    <?php endif; ?>
                    <span class="muted">#<?= $uid ?> · Moderator / Support</span>
                  </div>
                </td>
                <td>
                  <div class="dm-member-cell">
                    <strong><?= htmlspecialchars((string) ($mod['phone'] ?? '—')) ?></strong>
                    <?php if (!empty($mod['email'])): ?>
                      <span><?= htmlspecialchars((string) $mod['email']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="dm-member-cell">
                    <strong><?= htmlspecialchars($scope) ?></strong>
                    <span>Akarere desk</span>
                  </div>
                </td>
                <td><span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                <td><strong><?= (int) $mod['actions_30d'] ?></strong></td>
                <td><?= (int) $mod['moderated_30d'] ?></td>
                <td><?= (int) $mod['reports_30d'] ?></td>
                <td><small class="muted"><?= $updatedAt !== '' ? htmlspecialchars(date('d M Y H:i', strtotime((string) $updatedAt))) : '—' ?></small></td>
                <td class="portal-actions">
                  <?php if ($uid !== $selfId): ?>
                    <?= portalActionForm('set-status', ['user_id' => $uid, 'account_status' => 'active', 'return_pane' => 'moderators'], 'Activate', 'btn-sm ok') ?>
                    <?= portalActionForm('set-status', ['user_id' => $uid, 'account_status' => 'suspended', 'return_pane' => 'moderators'], 'Suspend', 'btn-sm danger') ?>
                  <?php else: ?>
                    <span class="muted">You</span>
                  <?php endif; ?>
                  <?php if ($isAdminPreview): ?>
                    <a class="btn-sm" href="<?= $navBase ?>?view_role=3&amp;view_district=<?= urlencode($district) ?>">Open desk</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </section>

  <section class="admin-pane" data-pane="reports" id="pane-reports">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Reports · <?= htmlspecialchars($district) ?></span>
      <h2>Local community reports</h2>
      <p class="admin-pane-sub">Queue <?= (int) $dmReportQueue ?> · Resolved <?= (int) $dmReportResolved ?> · Handled this month <?= (int) $dmReportHandledMonth ?></p>
    </header>

    <section class="panel dm-reports-stats">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="dm-reports-kpis" role="list">
        <div class="dm-reports-kpi<?= $dmReportOpen > 0 ? ' is-hot' : '' ?>" role="listitem">
          <span>Open</span><strong><?= (int) $dmReportOpen ?></strong>
        </div>
        <div class="dm-reports-kpi<?= $dmReportReviewing > 0 ? ' is-hot' : '' ?>" role="listitem">
          <span>Reviewing</span><strong><?= (int) $dmReportReviewing ?></strong>
        </div>
        <div class="dm-reports-kpi" role="listitem">
          <span>Queue</span><strong><?= (int) $dmReportQueue ?></strong>
        </div>
        <div class="dm-reports-kpi" role="listitem">
          <span>Resolved</span><strong><?= (int) $dmReportResolved ?></strong>
        </div>
        <div class="dm-reports-kpi" role="listitem">
          <span>Dismissed</span><strong><?= (int) $dmReportDismissed ?></strong>
        </div>
        <div class="dm-reports-kpi" role="listitem">
          <span>This month</span><strong><?= (int) $dmReportHandledMonth ?></strong>
        </div>
      </div>
    </section>

    <section class="panel dm-reports-charts">
      <header class="dm-reports-section-head">
        <h3>District report analytics</h3>
        <p>Status, target type, Items vs Jobs, reasons, and 6-month trend for <?= htmlspecialchars($district) ?>.</p>
      </header>
      <div class="dm-reports-chart-grid">
        <div class="dm-reports-chart-card">
          <h4>Status</h4>
          <div class="dm-reports-chart-wrap is-donut">
            <canvas id="dm-chart-report-status" aria-label="Reports by status"></canvas>
          </div>
        </div>
        <div class="dm-reports-chart-card">
          <h4>Target</h4>
          <div class="dm-reports-chart-wrap is-donut">
            <canvas id="dm-chart-report-target" aria-label="Reports by target"></canvas>
          </div>
        </div>
        <div class="dm-reports-chart-card">
          <h4>Items vs Jobs</h4>
          <div class="dm-reports-chart-wrap is-donut">
            <canvas id="dm-chart-report-biz" aria-label="Item vs Job reports"></canvas>
          </div>
        </div>
        <div class="dm-reports-chart-card is-wide">
          <h4>6-month trend</h4>
          <div class="dm-reports-chart-wrap">
            <canvas id="dm-chart-report-trend" aria-label="Reports trend"></canvas>
          </div>
        </div>
        <div class="dm-reports-chart-card is-full">
          <h4>Top reasons</h4>
          <div class="dm-reports-chart-wrap is-bar">
            <canvas id="dm-chart-report-reasons" aria-label="Top report reasons"></canvas>
          </div>
        </div>
      </div>
      <script type="application/json" id="dm-reports-charts-data"><?= json_encode($dmReportCharts, JSON_UNESCAPED_UNICODE) ?></script>
    </section>

    <section class="panel dm-reports-table-panel">
      <header class="dm-reports-section-head">
        <div>
          <h3>Open queue</h3>
          <p>Reports waiting in <?= htmlspecialchars($district) ?>. Mark Reviewing while you check, then Resolve or Dismiss.</p>
        </div>
        <span class="dm-reports-count"><?= count($openReports) ?> shown</span>
      </header>

      <?php if (!$openReports): ?>
        <p class="hint dm-reports-empty">No open local reports. Queue is clear.</p>
      <?php else: ?>
      <div class="table-wrap dm-reports-table-wrap">
        <table class="dm-reports-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Status</th>
              <th>Target</th>
              <th>Reason</th>
              <th>Details</th>
              <th>Reporter</th>
              <th>Date</th>
              <th>Handle</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($openReports as $r):
            $targetType = (string) ($r['target_type'] ?? '');
            $biz = (string) ($r['business_type'] ?? '');
            if ($targetType === 'listing' && $biz !== 'item' && $biz !== 'job') {
                $cat = (int) ($r['category_id'] ?? 0);
                $biz = $cat === 9 ? 'job' : 'item';
            }
            if ($targetType === 'listing') {
              $targetLabel = ($biz === 'job' ? 'Job' : 'Item') . ' #' . (int) $r['target_id'];
              $targetTitle = (string) ($r['listing_title'] ?: 'Listing');
            } elseif ($targetType === 'user') {
              $targetLabel = 'Member #' . (int) $r['target_id'];
              $targetTitle = (string) ($r['user_nickname'] ?: ($r['user_phone'] ?: 'Member'));
            } else {
              $targetLabel = ucfirst($targetType ?: 'Target') . ' #' . (int) $r['target_id'];
              $targetTitle = 'Chat / other';
            }
            $status = (string) ($r['status'] ?? 'open');
            $statusClass = match ($status) {
                'reviewing' => 'is-review',
                'resolved' => 'is-ok',
                'dismissed' => 'is-muted',
                default => 'is-open',
            };
          ?>
            <tr>
              <td><strong>#<?= (int) $r['id'] ?></strong></td>
              <td><span class="dm-report-status <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
              <td>
                <div class="dm-report-target">
                  <strong><?= htmlspecialchars($targetLabel) ?></strong>
                  <span><?= htmlspecialchars(mb_strimwidth($targetTitle, 0, 42, '…')) ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars((string) ($r['reason'] ?? '—')) ?></td>
              <td><small class="muted"><?= htmlspecialchars(mb_strimwidth((string) ($r['details'] ?? '—'), 0, 72, '…')) ?></small></td>
              <td>
                <div class="dm-report-target">
                  <strong><?= htmlspecialchars((string) ($r['reporter_name'] ?: 'Member')) ?></strong>
                  <span><?= htmlspecialchars((string) ($r['reporter_phone'] ?: '—')) ?></span>
                </div>
              </td>
              <td><small class="muted"><?= !empty($r['created_at']) ? htmlspecialchars(date('d M Y H:i', strtotime((string) $r['created_at']))) : '—' ?></small></td>
              <td class="portal-actions">
                <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'reviewing', 'return_pane' => 'reports'], 'Reviewing', 'btn-sm warn') ?>
                <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'resolved', 'return_pane' => 'reports'], 'Resolve', 'btn-sm ok') ?>
                <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'dismissed', 'return_pane' => 'reports'], 'Dismiss', 'btn-sm') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($closedReports): ?>
    <section class="panel dm-reports-table-panel">
      <header class="dm-reports-section-head">
        <div>
          <h3>Recently handled</h3>
          <p>Resolved or dismissed in <?= htmlspecialchars($district) ?>.</p>
        </div>
        <span class="dm-reports-count"><?= count($closedReports) ?> recent</span>
      </header>
      <div class="table-wrap dm-reports-table-wrap">
        <table class="dm-reports-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Status</th>
              <th>Target</th>
              <th>Reason</th>
              <th>Closed</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($closedReports as $r):
            $targetType = (string) ($r['target_type'] ?? '');
            if ($targetType === 'listing') {
              $biz = (($r['business_type'] ?? '') === 'job') ? 'Job' : 'Item';
              $targetLabel = $biz . ' #' . (int) $r['target_id'];
              $targetTitle = (string) ($r['listing_title'] ?: 'Listing');
            } elseif ($targetType === 'user') {
              $targetLabel = 'Member #' . (int) $r['target_id'];
              $targetTitle = (string) ($r['user_nickname'] ?: 'Member');
            } else {
              $targetLabel = ucfirst($targetType) . ' #' . (int) $r['target_id'];
              $targetTitle = '—';
            }
            $status = (string) ($r['status'] ?? '');
            $statusClass = $status === 'resolved' ? 'is-ok' : 'is-muted';
            $closedAt = $r['updated_at'] ?? $r['created_at'] ?? '';
          ?>
            <tr>
              <td><strong>#<?= (int) $r['id'] ?></strong></td>
              <td><span class="dm-report-status <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
              <td>
                <div class="dm-report-target">
                  <strong><?= htmlspecialchars($targetLabel) ?></strong>
                  <span><?= htmlspecialchars(mb_strimwidth($targetTitle, 0, 42, '…')) ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars((string) ($r['reason'] ?? '—')) ?></td>
              <td><small class="muted"><?= $closedAt !== '' ? htmlspecialchars(date('d M Y H:i', strtotime((string) $closedAt))) : '—' ?></small></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  </section>

  <section class="admin-pane" data-pane="escalate" id="pane-escalate">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Boundaries · <?= htmlspecialchars($district) ?></span>
      <h2>Escalate to Admin</h2>
      <p class="admin-pane-sub">Hard limits for this Akarere, then the ban handoff to Admin.</p>
    </header>

    <div class="dm-bound">
      <section class="dm-limits-strip" aria-label="Blocked powers">
        <header class="dm-limits-strip-head">
          <span class="dm-bound-badge is-block">Blocked</span>
          <h3>Hard limits</h3>
        </header>
        <div class="dm-limits-grid">
          <article class="dm-limit-item">
            <strong>Admin accounts</strong>
            <span>Cannot change</span>
          </article>
          <article class="dm-limit-item">
            <strong>Other District Managers</strong>
            <span>Outside your Akarere</span>
          </article>
          <article class="dm-limit-item">
            <strong>Ban power</strong>
            <span>Admin only · escalate below</span>
          </article>
        </div>
      </section>

      <section class="dm-escalate-board panel" id="ban-escalate">
        <div class="rw-flag-bar thin" aria-hidden="true">
          <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
        </div>
        <header class="dm-escalate-head">
          <div>
            <span class="dm-bound-badge is-escalate">Escalate</span>
            <h3>Ban requests → Admin</h3>
            <p>
              Fraud or severe abuse in <?= htmlspecialchars($district) ?>:
              suspend first, collect details, then Admin decides the nationwide ban.
            </p>
          </div>
        </header>

        <div class="table-wrap dm-escalate-table-wrap">
          <table class="dm-escalate-table">
            <thead>
              <tr>
                <th>Step</th>
                <th>Action</th>
                <th>What to do</th>
                <th>Send / need</th>
                <th>Access</th>
              </tr>
            </thead>
            <tbody>
              <tr class="is-step-1">
                <td><span class="dm-escalate-step">1</span></td>
                <td>
                  <strong>Suspend first</strong>
                  <span class="dm-escalate-owner">District Manager</span>
                </td>
                <td>Open Members in <?= htmlspecialchars($district) ?> and suspend the account immediately.</td>
                <td>
                  <ul class="dm-escalate-need">
                    <li>Find the member</li>
                    <li>Click Suspend</li>
                  </ul>
                </td>
                <td>
                  <a class="dm-escalate-btn" href="<?= htmlspecialchars($dmNavHref('members')) ?>" data-open="members">Open Members →</a>
                </td>
              </tr>
              <tr class="is-step-2">
                <td><span class="dm-escalate-step">2</span></td>
                <td>
                  <strong>Collect details</strong>
                  <span class="dm-escalate-owner">District Manager</span>
                </td>
                <td>Copy the facts Admin needs before a nationwide ban.</td>
                <td>
                  <ul class="dm-escalate-need">
                    <li>Member ID (#)</li>
                    <li>Phone number</li>
                    <li>Clear reason</li>
                  </ul>
                </td>
                <td>
                  <a class="dm-escalate-btn is-soft" href="<?= htmlspecialchars($dmNavHref('members')) ?>" data-open="members">Get ID &amp; phone →</a>
                </td>
              </tr>
              <tr class="is-step-3">
                <td><span class="dm-escalate-step">3</span></td>
                <td>
                  <strong>Admin decides</strong>
                  <span class="dm-escalate-owner">Admin only</span>
                </td>
                <td>Admin reviews your request and applies the ban nationwide if needed.</td>
                <td>
                  <ul class="dm-escalate-need">
                    <li>Your report</li>
                    <li>Evidence / reason</li>
                    <li>Final ban decision</li>
                  </ul>
                </td>
                <td>
                  <span class="dm-escalate-wait">Waiting on Admin</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </section>
</div>

<script>
(function () {
  var shell = document.getElementById('dm-shell');
  if (!shell) return;
  var alias = {
    home: 'home',
    checklist: 'checklist',
    'item-approvals': 'item-approvals',
    'job-approvals': 'job-approvals',
    listings: 'item-approvals',
    members: 'members',
    users: 'members',
    'id-queue': 'id-queue',
    moderators: 'moderators',
    reports: 'reports',
    escalate: 'escalate'
  };
  // Scope stack per district so preview/session Back does not mix routes.
  var stackKey = 'gugu_dm_nav_stack_v2_' + (shell.getAttribute('data-view-district') || 'self');
  function readStack() {
    try {
      var s = JSON.parse(sessionStorage.getItem(stackKey) || '[]');
      return Array.isArray(s) ? s.filter(function (p) { return !!alias[p]; }) : [];
    } catch (e) { return []; }
  }
  function writeStack(s) {
    try { sessionStorage.setItem(stackKey, JSON.stringify(s)); } catch (e) {}
  }
  function currentPaneKey() {
    try {
      var p = new URLSearchParams(location.search).get('pane');
      if (p && alias[p]) return alias[p];
    } catch (e) {}
    var h = (location.hash || '').replace(/^#/, '');
    return alias[h] || 'home';
  }
  function applyPreviewParams(u) {
    // Always keep Admin locked on District Manager until exit_preview.
    if (shell.getAttribute('data-preview') === '1') {
      u.searchParams.set('view_role', shell.getAttribute('data-view-role') || '2');
      var vd = shell.getAttribute('data-view-district') || '';
      if (vd) u.searchParams.set('view_district', vd);
      u.searchParams.delete('exit_preview');
    }
    return u;
  }
  function setUrl(pane) {
    try {
      var u = applyPreviewParams(new URL(location.href));
      if (pane === 'home') u.searchParams.delete('pane');
      else u.searchParams.set('pane', pane);
      history.replaceState({ dmPane: pane }, '', u.pathname + u.search);
    } catch (e) {}
  }
  function showPane(key) {
    var panes = shell.querySelectorAll('.admin-pane');
    for (var i = 0; i < panes.length; i++) {
      panes[i].classList.toggle('is-active', panes[i].getAttribute('data-pane') === key);
    }
    try {
      shell.scrollTop = 0;
      var main = document.querySelector('.main-content');
      if (main) main.scrollTop = 0;
      window.scrollTo(0, 0);
    } catch (e) {}
    if (key === 'reports') setTimeout(initDmReportCharts, 40);
  }
  function openPane(name, opts) {
    opts = opts || {};
    var key = alias[name] || 'home';

    if (opts.initial) {
      // Rebuild from URL on every full load so Back always returns to DM home.
      writeStack(key === 'home' ? ['home'] : ['home', key]);
      showPane(key);
      setUrl(key);
      return;
    }

    if (opts.back) {
      showPane(key);
      setUrl(key);
      return;
    }

    var stack = readStack();
    if (!stack.length || stack[0] !== 'home') stack = ['home'];
    if (key === 'home') {
      writeStack(['home']);
    } else {
      var top = stack[stack.length - 1];
      if (top !== key) {
        stack.push(key);
        writeStack(stack);
      } else {
        writeStack(stack);
      }
    }
    showPane(key);
    setUrl(key);
  }
  shell.addEventListener('click', function (e) {
    var back = e.target.closest('[data-back]');
    if (back && shell.contains(back)) {
      e.preventDefault();
      e.stopPropagation();
      var stack = readStack();
      if (stack.length > 1) {
        stack.pop();
        writeStack(stack.length ? stack : ['home']);
        openPane(stack.length ? stack[stack.length - 1] : 'home', { back: true });
      } else {
        writeStack(['home']);
        openPane('home', { back: true });
      }
      return;
    }
    var btn = e.target.closest('[data-open]');
    if (!btn || !shell.contains(btn)) return;
    e.preventDefault();
    e.stopPropagation();
    openPane(btn.getAttribute('data-open'));
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    // Card clicks are handled above; avoid double open / URL corruption.
    if (a.hasAttribute('data-open') && shell.contains(a)) return;

    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#') {
      var hashPane = href.replace(/^#/, '');
      if (alias[hashPane]) {
        e.preventDefault();
        openPane(hashPane);
      }
      return;
    }

    try {
      var linkUrl = new URL(href, location.origin);
      if (linkUrl.pathname.indexOf('/admin/dashboard.php') === -1) return;

      // Explicit Admin exit only — never drop preview otherwise.
      if (linkUrl.searchParams.has('exit_preview')) return;

      var vr = linkUrl.searchParams.get('view_role');
      // Switching to Moderator desk is intentional (still not Admin).
      if (vr === '3') return;

      // While Admin previews DM, rewrite any bare dashboard link back into DM session.
      if (shell.getAttribute('data-preview') === '1') {
        var forceVr = shell.getAttribute('data-view-role') || '2';
        var forceVd = shell.getAttribute('data-view-district') || '';
        if (!vr || vr === '2' || vr === '1') {
          linkUrl.searchParams.set('view_role', forceVr);
          if (forceVd) linkUrl.searchParams.set('view_district', forceVd);
          linkUrl.searchParams.delete('exit_preview');
          var pane = linkUrl.searchParams.get('pane') || 'home';
          if (alias[pane]) {
            e.preventDefault();
            openPane(pane);
            return;
          }
          // Non-DM pane while previewing — stay on DM home instead of Admin.
          e.preventDefault();
          openPane('home');
          return;
        }
      }

      var paneStay = linkUrl.searchParams.get('pane') || 'home';
      if (!alias[paneStay]) return;
      e.preventDefault();
      openPane(paneStay);
    } catch (err) {}
  });
  openPane(currentPaneKey(), { initial: true });

  var alertsBtn = document.getElementById('dm-order-alerts');
  if (alertsBtn) {
    alertsBtn.addEventListener('click', function () {
      var bell = document.getElementById('pay-bell-toggle');
      if (bell) bell.click();
    });
  }

  var dmReportCharts = { status: null, target: null, biz: null, trend: null, reasons: null };
  function destroyDmReportCharts() {
    Object.keys(dmReportCharts).forEach(function (k) {
      if (dmReportCharts[k]) {
        dmReportCharts[k].destroy();
        dmReportCharts[k] = null;
      }
    });
  }
  function initDmReportCharts() {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('dm-reports-charts-data');
    if (!el) return;
    var data;
    try { data = JSON.parse(el.textContent || '{}'); } catch (e) { return; }
    destroyDmReportCharts();

    var green = '#145A32';
    var green2 = '#1F7A45';
    var green3 = '#2E8B57';
    var green4 = '#7BC49A';
    var yellow = '#C99600';
    var ink = '#0F3D28';
    var grid = 'rgba(32, 96, 61, 0.12)';
    var donutOpts = {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, color: ink, font: { weight: '600', size: 11 } } }
      }
    };

    var statusCtx = document.getElementById('dm-chart-report-status');
    if (statusCtx) {
      dmReportCharts.status = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: (data.status && data.status.labels) || [],
          datasets: [{
            data: (data.status && data.status.data) || [],
            backgroundColor: [yellow, green3, green, green4],
            borderWidth: 0,
            hoverOffset: 4
          }]
        },
        options: donutOpts
      });
    }

    var targetCtx = document.getElementById('dm-chart-report-target');
    if (targetCtx) {
      dmReportCharts.target = new Chart(targetCtx, {
        type: 'doughnut',
        data: {
          labels: (data.target && data.target.labels) || [],
          datasets: [{
            data: (data.target && data.target.data) || [],
            backgroundColor: [green, green2, green4],
            borderWidth: 0,
            hoverOffset: 4
          }]
        },
        options: donutOpts
      });
    }

    var bizCtx = document.getElementById('dm-chart-report-biz');
    if (bizCtx) {
      dmReportCharts.biz = new Chart(bizCtx, {
        type: 'doughnut',
        data: {
          labels: (data.biz && data.biz.labels) || [],
          datasets: [{
            data: (data.biz && data.biz.data) || [],
            backgroundColor: ['#00A1DE', green],
            borderWidth: 0,
            hoverOffset: 4
          }]
        },
        options: donutOpts
      });
    }

    var trendCtx = document.getElementById('dm-chart-report-trend');
    if (trendCtx) {
      dmReportCharts.trend = new Chart(trendCtx, {
        type: 'line',
        data: {
          labels: (data.trend && data.trend.labels) || [],
          datasets: [
            {
              label: 'New reports',
              data: (data.trend && data.trend.created) || [],
              borderColor: yellow,
              backgroundColor: 'rgba(201, 150, 0, 0.15)',
              tension: 0.35,
              fill: true,
              pointRadius: 3
            },
            {
              label: 'Handled',
              data: (data.trend && data.trend.closed) || [],
              borderColor: green,
              backgroundColor: 'rgba(20, 90, 50, 0.12)',
              tension: 0.35,
              fill: true,
              pointRadius: 3
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, color: ink, font: { weight: '600', size: 11 } } }
          },
          scales: {
            x: { ticks: { color: ink, font: { size: 10 } }, grid: { color: grid } },
            y: { beginAtZero: true, ticks: { precision: 0, color: ink, font: { size: 10 } }, grid: { color: grid } }
          }
        }
      });
    }

    var reasonCtx = document.getElementById('dm-chart-report-reasons');
    if (reasonCtx) {
      var reasonLabels = (data.reasons && data.reasons.labels) || [];
      var reasonData = (data.reasons && data.reasons.data) || [];
      if (!reasonLabels.length) {
        reasonLabels = ['No data yet'];
        reasonData = [0];
      }
      dmReportCharts.reasons = new Chart(reasonCtx, {
        type: 'bar',
        data: {
          labels: reasonLabels,
          datasets: [{
            label: 'Reports',
            data: reasonData,
            backgroundColor: green2,
            borderRadius: 8,
            maxBarThickness: 36
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { ticks: { color: ink, font: { size: 10 } }, grid: { display: false } },
            y: { beginAtZero: true, ticks: { precision: 0, color: ink, font: { size: 10 } }, grid: { color: grid } }
          }
        }
      });
    }
  }
})();
</script>
