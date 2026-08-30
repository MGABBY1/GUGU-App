<?php
/**
 * Role 3 · Moderator / Support — Trust & Safety Desk
 * Card home + activity panes (queue, IDs, reports, checklist).
 */
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/management_roles.php';

$db = getDB();
$mgmt = guguManagementRoles()[3];
$district = !empty($portal_view_district)
  ? $portal_view_district
  : ($_SESSION['admin_district'] ?: ($_SESSION['district'] ?? 'Gasabo'));

$isAdminPreview = ((int) ($portal_actual_role_id ?? 0) === 1);
$navBase = '/gugu-app/admin/dashboard.php';
$navQs = '';
if ($isAdminPreview) {
  $navQs = 'view_role=3&view_district=' . rawurlencode($district) . '&';
}
$tsNavHref = static function (string $pane) use ($navBase, $navQs): string {
  $pane = trim($pane);
  if ($pane === '' || $pane === 'home') {
    return $navBase . ($navQs !== '' ? ('?' . rtrim($navQs, '&')) : '');
  }
  return $navBase . '?' . $navQs . 'pane=' . rawurlencode($pane);
};

// Keep business_type aligned for this district (items vs jobs)
try {
  $jobCat = guguJobCategoryId();
  if ($jobCat > 0) {
    $db->prepare('UPDATE listings SET business_type = "job" WHERE district = ? AND category_id = ? AND business_type <> "job"')
       ->execute([$district, $jobCat]);
    $db->prepare('UPDATE listings SET business_type = "item" WHERE district = ? AND (category_id IS NULL OR category_id <> ?) AND business_type <> "item"')
       ->execute([$district, $jobCat]);
  }
} catch (Throwable $e) {
  // older DB without business_type — ignore
}

$countListing = static function (string $where, array $extra = []) use ($db, $district): int {
  $params = array_merge([$district], $extra);
  $stmt = $db->prepare("SELECT COUNT(*) FROM listings WHERE district = ? AND {$where}");
  $stmt->execute($params);
  return (int) $stmt->fetchColumn();
};

$review = $countListing('moderation_status IN ("pending","flagged")');
$itemReview = $countListing('moderation_status IN ("pending","flagged") AND business_type = "item"');
$jobReview = $countListing('moderation_status IN ("pending","flagged") AND business_type = "job"');
$flagged = $countListing('moderation_status = "flagged"');
$active = $countListing('status = "active" AND moderation_status = "approved"');
$paidPending = $countListing('moderation_status = "pending" AND payment_status = "paid"');
$unpaidPending = $countListing('moderation_status IN ("pending","flagged") AND payment_status = "unpaid"');
$checklistScope = $district;
$checklistRole = 3;

$stmt = $db->prepare('
  SELECT l.id, l.title, l.district, l.sector, l.moderation_status, l.payment_status,
         l.announce_fee_rwf, l.user_id, l.business_type, l.category_id, l.created_at,
         u.nickname, u.email, u.phone
  FROM listings l
  JOIN users u ON u.id = l.user_id
  WHERE l.moderation_status IN ("pending","flagged") AND l.district = ?
  ORDER BY FIELD(l.moderation_status, "flagged", "pending"), l.created_at DESC
  LIMIT 60
');
$stmt->execute([$district]);
$queue = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmt = $db->prepare('
  SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status, r.created_at,
         COALESCE(l.district, u.district) AS place_district,
         COALESCE(u.nickname, l.title) AS target_label,
         u.email AS member_email,
         u.phone AS member_phone
  FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  WHERE r.status IN ("open","reviewing") AND (l.district = ? OR u.district = ?)
  ORDER BY r.created_at DESC
  LIMIT 40
');
$stmt->execute([$district, $district]);
$openReports = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$reports = count($openReports);

$idData = portalIdVerificationData($db, $district);
$idPending = (int) $idData['pending'];
$idApproved = (int) $idData['approved'];
$idQueue = $idData['queue'];
?>
<div class="admin-shell ts-shell" id="ts-shell"
     data-preview="<?= $isAdminPreview ? '1' : '0' ?>"
     data-view-role="<?= $isAdminPreview ? '3' : '' ?>"
     data-view-district="<?= htmlspecialchars($district, ENT_QUOTES) ?>">

  <!-- HOME -->
  <section class="admin-pane is-active" data-pane="home" id="home">
    <section class="panel portal-hero portal-hero-support admin-owner-hero">
      <div class="rw-flag-bar" aria-hidden="true">
        <span class="rw-blue"></span>
        <span class="rw-yellow"></span>
        <span class="rw-green"></span>
      </div>
      <div class="admin-owner-hero-inner">
        <div class="portal-hero-text">
          <span class="portal-kicker"><?= htmlspecialchars($mgmt['kicker']) ?></span>
          <h2>Trust &amp; Safety · <?= htmlspecialchars($district) ?></h2>
          <p>Keep <?= htmlspecialchars($district) ?> safe — queue, IDs, reports, and fraud.</p>
        </div>
        <div class="admin-kpi-strip" role="list">
          <div class="admin-kpi<?= $review > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Queue</span>
            <strong class="admin-kpi-value"><?= $review ?></strong>
          </div>
          <div class="admin-kpi<?= $idPending > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">ID pending</span>
            <strong class="admin-kpi-value"><?= $idPending ?></strong>
          </div>
          <div class="admin-kpi<?= $reports > 0 ? ' is-alert' : '' ?>" role="listitem">
            <span class="admin-kpi-label">Reports</span>
            <strong class="admin-kpi-value"><?= $reports ?></strong>
          </div>
          <div class="admin-kpi" role="listitem">
            <span class="admin-kpi-label">Live posts</span>
            <strong class="admin-kpi-value"><?= $active ?></strong>
          </div>
        </div>
      </div>
    </section>

    <section class="dm-board ts-board" aria-label="Trust & Safety duties">
      <header class="dm-board-head">
        <span class="dm-board-badge is-allow">Your desk</span>
        <h3>Open a card · <?= htmlspecialchars($district) ?></h3>
      </header>
      <div class="dm-duty-grid ts-duty-grid">
        <article class="dm-duty-card is-scope ts-card-scope">
          <span class="dm-duty-label">Scope</span>
          <h4><?= htmlspecialchars($district) ?></h4>
          <p>Trust &amp; Safety work stays in this Akarere only.</p>
        </article>

        <a class="dm-duty-card<?= $review > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings">
          <span class="dm-duty-label">Moderation queue</span>
          <h4>Review posts</h4>
          <p>Mark paid · Approve · Flag · Reject spam</p>
          <div class="dm-duty-meta">
            <span><?= $review ?> waiting</span>
            <span><?= $itemReview ?> items</span>
            <span><?= $jobReview ?> jobs</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $unpaidPending > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings">
          <span class="dm-duty-label">Payments</span>
          <h4>Confirm MoMo</h4>
          <p>Check payment then Mark paid on the queue.</p>
          <div class="dm-duty-meta">
            <span><?= $unpaidPending ?> unpaid</span>
            <span><?= $paidPending ?> paid ready</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $idPending > 0 ? ' is-alert has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('id-queue')) ?>" data-open="id-queue">
          <span class="dm-duty-label">ID verification</span>
          <h4>Approve / reject IDs</h4>
          <p>Member national ID documents in <?= htmlspecialchars($district) ?>.</p>
          <div class="dm-duty-meta">
            <span><?= $idPending ?> pending</span>
            <span><?= $idApproved ?> approved</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $reports > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('reports')) ?>" data-open="reports">
          <span class="dm-duty-label">Reports</span>
          <h4>Tickets &amp; flags</h4>
          <p>Resolve or dismiss community reports.</p>
          <div class="dm-duty-meta">
            <span><?= $reports ?> open</span>
          </div>
        </a>

        <a class="dm-duty-card" href="<?= htmlspecialchars($tsNavHref('checklist')) ?>" data-open="checklist">
          <span class="dm-duty-label">Routine</span>
          <h4>Daily checklist</h4>
          <p>Queue → MoMo → Approve → Reports → IDs</p>
          <div class="dm-duty-meta">
            <span><?= $review ?> queue</span>
            <span><?= $flagged ?> flagged</span>
          </div>
        </a>

        <article class="dm-duty-card ts-card-limits">
          <span class="dm-duty-label">Limits</span>
          <h4>Members only</h4>
          <p>You may suspend / ban fraud members. Not Admin or District Manager accounts. No staff role changes.</p>
        </article>
      </div>
    </section>

    <section class="dm-board ts-board" aria-label="Daily order">
      <header class="dm-board-head">
        <span class="dm-board-badge is-order">Daily order</span>
        <h3>Work in this sequence</h3>
      </header>
      <div class="dm-order-grid ts-order-grid">
        <a class="dm-order-card" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings">
          <em>1</em>
          <strong>Queue</strong>
          <span>Needs review posts</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings">
          <em>2</em>
          <strong>MoMo</strong>
          <span>Mark paid when received</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings">
          <em>3</em>
          <strong>Approve</strong>
          <span>Safe posts go live</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings">
          <em>4</em>
          <strong>Reject</strong>
          <span>Spam / fake / scams</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($tsNavHref('reports')) ?>" data-open="reports">
          <em>5</em>
          <strong>Reports</strong>
          <span>Resolve or dismiss</span>
        </a>
        <a class="dm-order-card" href="<?= htmlspecialchars($tsNavHref('id-queue')) ?>" data-open="id-queue">
          <em>6</em>
          <strong>IDs</strong>
          <span>Approve or reject</span>
        </a>
      </div>
    </section>
  </section>

  <!-- CHECKLIST -->
  <section class="admin-pane" data-pane="checklist" id="pane-checklist">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Daily routine · <?= htmlspecialchars($district) ?></span>
      <h2>Trust &amp; Safety checklist</h2>
    </header>
    <?php require __DIR__ . '/../includes/daily_checklist.php'; ?>
  </section>

  <!-- QUEUE -->
  <section class="admin-pane" data-pane="listings" id="pane-listings">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Moderation · <?= htmlspecialchars($district) ?></span>
      <h2>Needs review queue</h2>
      <p class="admin-pane-sub">Items &amp; jobs waiting — Mark paid, Approve, Flag, Reject, or act on fraud.</p>
    </header>

    <div class="chips" style="margin-bottom:12px">
      <span class="chip <?= $review > 0 ? 'chip-yellow' : 'chip-green' ?>">Waiting · <?= $review ?></span>
      <span class="chip">Items · <?= $itemReview ?></span>
      <span class="chip">Jobs · <?= $jobReview ?></span>
      <span class="chip"><?= $unpaidPending ?> unpaid</span>
      <span class="chip"><?= $paidPending ?> paid ready</span>
    </div>

    <?php if (!$queue): ?>
      <section class="panel">
        <p class="hint">Queue empty — nothing waiting in <?= htmlspecialchars($district) ?>.</p>
      </section>
    <?php else: ?>
      <section class="panel">
        <div class="table-wrap"><table class="ts-queue-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Title</th>
              <th>Type</th>
              <th>Member</th>
              <th>Email</th>
              <th>Pay / Mod</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($queue as $l):
            $sellerEmail = trim((string) ($l['email'] ?? ''));
            $biz = (string) ($l['business_type'] ?? '');
            if ($biz !== 'item' && $biz !== 'job') {
              $biz = guguBusinessTypeFromCategory((int) ($l['category_id'] ?? 0));
            }
            $bizLabel = $biz === 'job' ? 'Job' : 'Item';
          ?>
            <tr>
              <td>#<?= (int) $l['id'] ?></td>
              <td>
                <strong><?= htmlspecialchars((string) $l['title']) ?></strong>
                <?php if (!empty($l['sector'])): ?>
                  <br><small class="muted"><?= htmlspecialchars((string) $l['sector']) ?></small>
                <?php endif; ?>
              </td>
              <td><span class="status-pill"><?= htmlspecialchars($bizLabel) ?></span></td>
              <td>
                <?= htmlspecialchars($l['nickname'] ?: '—') ?>
                <?php if (!empty($l['phone'])): ?>
                  <br><small class="muted"><?= htmlspecialchars((string) $l['phone']) ?></small>
                <?php endif; ?>
              </td>
              <td><?= $sellerEmail !== '' ? htmlspecialchars($sellerEmail) : '<span class="muted">—</span>' ?></td>
              <td>
                <span class="<?= htmlspecialchars(portalStatusPillClass((string) ($l['payment_status'] ?? 'unpaid'))) ?>"><?= htmlspecialchars((string) ($l['payment_status'] ?? 'unpaid')) ?></span>
                <br><span class="<?= htmlspecialchars(portalStatusPillClass((string) $l['moderation_status'])) ?>"><?= htmlspecialchars((string) $l['moderation_status']) ?></span>
                <br><small class="muted"><?= (int) ($l['announce_fee_rwf'] ?? 1000) ?> RWF</small>
              </td>
              <td class="portal-actions">
                <?= portalActionForm('mark-listing-paid', ['listing_id' => $l['id'], 'payment_note' => 'MoMo received', 'return_pane' => 'listings'], 'Mark paid', 'btn-sm warn') ?>
                <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'approved', 'return_pane' => 'listings'], 'Approve', 'btn-sm ok') ?>
                <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'flagged', 'return_pane' => 'listings'], 'Flag', 'btn-sm warn') ?>
                <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'rejected', 'return_pane' => 'listings'], 'Reject', 'btn-sm danger') ?>
                <?= portalActionForm('suspend-seller', ['user_id' => $l['user_id'], 'return_pane' => 'listings'], 'Suspend', 'btn-sm danger') ?>
                <?= portalActionForm('ban-seller', ['user_id' => $l['user_id'], 'return_pane' => 'listings'], 'Ban fraud', 'btn-sm danger') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
    <?php endif; ?>
  </section>

  <!-- ID QUEUE -->
  <section class="admin-pane" data-pane="id-queue" id="pane-id-queue">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Member IDs · <?= htmlspecialchars($district) ?></span>
      <h2>ID verification</h2>
      <p class="admin-pane-sub">Approve clear documents · Reject unclear ones so members can resubmit.</p>
    </header>
    <?php portalRenderIdVerificationQueue($idData, $district); ?>
  </section>

  <!-- REPORTS -->
  <section class="admin-pane" data-pane="reports" id="pane-reports">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Support · <?= htmlspecialchars($district) ?></span>
      <h2>Tickets &amp; reports</h2>
      <p class="admin-pane-sub">Open community flags for listings or members in <?= htmlspecialchars($district) ?>.</p>
    </header>

    <?php if (!$openReports): ?>
      <section class="panel">
        <p class="hint">No open reports in <?= htmlspecialchars($district) ?>.</p>
      </section>
    <?php else: ?>
      <section class="panel">
        <div class="table-wrap"><table class="ts-queue-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Target</th>
              <th>Member email</th>
              <th>Reason</th>
              <th>Status</th>
              <th>Handle</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($openReports as $r):
            $memberEmail = trim((string) ($r['member_email'] ?? ''));
            $targetLabel = trim((string) ($r['target_label'] ?? ''));
          ?>
            <tr>
              <td>#<?= (int) $r['id'] ?></td>
              <td>
                <strong><?= htmlspecialchars((string) ($r['target_type'] ?? '') . ' #' . (int) ($r['target_id'] ?? 0)) ?></strong>
                <?php if ($targetLabel !== ''): ?>
                  <br><small class="muted"><?= htmlspecialchars($targetLabel) ?></small>
                <?php endif; ?>
                <?php if (!empty($r['member_phone'])): ?>
                  <br><small class="muted"><?= htmlspecialchars((string) $r['member_phone']) ?></small>
                <?php endif; ?>
              </td>
              <td><?= $memberEmail !== '' ? htmlspecialchars($memberEmail) : '<span class="muted">—</span>' ?></td>
              <td>
                <?= htmlspecialchars((string) ($r['reason'] ?? '')) ?>
                <?php if (!empty($r['details'])): ?>
                  <br><small class="muted"><?= htmlspecialchars((string) $r['details']) ?></small>
                <?php endif; ?>
              </td>
              <td><span class="<?= htmlspecialchars(portalStatusPillClass((string) ($r['status'] ?? 'open'))) ?>"><?= htmlspecialchars((string) ($r['status'] ?? 'open')) ?></span></td>
              <td class="portal-actions">
                <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'reviewing', 'return_pane' => 'reports'], 'Reviewing', 'btn-sm warn') ?>
                <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'resolved', 'return_pane' => 'reports'], 'Resolve', 'btn-sm ok') ?>
                <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'dismissed', 'return_pane' => 'reports'], 'Dismiss', 'btn-sm') ?>
                <?php if (($r['target_type'] ?? '') === 'user'): ?>
                  <?= portalActionForm('ban-seller', ['user_id' => $r['target_id'], 'return_pane' => 'reports'], 'Ban user', 'btn-sm danger') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  var shell = document.getElementById('ts-shell');
  if (!shell) return;
  var alias = {
    home: 'home',
    checklist: 'checklist',
    listings: 'listings',
    'item-approvals': 'listings',
    'job-approvals': 'listings',
    payments: 'listings',
    'id-queue': 'id-queue',
    reports: 'reports'
  };
  // Scope stack per district so preview / Back does not mix routes.
  var stackKey = 'gugu_ts_nav_stack_v2_' + (shell.getAttribute('data-view-district') || 'self');
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
    if (shell.getAttribute('data-preview') === '1') {
      u.searchParams.set('view_role', shell.getAttribute('data-view-role') || '3');
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
      u.hash = '';
      history.replaceState({ tsPane: pane }, '', u.pathname + u.search);
    } catch (e) {}
  }
  function showPane(key) {
    key = alias[key] || key || 'home';
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
  }
  function openPane(name, opts) {
    opts = opts || {};
    var key = alias[name] || 'home';

    if (key !== 'checklist' && typeof closeAllChecklistExpands === 'function') {
      closeAllChecklistExpands();
    }

    if (opts.initial) {
      // Rebuild from URL on every full load so Back always returns to home first.
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
      if (top !== key) stack.push(key);
      if (stack.length > 12) stack = stack.slice(-12);
      writeStack(stack);
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
    var open = e.target.closest('[data-open]');
    if (open && shell.contains(open)) {
      e.preventDefault();
      e.stopPropagation();
      openPane(open.getAttribute('data-open') || 'home');
    }
  });

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
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
      if (linkUrl.searchParams.has('exit_preview')) return;

      var vr = linkUrl.searchParams.get('view_role');
      if (vr === '2') return;

      if (shell.getAttribute('data-preview') === '1') {
        var forceVr = shell.getAttribute('data-view-role') || '3';
        var forceVd = shell.getAttribute('data-view-district') || '';
        if (!vr || vr === '3' || vr === '1') {
          linkUrl.searchParams.set('view_role', forceVr);
          if (forceVd) linkUrl.searchParams.set('view_district', forceVd);
          linkUrl.searchParams.delete('exit_preview');
          var pane = linkUrl.searchParams.get('pane') || 'home';
          if (alias[pane]) {
            e.preventDefault();
            openPane(pane);
            return;
          }
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

  // Checklist action → inline expand row in the table
  function closeAllChecklistExpands() {
    shell.querySelectorAll('.ts-checklist-expand-row').forEach(function (row) {
      row.hidden = true;
    });
    shell.querySelectorAll('[data-ts-expand]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('is-open');
    });
    shell.querySelectorAll('.ts-checklist-row.is-expanded').forEach(function (row) {
      row.classList.remove('is-expanded');
    });
  }
  function fillExpandBody(body, paneKey) {
    paneKey = alias[paneKey] || paneKey;
    if (!body || body.getAttribute('data-ts-filled') === paneKey) return;
    var pane = shell.querySelector('.admin-pane[data-pane="' + paneKey + '"]');
    if (!pane) return;
    body.innerHTML = '';
    var children = pane.children;
    for (var c = 0; c < children.length; c++) {
      if (children[c].classList && children[c].classList.contains('admin-pane-head')) continue;
      body.appendChild(children[c].cloneNode(true));
    }
    body.querySelectorAll('input[name="return_pane"]').forEach(function (inp) {
      inp.value = 'checklist';
    });
    body.setAttribute('data-ts-filled', paneKey);
  }
  function toggleChecklistExpand(btn) {
    var targetKey = btn.getAttribute('data-ts-expand-target') || '';
    var paneKey = btn.getAttribute('data-ts-expand') || '';
    var panel = document.getElementById(targetKey + '-panel');
    if (!panel) return;
    var isOpen = !panel.hidden;
    closeAllChecklistExpands();
    if (isOpen) return;
    var body = panel.querySelector('[data-ts-expand-body]');
    fillExpandBody(body, paneKey);
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('is-open');
    var mainRow = shell.querySelector('[data-checklist-row="' + targetKey + '"]');
    if (mainRow) mainRow.classList.add('is-expanded');
    try {
      panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } catch (e) {}
  }
  shell.addEventListener('click', function (e) {
    var expandBtn = e.target.closest('[data-ts-expand]');
    if (expandBtn && shell.contains(expandBtn)) {
      e.preventDefault();
      e.stopPropagation();
      toggleChecklistExpand(expandBtn);
      return;
    }
    var closeBtn = e.target.closest('[data-ts-expand-close]');
    if (closeBtn && shell.contains(closeBtn)) {
      e.preventDefault();
      closeAllChecklistExpands();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllChecklistExpands();
  });

  openPane(currentPaneKey(), { initial: true });
})();
</script>
