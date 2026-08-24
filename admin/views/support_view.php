<?php
/**
 * Role 3 · Moderator / Support — Trust & Safety Desk
 */
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/management_roles.php';

$db = getDB();
$mgmt = guguManagementRoles()[3];
$district = !empty($portal_view_district)
  ? $portal_view_district
  : ($_SESSION['admin_district'] ?: ($_SESSION['district'] ?? 'Gasabo'));

$countListing = function (string $where) use ($db, $district): int {
  $stmt = $db->prepare("SELECT COUNT(*) FROM listings WHERE $where AND district = ?");
  $stmt->execute([$district]);
  return (int) $stmt->fetchColumn();
};
$review = $countListing('moderation_status IN ("pending","flagged")');
$flagged = $countListing('moderation_status = "flagged"');
$active = $countListing('status = "active"');
$paidPending = $countListing('moderation_status = "pending" AND payment_status = "paid"');
$unpaidPending = $countListing('moderation_status IN ("pending","flagged") AND payment_status = "unpaid"');
$checklistScope = $district;

$stmt = $db->prepare('
  SELECT l.id, l.title, l.district, l.moderation_status, l.payment_status, l.announce_fee_rwf, l.user_id,
         u.nickname, u.email, u.phone
  FROM listings l
  JOIN users u ON u.id = l.user_id
  WHERE l.moderation_status IN ("pending","flagged") AND l.district = ?
  ORDER BY FIELD(l.moderation_status, "flagged", "pending"), l.created_at DESC
  LIMIT 40
');
$stmt->execute([$district]);
$queue = $stmt->fetchAll();

$stmt = $db->prepare('
  SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status
  FROM reports r
  LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
  LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
  WHERE r.status IN ("open","reviewing") AND (l.district = ? OR u.district = ?)
  ORDER BY r.created_at DESC
  LIMIT 40
');
$stmt->execute([$district, $district]);
$openReports = $stmt->fetchAll();
$reports = count($openReports);

$idData = portalIdVerificationData($db, $district);
$idPending = (int) $idData['pending'];
$idQueue = $idData['queue'];
$checklistRole = 3;
?>
<section class="panel portal-hero portal-hero-support">
  <div class="portal-hero-text">
    <span class="portal-kicker"><?= htmlspecialchars($mgmt['kicker']) ?></span>
    <h2><?= htmlspecialchars($mgmt['workspace']) ?> · <?= htmlspecialchars($district) ?></h2>
    <p>Review local flagged listings, ID verification, support tickets, and fraudulent accounts.</p>
  </div>
  <div class="stats">
    <div class="stat"><strong><?= $review ?></strong><span>Queue</span></div>
    <div class="stat"><strong><?= $idPending ?></strong><span>ID pending</span></div>
    <div class="stat"><strong><?= $reports ?></strong><span>Open reports</span></div>
    <div class="stat"><strong><?= $active ?></strong><span>Active items</span></div>
  </div>
</section>

<?php require __DIR__ . '/../includes/daily_checklist.php'; ?>

<div id="id-queue">
  <?php portalRenderIdVerificationQueue($idData, $district); ?>
</div>

<section class="panel">
  <h3>Key responsibilities</h3>
  <ul class="portal-duties">
    <?php foreach ($mgmt['responsibilities'] as $item): ?>
      <li><?= htmlspecialchars($item) ?></li>
    <?php endforeach; ?>
    <li>Cannot change System Administrator or District Manager accounts</li>
    <li>Cannot assign staff roles</li>
  </ul>
</section>

<section class="panel" id="listings">
  <h3>Flagged / pending Gurisha queue</h3>
  <?php if (!$queue): ?>
    <p class="hint">Queue empty ✅</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Title</th><th>Member</th><th>Email</th><th>District</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($queue as $l):
      $sellerEmail = trim((string) ($l['email'] ?? ''));
    ?>
      <tr>
        <td>#<?= (int)$l['id'] ?></td>
        <td><?= htmlspecialchars($l['title']) ?></td>
        <td>
          <?= htmlspecialchars($l['nickname'] ?: '—') ?>
          <?php if (!empty($l['phone'])): ?>
            <br><small class="muted"><?= htmlspecialchars((string) $l['phone']) ?></small>
          <?php endif; ?>
        </td>
        <td><?= $sellerEmail !== '' ? htmlspecialchars($sellerEmail) : '<span class="muted">—</span>' ?></td>
        <td><?= htmlspecialchars($l['district']) ?></td>
        <td>
          <span class="status-pill"><?= htmlspecialchars($l['moderation_status']) ?></span>
          <br><small class="muted"><?= (int)($l['announce_fee_rwf'] ?? 1000) ?> RWF · <?= htmlspecialchars($l['payment_status'] ?? 'unpaid') ?></small>
        </td>
        <td class="portal-actions">
          <?= portalActionForm('mark-listing-paid', ['listing_id' => $l['id'], 'payment_note' => 'MoMo received'], 'Mark paid', 'btn-sm warn') ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'approved'], 'Approve', 'btn-sm ok') ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'flagged'], 'Flag', 'btn-sm warn') ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'rejected'], 'Reject', 'btn-sm danger') ?>
          <?= portalActionForm('suspend-seller', ['user_id' => $l['user_id']], 'Suspend', 'btn-sm danger') ?>
          <?= portalActionForm('ban-seller', ['user_id' => $l['user_id']], 'Ban fraud', 'btn-sm danger') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="panel" id="reports">
  <h3>Support tickets &amp; reports</h3>
  <?php if (!$openReports): ?>
    <p class="hint">No open reports</p>
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
          <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'reviewing'], 'Reviewing', 'btn-sm warn') ?>
          <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'resolved'], 'Resolve', 'btn-sm ok') ?>
          <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'dismissed'], 'Dismiss', 'btn-sm') ?>
          <?php if (($r['target_type'] ?? '') === 'user'): ?>
            <?= portalActionForm('ban-seller', ['user_id' => $r['target_id']], 'Ban user', 'btn-sm danger') ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
