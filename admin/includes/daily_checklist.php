<?php
/**
 * Daily checklist — shared by Admin / District / Moderator (Trust & Safety) portals.
 *
 * Expected variables (optional; defaults to 0):
 *   $review, $unpaidPending, $paidPending, $reports, $idPending
 *   $checklistScope — short label e.g. "nationwide" or district name
 *   $checklistRole — 1 Admin · 2 District Manager · 3 Moderator / Support
 *   $idQueue — when set, ID review step is shown
 */
$review = (int) ($review ?? 0);
$unpaidPending = (int) ($unpaidPending ?? 0);
$paidPending = (int) ($paidPending ?? 0);
$reports = (int) ($reports ?? $localReports ?? 0);
$scopeRaw = (string) ($checklistScope ?? 'all Gura & Gurisha');
$scope = htmlspecialchars($scopeRaw);
$fee = (int) (defined('GUGU_ANNOUNCE_FEE_RWF') ? GUGU_ANNOUNCE_FEE_RWF : 1000);
$momoName = defined('GUGU_MOMO_NAME') ? GUGU_MOMO_NAME : 'Gura & Gurisha';
$momoNum = defined('GUGU_MOMO_NUMBER') ? GUGU_MOMO_NUMBER : '';

$checklistRole = (int) ($checklistRole ?? $portal_role_id ?? 0);
if ($checklistRole < 1 || $checklistRole > 3) {
    $checklistRole = (int) ($_SESSION['role_id'] ?? 1);
}
$isSupport = $checklistRole === 3;
$isDistrict = $checklistRole === 2;

$doneQueue = $review === 0;
$donePay = $unpaidPending === 0;
$doneApprove = $paidPending === 0;
$doneReports = $reports === 0;
$idPending = (int) ($idPending ?? 0);
$doneId = $idPending === 0;

// ID step: nationwide Admin, any portal with pending IDs, or Support / District desks
$showIdStep = $scopeRaw === 'nationwide'
    || $idPending > 0
    || isset($idQueue)
    || $isSupport
    || $isDistrict;

$totalSteps = 6 + ($showIdStep ? 1 : 0);
$progressDone = 1
    + ($doneQueue ? 1 : 0)
    + ($donePay ? 1 : 0)
    + ($doneApprove ? 1 : 0)
    + ($doneQueue ? 1 : 0) // reject spam tracks same queue clear state
    + ($doneReports ? 1 : 0)
    + (($showIdStep && $doneId) ? 1 : 0);
$pct = (int) round(($progressDone / $totalSteps) * 100);

// Preserve Admin preview of District / Support desks in action links
$previewQs = '';
$actualRole = (int) ($portal_actual_role_id ?? $_SESSION['role_id'] ?? 0);
$viewDistrict = trim((string) ($portal_view_district ?? ''));
if ($actualRole === 1 && ($checklistRole === 2 || $checklistRole === 3) && $viewDistrict !== '') {
    $previewQs = 'view_role=' . $checklistRole
        . '&view_district=' . rawurlencode($viewDistrict)
        . '&';
}

$href = static function (string $paneOrHash) use ($isSupport, $previewQs): string {
    if ($isSupport) {
        // Trust & Safety Desk is a single scroll page — use section anchors
        $map = [
            'item-approvals' => '#listings',
            'listings' => '#listings',
            'payments' => '#listings',
            'reports' => '#reports',
            'id-queue' => '#id-queue',
            'checklist' => '#checklist',
            'home' => '#checklist',
        ];
        $hash = $map[$paneOrHash] ?? ('#' . ltrim($paneOrHash, '#'));
        $base = '/gugu-app/admin/dashboard.php';
        if ($previewQs !== '') {
            return $base . '?' . rtrim($previewQs, '&') . $hash;
        }
        return $hash;
    }
    $pane = ltrim($paneOrHash, '#');
    if ($pane === '' || $pane === 'home') {
        return '/gugu-app/admin/dashboard.php' . ($previewQs !== '' ? ('?' . rtrim($previewQs, '&')) : '');
    }
    return '/gugu-app/admin/dashboard.php?' . $previewQs . 'pane=' . rawurlencode($pane);
};

if ($isSupport) {
    $title = 'Trust & Safety checklist';
    $sub = 'Work this list every day on the desk · ' . $scope;
    $openStrong = 'Open portal';
    $openText = 'You are here — Trust & Safety Desk · working in ' . $scope . '.';
    $approveStrong = 'Approve good posts';
    $approveText = $paidPending . ' paid post' . ($paidPending === 1 ? '' : 's')
        . ' ready — Approve so they go live safely in ' . $scope . '.';
    $approveDone = 'Caught up';
    $approveTodo = 'Approve now';
} elseif ($isDistrict) {
    $title = 'District checklist';
    $sub = 'Work this list every day · ' . $scope;
    $openStrong = 'Open portal';
    $openText = 'You are here — District Operations Hub · working in ' . $scope . '.';
    $approveStrong = 'Approve good posts (Admin earns)';
    $approveText = $paidPending . ' paid post' . ($paidPending === 1 ? '' : 's')
        . ' ready — Approve to go live and count earnings.';
    $approveDone = 'Caught up';
    $approveTodo = 'Approve now';
} else {
    $title = 'Admin checklist';
    $sub = 'Work this list every day · ' . $scope;
    $openStrong = 'Open portal';
    $openText = 'You are here — working in ' . $scope . '.';
    $approveStrong = 'Approve good posts (Admin earns)';
    $approveText = $paidPending . ' paid post' . ($paidPending === 1 ? '' : 's')
        . ' ready — Approve to go live and count earnings.';
    $approveDone = 'Caught up';
    $approveTodo = 'Approve now';
}
?>
<section class="panel daily-checklist<?= $isSupport ? ' daily-checklist-support' : '' ?>" id="checklist">
  <div class="daily-checklist-head">
    <div>
      <span class="portal-kicker daily-kicker">Daily routine</span>
      <h3><?= htmlspecialchars($title) ?></h3>
      <p class="daily-checklist-sub"><?= $sub ?></p>
    </div>
    <div class="daily-progress" aria-label="Checklist progress">
      <strong><?= $progressDone ?>/<?= $totalSteps ?></strong>
      <span><?= $pct ?>% clear</span>
    </div>
  </div>
  <div class="daily-progress-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
    <i style="width:<?= $pct ?>%"></i>
  </div>
  <ol class="daily-steps">
    <li class="daily-step is-done">
      <span class="daily-check" aria-hidden="true">✓</span>
      <div class="daily-step-body">
        <strong><?= htmlspecialchars($openStrong) ?></strong>
        <p><?= $openText ?></p>
      </div>
      <span class="daily-badge ok">Done</span>
    </li>
    <li class="daily-step <?= $doneQueue ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneQueue ? '✓' : '2' ?></span>
      <div class="daily-step-body">
        <strong>Check Needs review / listing queue</strong>
        <p><?= $review ?> item<?= $review === 1 ? '' : 's' ?> waiting in the queue.</p>
      </div>
      <a class="btn-sm <?= $doneQueue ? 'ok' : 'warn' ?>" href="<?= htmlspecialchars($href($isSupport ? 'listings' : 'item-approvals')) ?>"><?= $doneQueue ? 'View queue' : 'Open queue' ?></a>
    </li>
    <li class="daily-step <?= $donePay ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $donePay ? '✓' : '3' ?></span>
      <div class="daily-step-body">
        <strong>Confirm MoMo payments</strong>
        <p>
          <?= $unpaidPending ?> unpaid · check MoMo to
          <strong><?= htmlspecialchars($momoName) ?></strong>
          <?php if ($momoNum !== ''): ?>(<code><?= htmlspecialchars($momoNum) ?></code>)<?php endif; ?>
          · <?= $fee ?> RWF each · then <em>Mark paid</em>.
        </p>
      </div>
      <a class="btn-sm <?= $donePay ? 'ok' : 'warn' ?>" href="<?= htmlspecialchars($href($isSupport ? 'listings' : 'payments')) ?>"><?= $donePay ? 'All paid' : 'Confirm pay' ?></a>
    </li>
    <li class="daily-step <?= $doneApprove ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneApprove ? '✓' : '4' ?></span>
      <div class="daily-step-body">
        <strong><?= htmlspecialchars($approveStrong) ?></strong>
        <p><?= $approveText ?></p>
      </div>
      <a class="btn-sm <?= $doneApprove ? 'ok' : '' ?>" href="<?= htmlspecialchars($href($isSupport ? 'listings' : 'item-approvals')) ?>"><?= $doneApprove ? htmlspecialchars($approveDone) : htmlspecialchars($approveTodo) ?></a>
    </li>
    <li class="daily-step <?= $doneQueue ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneQueue ? '✓' : '5' ?></span>
      <div class="daily-step-body">
        <strong>Reject spam / fake posts</strong>
        <p>Use <em>Reject</em> on junk, scams, or fake listings in the same queue.</p>
      </div>
      <a class="btn-sm danger" href="<?= htmlspecialchars($href($isSupport ? 'listings' : 'item-approvals')) ?>">Reject junk</a>
    </li>
    <li class="daily-step <?= $doneReports ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneReports ? '✓' : '6' ?></span>
      <div class="daily-step-body">
        <strong>Handle open reports</strong>
        <p><?= $reports ?> open report<?= $reports === 1 ? '' : 's' ?> — resolve or dismiss if they apply.</p>
      </div>
      <a class="btn-sm <?= $doneReports ? 'ok' : 'warn' ?>" href="<?= htmlspecialchars($href('reports')) ?>"><?= $doneReports ? 'No reports' : 'Open reports' ?></a>
    </li>
    <?php if ($showIdStep): ?>
    <li class="daily-step <?= $doneId ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneId ? '✓' : '7' ?></span>
      <div class="daily-step-body">
        <strong>Review ID pending</strong>
        <p><?= $idPending ?> member ID<?= $idPending === 1 ? '' : 's' ?> waiting — Approve or Reject documents.</p>
      </div>
      <a class="btn-sm <?= $doneId ? 'ok' : 'warn' ?>" href="<?= htmlspecialchars($href('id-queue')) ?>"><?= $doneId ? 'IDs clear' : 'Review IDs' ?></a>
    </li>
    <?php endif; ?>
  </ol>
</section>
