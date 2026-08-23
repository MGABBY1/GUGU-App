<?php
/**
 * Daily Admin checklist — shared by Super / Regional / Support portals.
 *
 * Expected variables (optional; defaults to 0):
 *   $review, $unpaidPending, $paidPending, $reports
 *   $checklistScope — short label e.g. "nationwide" or district name
 */
$review = (int) ($review ?? 0);
$unpaidPending = (int) ($unpaidPending ?? 0);
$paidPending = (int) ($paidPending ?? 0);
$reports = (int) ($reports ?? $localReports ?? 0);
$scope = htmlspecialchars($checklistScope ?? 'all Gura & Gurisha');
$fee = (int) (defined('GUGU_ANNOUNCE_FEE_RWF') ? GUGU_ANNOUNCE_FEE_RWF : 1000);
$momoName = defined('GUGU_MOMO_NAME') ? GUGU_MOMO_NAME : 'Gura & Gurisha';
$momoNum = defined('GUGU_MOMO_NUMBER') ? GUGU_MOMO_NUMBER : '';

$doneQueue = $review === 0;
$donePay = $unpaidPending === 0;
$doneApprove = $paidPending === 0;
$doneReports = $reports === 0;
$idPending = (int) ($idPending ?? 0);
$doneId = $idPending === 0;

// Steps: portal open + queue + pay + approve + reject + reports (+ ID when tracked)
$totalSteps = 6 + ($checklistScope === 'nationwide' || $idPending > 0 || isset($idQueue) ? 1 : 0);
$progressDone = 1
    + ($doneQueue ? 1 : 0)
    + ($donePay ? 1 : 0)
    + ($doneApprove ? 1 : 0)
    + ($doneQueue ? 1 : 0)
    + ($doneReports ? 1 : 0)
    + (($totalSteps > 6 && $doneId) ? 1 : 0);
$pct = (int) round(($progressDone / $totalSteps) * 100);
?>
<section class="panel daily-checklist" id="checklist">
  <div class="daily-checklist-head">
    <div>
      <span class="portal-kicker daily-kicker">Daily routine</span>
      <h3>Admin checklist</h3>
      <p class="daily-checklist-sub">Work this list every day · <?= $scope ?></p>
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
        <strong>Open portal</strong>
        <p>You are here — working in <?= $scope ?>.</p>
      </div>
      <span class="daily-badge ok">Done</span>
    </li>
    <li class="daily-step <?= $doneQueue ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneQueue ? '✓' : '2' ?></span>
      <div class="daily-step-body">
        <strong>Check Needs review / listing queue</strong>
        <p><?= $review ?> item<?= $review === 1 ? '' : 's' ?> waiting in the queue.</p>
      </div>
      <a class="btn-sm <?= $doneQueue ? 'ok' : 'warn' ?>" href="?pane=item-approvals"><?= $doneQueue ? 'View queue' : 'Open queue' ?></a>
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
      <a class="btn-sm <?= $donePay ? 'ok' : 'warn' ?>" href="?pane=payments"><?= $donePay ? 'All paid' : 'Confirm pay' ?></a>
    </li>
    <li class="daily-step <?= $doneApprove ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneApprove ? '✓' : '4' ?></span>
      <div class="daily-step-body">
        <strong>Approve good posts (Admin earns)</strong>
        <p><?= $paidPending ?> paid post<?= $paidPending === 1 ? '' : 's' ?> ready — Approve to go live and count earnings.</p>
      </div>
      <a class="btn-sm <?= $doneApprove ? 'ok' : '' ?>" href="?pane=item-approvals"><?= $doneApprove ? 'Caught up' : 'Approve now' ?></a>
    </li>
    <li class="daily-step <?= $doneQueue ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneQueue ? '✓' : '5' ?></span>
      <div class="daily-step-body">
        <strong>Reject spam / fake posts</strong>
        <p>Use <em>Reject</em> on junk, scams, or fake listings in the same queue.</p>
      </div>
      <a class="btn-sm danger" href="?pane=item-approvals">Reject junk</a>
    </li>
    <li class="daily-step <?= $doneReports ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneReports ? '✓' : '6' ?></span>
      <div class="daily-step-body">
        <strong>Handle open reports</strong>
        <p><?= $reports ?> open report<?= $reports === 1 ? '' : 's' ?> — resolve or dismiss if they apply.</p>
      </div>
      <a class="btn-sm <?= $doneReports ? 'ok' : 'warn' ?>" href="?pane=reports"><?= $doneReports ? 'No reports' : 'Open reports' ?></a>
    </li>
    <?php if ($totalSteps > 6): ?>
    <li class="daily-step <?= $doneId ? 'is-done' : 'is-todo' ?>">
      <span class="daily-check" aria-hidden="true"><?= $doneId ? '✓' : '7' ?></span>
      <div class="daily-step-body">
        <strong>Review ID pending</strong>
        <p><?= $idPending ?> member ID<?= $idPending === 1 ? '' : 's' ?> waiting — Approve or Reject documents.</p>
      </div>
      <a class="btn-sm <?= $doneId ? 'ok' : 'warn' ?>" href="?pane=id-queue"><?= $doneId ? 'IDs clear' : 'Review IDs' ?></a>
    </li>
    <?php endif; ?>
  </ol>
</section>
