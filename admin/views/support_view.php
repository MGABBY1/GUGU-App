<?php
/**
 * Role 3 · Moderator / Support — Trust & Safety Desk
 * Card home + activity panes (queue, IDs, reports, checklist).
 */
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/management_roles.php';
require_once __DIR__ . '/../includes/support_desk.php';

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
$desk = portalSupportDeskData($db, $district);
$review = (int) $desk['review'];
$itemReview = (int) $desk['item_review'];
$jobReview = (int) $desk['job_review'];
$flagged = (int) $desk['flagged'];
$active = (int) $desk['active'];
$paidPending = (int) $desk['paid_pending'];
$unpaidPending = (int) $desk['unpaid_pending'];
$queue = $desk['queue'];
$openReports = $desk['open_reports'];
$reports = (int) $desk['reports'];
$idData = $desk['id_data'];
$idPending = (int) $desk['id_pending'];
$idApproved = (int) $desk['id_approved'];
$idQueue = $idData['queue'];
$checklistScope = $district;
$checklistRole = 3;
?>
<div class="admin-shell ts-shell" id="ts-shell"
     data-preview="<?= $isAdminPreview ? '1' : '0' ?>"
     data-view-role="<?= $isAdminPreview ? '3' : '' ?>"
     data-view-district="<?= htmlspecialchars($district, ENT_QUOTES) ?>">
  <div class="ts-live-sync" id="ts-live-sync" aria-live="polite" hidden>Live · synced</div>

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

        <a class="dm-duty-card<?= $review > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings" data-ts-duty="listings">
          <span class="dm-duty-label">Moderation queue</span>
          <h4>Review posts</h4>
          <p>Mark paid · Approve · Flag · Reject spam</p>
          <div class="dm-duty-meta">
            <span data-ts-meta="review-wait"><?= $review ?> waiting</span>
            <span data-ts-meta="review-items"><?= $itemReview ?> items</span>
            <span data-ts-meta="review-jobs"><?= $jobReview ?> jobs</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $unpaidPending > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('listings')) ?>" data-open="listings" data-ts-duty="payments">
          <span class="dm-duty-label">Payments</span>
          <h4>Confirm MoMo</h4>
          <p>Check payment then Mark paid on the queue.</p>
          <div class="dm-duty-meta">
            <span data-ts-meta="unpaid"><?= $unpaidPending ?> unpaid</span>
            <span data-ts-meta="paid-ready"><?= $paidPending ?> paid ready</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $idPending > 0 ? ' is-alert has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('id-queue')) ?>" data-open="id-queue" data-ts-duty="ids">
          <span class="dm-duty-label">ID verification</span>
          <h4>Approve / reject IDs</h4>
          <p>Member national ID documents in <?= htmlspecialchars($district) ?>.</p>
          <div class="dm-duty-meta">
            <span data-ts-meta="id-pending"><?= $idPending ?> pending</span>
            <span data-ts-meta="id-approved"><?= $idApproved ?> approved</span>
          </div>
        </a>

        <a class="dm-duty-card<?= $reports > 0 ? ' has-queue' : '' ?>" href="<?= htmlspecialchars($tsNavHref('reports')) ?>" data-open="reports" data-ts-duty="reports">
          <span class="dm-duty-label">Reports</span>
          <h4>Tickets &amp; flags</h4>
          <p>Resolve or dismiss community reports.</p>
          <div class="dm-duty-meta">
            <span data-ts-meta="reports-open"><?= $reports ?> open</span>
          </div>
        </a>

        <a class="dm-duty-card" href="<?= htmlspecialchars($tsNavHref('checklist')) ?>" data-open="checklist" data-ts-duty="checklist">
          <span class="dm-duty-label">Routine</span>
          <h4>Daily checklist</h4>
          <p>Queue → MoMo → Approve → Reports → IDs</p>
          <div class="dm-duty-meta">
            <span data-ts-meta="checklist-queue"><?= $review ?> queue</span>
            <span data-ts-meta="checklist-flagged"><?= $flagged ?> flagged</span>
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
  <section class="admin-pane" data-pane="listings" id="pane-listings">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Moderation · <?= htmlspecialchars($district) ?></span>
      <h2>Needs review queue</h2>
      <p class="admin-pane-sub">Items &amp; jobs waiting — Mark paid, Approve, Flag, Reject, or act on fraud.</p>
    </header>

    <div class="ts-live-pane-body" data-ts-live-view="queue" data-ts-return-pane="listings">
    <div class="chips" style="margin-bottom:12px">
      <span class="chip <?= $review > 0 ? 'chip-yellow' : 'chip-green' ?>">Waiting · <?= $review ?></span>
      <span class="chip">Items · <?= $itemReview ?></span>
      <span class="chip">Jobs · <?= $jobReview ?></span>
      <span class="chip"><?= $unpaidPending ?> unpaid</span>
      <span class="chip"><?= $paidPending ?> paid ready</span>
    </div>
    <?php portalSupportRenderQueueTable($queue, 'listings'); ?>
    </div>
  </section>

  <!-- ID QUEUE -->
  <section class="admin-pane" data-pane="id-queue" id="pane-id-queue">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Member IDs · <?= htmlspecialchars($district) ?></span>
      <h2>ID verification</h2>
      <p class="admin-pane-sub">Approve clear documents · Reject unclear ones so members can resubmit.</p>
    </header>
    <div class="ts-live-pane-body" data-ts-live-view="ids" data-ts-return-pane="id-queue">
    <?php portalRenderIdVerificationQueue($idData, $district, 'id-queue'); ?>
    </div>
  </section>

  <!-- REPORTS -->
  <section class="admin-pane" data-pane="reports" id="pane-reports">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-back="1">&larr; Back</button>
      <span class="admin-pane-kicker">Support · <?= htmlspecialchars($district) ?></span>
      <h2>Tickets &amp; reports</h2>
      <p class="admin-pane-sub">Open community flags for listings or members in <?= htmlspecialchars($district) ?>.</p>
    </header>

    <div class="ts-live-pane-body" data-ts-live-view="reports" data-ts-return-pane="reports">
    <?php portalSupportRenderReportsTable($openReports, $district, 'reports'); ?>
    </div>
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

  // Live DB sync — auto-refresh all Support desk UI (checklist, panes, home cards)
  var checklistRoot = document.getElementById('ts-checklist-root');
  var liveSyncEl = document.getElementById('ts-live-sync');
  var tsApiBase = '/gugu-app/admin/api/ts-desk.php';
  var tsLastStatsKey = '';
  var tsSyncBusy = false;
  var TS_SYNC_MS = 12000;

  function getActivePaneKey() {
    var p = shell.querySelector('.admin-pane.is-active');
    return p ? (p.getAttribute('data-pane') || 'home') : 'home';
  }
  function tsApiUrl(view, returnPane) {
    var u = new URL(tsApiBase, location.origin);
    u.searchParams.set('view', view || 'stats');
    u.searchParams.set('district', shell.getAttribute('data-view-district') || '');
    if (returnPane) u.searchParams.set('return_pane', returnPane);
    if (shell.getAttribute('data-preview') === '1') {
      u.searchParams.set('view_role', shell.getAttribute('data-view-role') || '3');
      u.searchParams.set('view_district', shell.getAttribute('data-view-district') || '');
    }
    return u.pathname + u.search;
  }
  function tsFetch(view, returnPane) {
    return fetch(tsApiUrl(view, returnPane), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); });
  }
  function tsStatsKey(stats) {
    if (!stats) return '';
    return [stats.review, stats.unpaid_pending, stats.paid_pending, stats.reports,
      stats.id_pending, stats.active, stats.flagged].join('|');
  }
  function showLiveSyncPulse(changed) {
    if (!liveSyncEl) return;
    liveSyncEl.hidden = false;
    liveSyncEl.textContent = changed ? 'Live · new updates' : 'Live · synced';
    liveSyncEl.classList.add('is-flash');
    setTimeout(function () { liveSyncEl.classList.remove('is-flash'); }, 1200);
  }
  function tsChipClass(kind, val) {
    if (kind === 'paid') return val > 0 ? 'is-warn' : 'is-ok';
    return val > 0 ? 'is-alert' : 'is-ok';
  }
  function applyHomeCards(stats) {
    var setMeta = function (sel, text) {
      var el = shell.querySelector('[data-ts-meta="' + sel + '"]');
      if (el) el.textContent = text;
    };
    setMeta('review-wait', (stats.review || 0) + ' waiting');
    setMeta('review-items', (stats.item_review || 0) + ' items');
    setMeta('review-jobs', (stats.job_review || 0) + ' jobs');
    setMeta('unpaid', (stats.unpaid_pending || 0) + ' unpaid');
    setMeta('paid-ready', (stats.paid_pending || 0) + ' paid ready');
    setMeta('id-pending', (stats.id_pending || 0) + ' pending');
    setMeta('id-approved', (stats.id_approved || 0) + ' approved');
    setMeta('reports-open', (stats.reports || 0) + ' open');
    setMeta('checklist-queue', (stats.review || 0) + ' queue');
    setMeta('checklist-flagged', (stats.flagged || 0) + ' flagged');
    var listingsCard = shell.querySelector('[data-ts-duty="listings"]');
    if (listingsCard) listingsCard.classList.toggle('has-queue', (stats.review || 0) > 0);
    var payCard = shell.querySelector('[data-ts-duty="payments"]');
    if (payCard) payCard.classList.toggle('has-queue', (stats.unpaid_pending || 0) > 0);
    var idCard = shell.querySelector('[data-ts-duty="ids"]');
    if (idCard) {
      idCard.classList.toggle('has-queue', (stats.id_pending || 0) > 0);
      idCard.classList.toggle('is-alert', (stats.id_pending || 0) > 0);
    }
    var repCard = shell.querySelector('[data-ts-duty="reports"]');
    if (repCard) repCard.classList.toggle('has-queue', (stats.reports || 0) > 0);
  }
  function applyChecklistPayload(data) {
    if (!data) return;
    var stats = data.stats || {};
    var progress = data.progress || {};
    applyHomeCards(stats);
    if (checklistRoot) {
      var chipMap = {
        review: stats.review || 0,
        unpaid: stats.unpaid_pending || 0,
        paid: stats.paid_pending || 0,
        reports: stats.reports || 0,
        id: stats.id_pending || 0
      };
      Object.keys(chipMap).forEach(function (key) {
        var chip = checklistRoot.querySelector('[data-ts-chip="' + key + '"]');
        if (!chip) return;
        chip.querySelector('strong').textContent = String(chipMap[key]);
        chip.classList.remove('is-ok', 'is-warn', 'is-alert');
        chip.classList.add(tsChipClass(key, chipMap[key]));
      });
      var progCount = document.getElementById('ts-checklist-progress-count');
      var progPct = document.getElementById('ts-checklist-progress-pct');
      var progFill = document.getElementById('ts-checklist-progress-fill');
      var progBar = document.getElementById('ts-checklist-progress-bar');
      if (progCount) progCount.textContent = (progress.done || 0) + '/' + (progress.total || 7);
      if (progPct) progPct.textContent = (progress.pct || 0) + '% clear';
      if (progFill) progFill.style.width = (progress.pct || 0) + '%';
      if (progBar) progBar.setAttribute('aria-valuenow', String(progress.pct || 0));
      var foot = document.getElementById('ts-checklist-foot');
      if (foot && data.foot) foot.textContent = data.foot;
      (data.rows || []).forEach(function (row) {
        var tr = checklistRoot.querySelector('[data-ts-step="' + row.num + '"]');
        if (!tr) return;
        tr.classList.toggle('is-done', !!row.done);
        tr.classList.toggle('is-todo', !row.done);
        var step = tr.querySelector('.ts-checklist-step');
        if (step) step.textContent = row.done ? '✓' : String(row.num);
        var detail = tr.querySelector('.ts-checklist-task p');
        if (detail && row.detail) detail.textContent = row.detail;
        var metricLabel = tr.querySelector('.ts-checklist-metric-label');
        if (metricLabel && row.metric_label) metricLabel.textContent = row.metric_label;
        var metric = tr.querySelector('.ts-checklist-metric strong');
        if (metric) metric.textContent = row.metric;
        var pill = tr.querySelector('.ts-checklist-status .ts-checklist-pill');
        if (pill) {
          pill.textContent = row.status;
          pill.className = 'ts-checklist-pill is-' + (row.status_class || 'ok');
        }
        var btn = tr.querySelector('[data-ts-expand-view]');
        if (btn && row.action_label) btn.textContent = row.action_label;
      });
    }
    shell.querySelectorAll('.admin-kpi').forEach(function (kpi) {
      var label = (kpi.querySelector('.admin-kpi-label') || {}).textContent || '';
      var val = kpi.querySelector('.admin-kpi-value');
      if (!val) return;
      if (label.indexOf('Queue') === 0) val.textContent = String(stats.review || 0);
      if (label.indexOf('ID pending') === 0) val.textContent = String(stats.id_pending || 0);
      if (label.indexOf('Reports') === 0) val.textContent = String(stats.reports || 0);
      if (label.indexOf('Live posts') === 0) val.textContent = String(stats.active || 0);
      kpi.classList.toggle('is-alert', (label.indexOf('Queue') === 0 && stats.review > 0)
        || (label.indexOf('ID pending') === 0 && stats.id_pending > 0)
        || (label.indexOf('Reports') === 0 && stats.reports > 0));
    });
    var newKey = tsStatsKey(stats);
    var changed = tsLastStatsKey !== '' && newKey !== tsLastStatsKey;
    tsLastStatsKey = newKey;
    showLiveSyncPulse(changed);
    return changed;
  }
  function refreshChecklistStats() {
    return tsFetch('stats').then(function (data) {
      if (data && data.ok) applyChecklistPayload(data);
      return data;
    }).catch(function () {});
  }
  function refreshLivePaneBody(liveEl, silent) {
    if (!liveEl) return Promise.resolve();
    var view = liveEl.getAttribute('data-ts-live-view') || '';
    var rp = liveEl.getAttribute('data-ts-return-pane') || '';
    if (!view) return Promise.resolve();
    if (!silent) liveEl.classList.add('is-loading');
    return tsFetch(view, rp).then(function (data) {
      if (data && data.ok && data.html !== undefined) {
        liveEl.innerHTML = data.html;
        applyChecklistPayload(data);
      }
    }).catch(function () {}).then(function () {
      liveEl.classList.remove('is-loading');
    });
  }
  function getOpenExpandBody() {
    var row = shell.querySelector('.ts-checklist-expand-row:not([hidden])');
    return row ? row.querySelector('[data-ts-expand-body]') : null;
  }
  function syncTsDesk(silent) {
    if (tsSyncBusy || document.hidden) return Promise.resolve();
    tsSyncBusy = true;
    var active = getActivePaneKey();
    var jobs = [refreshChecklistStats()];
    if (active === 'listings' || active === 'id-queue' || active === 'reports') {
      var pane = shell.querySelector('.admin-pane.is-active .ts-live-pane-body');
      jobs.push(refreshLivePaneBody(pane, !!silent));
    }
    var expandBody = getOpenExpandBody();
    if (expandBody) {
      var wrap = expandBody.closest('.ts-checklist-expand');
      var viewKey = wrap ? wrap.getAttribute('data-ts-expand-view') : '';
      if (viewKey) {
        jobs.push(tsFetch(viewKey, 'checklist').then(function (data) {
          if (data && data.ok && data.html !== undefined) {
            expandBody.innerHTML = data.html;
            applyChecklistPayload(data);
          }
        }));
      }
    }
    return Promise.all(jobs).finally(function () { tsSyncBusy = false; });
  }
  function closeAllChecklistExpands() {
    shell.querySelectorAll('.ts-checklist-expand-row').forEach(function (row) {
      row.hidden = true;
    });
    shell.querySelectorAll('[data-ts-expand-view]').forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('is-open');
    });
    shell.querySelectorAll('.ts-checklist-row.is-expanded').forEach(function (row) {
      row.classList.remove('is-expanded');
    });
  }
  function loadExpandBody(body, viewKey, silent) {
    if (!body) return Promise.resolve();
    if (!silent) body.innerHTML = '<p class="hint">Loading latest from database…</p>';
    return tsFetch(viewKey, 'checklist').then(function (data) {
      if (!data || !data.ok) throw new Error('load failed');
      body.innerHTML = data.html || '<p class="hint">Nothing to show.</p>';
      applyChecklistPayload(data);
    }).catch(function () {
      if (!silent) body.innerHTML = '<p class="hint">Could not load live data. Refresh the page and try again.</p>';
    });
  }
  function toggleChecklistExpand(btn) {
    var targetKey = btn.getAttribute('data-ts-expand-target') || '';
    var viewKey = btn.getAttribute('data-ts-expand-view') || '';
    var panel = document.getElementById(targetKey + '-panel');
    if (!panel) return;
    var isOpen = !panel.hidden;
    closeAllChecklistExpands();
    if (isOpen) return;
    var body = panel.querySelector('[data-ts-expand-body]');
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('is-open');
    var mainRow = shell.querySelector('[data-checklist-row="' + targetKey + '"]');
    if (mainRow) mainRow.classList.add('is-expanded');
    loadExpandBody(body, viewKey, false).then(function () {
      try { panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
    });
  }
  shell.addEventListener('click', function (e) {
    var expandBtn = e.target.closest('[data-ts-expand-view]');
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
      refreshChecklistStats();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllChecklistExpands();
      refreshChecklistStats();
    }
  });
  var origShowPane = showPane;
  showPane = function (key) {
    origShowPane(key);
    syncTsDesk(false);
  };
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) syncTsDesk(true);
  });
  setInterval(function () { syncTsDesk(true); }, TS_SYNC_MS);

  openPane(currentPaneKey(), { initial: true });
  syncTsDesk(false);
})();
</script>
