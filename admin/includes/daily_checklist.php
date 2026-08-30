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
$active = (int) ($active ?? 0);
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
    $pane = ltrim($paneOrHash, '#');
    if ($isSupport) {
        $map = [
            'item-approvals' => 'listings',
            'listings' => 'listings',
            'payments' => 'listings',
            'reports' => 'reports',
            'id-queue' => 'id-queue',
            'checklist' => 'checklist',
            'home' => 'home',
        ];
        $pane = $map[$pane] ?? $pane;
        $base = '/gugu-app/admin/dashboard.php';
        $qs = $previewQs;
        if ($pane !== '' && $pane !== 'home') {
            $qs .= 'pane=' . rawurlencode($pane);
        } else {
            $qs = rtrim($qs, '&');
        }
        return $qs !== '' ? ($base . '?' . $qs) : $base;
    }
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

$tsChecklistRows = [];
if ($isSupport) {
    $tsChecklistRows = [
        [
            'num' => 1,
            'done' => true,
            'title' => $openStrong,
            'detail' => $openText . ' · ' . $active . ' live post' . ($active === 1 ? '' : 's') . ' in ' . $scopeRaw . '.',
            'metric' => (string) $active,
            'metric_label' => 'Live posts',
            'status' => 'Done',
            'status_class' => 'ok',
            'action' => $href('home'),
            'action_label' => 'View desk',
            'action_class' => 'ok',
            'pane' => 'desk',
            'expand_view' => 'desk',
        ],
        [
            'num' => 2,
            'done' => $doneQueue,
            'title' => 'Needs review queue',
            'detail' => 'Check flagged and pending posts waiting for Trust & Safety.',
            'metric' => (string) $review,
            'metric_label' => 'Waiting',
            'status' => $doneQueue ? 'Clear' : 'Check now',
            'status_class' => $doneQueue ? 'ok' : 'warn',
            'action' => $href('listings'),
            'action_label' => $doneQueue ? 'View queue' : 'Open queue',
            'action_class' => $doneQueue ? 'ok' : 'warn',
            'pane' => 'listings',
            'expand_view' => 'queue',
        ],
        [
            'num' => 3,
            'done' => $donePay,
            'title' => 'Confirm MoMo payments',
            'detail' => $unpaidPending . ' unpaid · ' . $fee . ' RWF each · Mark paid after MoMo to '
                . $momoName . ($momoNum !== '' ? ' (' . $momoNum . ')' : '') . '.',
            'metric' => (string) $unpaidPending,
            'metric_label' => 'Unpaid',
            'status' => $donePay ? 'All paid' : 'Confirm',
            'status_class' => $donePay ? 'ok' : 'warn',
            'action' => $href('listings'),
            'action_label' => $donePay ? 'All paid' : 'Confirm pay',
            'action_class' => $donePay ? 'ok' : 'warn',
            'pane' => 'listings',
            'expand_view' => 'unpaid',
        ],
        [
            'num' => 4,
            'done' => $doneApprove,
            'title' => $approveStrong,
            'detail' => $approveText,
            'metric' => (string) $paidPending,
            'metric_label' => 'Paid ready',
            'status' => $doneApprove ? 'Caught up' : 'Approve',
            'status_class' => $doneApprove ? 'ok' : 'warn',
            'action' => $href('listings'),
            'action_label' => $doneApprove ? $approveDone : $approveTodo,
            'action_class' => $doneApprove ? 'ok' : '',
            'pane' => 'listings',
            'expand_view' => 'paid',
        ],
        [
            'num' => 5,
            'done' => $doneQueue,
            'title' => 'Reject spam / fake posts',
            'detail' => 'Use Reject on junk, scams, or fake listings in the moderation queue.',
            'metric' => $doneQueue ? '0' : (string) $review,
            'metric_label' => 'To review',
            'status' => $doneQueue ? 'Clear' : 'Review',
            'status_class' => $doneQueue ? 'ok' : 'danger',
            'action' => $href('listings'),
            'action_label' => 'Reject junk',
            'action_class' => 'danger',
            'pane' => 'listings',
            'expand_view' => 'flagged',
        ],
        [
            'num' => 6,
            'done' => $doneReports,
            'title' => 'Handle open reports',
            'detail' => 'Resolve or dismiss community flags that apply to ' . $scope . '.',
            'metric' => (string) $reports,
            'metric_label' => 'Open',
            'status' => $doneReports ? 'No reports' : 'Open',
            'status_class' => $doneReports ? 'ok' : 'warn',
            'action' => $href('reports'),
            'action_label' => $doneReports ? 'No reports' : 'Open reports',
            'action_class' => $doneReports ? 'ok' : 'warn',
            'pane' => 'reports',
            'expand_view' => 'reports',
        ],
    ];
    if ($showIdStep) {
        $tsChecklistRows[] = [
            'num' => 7,
            'done' => $doneId,
            'title' => 'Review ID pending',
            'detail' => 'Approve or reject member national ID documents in ' . $scope . '.',
            'metric' => (string) $idPending,
            'metric_label' => 'Pending',
            'status' => $doneId ? 'IDs clear' : 'Review',
            'status_class' => $doneId ? 'ok' : 'warn',
            'action' => $href('id-queue'),
            'action_label' => $doneId ? 'IDs clear' : 'Review IDs',
            'action_class' => $doneId ? 'ok' : 'warn',
            'pane' => 'id-queue',
            'expand_view' => 'ids',
        ];
    }
    $tsPendingCount = count(array_filter($tsChecklistRows, static fn($r) => empty($r['done']) && !empty($r['action'])));
}
?>
<section class="panel daily-checklist<?= $isSupport ? ' daily-checklist-support ts-checklist-panel' : '' ?>" id="ts-checklist-root"<?= $isSupport ? ' data-ts-district="' . htmlspecialchars($scopeRaw, ENT_QUOTES) . '"' : '' ?>>
  <div class="daily-checklist-head">
    <div>
      <span class="portal-kicker daily-kicker">Daily routine</span>
      <h3><?= htmlspecialchars($title) ?></h3>
      <p class="daily-checklist-sub"><?= $sub ?></p>
    </div>
    <div class="daily-progress" aria-label="Checklist progress" id="ts-checklist-progress">
      <strong id="ts-checklist-progress-count"><?= $progressDone ?>/<?= $totalSteps ?></strong>
      <span id="ts-checklist-progress-pct"><?= $pct ?>% clear</span>
    </div>
  </div>
  <div class="daily-progress-bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" id="ts-checklist-progress-bar">
    <i id="ts-checklist-progress-fill" style="width:<?= $pct ?>%"></i>
  </div>

  <?php if ($isSupport): ?>
  <div class="ts-checklist-summary" aria-label="Quick status" id="ts-checklist-summary">
    <div class="ts-checklist-chip<?= $review > 0 ? ' is-alert' : ' is-ok' ?>" data-ts-chip="review">
      <span>Queue</span><strong><?= $review ?></strong>
    </div>
    <div class="ts-checklist-chip<?= $unpaidPending > 0 ? ' is-alert' : ' is-ok' ?>" data-ts-chip="unpaid">
      <span>Unpaid</span><strong><?= $unpaidPending ?></strong>
    </div>
    <div class="ts-checklist-chip<?= $paidPending > 0 ? ' is-warn' : ' is-ok' ?>" data-ts-chip="paid">
      <span>Paid ready</span><strong><?= $paidPending ?></strong>
    </div>
    <div class="ts-checklist-chip<?= $reports > 0 ? ' is-alert' : ' is-ok' ?>" data-ts-chip="reports">
      <span>Reports</span><strong><?= $reports ?></strong>
    </div>
    <div class="ts-checklist-chip<?= $idPending > 0 ? ' is-alert' : ' is-ok' ?>" data-ts-chip="id">
      <span>ID pending</span><strong><?= $idPending ?></strong>
    </div>
  </div>

  <div class="table-wrap ts-checklist-table-wrap">
    <table class="ts-checklist-table">
      <thead>
        <tr>
          <th scope="col">#</th>
          <th scope="col">Task</th>
          <th scope="col">Check</th>
          <th scope="col">Status</th>
          <th scope="col">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tsChecklistRows as $row):
        $rowKey = 'ts-cl-' . (int) $row['num'];
        $hasExpand = $isSupport && !empty($row['expand_view']);
      ?>
        <tr class="ts-checklist-row<?= !empty($row['done']) ? ' is-done' : ' is-todo' ?><?= $hasExpand ? ' has-expand' : '' ?>"
            data-checklist-row="<?= htmlspecialchars($rowKey) ?>" data-ts-step="<?= (int) $row['num'] ?>">
          <td class="ts-checklist-num">
            <span class="ts-checklist-step" aria-hidden="true"><?= !empty($row['done']) ? '✓' : (int) $row['num'] ?></span>
          </td>
          <td class="ts-checklist-task">
            <strong><?= htmlspecialchars((string) $row['title']) ?></strong>
            <p><?= htmlspecialchars((string) $row['detail']) ?></p>
          </td>
          <td class="ts-checklist-metric">
            <span class="ts-checklist-metric-label"><?= htmlspecialchars((string) $row['metric_label']) ?></span>
            <strong><?= htmlspecialchars((string) $row['metric']) ?></strong>
          </td>
          <td class="ts-checklist-status">
            <span class="ts-checklist-pill is-<?= htmlspecialchars((string) $row['status_class']) ?>">
              <?= htmlspecialchars((string) $row['status']) ?>
            </span>
          </td>
          <td class="ts-checklist-action">
            <?php if (!empty($row['action'])): ?>
              <?php if ($hasExpand): ?>
                <button type="button" class="btn-sm <?= htmlspecialchars((string) $row['action_class']) ?>"
                        data-ts-expand-view="<?= htmlspecialchars((string) $row['expand_view']) ?>"
                        data-ts-expand-target="<?= htmlspecialchars($rowKey) ?>"
                        aria-expanded="false"
                        aria-controls="<?= htmlspecialchars($rowKey) ?>-panel">
                  <?= htmlspecialchars((string) $row['action_label']) ?>
                </button>
              <?php else: ?>
                <a class="btn-sm <?= htmlspecialchars((string) $row['action_class']) ?>" href="<?= htmlspecialchars((string) $row['action']) ?>">
                  <?= htmlspecialchars((string) $row['action_label']) ?>
                </a>
              <?php endif; ?>
            <?php else: ?>
              <span class="ts-checklist-pill is-ok"><?= htmlspecialchars((string) $row['status']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($hasExpand): ?>
        <tr class="ts-checklist-expand-row" id="<?= htmlspecialchars($rowKey) ?>-panel" hidden>
          <td colspan="5">
            <div class="ts-checklist-expand" data-ts-expand-view="<?= htmlspecialchars((string) $row['expand_view']) ?>">
              <header class="ts-checklist-expand-head">
                <div>
                  <span class="ts-checklist-expand-kicker">Step <?= (int) $row['num'] ?> · <?= htmlspecialchars($scopeRaw) ?></span>
                  <strong><?= htmlspecialchars((string) $row['title']) ?></strong>
                </div>
                <button type="button" class="ts-checklist-expand-close" data-ts-expand-close aria-label="Close">Close</button>
              </header>
              <div class="ts-checklist-expand-body" data-ts-expand-body></div>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($tsPendingCount === 0): ?>
    <p class="ts-checklist-foot hint" id="ts-checklist-foot">All routine checks are clear for <?= htmlspecialchars($scope) ?> today.</p>
  <?php else: ?>
    <p class="ts-checklist-foot hint" id="ts-checklist-foot"><?= (int) $tsPendingCount ?> step<?= $tsPendingCount === 1 ? '' : 's' ?> still need attention — use the Action column.</p>
  <?php endif; ?>

  <?php else: ?>
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
  <?php endif; ?>
</section>
