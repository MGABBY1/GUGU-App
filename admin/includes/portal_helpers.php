<?php
/**
 * Shared helpers for role-specific staff portals.
 */

function portalFlash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) {
        $_SESSION['portal_flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    if (empty($_SESSION['portal_flash'])) {
        return null;
    }
    $flash = $_SESSION['portal_flash'];
    unset($_SESSION['portal_flash']);
    return $flash;
}

/** Admin preview of District Manager / Moderator dashboards (session sticky). */
function portalPreviewClear(): void {
    unset($_SESSION['portal_preview_role'], $_SESSION['portal_preview_district']);
}

function portalPreviewSet(int $role, string $district = ''): void {
    if ($role === 2 || $role === 3) {
        $_SESSION['portal_preview_role'] = $role;
        $_SESSION['portal_preview_district'] = $district;
        return;
    }
    portalPreviewClear();
}

/** @return array{role:int,district:string} */
function portalPreviewGet(): array {
    $role = (int) ($_SESSION['portal_preview_role'] ?? 0);
    $district = trim((string) ($_SESSION['portal_preview_district'] ?? ''));
    if ($role !== 2 && $role !== 3) {
        return ['role' => 0, 'district' => ''];
    }
    return ['role' => $role, 'district' => $district];
}

/** True when Admin is locked into a District Manager / Moderator preview. */
function portalPreviewActive(): bool {
    if ((int) ($_SESSION['role_id'] ?? 0) !== 1) {
        return false;
    }
    $preview = portalPreviewGet();
    return $preview['role'] === 2 || $preview['role'] === 3;
}

/**
 * Build dashboard query string while keeping sticky preview params.
 *
 * @param array<string, scalar|null> $extra
 */
function portalPreviewQs(array $extra = []): string {
    $qs = [];
    if (portalPreviewActive()) {
        $preview = portalPreviewGet();
        $qs['view_role'] = $preview['role'];
        if ($preview['district'] !== '') {
            $qs['view_district'] = $preview['district'];
        }
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $qs[$k] = $v;
    }
    return $qs ? ('?' . http_build_query($qs)) : '';
}

function portalDistricts(): array {
    return [
        'Gasabo', 'Kicukiro', 'Nyarugenge',
        'Burera', 'Gakenke', 'Gicumbi', 'Musanze', 'Rulindo',
        'Gisagara', 'Huye', 'Kamonyi', 'Muhanga', 'Nyamagabe', 'Nyanza', 'Nyaruguru', 'Ruhango',
        'Bugesera', 'Gatsibo', 'Kayonza', 'Kirehe', 'Ngoma', 'Nyagatare', 'Rwamagana',
        'Karongi', 'Ngororero', 'Nyabihu', 'Nyamasheke', 'Rubavu', 'Rusizi', 'Rutsiro',
    ];
}

function portalRedirect(string $pane = ''): void {
    // Keep Admin on the dashboard they chose to preview until they exit explicitly.
    // Use ?pane= — browsers drop #hash on HTTP Location redirects.
    $url = '/gugu-app/admin/dashboard.php';
    $qs = [];
    $preview = portalPreviewGet();
    if ($pane === '') {
        $pane = trim((string) ($_POST['return_pane'] ?? ''));
    }
    $pane = trim(ltrim($pane, '#/'));
    $regionalPanes = [
        'home', 'checklist', 'item-approvals', 'job-approvals',
        'members', 'moderators', 'reports', 'escalate', 'id-queue',
    ];
    $supportPanes = [
        'home', 'listings', 'id-queue', 'checklist', 'reports',
    ];
    // Sticky preview wins over Admin-only destinations until exit_preview.
    if ((int) ($_SESSION['role_id'] ?? 0) === 1 && ($preview['role'] === 2 || $preview['role'] === 3)) {
        $qs['view_role'] = $preview['role'];
        if ($preview['district'] !== '') {
            $qs['view_district'] = $preview['district'];
        }
        $allowed = $preview['role'] === 2 ? $regionalPanes : $supportPanes;
        if ($pane !== '' && !in_array($pane, $allowed, true)) {
            // Map common Admin destinations back into the preview hub.
            if ($preview['role'] === 2 && in_array($pane, ['staff', 'users', 'listings', 'payments', 'system-controls', 'analytics', 'permissions', 'dashboards'], true)) {
                $pane = $pane === 'listings' ? 'item-approvals' : 'home';
            } elseif ($preview['role'] === 3 && in_array($pane, ['staff', 'users', 'members', 'payments', 'system-controls', 'item-approvals', 'job-approvals'], true)) {
                $pane = in_array($pane, ['item-approvals', 'job-approvals', 'payments'], true) ? 'listings' : 'home';
            } else {
                $pane = 'home';
            }
        }
        if ($pane === '') {
            $pane = 'home';
        }
    }
    if ($pane !== '' && $pane !== 'home') {
        $qs['pane'] = $pane;
    }
    if ($qs) {
        $url .= '?' . http_build_query($qs);
    }
    header('Location: ' . $url);
    exit;
}

function portalActionForm(string $action, array $fields, string $btnLabel, string $btnClass = 'btn-sm'): string {
    $html = '<form method="post" action="/gugu-app/admin/actions.php" class="portal-inline-form">';
    $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">';
    // Always keep Admin preview sticky on every action form.
    if (portalPreviewActive()) {
        $preview = portalPreviewGet();
        $html .= '<input type="hidden" name="view_role" value="' . (int) $preview['role'] . '">';
        if ($preview['district'] !== '') {
            $html .= '<input type="hidden" name="view_district" value="' . htmlspecialchars($preview['district']) . '">';
        }
    }
    // Return to the pane the staff was working on (cards / tables).
    $returnPane = trim((string) ($fields['return_pane'] ?? $_GET['pane'] ?? ''));
    unset($fields['return_pane']);
    if ($returnPane !== '') {
        $html .= '<input type="hidden" name="return_pane" value="' . htmlspecialchars($returnPane) . '">';
    }
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $value) . '">';
    }
    $html .= '<button type="submit" class="' . htmlspecialchars($btnClass) . '">' . htmlspecialchars($btnLabel) . '</button>';
    $html .= '</form>';
    return $html;
}

/**
 * Stats + queues for one business stream (item or job).
 *
 * @return array{
 *   type:string,label:string,fee:int,queue:array,all:array,
 *   review:int,paid_pending:int,unpaid_pending:int,active:int,total:int,
 *   fee_income:int,fee_income_month:int,paid_count:int,unpaid_value:int
 * }
 */
function portalBusinessStream(PDO $db, string $businessType, ?string $district = null): array {
    $type = $businessType === 'job' ? 'job' : 'item';
    // Approvals UI: Akazi = Job announcements (never marketplace items).
    $label = $type === 'job' ? 'Job announcement' : guguBusinessLabel($type);
    $fee = guguAnnounceFeeForBusiness($type);
    $jobCat = guguJobCategoryId();

    // Keep DB in sync: job category → business_type=job, everything else → item
    try {
        if ($jobCat > 0) {
            $db->prepare('UPDATE listings SET business_type = "job" WHERE category_id = ? AND business_type <> "job"')
               ->execute([$jobCat]);
            $db->prepare('UPDATE listings SET business_type = "item" WHERE (category_id IS NULL OR category_id <> ?) AND business_type <> "item"')
               ->execute([$jobCat]);
        }
    } catch (Throwable $e) {
        // ignore if column missing on older DB
    }

    // Strict split: Job Approvals = job announcements only; Item Approvals = items only.
    if ($type === 'job') {
        $scopeSql = 'l.business_type = "job"';
        $scopeParams = [];
    } else {
        $scopeSql = 'l.business_type = "item"';
        $scopeParams = [];
    }

    $district = $district !== null ? trim($district) : '';
    if ($district !== '') {
        $scopeSql .= ' AND l.district = ?';
        $scopeParams[] = $district;
    }

    $run = static function (string $sql, array $params = []) use ($db) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    };

    $review = (int) $run(
        "SELECT COUNT(*) FROM listings l WHERE {$scopeSql} AND l.moderation_status IN (\"pending\",\"flagged\")",
        $scopeParams
    )->fetchColumn();
    $paidPending = (int) $run(
        "SELECT COUNT(*) FROM listings l WHERE {$scopeSql} AND l.moderation_status = \"pending\" AND l.payment_status = \"paid\"",
        $scopeParams
    )->fetchColumn();
    $unpaidPending = (int) $run(
        "SELECT COUNT(*) FROM listings l WHERE {$scopeSql} AND l.moderation_status IN (\"pending\",\"flagged\") AND l.payment_status = \"unpaid\"",
        $scopeParams
    )->fetchColumn();
    $active = (int) $run(
        "SELECT COUNT(*) FROM listings l WHERE {$scopeSql} AND l.status = \"active\" AND l.moderation_status = \"approved\"",
        $scopeParams
    )->fetchColumn();
    $total = (int) $run(
        "SELECT COUNT(*) FROM listings l WHERE {$scopeSql}",
        $scopeParams
    )->fetchColumn();
    $feeIncome = (int) $run(
        "SELECT COALESCE(SUM(l.announce_fee_rwf),0) FROM listings l WHERE {$scopeSql} AND l.payment_status = \"paid\"",
        $scopeParams
    )->fetchColumn();
    $paidCount = (int) $run(
        "SELECT COUNT(*) FROM listings l WHERE {$scopeSql} AND l.payment_status = \"paid\"",
        $scopeParams
    )->fetchColumn();
    $unpaidValue = (int) $run(
        "SELECT COALESCE(SUM(l.announce_fee_rwf),0) FROM listings l WHERE {$scopeSql} AND l.payment_status = \"unpaid\" AND l.moderation_status IN (\"pending\",\"flagged\")",
        $scopeParams
    )->fetchColumn();

    try {
        $feeIncomeMonth = (int) $run(
            "SELECT COALESCE(SUM(l.announce_fee_rwf),0) FROM listings l WHERE {$scopeSql} AND l.payment_status = \"paid\" AND COALESCE(l.updated_at, l.created_at) >= DATE_FORMAT(NOW(), \"%Y-%m-01\")",
            $scopeParams
        )->fetchColumn();
    } catch (Throwable $e) {
        $feeIncomeMonth = (int) $run(
            "SELECT COALESCE(SUM(l.announce_fee_rwf),0) FROM listings l WHERE {$scopeSql} AND l.payment_status = \"paid\" AND l.created_at >= DATE_FORMAT(NOW(), \"%Y-%m-01\")",
            $scopeParams
        )->fetchColumn();
    }

    $queue = $run("
        SELECT l.id, l.title, l.district, l.sector, l.price, l.is_free, l.moderation_status, l.payment_status,
               l.announce_fee_rwf, l.user_id, l.created_at, l.updated_at, l.category_id, l.business_type,
               l.status, u.nickname AS seller_name, u.phone AS seller_phone, u.email AS seller_email
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE {$scopeSql} AND l.moderation_status IN (\"pending\",\"flagged\")
        ORDER BY FIELD(l.moderation_status, \"pending\", \"flagged\"), l.created_at DESC
        LIMIT 120
    ", $scopeParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $all = $run("
        SELECT l.id, l.title, l.district, l.sector, l.price, l.is_free, l.moderation_status, l.payment_status,
               l.announce_fee_rwf, l.user_id, l.created_at, l.updated_at, l.category_id, l.business_type,
               l.status, u.nickname AS seller_name, u.phone AS seller_phone, u.email AS seller_email
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE {$scopeSql}
        ORDER BY FIELD(l.moderation_status, \"pending\", \"flagged\", \"approved\", \"rejected\"),
                 l.created_at DESC
        LIMIT 120
    ", $scopeParams)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Hard safety net: never leak the other business into this stream
    $filterRows = static function (array $rows) use ($type): array {
        return array_values(array_filter($rows, static function ($r) use ($type) {
            $biz = strtolower(trim((string) ($r['business_type'] ?? '')));
            if ($type === 'job') {
                return $biz === 'job';
            }
            return $biz === 'item' || $biz === '';
        }));
    };
    $queue = $filterRows($queue);
    $all = $filterRows($all);

    return [
        'type' => $type,
        'label' => $label,
        'fee' => $fee,
        'queue' => $queue,
        'all' => $all,
        'review' => $review,
        'paid_pending' => $paidPending,
        'unpaid_pending' => $unpaidPending,
        'active' => $active,
        'total' => $total,
        'fee_income' => $feeIncome,
        'fee_income_month' => $feeIncomeMonth,
        'paid_count' => $paidCount,
        'unpaid_value' => $unpaidValue,
    ];
}

/** CSS class for payment / moderation / listing status pills */
function portalStatusPillClass(string $status): string {
    $s = strtolower(trim($status));
    return match ($s) {
        'paid', 'waived', 'approved', 'active', 'resolved' => 'status-pill status-ok',
        'unpaid', 'pending', 'flagged', 'reviewing', 'open', 'reserved' => 'status-pill status-warn',
        'rejected', 'banned', 'suspended', 'dismissed', 'sold' => 'status-pill status-bad',
        default => 'status-pill',
    };
}

/** Format listing price for approval tables. */
function portalListingPriceLabel(array $listing): string {
    if (!empty($listing['is_free'])) {
        return 'Free';
    }
    $price = (int) ($listing['price'] ?? 0);
    return $price > 0 ? (number_format($price) . ' RWF') : '—';
}

/**
 * District hub stream: local review queue + full nationwide directory table.
 *
 * @return array<string,mixed>
 */
function portalBusinessStreamForDistrictHub(PDO $db, string $businessType, string $district): array {
    $district = trim($district);
    $local = portalBusinessStream($db, $businessType, $district !== '' ? $district : null);
    $nation = portalBusinessStream($db, $businessType, null);
    $local['local_total'] = (int) ($local['total'] ?? 0);
    $local['all'] = is_array($nation['all'] ?? null) ? $nation['all'] : [];
    $local['total'] = (int) ($nation['total'] ?? count($local['all']));
    $local['highlight_district'] = $district;
    return $local;
}

/** Render one approval table row. */
function portalRenderApprovalRow(array $l, array $ctx): void {
    $fee = (int) ($ctx['fee'] ?? 0);
    $label = (string) ($ctx['label'] ?? 'Item');
    $returnPane = (string) ($ctx['return_pane'] ?? 'item-approvals');
    $inQueue = !empty($ctx['in_queue']);
    $highlightDistrict = trim((string) ($ctx['highlight_district'] ?? ''));
    $actionDistrict = trim((string) ($ctx['action_district'] ?? ''));

    $pay = (string) ($l['payment_status'] ?? 'unpaid');
    $mod = (string) ($l['moderation_status'] ?? 'pending');
    $st = (string) ($l['status'] ?? '—');
    $payReady = in_array($pay, ['paid', 'waived'], true);
    $posted = $l['created_at'] ?? '';
    $sector = trim((string) ($l['sector'] ?? ''));
    $rowDistrict = trim((string) ($l['district'] ?? ''));
    $place = $rowDistrict !== '' ? $rowDistrict : '—';
    if ($sector !== '') {
        $place .= ' · ' . $sector;
    }
    $isLocal = $highlightDistrict !== '' && strcasecmp($rowDistrict, $highlightDistrict) === 0;
    $canAct = $actionDistrict === '' || strcasecmp($rowDistrict, $actionDistrict) === 0;
    $sellerEmail = trim((string) ($l['seller_email'] ?? ''));
    $rowClass = [];
    if ($inQueue) {
        $rowClass[] = $payReady ? 'row-paid-ready' : 'row-unpaid';
    }
    if ($isLocal) {
        $rowClass[] = 'row-local-district';
    }
    if (!$canAct && !$inQueue) {
        $rowClass[] = 'row-outside-district';
    }
    ?>
    <tr class="<?= htmlspecialchars(implode(' ', $rowClass)) ?>">
      <td><span class="approvals-id">#<?= (int) $l['id'] ?></span></td>
      <td>
        <strong class="approvals-post-title"><?= htmlspecialchars((string) ($l['title'] ?? 'Untitled')) ?></strong>
        <br><small class="muted"><?= htmlspecialchars(portalListingPriceLabel($l)) ?></small>
        <?php if ($isLocal && $highlightDistrict !== ''): ?>
          <br><span class="approvals-local-tag">Your Akarere</span>
        <?php endif; ?>
      </td>
      <td>
        <span class="approvals-poster"><?= htmlspecialchars($l['seller_name'] ?: ('User #' . (int) ($l['user_id'] ?? 0))) ?></span>
        <?php if (!empty($l['seller_phone'])): ?>
          <br><small class="muted"><?= htmlspecialchars((string) $l['seller_phone']) ?></small>
        <?php endif; ?>
      </td>
      <td class="id-cell-nowrap"><?= $sellerEmail !== '' ? htmlspecialchars($sellerEmail) : '<span class="muted">—</span>' ?></td>
      <td><?= htmlspecialchars($place) ?></td>
      <td>
        <span class="approvals-fee"><?= number_format((int) ($l['announce_fee_rwf'] ?? $fee)) ?> RWF</span>
        <br><span class="<?= htmlspecialchars(portalStatusPillClass($pay)) ?>"><?= htmlspecialchars($pay) ?></span>
      </td>
      <td><span class="<?= htmlspecialchars(portalStatusPillClass($mod)) ?>"><?= htmlspecialchars($mod) ?></span></td>
      <?php if (!$inQueue): ?>
        <td><span class="<?= htmlspecialchars(portalStatusPillClass($st)) ?>"><?= htmlspecialchars($st) ?></span></td>
      <?php endif; ?>
      <td><small class="muted"><?= $posted !== '' ? htmlspecialchars(date('d M Y', strtotime((string) $posted))) : '—' ?></small></td>
      <td class="portal-actions">
        <?php if (!$canAct): ?>
          <span class="muted">Outside your Akarere</span>
        <?php elseif (in_array($mod, ['pending', 'flagged'], true)): ?>
          <?php if (!$payReady): ?>
            <?= portalActionForm('mark-listing-paid', ['listing_id' => $l['id'], 'payment_note' => $label . ' MoMo ' . $fee . ' RWF', 'return_pane' => $returnPane], 'Mark paid', 'btn-sm warn') ?>
          <?php endif; ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'approved', 'return_pane' => $returnPane], $inQueue ? 'Approve (live)' : 'Approve', 'btn-sm ok') ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'rejected', 'return_pane' => $returnPane], 'Reject', 'btn-sm danger') ?>
        <?php elseif ($mod === 'approved'): ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'flagged', 'return_pane' => $returnPane], 'Flag', 'btn-sm warn') ?>
        <?php else: ?>
          <?= portalActionForm('moderate-listing', ['listing_id' => $l['id'], 'moderation_status' => 'pending', 'return_pane' => $returnPane], 'Re-queue', 'btn-sm') ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php
}

/** Render Item or Job approvals pane body (queue + all posts). */
function portalRenderBusinessApprovals(array $stream): void {
    $fee = (int) $stream['fee'];
    $label = (string) $stream['label'];
    $type = (string) ($stream['type'] ?? 'item');
    $queue = is_array($stream['queue'] ?? null) ? $stream['queue'] : [];
    $all = is_array($stream['all'] ?? null) ? $stream['all'] : [];
    $isJob = $type === 'job';
    $returnPane = $isJob ? 'job-approvals' : 'item-approvals';
    $onlyNote = $isJob
        ? 'Akazi job announcements only — marketplace items stay in Item Approvals.'
        : 'Gurisha items only — job announcements stay in Job Approvals.';
    $boardClass = 'approvals-board approvals-board--' . ($isJob ? 'job' : 'item');
    $directoryTitle = $isJob
        ? 'All Job announcements in database'
        : 'All Item posts in database';
    $emptyDirectory = $isJob
        ? 'No job announcements in database yet'
        : 'No item posts in database yet';
    $highlightDistrict = trim((string) ($stream['highlight_district'] ?? ''));
    $actionDistrict = array_key_exists('action_district', $stream)
        ? trim((string) $stream['action_district'])
        : '';
    $localTotal = (int) ($stream['local_total'] ?? 0);
    $dbTotal = max((int) ($stream['total'] ?? 0), count($all));
    $rowCtx = [
        'fee' => $fee,
        'label' => $label,
        'return_pane' => $returnPane,
        'highlight_district' => $highlightDistrict,
        'action_district' => $actionDistrict,
    ];
    $showDirectoryFirst = count($queue) === 0;
    ?>
    <div class="<?= htmlspecialchars($boardClass) ?>">
      <section class="approvals-summary panel">
        <div class="rw-flag-bar thin" aria-hidden="true">
          <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
        </div>
        <div class="approvals-summary-top">
          <div>
            <span class="approvals-eyebrow"><?= $isJob ? 'Akazi · Job announcements' : 'Gurisha queue' ?></span>
            <h3 class="approvals-title"><?= htmlspecialchars($label) ?> review board</h3>
            <p class="approvals-lead">
              <?= htmlspecialchars($onlyNote) ?>
              Fee <?= number_format($fee) ?> RWF · Admin earns when you Approve.
              <?php if ($highlightDistrict !== ''): ?>
                Review queue is for <strong><?= htmlspecialchars($highlightDistrict) ?></strong>;
                directory lists every <?= $isJob ? 'job announcement' : strtolower($label) ?> in the database.
              <?php endif; ?>
            </p>
          </div>
          <div class="approvals-fee-badge" title="Announce fee">
            <span>Fee</span>
            <strong><?= number_format($fee) ?> <small>RWF</small></strong>
          </div>
        </div>
        <div class="approvals-metrics" role="list">
          <div class="approvals-metric is-wait" role="listitem">
            <span>Waiting<?= $highlightDistrict !== '' ? ' (local)' : '' ?></span>
            <strong><?= (int) $stream['review'] ?></strong>
          </div>
          <div class="approvals-metric is-paid" role="listitem">
            <span>Paid &amp; ready</span>
            <strong><?= (int) $stream['paid_pending'] ?></strong>
          </div>
          <div class="approvals-metric is-unpaid" role="listitem">
            <span>Unpaid</span>
            <strong><?= (int) $stream['unpaid_pending'] ?></strong>
          </div>
          <div class="approvals-metric is-live" role="listitem">
            <span>Live<?= $highlightDistrict !== '' ? ' (local)' : '' ?></span>
            <strong><?= (int) $stream['active'] ?></strong>
          </div>
          <div class="approvals-metric is-total" role="listitem">
            <span>In database</span>
            <strong><?= $dbTotal ?></strong>
          </div>
        </div>
      </section>

      <?php
      $renderQueue = static function () use ($queue, $label, $all, $rowCtx, $highlightDistrict): void {
          ?>
      <section class="approvals-block panel approvals-block--queue">
        <header class="approvals-block-head">
          <div>
            <span class="approvals-block-badge is-queue">Needs review</span>
            <h4 class="panel-subhead">
              <?= htmlspecialchars($label) ?> queue
              <?php if ($highlightDistrict !== ''): ?>
                · <?= htmlspecialchars($highlightDistrict) ?>
              <?php endif; ?>
            </h4>
            <p class="approvals-block-sub">Confirm MoMo → Mark paid → Approve (live). Reject spam / fakes.</p>
          </div>
          <span class="approvals-count"><?= count($queue) ?> in queue</span>
        </header>
        <?php if (!$queue): ?>
          <div class="approvals-empty">
            <strong>Queue clear</strong>
            <p>
              No pending <?= htmlspecialchars(strtolower($label)) ?> posts
              <?= $highlightDistrict !== '' ? (' in ' . htmlspecialchars($highlightDistrict)) : '' ?>.
              <?php if ($all): ?>
                <?= count($all) ?> post<?= count($all) === 1 ? '' : 's' ?> from the database <?= count($all) === 1 ? 'is' : 'are' ?> listed in the directory.
              <?php endif; ?>
            </p>
          </div>
        <?php else: ?>
          <div class="table-wrap approvals-table-wrap">
            <table class="approvals-table">
              <thead>
                <tr>
                  <th>ID</th><th>Title / Price</th><th>Poster</th><th>Email</th><th>Place</th>
                  <th>Fee / Pay</th><th>Moderation</th><th>Posted</th><th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($queue as $l) {
                  portalRenderApprovalRow($l, $rowCtx + ['in_queue' => true]);
              } ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
          <?php
      };

      $renderDirectory = static function () use ($all, $label, $onlyNote, $dbTotal, $localTotal, $rowCtx, $highlightDistrict, $type, $directoryTitle, $emptyDirectory, $isJob): void {
          ?>
      <section class="approvals-block panel approvals-block--all" id="approvals-all-<?= htmlspecialchars($type) ?>">
        <header class="approvals-block-head">
          <div>
            <span class="approvals-block-badge is-all">Directory</span>
            <h4 class="panel-subhead"><?= htmlspecialchars($directoryTitle) ?></h4>
            <p class="approvals-block-sub">
              <?= htmlspecialchars($onlyNote) ?>
              Showing <strong><?= count($all) ?></strong> <?= $isJob ? 'job announcement' : 'post' ?><?= count($all) === 1 ? '' : 's' ?>
              (database total <strong><?= $dbTotal ?></strong>)
              <?php if ($highlightDistrict !== ''): ?>
                · <strong><?= (int) $localTotal ?></strong> in <?= htmlspecialchars($highlightDistrict) ?>
              <?php endif; ?>.
            </p>
          </div>
          <span class="approvals-count"><?= count($all) ?> shown</span>
        </header>
        <?php if (!$all): ?>
          <div class="approvals-empty">
            <strong><?= htmlspecialchars($emptyDirectory) ?></strong>
            <p>When members post <?= $isJob ? 'an Akazi job announcement' : 'a Gurisha item' ?>, it will appear here automatically.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap approvals-table-wrap">
            <table class="approvals-table approvals-table--all">
              <thead>
                <tr>
                  <th>ID</th><th>Title / Price</th><th>Poster</th><th>Email</th><th>Place</th>
                  <th>Fee / Pay</th><th>Moderation</th><th>Status</th><th>Posted</th><th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($all as $l) {
                  portalRenderApprovalRow($l, $rowCtx + ['in_queue' => false]);
              } ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
          <?php
      };

      if ($showDirectoryFirst) {
          $renderDirectory();
          $renderQueue();
      } else {
          $renderQueue();
          $renderDirectory();
      }
      ?>
    </div>
    <?php
}

/**
 * Payment alerts for Admin / District Manager — Items vs Jobs.
 *
 * @return array{
 *   unpaid:array,paid_ready:array,recent_paid:array,
 *   unpaid_count:int,paid_ready_count:int,recent_paid_count:int,total:int,
 *   item_unpaid:array,item_paid:array,job_unpaid:array,job_paid:array,
 *   item_unpaid_count:int,item_paid_count:int,job_unpaid_count:int,job_paid_count:int
 * }
 */
function portalPaymentAlerts(PDO $db, ?string $district = null): array {
    $district = $district !== null ? trim($district) : '';
    $distSql = $district !== '' ? ' AND l.district = ?' : '';
    $distParams = $district !== '' ? [$district] : [];

    $fetch = static function (string $sql, array $params) use ($db): array {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    };

    $normalize = static function (array $rows): array {
        foreach ($rows as &$row) {
            $biz = (string) ($row['business_type'] ?? '');
            if ($biz !== 'item' && $biz !== 'job') {
                $biz = guguBusinessTypeFromCategory((int) ($row['category_id'] ?? 0));
            }
            $row['business_type'] = $biz;
            $row['business_label'] = guguBusinessLabel($biz);
        }
        unset($row);
        return $rows;
    };

    $unpaid = $normalize($fetch("
        SELECT l.id, l.title, l.district, l.business_type, l.category_id, l.announce_fee_rwf,
               l.payment_status, l.moderation_status, l.created_at, l.user_id,
               u.nickname AS seller_name, u.phone AS seller_phone
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.moderation_status IN ('pending','flagged')
          AND l.payment_status = 'unpaid'
          {$distSql}
        ORDER BY l.created_at DESC
        LIMIT 40
    ", $distParams));

    $paidReady = $normalize($fetch("
        SELECT l.id, l.title, l.district, l.business_type, l.category_id, l.announce_fee_rwf,
               l.payment_status, l.moderation_status, l.paid_at, l.created_at, l.user_id,
               u.nickname AS seller_name, u.phone AS seller_phone
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.moderation_status IN ('pending','flagged')
          AND l.payment_status = 'paid'
          {$distSql}
        ORDER BY COALESCE(l.paid_at, l.created_at) DESC
        LIMIT 40
    ", $distParams));

    $recentPaid = $normalize($fetch("
        SELECT l.id, l.title, l.district, l.business_type, l.category_id, l.announce_fee_rwf,
               l.payment_status, l.moderation_status, l.status, l.paid_at, l.user_id,
               u.nickname AS seller_name
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.payment_status = 'paid'
          AND l.paid_at IS NOT NULL
          AND l.paid_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
          {$distSql}
        ORDER BY l.paid_at DESC
        LIMIT 20
    ", $distParams));

    $splitBiz = static function (array $rows, string $type): array {
        return array_values(array_filter($rows, static fn($r) => ($r['business_type'] ?? '') === $type));
    };

    $itemUnpaid = $splitBiz($unpaid, 'item');
    $jobUnpaid = $splitBiz($unpaid, 'job');
    $itemPaid = $splitBiz($paidReady, 'item');
    $jobPaid = $splitBiz($paidReady, 'job');

    $unpaidCount = count($unpaid);
    $paidReadyCount = count($paidReady);

    return [
        'unpaid' => $unpaid,
        'paid_ready' => $paidReady,
        'recent_paid' => $recentPaid,
        'unpaid_count' => $unpaidCount,
        'paid_ready_count' => $paidReadyCount,
        'recent_paid_count' => count($recentPaid),
        'total' => $unpaidCount + $paidReadyCount,
        'item_unpaid' => $itemUnpaid,
        'item_paid' => $itemPaid,
        'job_unpaid' => $jobUnpaid,
        'job_paid' => $jobPaid,
        'item_unpaid_count' => count($itemUnpaid),
        'item_paid_count' => count($itemPaid),
        'job_unpaid_count' => count($jobUnpaid),
        'job_paid_count' => count($jobPaid),
    ];
}

/**
 * Members waiting for staff response (ID review after register / upload).
 *
 * @return array{pending:array,pending_count:int,total:int}
 */
function portalMemberAlerts(PDO $db, ?string $district = null): array {
    $data = portalIdVerificationData($db, $district);
    $queue = $data['queue'] ?? [];
    $pending = (int) ($data['pending'] ?? count($queue));
    return [
        'pending' => $queue,
        'pending_count' => $pending,
        'total' => $pending,
    ];
}

function portalEmptyPaymentAlerts(): array {
    return [
        'unpaid' => [],
        'paid_ready' => [],
        'recent_paid' => [],
        'unpaid_count' => 0,
        'paid_ready_count' => 0,
        'recent_paid_count' => 0,
        'total' => 0,
        'item_unpaid' => [],
        'item_paid' => [],
        'job_unpaid' => [],
        'job_paid' => [],
        'item_unpaid_count' => 0,
        'item_paid_count' => 0,
        'job_unpaid_count' => 0,
        'job_paid_count' => 0,
    ];
}

/** One notification message row (HTML). */
function portalPaymentBellMsgHtml(array $msg): string {
    $kind = htmlspecialchars((string) ($msg['kind'] ?? 'unpaid'));
    $html = '<article class="pay-bell-msg is-' . $kind . '">';
    $html .= '<div class="pay-bell-msg-body">';
    $html .= '<strong>' . htmlspecialchars((string) ($msg['title'] ?? '')) . '</strong>';
    $html .= '<p>' . htmlspecialchars((string) ($msg['body'] ?? '')) . '</p>';
    if (($msg['meta'] ?? '') !== '') {
        $html .= '<small>' . htmlspecialchars((string) $msg['meta']) . '</small>';
    }
    $html .= '</div>';
    if (($msg['action'] ?? '') === 'mark_paid') {
        $html .= '<div class="pay-bell-msg-actions">';
        $html .= portalActionForm('mark-listing-paid', [
            'listing_id' => (int) $msg['id'],
            'payment_note' => 'MoMo received · auto publish',
            'auto_publish' => '1',
            'return_pane' => (($msg['biz'] ?? '') === 'job') ? 'job-approvals' : 'item-approvals',
        ], 'Mark paid → live', 'btn-sm warn');
        $html .= '</div>';
    } elseif (($msg['action'] ?? '') === 'approve') {
        $html .= '<div class="pay-bell-msg-actions">';
        $html .= portalActionForm('moderate-listing', [
            'listing_id' => (int) $msg['id'],
            'moderation_status' => 'approved',
            'return_pane' => (($msg['biz'] ?? '') === 'job') ? 'job-approvals' : 'item-approvals',
        ], 'Approve live', 'btn-sm ok');
        $html .= '</div>';
    } elseif (($msg['action'] ?? '') === 'review_id') {
        $html .= '<div class="pay-bell-msg-actions">';
        $html .= portalActionForm('review-id', [
            'user_id' => (int) $msg['id'],
            'id_status' => 'approved',
            'return_pane' => 'id-queue',
        ], 'Approve ID', 'btn-sm ok');
        $html .= portalActionForm('review-id', [
            'user_id' => (int) $msg['id'],
            'id_status' => 'rejected',
            'id_reject_reason' => 'Unclear document — please resubmit',
            'return_pane' => 'id-queue',
        ], 'Reject', 'btn-sm danger');
        $html .= '</div>';
    }
    $html .= '</article>';
    return $html;
}

/**
 * Notifications panel — Items, Jobs, and Members waiting for ID review.
 */
function portalRenderPaymentBell(array $alerts, string $scopeLabel = 'Nationwide', ?array $memberAlerts = null): void {
    $itemUnpaid = $alerts['item_unpaid'] ?? [];
    $itemPaid = $alerts['item_paid'] ?? [];
    $jobUnpaid = $alerts['job_unpaid'] ?? [];
    $jobPaid = $alerts['job_paid'] ?? [];
    $itemUnpaidCount = (int) ($alerts['item_unpaid_count'] ?? count($itemUnpaid));
    $itemPaidCount = (int) ($alerts['item_paid_count'] ?? count($itemPaid));
    $jobUnpaidCount = (int) ($alerts['job_unpaid_count'] ?? count($jobUnpaid));
    $jobPaidCount = (int) ($alerts['job_paid_count'] ?? count($jobPaid));
    $memberPending = $memberAlerts['pending'] ?? [];
    $memberPendingCount = (int) ($memberAlerts['pending_count'] ?? count($memberPending));
    $badge = $itemUnpaidCount + $itemPaidCount + $jobUnpaidCount + $jobPaidCount + $memberPendingCount;

    $toMsg = static function (array $row, string $kind, string $action): array {
        $fee = (int) ($row['announce_fee_rwf'] ?? 0);
        $titlePost = (string) ($row['title'] ?? 'Post');
        $seller = (string) ($row['seller_name'] ?: 'Member');
        $biz = (string) ($row['business_type'] ?? 'item');
        if ($kind === 'unpaid') {
            return [
                'kind' => 'unpaid',
                'title' => 'Waiting for MoMo',
                'body' => $titlePost . ' · ' . $seller . ' · ' . number_format($fee) . ' RWF unpaid',
                'meta' => '#' . (int) $row['id'] . ' · ' . ($row['district'] ?? '—'),
                'id' => (int) $row['id'],
                'biz' => $biz,
                'action' => $action,
            ];
        }
        return [
            'kind' => 'paid',
            'title' => 'Paid — approve to go live',
            'body' => $titlePost . ' · ' . $seller . ' · ' . number_format($fee) . ' RWF paid',
            'meta' => '#' . (int) $row['id'] . ' · ' . ($row['district'] ?? '—'),
            'id' => (int) $row['id'],
            'biz' => $biz,
            'action' => $action,
        ];
    };

    $idQueueHref = '?pane=id-queue';
    if (portalPreviewActive()) {
        $preview = portalPreviewGet();
        $idQueueHref = '?' . http_build_query(array_filter([
            'view_role' => $preview['role'],
            'view_district' => $preview['district'] !== '' ? $preview['district'] : null,
            'pane' => 'id-queue',
        ]));
    }

    $papers = [
        [
            'key' => 'item',
            'title' => 'Items',
            'tag' => 'Gurisha',
            'unpaid' => $itemUnpaid,
            'paid' => $itemPaid,
            'unpaid_count' => $itemUnpaidCount,
            'paid_count' => $itemPaidCount,
        ],
        [
            'key' => 'job',
            'title' => 'Job announcements',
            'tag' => 'Akazi',
            'unpaid' => $jobUnpaid,
            'paid' => $jobPaid,
            'unpaid_count' => $jobUnpaidCount,
            'paid_count' => $jobPaidCount,
        ],
    ];
    ?>
    <div class="pay-bell" id="pay-bell">
      <button type="button" class="pay-bell-btn" id="pay-bell-toggle" aria-expanded="false" aria-controls="pay-bell-panel" title="Staff notifications">
        <span class="pay-bell-ico" aria-hidden="true">🔔</span>
        <span class="pay-bell-text">
          <span class="pay-bell-label">Alerts</span>
          <span class="pay-bell-sub">Members <?= (int) $memberPendingCount ?> · Items <?= (int) $itemPaidCount ?>/<?= (int) $itemUnpaidCount ?> · Jobs <?= (int) $jobPaidCount ?>/<?= (int) $jobUnpaidCount ?></span>
        </span>
        <?php if ($badge > 0): ?>
          <span class="pay-bell-badge"><?= $badge > 99 ? '99+' : $badge ?></span>
        <?php endif; ?>
      </button>
      <div class="pay-bell-panel pay-bell-panel--papers" id="pay-bell-panel" hidden>
        <header class="pay-bell-head">
          <div>
            <strong>Notifications</strong>
            <span><?= htmlspecialchars($scopeLabel) ?> · Members ID · Items &amp; Jobs</span>
          </div>
          <button type="button" class="pay-bell-close" id="pay-bell-close" aria-label="Close">×</button>
        </header>

        <div class="pay-bell-list">
          <section class="pay-paper is-member">
            <header class="pay-paper-head">
              <div>
                <span class="pay-paper-tag">Members</span>
                <h4>ID verification</h4>
              </div>
              <div class="pay-paper-counts">
                <span class="pay-paper-chip is-unpaid"><?= (int) $memberPendingCount ?> waiting</span>
              </div>
            </header>
            <div class="pay-paper-block is-unpaid">
              <div class="pay-paper-block-head">
                <strong>Waiting for your response</strong>
                <span>Registered members who submitted ID</span>
              </div>
              <?php if (!$memberPending): ?>
                <p class="pay-paper-empty">No member IDs waiting for review.</p>
              <?php else: ?>
                <?php foreach ($memberPending as $u):
                    $nick = (string) ($u['nickname'] ?: 'Member');
                    $phone = (string) ($u['phone'] ?? '');
                    $idNum = (string) ($u['id_number'] ?? '');
                    echo portalPaymentBellMsgHtml([
                        'kind' => 'member',
                        'title' => 'Member ID needs review',
                        'body' => $nick . ($phone !== '' ? (' · ' . $phone) : '') . ($idNum !== '' ? (' · ID ' . $idNum) : ''),
                        'meta' => '#' . (int) $u['id'] . ' · ' . ($u['district'] ?? '—'),
                        'id' => (int) $u['id'],
                        'action' => 'review_id',
                    ]);
                endforeach; ?>
              <?php endif; ?>
            </div>
            <div class="pay-paper-block">
              <div class="pay-bell-msg-actions" style="padding:8px 10px 12px">
                <a class="btn-sm" href="<?= htmlspecialchars($idQueueHref) ?>">Open full ID queue</a>
              </div>
            </div>
          </section>

          <?php foreach ($papers as $paper): ?>
            <section class="pay-paper is-<?= htmlspecialchars($paper['key']) ?>">
              <header class="pay-paper-head">
                <div>
                  <span class="pay-paper-tag"><?= htmlspecialchars($paper['tag']) ?></span>
                  <h4><?= htmlspecialchars($paper['title']) ?></h4>
                </div>
                <div class="pay-paper-counts">
                  <span class="pay-paper-chip is-paid"><?= (int) $paper['paid_count'] ?> paid</span>
                  <span class="pay-paper-chip is-unpaid"><?= (int) $paper['unpaid_count'] ?> unpaid</span>
                </div>
              </header>

              <div class="pay-paper-block is-unpaid">
                <div class="pay-paper-block-head">
                  <strong>Unpaid</strong>
                  <span>Waiting for MoMo</span>
                </div>
                <?php if (!$paper['unpaid']): ?>
                  <p class="pay-paper-empty">No unpaid <?= htmlspecialchars(strtolower($paper['title'])) ?>.</p>
                <?php else: ?>
                  <?php foreach ($paper['unpaid'] as $row) {
                      echo portalPaymentBellMsgHtml($toMsg($row, 'unpaid', 'mark_paid'));
                  } ?>
                <?php endif; ?>
              </div>

              <div class="pay-paper-block is-paid">
                <div class="pay-paper-block-head">
                  <strong>Paid</strong>
                  <span>Ready to approve</span>
                </div>
                <?php if (!$paper['paid']): ?>
                  <p class="pay-paper-empty">No paid <?= htmlspecialchars(strtolower($paper['title'])) ?> waiting.</p>
                <?php else: ?>
                  <?php foreach ($paper['paid'] as $row) {
                      echo portalPaymentBellMsgHtml($toMsg($row, 'paid', 'approve'));
                  } ?>
                <?php endif; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
        <footer class="pay-bell-foot">
          <a href="<?= htmlspecialchars($idQueueHref) ?>">Member ID queue</a>
          <a href="#payment-notifications" id="pay-bell-see-all">Payment board</a>
        </footer>
      </div>
    </div>
    <script>
    (function () {
      var root = document.getElementById('pay-bell');
      if (!root) return;
      var btn = document.getElementById('pay-bell-toggle');
      var panel = document.getElementById('pay-bell-panel');
      var closeBtn = document.getElementById('pay-bell-close');
      function open() {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        root.classList.add('is-open');
      }
      function close() {
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        root.classList.remove('is-open');
      }
      function toggle() {
        if (panel.hidden) open(); else close();
      }
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        toggle();
      });
      if (closeBtn) closeBtn.addEventListener('click', close);
      document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) close();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
      });
      var seeAll = document.getElementById('pay-bell-see-all');
      if (seeAll) {
        seeAll.addEventListener('click', function () {
          close();
          var board = document.getElementById('payment-notifications');
          if (board) board.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }
    })();
    </script>
    <?php
}

/** Dashboard payment board — Items paper + Jobs paper. */
function portalRenderPaymentNotifications(array $alerts, string $scopeLabel = 'Nationwide'): void {
    $itemPaid = (int) ($alerts['item_paid_count'] ?? 0);
    $itemUnpaid = (int) ($alerts['item_unpaid_count'] ?? 0);
    $jobPaid = (int) ($alerts['job_paid_count'] ?? 0);
    $jobUnpaid = (int) ($alerts['job_unpaid_count'] ?? 0);
    ?>
    <section class="pay-notify panel" id="payment-notifications" aria-label="Payment notifications">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <header class="pay-notify-head">
        <div>
          <span class="pay-notify-kicker">Notifications · <?= htmlspecialchars($scopeLabel) ?></span>
          <h3>Items &amp; Job announcements</h3>
          <p>Two papers: Gurisha (Items) and Akazi (Jobs). Each shows paid and unpaid separately.</p>
        </div>
      </header>
      <div class="pay-notify-papers">
        <article class="pay-notify-paper is-item">
          <header>
            <span class="pay-paper-tag">Gurisha</span>
            <h4>Items</h4>
          </header>
          <div class="pay-notify-paper-stats">
            <span class="is-paid"><strong><?= $itemPaid ?></strong> paid</span>
            <span class="is-unpaid"><strong><?= $itemUnpaid ?></strong> unpaid</span>
          </div>
        </article>
        <article class="pay-notify-paper is-job">
          <header>
            <span class="pay-paper-tag">Akazi</span>
            <h4>Job announcements</h4>
          </header>
          <div class="pay-notify-paper-stats">
            <span class="is-paid"><strong><?= $jobPaid ?></strong> paid</span>
            <span class="is-unpaid"><strong><?= $jobUnpaid ?></strong> unpaid</span>
          </div>
        </article>
      </div>
      <p class="hint" style="margin:0">Open <strong>Alerts</strong> in the top bar for the full separated message list and quick actions.</p>
    </section>
    <?php
}

/**
 * Member ID verification stats (+ optional district filter for Support).
 *
 * @return array{pending:int,approved:int,rejected:int,none:int,queue:array,recent:array}
 */
function portalIdVerificationData(PDO $db, ?string $district = null): array {
    $scope = 'role_id = 4';
    $params = [];
    if ($district !== null && $district !== '') {
        $scope .= ' AND district = ?';
        $params[] = $district;
    }

    $count = static function (string $status) use ($db, $scope, $params): int {
        try {
            $sql = "SELECT COUNT(*) FROM users WHERE {$scope} AND id_status = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge($params, [$status]));
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };

    $queue = [];
    $recent = [];
    try {
        $sqlQueue = "
            SELECT id, nickname, phone, email, district, id_number, id_document_path, id_status, id_reject_reason, created_at, updated_at
            FROM users
            WHERE {$scope} AND id_status = 'pending'
            ORDER BY COALESCE(updated_at, created_at) ASC
            LIMIT 50
        ";
        $stmt = $db->prepare($sqlQueue);
        $stmt->execute($params);
        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sqlRecent = "
            SELECT id, nickname, phone, email, district, id_number, id_document_path, id_status, id_reject_reason,
                   id_verified_at, updated_at, created_at
            FROM users
            WHERE {$scope} AND id_status IN ('approved','rejected')
            ORDER BY COALESCE(id_verified_at, updated_at, created_at) DESC
            LIMIT 40
        ";
        $stmt = $db->prepare($sqlRecent);
        $stmt->execute($params);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $queue = [];
        $recent = [];
    }

    return [
        'pending' => $count('pending'),
        'approved' => $count('approved'),
        'rejected' => $count('rejected'),
        'none' => $count('none'),
        'queue' => $queue,
        'recent' => $recent,
    ];
}

function portalIdStatusLabel(string $status): string {
    return match ($status) {
        'pending' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'none' => 'Not submitted',
        default => ucfirst($status),
    };
}

/** Clear admin datetime: 09 Aug 2026 · 19:32 */
function portalFormatDateTime(?string $datetime): string {
    $raw = trim((string) $datetime);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return htmlspecialchars($raw);
    }
    return date('d M Y · H:i', $ts);
}

/** Relative + absolute for admin tables */
function portalFormatReviewedAt(?string $datetime): array {
    $raw = trim((string) $datetime);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return ['label' => '—', 'title' => ''];
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return ['label' => $raw, 'title' => $raw];
    }
    $diff = max(0, time() - $ts);
    if ($diff < 60) {
        $rel = 'Just now';
    } elseif ($diff < 3600) {
        $rel = (int) floor($diff / 60) . ' min ago';
    } elseif ($diff < 86400) {
        $rel = (int) floor($diff / 3600) . 'h ago';
    } elseif ($diff < 604800) {
        $rel = (int) floor($diff / 86400) . 'd ago';
    } else {
        $rel = date('d M Y', $ts);
    }
    return [
        'label' => date('d M Y · H:i', $ts),
        'rel' => $rel,
        'title' => date('Y-m-d H:i:s', $ts),
    ];
}

function portalIsSupportDeskContext(): bool {
    $role = (int) ($_SESSION['role_id'] ?? 0);
    if ($role === 3) {
        return true;
    }
    $preview = portalPreviewGet();
    return $role === 1 && $preview['role'] === 3;
}

/** Full Member ID verification queue panel (Admin + Support). */
function portalRenderIdVerificationQueue(array $data, string $scopeNote = 'Nationwide', string $returnPane = 'id-queue'): void {
    $pending = (int) ($data['pending'] ?? 0);
    $approved = (int) ($data['approved'] ?? 0);
    $rejected = (int) ($data['rejected'] ?? 0);
    $none = (int) ($data['none'] ?? 0);
    $queue = $data['queue'] ?? [];
    $recent = $data['recent'] ?? [];
    $uploadBase = defined('UPLOAD_URL') ? UPLOAD_URL : '/gugu-app/public/uploads/';
    ?>
    <section class="panel id-verify-panel" id="id-verification">
      <div class="rw-flag-bar thin" aria-hidden="true">
        <span class="rw-blue"></span><span class="rw-yellow"></span><span class="rw-green"></span>
      </div>
      <div class="chips" style="margin-bottom:12px">
        <span class="chip chip-yellow">Waiting &middot; <?= $pending ?></span>
        <span class="chip chip-green">Approved &middot; <?= $approved ?></span>
        <span class="chip">Rejected &middot; <?= $rejected ?></span>
        <span class="chip">Not submitted &middot; <?= $none ?></span>
        <span class="chip chip-blue"><?= htmlspecialchars($scopeNote) ?></span>
      </div>
      <p class="hint" style="margin:0 0 14px">
        Members upload a national ID photo in the app. Check the photo and number, then <strong>Approve</strong> or <strong>Reject</strong>.
        Rejected members can resubmit from the app.
      </p>

      <h4 class="panel-subhead">Waiting for your review</h4>
      <?php if (!$queue): ?>
        <p class="hint">No member IDs waiting &mdash; queue is clear.</p>
      <?php else: ?>
      <div class="table-wrap"><table class="id-queue-table">
        <thead>
          <tr>
            <th>Member</th>
            <th>Phone</th>
            <th>Email</th>
            <th>ID number</th>
            <th>Document</th>
            <th>Submitted</th>
            <th>Review</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($queue as $u):
            $doc = (string) ($u['id_document_path'] ?? '');
            $docUrl = $doc !== '' ? $uploadBase . $doc : '';
            $submitted = portalFormatReviewedAt($u['updated_at'] ?? $u['created_at'] ?? null);
            $memberEmail = trim((string) ($u['email'] ?? ''));
        ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($u['nickname'] ?: 'Member') ?></strong>
              <br><small class="muted">#<?= (int) $u['id'] ?> &middot; <?= htmlspecialchars($u['district'] ?: '—') ?></small>
            </td>
            <td class="id-cell-nowrap"><?= htmlspecialchars($u['phone']) ?></td>
            <td class="id-cell-nowrap"><?= $memberEmail !== '' ? htmlspecialchars($memberEmail) : '<span class="muted">—</span>' ?></td>
            <td><code class="id-number-code"><?= htmlspecialchars($u['id_number'] ?: '—') ?></code></td>
            <td class="id-doc-cell">
              <?php if ($docUrl !== ''): ?>
                <a class="id-doc-link" href="<?= htmlspecialchars($docUrl) ?>" target="_blank" rel="noreferrer">
                  <img class="id-doc-thumb" src="<?= htmlspecialchars($docUrl) ?>" alt="ID document">
                  <span>Open full size</span>
                </a>
              <?php else: ?>
                <span class="muted">No photo</span>
              <?php endif; ?>
            </td>
            <td class="id-datetime-cell" title="<?= htmlspecialchars($submitted['title']) ?>">
              <strong class="id-dt-main"><?= htmlspecialchars($submitted['label']) ?></strong>
              <?php if (!empty($submitted['rel'])): ?>
                <br><small class="muted"><?= htmlspecialchars($submitted['rel']) ?></small>
              <?php endif; ?>
            </td>
            <td class="portal-actions id-review-actions">
              <?= portalActionForm('review-id', ['user_id' => $u['id'], 'id_status' => 'approved', 'return_pane' => $returnPane], 'Approve ID', 'btn-sm ok') ?>
              <form method="post" action="/gugu-app/admin/actions.php" class="portal-inline-form id-reject-form">
                <input type="hidden" name="action" value="review-id">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="id_status" value="rejected">
                <input type="hidden" name="return_pane" value="<?= htmlspecialchars($returnPane) ?>">
                <?php
                $preview = portalPreviewGet();
                if ($preview['role'] === 2 || $preview['role'] === 3):
                ?>
                  <input type="hidden" name="view_role" value="<?= (int) $preview['role'] ?>">
                  <?php if ($preview['district'] !== ''): ?>
                    <input type="hidden" name="view_district" value="<?= htmlspecialchars($preview['district']) ?>">
                  <?php endif; ?>
                <?php endif; ?>
                <input type="text" name="id_reject_reason" maxlength="180" placeholder="Reject reason" value="Unclear document — please resubmit">
                <button type="submit" class="btn-sm danger">Reject</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>

      <h4 class="panel-subhead" style="margin-top:22px">Recently reviewed</h4>
      <p class="hint" style="margin:0 0 10px">Date and time of the latest Approve or Reject decision.</p>
      <?php if (!$recent): ?>
        <p class="hint">No approved or rejected IDs yet.</p>
      <?php else: ?>
      <div class="table-wrap"><table class="id-queue-table id-recent-table">
        <thead>
          <tr>
            <th>Member</th>
            <th>Phone</th>
            <th>Email</th>
            <th>ID number</th>
            <th>Status</th>
            <th>Document</th>
            <th>Reviewed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $u):
            $st = (string) ($u['id_status'] ?? '');
            $doc = (string) ($u['id_document_path'] ?? '');
            $docUrl = $doc !== '' ? $uploadBase . $doc : '';
            $pillClass = $st === 'approved' ? 'status-pill status-ok' : 'status-pill status-bad';
            $reviewedAt = portalFormatReviewedAt($u['id_verified_at'] ?? $u['updated_at'] ?? null);
            $memberEmail = trim((string) ($u['email'] ?? ''));
        ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($u['nickname'] ?: 'Member') ?></strong>
              <br><small class="muted">#<?= (int) $u['id'] ?> &middot; <?= htmlspecialchars($u['district'] ?: '—') ?></small>
            </td>
            <td class="id-cell-nowrap"><?= htmlspecialchars($u['phone']) ?></td>
            <td class="id-cell-nowrap"><?= $memberEmail !== '' ? htmlspecialchars($memberEmail) : '<span class="muted">—</span>' ?></td>
            <td><code class="id-number-code"><?= htmlspecialchars($u['id_number'] ?: '—') ?></code></td>
            <td>
              <span class="<?= $pillClass ?>"><?= htmlspecialchars(portalIdStatusLabel($st)) ?></span>
              <?php if ($st === 'rejected' && !empty($u['id_reject_reason'])): ?>
                <br><small class="muted id-reject-note"><?= htmlspecialchars($u['id_reject_reason']) ?></small>
              <?php endif; ?>
            </td>
            <td class="id-doc-cell">
              <?php if ($docUrl !== ''): ?>
                <a class="id-doc-link" href="<?= htmlspecialchars($docUrl) ?>" target="_blank" rel="noreferrer">
                  <img class="id-doc-thumb" src="<?= htmlspecialchars($docUrl) ?>" alt="ID document">
                  <span>View ID</span>
                </a>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="id-datetime-cell" title="<?= htmlspecialchars($reviewedAt['title']) ?>">
              <strong class="id-dt-main"><?= htmlspecialchars($reviewedAt['label']) ?></strong>
              <?php if (!empty($reviewedAt['rel'])): ?>
                <br><small class="muted"><?= htmlspecialchars($reviewedAt['rel']) ?></small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
}
