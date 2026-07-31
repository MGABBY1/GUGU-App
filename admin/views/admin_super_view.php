<?php
/**
 * Role 1 · Super Admin (platform owner) — Global
 * Focus: System Controls · Permission Controls · Global Financial Analytics · open any dashboard
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
$members = (int) $db->query('SELECT COUNT(*) FROM users WHERE role_id = 4')->fetchColumn();
$active = (int) $db->query('SELECT COUNT(*) FROM listings WHERE status="active" AND moderation_status="approved"')->fetchColumn();
$review = (int) $db->query('SELECT COUNT(*) FROM listings WHERE moderation_status IN ("pending","flagged")')->fetchColumn();
$reports = (int) $db->query('SELECT COUNT(*) FROM reports WHERE status IN ("open","reviewing")')->fetchColumn();
$staffCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE role_id BETWEEN 1 AND 3')->fetchColumn();
$byRole = $db->query('SELECT role_id, COUNT(*) c FROM users GROUP BY role_id ORDER BY role_id')->fetchAll();

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

$memberUsers = $db->query('
  SELECT id, nickname, phone, email, district, role_id, account_status, admin_district, id_status
  FROM users WHERE role_id = 4
  ORDER BY id DESC
  LIMIT 60
')->fetchAll();

$queue = $db->query('
  SELECT id, title, district, moderation_status, payment_status, announce_fee_rwf, user_id, created_at, category_id
  FROM listings
  WHERE moderation_status IN ("pending","flagged")
  ORDER BY created_at DESC
  LIMIT 40
')->fetchAll();
$paidPending = (int) $db->query('SELECT COUNT(*) FROM listings WHERE moderation_status="pending" AND payment_status="paid"')->fetchColumn();
$unpaidPending = (int) $db->query('SELECT COUNT(*) FROM listings WHERE moderation_status IN ("pending","flagged") AND payment_status="unpaid"')->fetchColumn();
$feeIncome = (int) $db->query('SELECT COALESCE(SUM(announce_fee_rwf),0) FROM listings WHERE payment_status="paid"')->fetchColumn();
$feeIncomeMonth = 0;
try {
    $feeIncomeMonth = (int) $db->query('SELECT COALESCE(SUM(announce_fee_rwf),0) FROM listings WHERE payment_status="paid" AND COALESCE(updated_at, created_at) >= DATE_FORMAT(NOW(), "%Y-%m-01")')->fetchColumn();
} catch (Throwable $e) {
    $feeIncomeMonth = (int) $db->query('SELECT COALESCE(SUM(announce_fee_rwf),0) FROM listings WHERE payment_status="paid" AND created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")')->fetchColumn();
}
$unpaidValue = (int) $db->query('SELECT COALESCE(SUM(announce_fee_rwf),0) FROM listings WHERE payment_status="unpaid" AND moderation_status IN ("pending","flagged")')->fetchColumn();
$paidCount = (int) $db->query('SELECT COUNT(*) FROM listings WHERE payment_status="paid"')->fetchColumn();
$revenueByDistrict = $db->query('
  SELECT district, COALESCE(SUM(announce_fee_rwf),0) AS revenue, COUNT(*) AS paid_posts
  FROM listings
  WHERE payment_status = "paid" AND district IS NOT NULL AND district <> ""
  GROUP BY district
  ORDER BY revenue DESC
  LIMIT 12
')->fetchAll();
$moderators = array_values(array_filter($managementUsers, static fn($u) => (int)$u['role_id'] === 3));
$districtManagers = array_values(array_filter($managementUsers, static fn($u) => (int)$u['role_id'] === 2));
$openReports = $db->query('SELECT id, target_type, target_id, reason, details, status, created_at FROM reports WHERE status IN ("open","reviewing") ORDER BY created_at DESC LIMIT 25')->fetchAll();


$idPending = 0;
$idQueue = [];
try {
    $idPending = (int) $db->query('SELECT COUNT(*) FROM users WHERE role_id = 4 AND id_status = "pending"')->fetchColumn();
    $idQueue = $db->query('
      SELECT id, nickname, phone, district, id_number, id_document_path, id_status, created_at
      FROM users
      WHERE role_id = 4 AND id_status = "pending"
      ORDER BY created_at DESC
      LIMIT 40
    ')->fetchAll();
} catch (Throwable $e) {
    $idPending = 0;
    $idQueue = [];
}

$checklistScope = 'nationwide';
$roleOptions = guguManagementRoleOptions();
$smsConfigured = defined('GUGU_SMS_API_URL') && GUGU_SMS_API_URL !== '';
$fee = (int) GUGU_ANNOUNCE_FEE_RWF;

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
        <?= htmlspecialchars($u['district'] ?: '—') ?>
        <?php if (in_array($rid, [2, 3], true) && !empty($u['admin_district'])): ?>
          <br><small class="muted">Scope: <?= htmlspecialchars($u['admin_district']) ?></small>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isSelf): ?>
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
          <?= (int)($u['moderated_30d'] ?? 0) ?> listings ·
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

  <!-- HOME: platform owner command center -->
  <section class="admin-pane is-active" data-pane="home" id="home">
    <section class="panel portal-hero portal-hero-super admin-owner-hero">
      <div class="rw-flag-bar" aria-hidden="true">
        <span class="rw-blue"></span>
        <span class="rw-yellow"></span>
        <span class="rw-green"></span>
      </div>
      <div class="admin-owner-hero-inner">
        <div class="portal-hero-text">
          <span class="portal-kicker">System Administrator (Super Admin) · Global</span>
          <h2>System Control Center</h2>
          <p>Your Super Admin portal — system settings, staff permissions, money, and nationwide item/job approvals.</p>
        </div>
        <div class="stats admin-owner-stats">
          <div class="stat"><strong><?= $staffCount ?></strong><span>Staff</span></div>
          <div class="stat"><strong><?= $members ?></strong><span>Members</span></div>
          <div class="stat"><strong><?= $review ?></strong><span>Queue</span></div>
          <div class="stat"><strong><?= number_format($feeIncome) ?></strong><span>Revenue (RWF)</span></div>
        </div>
      </div>
    </section>

    <section class="panel admin-duty-list" id="admin-should-do">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <span class="portal-kicker">Roles · what each Admin should do</span>
      <h3>Admin roles in Gura &amp; Gurisha</h3>
      <p class="admin-duty-lead">Three staff roles run the system. Members (role 4) only buy/sell in the app.</p>

      <div class="admin-role-duty-grid">
        <article class="admin-role-duty role-super">
          <header>
            <span class="admin-role-badge">Role 1</span>
            <h4>System Administrator (Super Admin)</h4>
            <p>Portal: System Control Center · Nationwide</p>
          </header>
          <ol>
            <li><strong>Approve items &amp; jobs</strong> — Mark paid → Approve so posts go live.</li>
            <li><strong>Confirm MoMo fees</strong> — <?= $fee ?> RWF per announcement · <?= htmlspecialchars(GUGU_MOMO_NUMBER) ?>.</li>
            <li><strong>Reject spam / fakes</strong> — keep the marketplace clean.</li>
            <li><strong>System Controls</strong> — MoMo gateway, announce fee, SMS.</li>
            <li><strong>Permissions</strong> — create District Managers &amp; Moderators; suspend staff.</li>
            <li><strong>Financials</strong> — track nationwide announce-fee revenue.</li>
            <li><strong>Reports &amp; IDs</strong> — resolve reports; approve member IDs.</li>
            <li><strong>Open other dashboards</strong> — preview District / Moderator views (you keep Super Admin power).</li>
          </ol>
          <div class="admin-role-actions">
            <button type="button" class="btn-sm ok" data-open="listings">Approvals<?= $review ? ' · ' . $review : '' ?></button>
            <button type="button" class="btn-sm" data-open="system-controls">System</button>
            <button type="button" class="btn-sm" data-open="permissions">Staff</button>
          </div>
        </article>

        <article class="admin-role-duty role-district">
          <header>
            <span class="admin-role-badge">Role 2</span>
            <h4>District Manager</h4>
            <p>Portal: District Operations Hub · One Akarere only</p>
          </header>
          <ol>
            <li><strong>Manage your district</strong> — Gasabo, Huye, etc. only (not nationwide).</li>
            <li><strong>Approve / reject local posts</strong> — items &amp; jobs in your Akarere.</li>
            <li><strong>Verify local sellers</strong> — check members selling in your district.</li>
            <li><strong>Confirm local MoMo fees</strong> — Mark paid for posts in your area.</li>
            <li><strong>Handle local reports</strong> — disputes and flags in your district.</li>
            <li><strong>Activate / suspend local members</strong> — when needed for trust &amp; safety.</li>
          </ol>
          <div class="admin-role-actions">
            <a class="btn-sm" href="/gugu-app/admin/dashboard.php?view_role=2&amp;view_district=Gasabo">Open District view</a>
          </div>
        </article>

        <article class="admin-role-duty role-mod">
          <header>
            <span class="admin-role-badge">Role 3</span>
            <h4>Moderator / Support</h4>
            <p>Portal: Trust &amp; Safety Desk · Flagged / support work</p>
          </header>
          <ol>
            <li><strong>Review flagged posts</strong> — items/jobs marked for review.</li>
            <li><strong>Approve, flag, or reject</strong> — spam, scams, fake announcements.</li>
            <li><strong>Handle support tickets &amp; reports</strong> — help members; close cases.</li>
            <li><strong>Review member IDs</strong> — Approve or Reject ID documents.</li>
            <li><strong>Ban / suspend fraud</strong> — stop abusive or fake accounts.</li>
          </ol>
          <div class="admin-role-actions">
            <a class="btn-sm" href="/gugu-app/admin/dashboard.php?view_role=3&amp;view_district=Gasabo">Open Moderator view</a>
          </div>
        </article>
      </div>

      <p class="admin-duty-note">
        <strong>Member (Role 4)</strong> — browse, sell items, announce jobs, chat, apply to jobs.
        Members never open this Admin portal.
      </p>
    </section>

    <div class="admin-group">
      <h3 class="admin-group-title">Approve posts first</h3>
      <p class="admin-group-sub">Items and Jobs wait here until you Mark paid → Approve</p>
      <div class="admin-command-grid">
        <button type="button" class="admin-cmd-card tone-blue" data-open="listings">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">✅</span>
            <span class="admin-cmd-tag">Approvals</span>
          </div>
          <h3>Item &amp; Job Approvals</h3>
          <p>Nationwide queue for marketplace items and job announcements.</p>
          <ul class="admin-cmd-meta">
            <li>Waiting · <strong><?= $review ?></strong></li>
            <li>Paid &amp; ready · <strong><?= $paidPending ?></strong></li>
            <li>Unpaid · <strong><?= $unpaidPending ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open approvals →</span>
        </button>
      </div>
    </div>

    <div class="admin-group">
      <h3 class="admin-group-title">Platform owner · Global</h3>
      <p class="admin-group-sub">System Controls · Permissions · Financial Analytics · Other dashboards</p>
      <div class="admin-command-grid">
        <button type="button" class="admin-cmd-card tone-blue" data-open="system-controls">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">⚙️</span>
            <span class="admin-cmd-tag">System Controls</span>
          </div>
          <h3>System Controls</h3>
          <p>Configure Mobile Money gateway, announce fee, and SMS.</p>
          <ul class="admin-cmd-meta">
            <li>Fee · <strong><?= $fee ?> RWF</strong></li>
            <li>MoMo · <strong><?= htmlspecialchars(GUGU_MOMO_NUMBER) ?></strong></li>
            <li>Mode · <strong><?= GUGU_MOMO_SANDBOX ? 'Sandbox' : 'Live' ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open System Controls →</span>
        </button>

        <button type="button" class="admin-cmd-card tone-green" data-open="permissions">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🔐</span>
            <span class="admin-cmd-tag">Permission Controls</span>
          </div>
          <h3>Permission Controls</h3>
          <p>Create District Managers, assign Moderators, suspend or disable staff.</p>
          <ul class="admin-cmd-meta">
            <li>District Managers · <strong><?= count($districtManagers) ?></strong></li>
            <li>Moderators · <strong><?= count($moderators) ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open Permission Controls →</span>
        </button>

        <button type="button" class="admin-cmd-card tone-yellow" data-open="analytics">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">📊</span>
            <span class="admin-cmd-tag">Global Finance</span>
          </div>
          <h3>Global Financial Analytics</h3>
          <p>Total platform revenue from announce fees (nationwide).</p>
          <ul class="admin-cmd-meta">
            <li>Total · <strong><?= number_format($feeIncome) ?> RWF</strong></li>
            <li>This month · <strong><?= number_format($feeIncomeMonth) ?> RWF</strong></li>
          </ul>
          <span class="admin-cmd-go">Open analytics →</span>
        </button>

        <button type="button" class="admin-cmd-card tone-blue" data-open="dashboards">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🧭</span>
            <span class="admin-cmd-tag">Access</span>
          </div>
          <h3>Open any dashboard</h3>
          <p>Inspect District Manager or Moderator screens — you keep Super Admin power.</p>
          <ul class="admin-cmd-meta">
            <li>District · <strong>Regional</strong></li>
            <li>Moderator · <strong>Local</strong></li>
          </ul>
          <span class="admin-cmd-go">Choose dashboard →</span>
        </button>
      </div>
    </div>

    <div class="admin-group">
      <h3 class="admin-group-title">Daily operations</h3>
      <p class="admin-group-sub">Routine queues and marketplace oversight</p>
      <div class="admin-command-grid">
        <button type="button" class="admin-cmd-card tone-blue" data-open="checklist">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">📋</span>
            <span class="admin-cmd-tag">Checklist</span>
          </div>
          <h3>Admin checklist</h3>
          <p>Daily routine — queue, MoMo confirm, approve, reports.</p>
          <ul class="admin-cmd-meta">
            <li>Queue · <strong><?= $review ?></strong></li>
            <li>Reports · <strong><?= $reports ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open checklist →</span>
        </button>
        <button type="button" class="admin-cmd-card tone-yellow" data-open="members">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🛒</span>
            <span class="admin-cmd-tag">Members</span>
          </div>
          <h3>Members</h3>
          <p>Buyers &amp; sellers on the marketplace.</p>
          <ul class="admin-cmd-meta">
            <li>Members · <strong><?= $members ?></strong></li>
            <li>ID pending · <strong><?= $idPending ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open members →</span>
        </button>
        <button type="button" class="admin-cmd-card tone-yellow" data-open="payments">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">💸</span>
            <span class="admin-cmd-tag">Payments</span>
          </div>
          <h3>Fee payments</h3>
          <p>Confirm <?= $fee ?> RWF announce fees via MoMo.</p>
          <ul class="admin-cmd-meta">
            <li>Unpaid · <strong><?= $unpaidPending ?></strong></li>
            <li>Income · <strong><?= number_format($feeIncome) ?> RWF</strong></li>
          </ul>
          <span class="admin-cmd-go">Open payments →</span>
        </button>
        <button type="button" class="admin-cmd-card tone-blue" data-open="listings">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">✅</span>
            <span class="admin-cmd-tag">Approvals</span>
          </div>
          <h3>Item &amp; Job Approvals</h3>
          <p>Mark paid → Approve items and jobs.</p>
          <ul class="admin-cmd-meta">
            <li>Queue · <strong><?= $review ?></strong></li>
            <li>Ready · <strong><?= $paidPending ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open approvals →</span>
        </button>
        <button type="button" class="admin-cmd-card tone-green" data-open="reports">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🛡️</span>
            <span class="admin-cmd-tag">Reports</span>
          </div>
          <h3>Reports &amp; ID review</h3>
          <p>Community reports and member ID verification.</p>
          <ul class="admin-cmd-meta">
            <li>Open reports · <strong><?= $reports ?></strong></li>
            <li>ID pending · <strong><?= $idPending ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open reports →</span>
        </button>
        <a class="admin-cmd-card tone-yellow" href="/gugu-app/app/" target="_blank" rel="noopener">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🇷🇼</span>
            <span class="admin-cmd-tag">Oversight</span>
          </div>
          <h3>Marketplace</h3>
          <p>Inspect live items, jobs, and chats.</p>
          <ul class="admin-cmd-meta">
            <li>Users · <strong><?= $users ?></strong></li>
          </ul>
          <span class="admin-cmd-go">Open marketplace →</span>
        </a>
      </div>
    </div>
  </section>

  <!-- SECTION: Daily checklist -->
  <section class="admin-pane" data-pane="checklist" id="pane-checklist">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Daily routine</span>
      <h2>Admin checklist</h2>
    </header>
    <?php require __DIR__ . '/../includes/daily_checklist.php'; ?>
  </section>

  <!-- SECTION: Permission Controls -->
  <section class="admin-pane" data-pane="permissions" id="permissions">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Permission Controls</span>
      <h2>Staff permissions</h2>
      <p class="admin-pane-sub">Create District Managers, assign Moderators, or disable a staff account.</p>
    </header>

    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <h3 class="panel-subhead">Create staff account</h3>
      <p class="hint">Member must already be registered. Enter their phone, pick role + Akarere.</p>
      <form method="post" action="/gugu-app/admin/actions.php" class="portal-settings-form">
        <input type="hidden" name="action" value="promote-staff">
        <div class="portal-form-grid">
          <label>Phone
            <input type="text" name="phone" required placeholder="07XXXXXXXX" autocomplete="tel">
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
        <button type="submit" class="btn-sm ok">Create District Manager / Moderator</button>
      </form>
    </section>

    <section class="panel">
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">1 · Super Admin</span>
        <span class="chip chip-green">2 · District Manager · <?= count($districtManagers) ?></span>
        <span class="chip chip-yellow">3 · Moderator · <?= count($moderators) ?></span>
      </div>
      <h4 class="panel-subhead">Role matrix</h4>
      <div class="table-wrap" style="margin-bottom:16px">
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
      <h4 class="panel-subhead">Staff accounts — change role or disable</h4>
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
              <?php adminSectionUserRow($u, $selfId, $districts, $roleOptions); ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: Members -->
  <section class="admin-pane" data-pane="members" id="members">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">People</span>
      <h2>Members</h2>
      <p class="admin-pane-sub">Marketplace buyers &amp; sellers.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-yellow">4 · Member</span>
        <span class="chip">Members · <?= $members ?></span>
        <span class="chip chip-yellow">ID pending · <?= $idPending ?></span>
        <?php if ($idPending > 0): ?>
          <button type="button" class="btn-sm warn" data-open="id-queue">Review ID pending →</button>
        <?php endif; ?>
      </div>
      <?php if (!$memberUsers): ?>
        <p class="hint">No members yet</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Phone</th><th>District</th><th>ID status</th><th>Role</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($memberUsers as $u):
              $idSt = $u['id_status'] ?? 'none';
            ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($u['nickname'] ?: 'User') ?></strong>
                  <?php if (!empty($u['email'])): ?><br><small class="muted"><?= htmlspecialchars($u['email']) ?></small><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['phone']) ?></td>
                <td><?= htmlspecialchars($u['district'] ?: '—') ?></td>
                <td><span class="status-pill"><?= htmlspecialchars($idSt) ?></span></td>
                <td>
                  <?php
                  $uid = (int) $u['id'];
                  $rid = (int) $u['role_id'];
                  ?>
                  <form method="post" action="/gugu-app/admin/actions.php" class="portal-row-form">
                    <input type="hidden" name="action" value="set-role">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <select name="role_id" onchange="var d=this.form.querySelector('[name=admin_district]'); if(d) d.style.display=(this.value==='2'||this.value==='3')?'inline-block':'none';">
                      <?php foreach ($roleOptions as $optId => $label): ?>
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
                </td>
                <td><span class="status-pill"><?= htmlspecialchars($u['account_status']) ?></span></td>
                <td class="portal-actions">
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
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Money &amp; listings</span>
      <h2>How Admin earns (payments)</h2>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-yellow">Unpaid · <?= $unpaidPending ?></span>
        <span class="chip chip-green">Ready · <?= $paidPending ?></span>
        <span class="chip chip-blue">Income · <?= number_format($feeIncome) ?> RWF</span>
      </div>
      <ul class="portal-duties">
        <li><strong>Every announcement costs <?= $fee ?> RWF</strong> — items, jobs, and other posts.</li>
        <li>Member pays MoMo to <strong><?= htmlspecialchars(GUGU_MOMO_NAME) ?></strong> · <code><?= htmlspecialchars(GUGU_MOMO_NUMBER) ?></code></li>
        <li>Post stays <strong>Pending</strong> until you <strong>Mark paid</strong> then <strong>Approve</strong>.</li>
      </ul>
      <div class="portal-actions" style="margin-top:12px">
        <button type="button" class="btn-sm warn" data-open="listings">Go to listing queue</button>
      </div>
    </section>
  </section>

  <!-- SECTION: Approvals queue -->
  <section class="admin-pane" data-pane="listings" id="listings">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Item &amp; Job Approvals</span>
      <h2>Item &amp; Job Approvals</h2>
      <p class="admin-pane-sub">Marketplace items + job announcements · Pay <?= $fee ?> RWF → Mark paid → Approve · Queue · <?= $review ?> · Ready · <?= $paidPending ?></p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <?php if (!$queue): ?>
        <p class="hint">Queue clear ✅</p>
      <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Title</th><th>District</th><th>Fee / Pay</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($queue as $l): ?>
          <tr>
            <td>#<?= (int)$l['id'] ?></td>
            <td>
              <?= htmlspecialchars($l['title']) ?>
              <?php if ((int)($l['category_id'] ?? 0) === 11): ?><br><small class="muted">Job</small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($l['district']) ?></td>
            <td>
              <?= (int)($l['announce_fee_rwf'] ?? $fee) ?> RWF
              <br><span class="status-pill"><?= htmlspecialchars($l['payment_status'] ?? 'unpaid') ?></span>
            </td>
            <td><span class="status-pill"><?= htmlspecialchars($l['moderation_status']) ?></span></td>
            <td class="portal-actions">
              <?= portalActionForm('mark-listing-paid', ['listing_id' => $l['id'], 'payment_note' => 'MoMo ' . $fee . ' RWF'], 'Mark paid', 'btn-sm warn') ?>
              <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'approved'], 'Approve (live)', 'btn-sm ok') ?>
              <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'rejected'], 'Reject', 'btn-sm danger') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: Reports (community) -->
  <section class="admin-pane" data-pane="reports" id="reports">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Safety &amp; platform</span>
      <h2>All open reports</h2>
      <p class="admin-pane-sub">Community reports only · Open · <?= $reports ?></p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-green">Open reports · <?= $reports ?></span>
        <span class="chip chip-yellow">ID pending · <?= $idPending ?></span>
        <button type="button" class="btn-sm warn" data-open="id-queue">Review ID pending →</button>
      </div>
      <?php if (!$openReports): ?>
        <p class="hint">No open community reports</p>
      <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Target</th><th>Reason</th><th>Handle</th></tr></thead>
        <tbody>
        <?php foreach ($openReports as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['target_type'] . ' #' . $r['target_id']) ?></td>
            <td>
              <?= htmlspecialchars($r['reason']) ?>
              <?php if (!empty($r['details'])): ?><br><small class="muted"><?= htmlspecialchars($r['details']) ?></small><?php endif; ?>
            </td>
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
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Safety &amp; platform</span>
      <h2>ID pending</h2>
      <p class="admin-pane-sub">Members waiting for ID approval · <?= $idPending ?> pending — review documents below.</p>
    </header>
    <section class="panel" id="id-verification">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <h3>Member ID verification queue</h3>
      <p class="muted" style="margin:0 0 12px">These members submitted an ID and are waiting for you (System Administrator) to approve or reject.</p>
      <?php if (!$idQueue): ?>
        <p class="hint">No ID pending ✅</p>
      <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Member</th><th>Phone</th><th>ID number</th><th>Document</th><th>Review</th></tr></thead>
        <tbody>
        <?php foreach ($idQueue as $u): ?>
          <tr>
            <td>
              <?= htmlspecialchars($u['nickname'] ?: 'User') ?>
              <br><small class="muted"><?= htmlspecialchars($u['district'] ?: '') ?></small>
            </td>
            <td><?= htmlspecialchars($u['phone']) ?></td>
            <td><code><?= htmlspecialchars($u['id_number'] ?: '—') ?></code></td>
            <td>
              <?php if (!empty($u['id_document_path'])): ?>
                <a href="<?= htmlspecialchars(UPLOAD_URL . $u['id_document_path']) ?>" target="_blank" rel="noreferrer">View ID photo</a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="portal-actions">
              <?= portalActionForm('review-id', ['user_id' => $u['id'], 'id_status' => 'approved'], 'Approve ID', 'btn-sm ok') ?>
              <?= portalActionForm('review-id', ['user_id' => $u['id'], 'id_status' => 'rejected', 'id_reject_reason' => 'Unclear document — resubmit'], 'Reject', 'btn-sm danger') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </section>

  <!-- SECTION: System Controls -->
  <section class="admin-pane" data-pane="system-controls" id="system-controls">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">System Controls</span>
      <h2>Payment gateway &amp; platform config</h2>
      <p class="admin-pane-sub">Mobile Money gateway, announce fee, and SMS — changes apply immediately.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-blue">Fee · <?= $fee ?> RWF</span>
        <span class="chip chip-green">MoMo · <?= htmlspecialchars(GUGU_MOMO_NUMBER) ?></span>
        <span class="chip chip-yellow"><?= GUGU_MOMO_SANDBOX ? 'Sandbox' : 'Live' ?></span>
        <span class="chip">SMS · <?= $smsConfigured ? 'configured' : 'not set' ?></span>
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
          <label>Announce fee (RWF)
            <input type="number" name="announce_fee_rwf" min="0" step="100" required value="<?= $fee ?>">
          </label>
          <label class="portal-check">
            <input type="checkbox" name="momo_sandbox" value="1" <?= GUGU_MOMO_SANDBOX ? 'checked' : '' ?>>
            Sandbox mode (testing)
          </label>
        </div>
        <h4 class="panel-subhead">SMS gateway</h4>
        <div class="portal-form-grid">
          <label>API URL
            <input type="url" name="sms_api_url" value="<?= htmlspecialchars(GUGU_SMS_API_URL) ?>" placeholder="https://…">
          </label>
          <label>API key
            <input type="password" name="sms_api_key" value="" placeholder="<?= $smsConfigured ? '•••• leave blank to keep' : 'Optional' ?>" autocomplete="new-password">
          </label>
          <label>Sender ID
            <input type="text" name="sms_sender" value="<?= htmlspecialchars(GUGU_SMS_SENDER) ?>" maxlength="11">
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
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Global Financial Analytics</span>
      <h2>Platform revenue</h2>
      <p class="admin-pane-sub">Total announce-fee income across all districts.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="stats admin-owner-stats" style="margin-bottom:16px">
        <div class="stat"><strong><?= number_format($feeIncome) ?></strong><span>Total revenue (RWF)</span></div>
        <div class="stat"><strong><?= number_format($feeIncomeMonth) ?></strong><span>This month (RWF)</span></div>
        <div class="stat"><strong><?= $paidCount ?></strong><span>Paid posts</span></div>
        <div class="stat"><strong><?= number_format($unpaidValue) ?></strong><span>Unpaid in queue (RWF)</span></div>
      </div>
      <h4 class="panel-subhead">Revenue by district</h4>
      <?php if (!$revenueByDistrict): ?>
        <p class="hint">No paid fees yet</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>District</th><th>Paid posts</th><th>Revenue (RWF)</th></tr>
          </thead>
          <tbody>
            <?php foreach ($revenueByDistrict as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['district']) ?></td>
                <td><?= (int) $row['paid_posts'] ?></td>
                <td><strong><?= number_format((int) $row['revenue']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <p style="margin-top:14px">
        <button type="button" class="btn-sm warn" data-open="payments">Open fee payments →</button>
      </p>
    </section>
  </section>

  <!-- SECTION: Open any dashboard -->
  <section class="admin-pane" data-pane="dashboards" id="dashboards">
    <header class="admin-pane-head">
      <button type="button" class="admin-back" data-open="home">← Dashboard</button>
      <span class="admin-pane-kicker">Dashboard access</span>
      <h2>Open District or Moderator dashboard</h2>
      <p class="admin-pane-sub">Same portal look — you keep Super Admin power while inspecting their screen.</p>
    </header>
    <section class="panel">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="admin-command-grid">
        <a class="admin-cmd-card tone-green" href="/gugu-app/admin/dashboard.php?view_role=2&amp;view_district=Gasabo">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🗺️</span>
            <span class="admin-cmd-tag">Regional</span>
          </div>
          <h3>District Manager dashboard</h3>
          <p>District Operations Hub — regional listings, sellers, reports.</p>
          <span class="admin-cmd-go">Open District view →</span>
        </a>
        <a class="admin-cmd-card tone-yellow" href="/gugu-app/admin/dashboard.php?view_role=3&amp;view_district=Gasabo">
          <div class="admin-cmd-top">
            <span class="admin-cmd-ico" aria-hidden="true">🛡️</span>
            <span class="admin-cmd-tag">Local</span>
          </div>
          <h3>Moderator dashboard</h3>
          <p>Trust &amp; Safety Desk — flagged queue, ID review, tickets.</p>
          <span class="admin-cmd-go">Open Moderator view →</span>
        </a>
      </div>
      <p class="hint" style="margin-top:14px">District / Moderator previews open from this page only. Your default portal stays <strong>System Administrator (Super Admin)</strong>.</p>
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
    management: 'permissions',
    members: 'members',
    payments: 'payments',
    listings: 'listings',
    reports: 'reports',
    'id-queue': 'id-queue',
    'system-controls': 'system-controls',
    settings: 'system-controls',
    'system-settings': 'system-controls',
    analytics: 'analytics',
    financials: 'analytics',
    dashboards: 'dashboards',
    users: 'permissions',
    'management-system': 'permissions',
    staff: 'permissions'
  };

  function currentPaneKey() {
    try {
      var q = new URLSearchParams(location.search || '');
      if (q.get('pane')) return q.get('pane');
    } catch (e) {}
    return (location.hash || '#home').replace(/^#/, '') || 'home';
  }

  function openPane(key) {
    key = alias[key] || 'home';
    if (!shell.querySelector('[data-pane="' + key + '"]')) key = 'home';
    shell.querySelectorAll('.admin-pane').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-pane') === key);
    });
    try {
      var url = new URL(location.href);
      if (key === 'home') url.searchParams.delete('pane');
      else url.searchParams.set('pane', key);
      url.hash = '';
      if (history.replaceState) history.replaceState(null, '', url.pathname + url.search);
    } catch (e) {}
    window.scrollTo(0, 0);
  }

  shell.addEventListener('click', function (e) {
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

  openPane(currentPaneKey());
  window.addEventListener('hashchange', function () {
    openPane(currentPaneKey());
  });
})();
</script>
