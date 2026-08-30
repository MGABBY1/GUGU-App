<?php
/**
 * Moderator / Support · Trust & Safety desk — shared DB stats, queues, checklist payloads.
 */
require_once __DIR__ . '/portal_helpers.php';

function portalSupportDeskDistrict(?string $fallback = null): string {
    $preview = portalPreviewGet();
    $role = (int) ($_SESSION['role_id'] ?? 0);
    if ($role === 1 && $preview['role'] === 3 && $preview['district'] !== '') {
        return $preview['district'];
    }
    if ($fallback !== null && trim($fallback) !== '') {
        return trim($fallback);
    }
    if ($role === 3) {
        $d = trim((string) ($_SESSION['admin_district'] ?? $_SESSION['district'] ?? ''));
        if ($d !== '') {
            return $d;
        }
    }
    $allowed = portalDistricts();
    return $allowed[0] ?? 'Gasabo';
}

function portalSupportDeskSync(PDO $db, string $district): void {
    try {
        $jobCat = guguJobCategoryId();
        if ($jobCat > 0) {
            $db->prepare('UPDATE listings SET business_type = "job" WHERE district = ? AND category_id = ? AND business_type <> "job"')
               ->execute([$district, $jobCat]);
            $db->prepare('UPDATE listings SET business_type = "item" WHERE district = ? AND (category_id IS NULL OR category_id <> ?) AND business_type <> "item"')
               ->execute([$district, $jobCat]);
        }
    } catch (Throwable $e) {
        // older DB without business_type
    }
}

function portalSupportDeskData(PDO $db, string $district): array {
    portalSupportDeskSync($db, $district);

    $count = static function (string $where, array $extra = []) use ($db, $district): int {
        $params = array_merge([$district], $extra);
        $stmt = $db->prepare("SELECT COUNT(*) FROM listings WHERE district = ? AND {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    $review = $count('moderation_status IN ("pending","flagged")');
    $itemReview = $count('moderation_status IN ("pending","flagged") AND business_type = "item"');
    $jobReview = $count('moderation_status IN ("pending","flagged") AND business_type = "job"');
    $flagged = $count('moderation_status = "flagged"');
    $active = $count('status = "active" AND moderation_status = "approved"');
    $paidPending = $count('moderation_status = "pending" AND payment_status = "paid"');
    $unpaidPending = $count('moderation_status IN ("pending","flagged") AND payment_status = "unpaid"');

    $stmt = $db->prepare('
      SELECT l.id, l.title, l.district, l.sector, l.moderation_status, l.payment_status,
             l.announce_fee_rwf, l.user_id, l.business_type, l.category_id, l.created_at,
             u.nickname, u.email, u.phone
      FROM listings l
      JOIN users u ON u.id = l.user_id
      WHERE l.moderation_status IN ("pending","flagged") AND l.district = ?
      ORDER BY FIELD(l.moderation_status, "flagged", "pending"), l.created_at DESC
      LIMIT 80
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

    $idData = portalIdVerificationData($db, $district);

    return [
        'district' => $district,
        'review' => $review,
        'item_review' => $itemReview,
        'job_review' => $jobReview,
        'flagged' => $flagged,
        'active' => $active,
        'paid_pending' => $paidPending,
        'unpaid_pending' => $unpaidPending,
        'reports' => count($openReports),
        'id_pending' => (int) ($idData['pending'] ?? 0),
        'id_approved' => (int) ($idData['approved'] ?? 0),
        'queue' => $queue,
        'open_reports' => $openReports,
        'id_data' => $idData,
    ];
}

function portalSupportFilterQueue(array $queue, string $filter): array {
    return array_values(array_filter($queue, static function (array $l) use ($filter): bool {
        $mod = (string) ($l['moderation_status'] ?? '');
        $pay = (string) ($l['payment_status'] ?? 'unpaid');
        return match ($filter) {
            'unpaid' => $pay === 'unpaid',
            'paid' => $pay === 'paid' && in_array($mod, ['pending', 'flagged'], true),
            'flagged' => $mod === 'flagged',
            default => true,
        };
    }));
}

function portalSupportListingBizLabel(array $l): string {
    $biz = (string) ($l['business_type'] ?? '');
    if ($biz !== 'item' && $biz !== 'job') {
        $biz = guguBusinessTypeFromCategory((int) ($l['category_id'] ?? 0));
    }
    return $biz === 'job' ? 'Job' : 'Item';
}

function portalSupportRenderQueueTable(array $listings, string $returnPane = 'checklist'): void {
    if (!$listings) {
        echo '<section class="panel"><p class="hint">Nothing in this queue right now — counts are up to date.</p></section>';
        return;
    }
    ?>
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
        <?php foreach ($listings as $l):
            $sellerEmail = trim((string) ($l['email'] ?? ''));
            $bizLabel = portalSupportListingBizLabel($l);
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
              <?= portalActionForm('mark-listing-paid', ['listing_id' => $l['id'], 'payment_note' => 'MoMo received', 'return_pane' => $returnPane], 'Mark paid', 'btn-sm warn') ?>
              <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'approved', 'return_pane' => $returnPane], 'Approve', 'btn-sm ok') ?>
              <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'flagged', 'return_pane' => $returnPane], 'Flag', 'btn-sm warn') ?>
              <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'rejected', 'return_pane' => $returnPane], 'Reject', 'btn-sm danger') ?>
              <?= portalActionForm('suspend-seller', ['user_id' => $l['user_id'], 'return_pane' => $returnPane], 'Suspend', 'btn-sm danger') ?>
              <?= portalActionForm('ban-seller', ['user_id' => $l['user_id'], 'return_pane' => $returnPane], 'Ban fraud', 'btn-sm danger') ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php
}

function portalSupportRenderReportsTable(array $reports, string $district, string $returnPane = 'checklist'): void {
    if (!$reports) {
        echo '<section class="panel"><p class="hint">No open reports in ' . htmlspecialchars($district) . '.</p></section>';
        return;
    }
    ?>
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
        <?php foreach ($reports as $r):
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
              <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'reviewing', 'return_pane' => $returnPane], 'Reviewing', 'btn-sm warn') ?>
              <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'resolved', 'return_pane' => $returnPane], 'Resolve', 'btn-sm ok') ?>
              <?= portalActionForm('resolve-report', ['report_id' => $r['id'], 'status' => 'dismissed', 'return_pane' => $returnPane], 'Dismiss', 'btn-sm') ?>
              <?php if (($r['target_type'] ?? '') === 'user'): ?>
                <?= portalActionForm('ban-seller', ['user_id' => $r['target_id'], 'return_pane' => $returnPane], 'Ban user', 'btn-sm danger') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php
}

function portalSupportRenderDeskSummary(array $data): void {
    $district = (string) ($data['district'] ?? '');
    ?>
    <div class="chips ts-desk-summary-chips" style="margin-bottom:12px">
      <span class="chip">Akarere · <?= htmlspecialchars($district) ?></span>
      <span class="chip <?= ($data['review'] ?? 0) > 0 ? 'chip-yellow' : 'chip-green' ?>">Queue · <?= (int) ($data['review'] ?? 0) ?></span>
      <span class="chip">Live · <?= (int) ($data['active'] ?? 0) ?></span>
      <span class="chip">Reports · <?= (int) ($data['reports'] ?? 0) ?></span>
      <span class="chip">ID pending · <?= (int) ($data['id_pending'] ?? 0) ?></span>
    </div>
    <section class="panel">
      <p class="hint" style="margin:0">
        Trust &amp; Safety Desk is open for <strong><?= htmlspecialchars($district) ?></strong>.
        <?= (int) ($data['active'] ?? 0) ?> live post<?= ((int) ($data['active'] ?? 0) === 1) ? '' : 's' ?>,
        <?= (int) ($data['review'] ?? 0) ?> waiting review,
        <?= (int) ($data['unpaid_pending'] ?? 0) ?> unpaid,
        <?= (int) ($data['paid_pending'] ?? 0) ?> paid ready to approve.
      </p>
    </section>
    <?php
}

function portalSupportRenderFragment(array $data, string $view, string $returnPane = 'checklist'): string {
    ob_start();
    $district = (string) ($data['district'] ?? '');
    switch ($view) {
        case 'desk':
            portalSupportRenderDeskSummary($data);
            break;
        case 'queue':
            echo '<div class="chips" style="margin-bottom:12px">';
            echo '<span class="chip">Waiting · ' . (int) $data['review'] . '</span>';
            echo '<span class="chip">Items · ' . (int) $data['item_review'] . '</span>';
            echo '<span class="chip">Jobs · ' . (int) $data['job_review'] . '</span>';
            echo '</div>';
            portalSupportRenderQueueTable($data['queue'], $returnPane);
            break;
        case 'unpaid':
            echo '<div class="chips" style="margin-bottom:12px">';
            echo '<span class="chip chip-yellow">Unpaid · ' . (int) $data['unpaid_pending'] . '</span>';
            echo '<span class="chip">' . (int) (defined('GUGU_ANNOUNCE_FEE_RWF') ? GUGU_ANNOUNCE_FEE_RWF : 1000) . ' RWF each</span>';
            echo '</div>';
            portalSupportRenderQueueTable(portalSupportFilterQueue($data['queue'], 'unpaid'), $returnPane);
            break;
        case 'paid':
            echo '<div class="chips" style="margin-bottom:12px">';
            echo '<span class="chip chip-green">Paid ready · ' . (int) $data['paid_pending'] . '</span>';
            echo '</div>';
            portalSupportRenderQueueTable(portalSupportFilterQueue($data['queue'], 'paid'), $returnPane);
            break;
        case 'flagged':
            echo '<div class="chips" style="margin-bottom:12px">';
            echo '<span class="chip chip-yellow">Flagged · ' . (int) $data['flagged'] . '</span>';
            echo '</div>';
            portalSupportRenderQueueTable(portalSupportFilterQueue($data['queue'], 'flagged'), $returnPane);
            break;
        case 'reports':
            portalSupportRenderReportsTable($data['open_reports'], $district, $returnPane);
            break;
        case 'ids':
            portalRenderIdVerificationQueue($data['id_data'], $district, $returnPane);
            break;
        default:
            echo '<p class="hint">Unknown view.</p>';
    }
    return (string) ob_get_clean();
}

function portalSupportChecklistPayload(array $data): array {
    $district = (string) ($data['district'] ?? '');
    $review = (int) ($data['review'] ?? 0);
    $unpaid = (int) ($data['unpaid_pending'] ?? 0);
    $paid = (int) ($data['paid_pending'] ?? 0);
    $reports = (int) ($data['reports'] ?? 0);
    $idPending = (int) ($data['id_pending'] ?? 0);
    $active = (int) ($data['active'] ?? 0);
    $flagged = (int) ($data['flagged'] ?? 0);
    $fee = (int) (defined('GUGU_ANNOUNCE_FEE_RWF') ? GUGU_ANNOUNCE_FEE_RWF : 1000);
    $momoName = defined('GUGU_MOMO_NAME') ? GUGU_MOMO_NAME : 'Gura & Gurisha';
    $momoNum = defined('GUGU_MOMO_NUMBER') ? GUGU_MOMO_NUMBER : '';

    $doneQueue = $review === 0;
    $donePay = $unpaid === 0;
    $doneApprove = $paid === 0;
    $doneReports = $reports === 0;
    $doneId = $idPending === 0;

    $totalSteps = 7;
    $progressDone = 1
        + ($doneQueue ? 1 : 0)
        + ($donePay ? 1 : 0)
        + ($doneApprove ? 1 : 0)
        + ($doneQueue ? 1 : 0)
        + ($doneReports ? 1 : 0)
        + ($doneId ? 1 : 0);
    $pct = (int) round(($progressDone / $totalSteps) * 100);
    $pendingCount = (!$doneQueue ? 1 : 0) + (!$donePay ? 1 : 0) + (!$doneApprove ? 1 : 0)
        + (!$doneQueue ? 1 : 0) + (!$doneReports ? 1 : 0) + (!$doneId ? 1 : 0);

    $payDetail = $unpaid . ' unpaid · ' . $fee . ' RWF each · Mark paid after MoMo to '
        . $momoName . ($momoNum !== '' ? ' (' . $momoNum . ')' : '') . '.';
    $approveDetail = $paid . ' paid post' . ($paid === 1 ? '' : 's')
        . ' ready — Approve so they go live safely in ' . $district . '.';

    return [
        'district' => $district,
        'stats' => [
            'review' => $review,
            'unpaid_pending' => $unpaid,
            'paid_pending' => $paid,
            'reports' => $reports,
            'id_pending' => $idPending,
            'id_approved' => (int) ($data['id_approved'] ?? 0),
            'active' => $active,
            'flagged' => $flagged,
            'item_review' => (int) ($data['item_review'] ?? 0),
            'job_review' => (int) ($data['job_review'] ?? 0),
        ],
        'progress' => [
            'done' => $progressDone,
            'total' => $totalSteps,
            'pct' => $pct,
            'pending_count' => $pendingCount,
        ],
        'rows' => [
            ['num' => 1, 'done' => true, 'metric' => (string) $active, 'metric_label' => 'Live posts',
                'status' => 'Done', 'status_class' => 'ok',
                'detail' => 'You are here — Trust & Safety Desk · working in ' . $district . '.',
                'action_label' => 'View desk'],
            ['num' => 2, 'done' => $doneQueue, 'metric' => (string) $review, 'metric_label' => 'Waiting',
                'status' => $doneQueue ? 'Clear' : 'Check now', 'status_class' => $doneQueue ? 'ok' : 'warn',
                'detail' => 'Check flagged and pending posts waiting for Trust & Safety.',
                'action_label' => $doneQueue ? 'View queue' : 'Open queue'],
            ['num' => 3, 'done' => $donePay, 'metric' => (string) $unpaid, 'metric_label' => 'Unpaid',
                'status' => $donePay ? 'All paid' : 'Confirm', 'status_class' => $donePay ? 'ok' : 'warn',
                'detail' => $payDetail,
                'action_label' => $donePay ? 'All paid' : 'Confirm pay'],
            ['num' => 4, 'done' => $doneApprove, 'metric' => (string) $paid, 'metric_label' => 'Paid ready',
                'status' => $doneApprove ? 'Caught up' : 'Approve', 'status_class' => $doneApprove ? 'ok' : 'warn',
                'detail' => $approveDetail,
                'action_label' => $doneApprove ? 'Caught up' : 'Approve now'],
            ['num' => 5, 'done' => $doneQueue, 'metric' => $doneQueue ? '0' : (string) $review, 'metric_label' => 'To review',
                'status' => $doneQueue ? 'Clear' : 'Review', 'status_class' => $doneQueue ? 'ok' : 'danger',
                'detail' => 'Use Reject on junk, scams, or fake listings in the moderation queue.',
                'action_label' => 'Reject junk'],
            ['num' => 6, 'done' => $doneReports, 'metric' => (string) $reports, 'metric_label' => 'Open',
                'status' => $doneReports ? 'No reports' : 'Open', 'status_class' => $doneReports ? 'ok' : 'warn',
                'detail' => 'Resolve or dismiss community flags that apply to ' . $district . '.',
                'action_label' => $doneReports ? 'No reports' : 'Open reports'],
            ['num' => 7, 'done' => $doneId, 'metric' => (string) $idPending, 'metric_label' => 'Pending',
                'status' => $doneId ? 'IDs clear' : 'Review', 'status_class' => $doneId ? 'ok' : 'warn',
                'detail' => 'Approve or reject member national ID documents in ' . $district . '.',
                'action_label' => $doneId ? 'IDs clear' : 'Review IDs'],
        ],
        'foot' => $pendingCount === 0
            ? 'All routine checks are clear for ' . $district . ' today.'
            : $pendingCount . ' step' . ($pendingCount === 1 ? '' : 's') . ' still need attention — use the Action column.',
    ];
}

function portalSupportRequireDeskApi(): string {
    adminRequireLogin();
    $role = (int) ($_SESSION['role_id'] ?? 0);
    $preview = portalPreviewGet();
    $allowed = $role === 3 || ($role === 1 && $preview['role'] === 3);
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $reqDistrict = trim((string) ($_GET['view_district'] ?? $_GET['district'] ?? ''));
    if ($role === 1 && $preview['role'] === 3 && $preview['district'] !== '') {
        if ($reqDistrict !== '' && strcasecmp($reqDistrict, $preview['district']) !== 0) {
            $reqDistrict = $preview['district'];
        }
        if ($reqDistrict === '') {
            $reqDistrict = $preview['district'];
        }
    }
    return portalSupportDeskDistrict($reqDistrict !== '' ? $reqDistrict : null);
}
