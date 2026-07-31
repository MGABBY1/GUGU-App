<?php
/**
 * Regional Manager Portal — Akarere (district) operations only
 */
require_once __DIR__ . '/../includes/portal_helpers.php';

$db = getDB();
$selfId = (int) $_SESSION['user_id'];
$district = !empty($portal_view_district)
  ? $portal_view_district
  : ($_SESSION['admin_district'] ?: ($_SESSION['district'] ?? 'Gasabo'));

$stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE district = ?');
$stmt->execute([$district]);
$users = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE status="active" AND district = ?');
$stmt->execute([$district]);
$active = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE moderation_status IN ("pending","flagged") AND district = ?');
$stmt->execute([$district]);
$review = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE moderation_status="pending" AND payment_status="paid" AND district = ?');
$stmt->execute([$district]);
$paidPending = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM listings WHERE moderation_status IN ("pending","flagged") AND payment_status="unpaid" AND district = ?');
$stmt->execute([$district]);
$unpaidPending = (int) $stmt->fetchColumn();

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

$stmt = $db->prepare('SELECT id, nickname, district, sector, role_id, account_status FROM users WHERE district = ? ORDER BY id DESC LIMIT 40');
$stmt->execute([$district]);
$localUsers = $stmt->fetchAll();

$stmt = $db->prepare('
  SELECT u.id, u.nickname, u.account_status,
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
  WHERE u.role_id = 3 AND COALESCE(NULLIF(u.admin_district, ""), u.district) = ?
  ORDER BY actions_30d DESC, u.nickname
');
$stmt->execute([$district]);
$moderatorPerformance = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, title, sector, moderation_status, payment_status, announce_fee_rwf, user_id FROM listings WHERE district = ? AND moderation_status IN ("pending","flagged") ORDER BY created_at DESC LIMIT 30');
$stmt->execute([$district]);
$queue = $stmt->fetchAll();

$stmt = $db->prepare('
  SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status
  FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  WHERE r.status IN ("open","reviewing") AND (l.district = ? OR u.district = ?)
  ORDER BY r.created_at DESC
  LIMIT 25
');
$stmt->execute([$district, $district]);
$openReports = $stmt->fetchAll();
?>
<section class="panel portal-hero portal-hero-regional">
  <div class="portal-hero-text">
    <span class="portal-kicker">Role 2 · Regional Manager</span>
    <h2><?= htmlspecialchars($district) ?> region</h2>
    <p>Manage members and listings in your Akarere only. You cannot assign roles or ban accounts.</p>
  </div>
  <div class="stats">
    <div class="stat"><strong><?= $users ?></strong><span>Local users</span></div>
    <div class="stat"><strong><?= $active ?></strong><span>Active items</span></div>
    <div class="stat"><strong><?= $review ?></strong><span>Needs review</span></div>
    <div class="stat"><strong><?= $localReports ?></strong><span>Local reports</span></div>
  </div>
</section>

<?php require __DIR__ . '/../includes/daily_checklist.php'; ?>

<section class="panel">
  <h3>Your duties</h3>
  <ul class="portal-duties">
    <li>Activate or suspend members (and Moderator / Support) in <?= htmlspecialchars($district) ?></li>
    <li>Confirm MoMo, Mark paid, Approve good posts (Admin earns)</li>
    <li>Reject spam / fake listings in your region</li>
    <li>Handle reports that involve local listings or users</li>
    <li>Cannot change Super Admin / other Regional Managers</li>
    <li>Cannot ban — escalate bans to Super Admin</li>
  </ul>
</section>

<section class="panel" id="moderator-performance">
  <h3>Moderator performance · last 30 days</h3>
  <p class="muted" style="margin:0 0 12px">Your local Trust &amp; Safety team in <?= htmlspecialchars($district) ?>.</p>
  <?php if (!$moderatorPerformance): ?>
    <p class="hint">No Moderator assigned to this district yet. Ask Super Admin to assign one in Management.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Moderator</th><th>Status</th><th>Total actions</th><th>Listings</th><th>Reports</th><th>Open dashboard</th></tr></thead>
      <tbody>
      <?php foreach ($moderatorPerformance as $moderator): ?>
        <tr>
          <td><strong><?= htmlspecialchars($moderator['nickname'] ?: 'Moderator') ?></strong></td>
          <td><span class="status-pill"><?= htmlspecialchars($moderator['account_status']) ?></span></td>
          <td><?= (int)$moderator['actions_30d'] ?></td>
          <td><?= (int)$moderator['moderated_30d'] ?></td>
          <td><?= (int)$moderator['reports_30d'] ?></td>
          <td>
            <?php if (($portal_actual_role_id ?? 2) === 1): ?>
              <a class="btn-sm" href="/gugu-app/admin/dashboard.php?view_role=3&amp;view_district=<?= urlencode($district) ?>">Open local dashboard</a>
            <?php else: ?>
              <span class="muted">Current district scope</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<section class="panel" id="users">
  <h3>Users in <?= htmlspecialchars($district) ?></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Sector</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($localUsers as $u):
      $uid = (int) $u['id'];
      $canAct = (int)$u['role_id'] >= 3 && $uid !== $selfId;
    ?>
      <tr>
        <td><?= htmlspecialchars($u['nickname'] ?: 'User') ?></td>
        <td><?= htmlspecialchars($u['sector'] ?: '—') ?></td>
        <td><?= htmlspecialchars(adminRoleLabel((int)$u['role_id'])) ?></td>
        <td><span class="status-pill"><?= htmlspecialchars($u['account_status']) ?></span></td>
        <td class="portal-actions">
          <?php if ($canAct): ?>
            <?= portalActionForm('set-status', ['user_id' => $uid, 'account_status' => 'active'], 'Activate', 'btn-sm ok') ?>
            <?= portalActionForm('set-status', ['user_id' => $uid, 'account_status' => 'suspended'], 'Suspend', 'btn-sm danger') ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel" id="listings">
  <h3>Items to review in <?= htmlspecialchars($district) ?></h3>
  <?php if (!$queue): ?>
    <p class="hint">Nothing to review ✅</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Title</th><th>Sector</th><th>Fee / Pay</th><th>Status</th><th>Moderate</th></tr></thead>
    <tbody>
    <?php foreach ($queue as $l): ?>
      <tr>
        <td>#<?= (int)$l['id'] ?></td>
        <td><?= htmlspecialchars($l['title']) ?></td>
        <td><?= htmlspecialchars($l['sector'] ?: '—') ?></td>
        <td>
          <?= (int)($l['announce_fee_rwf'] ?? GUGU_ANNOUNCE_FEE_RWF) ?> RWF
          <br><span class="status-pill"><?= htmlspecialchars($l['payment_status'] ?? 'unpaid') ?></span>
        </td>
        <td><span class="status-pill"><?= htmlspecialchars($l['moderation_status']) ?></span></td>
        <td class="portal-actions">
          <?= portalActionForm('mark-listing-paid', ['listing_id' => $l['id'], 'payment_note' => 'MoMo received'], 'Mark paid', 'btn-sm warn') ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'approved'], 'Approve', 'btn-sm ok') ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'rejected'], 'Reject', 'btn-sm danger') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="panel" id="reports">
  <h3>Reports in <?= htmlspecialchars($district) ?></h3>
  <?php if (!$openReports): ?>
    <p class="hint">No local open reports</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Target</th><th>Reason</th><th>Handle</th></tr></thead>
    <tbody>
    <?php foreach ($openReports as $r): ?>
      <tr>
        <td>#<?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars($r['target_type'] . ' #' . $r['target_id']) ?></td>
        <td><?= htmlspecialchars($r['reason']) ?></td>
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
