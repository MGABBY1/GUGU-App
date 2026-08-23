<?php
/**
 * System management portal POST actions —
 * System Administrator / District Manager / Moderator·Support.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/portal_helpers.php';
adminRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portalRedirect();
}

// Keep Admin preview sticky on every POST until explicit exit_preview.
if ((int) ($_SESSION['role_id'] ?? 0) === 1) {
    $postedRole = (int) ($_POST['view_role'] ?? 0);
    $postedDistrict = trim((string) ($_POST['view_district'] ?? ''));
    $allowed = portalDistricts();
    if (in_array($postedRole, [2, 3], true)) {
        if ($postedDistrict === '' || !in_array($postedDistrict, $allowed, true)) {
            $preview = portalPreviewGet();
            $postedDistrict = $preview['district'] !== '' ? $preview['district'] : ($allowed[0] ?? 'Gasabo');
        }
        if (!in_array($postedDistrict, $allowed, true)) {
            $postedDistrict = $allowed[0] ?? 'Gasabo';
        }
        portalPreviewSet($postedRole, $postedDistrict);
    } else {
        // Forms may omit view_role — never clear sticky District/Moderator session here.
        $preview = portalPreviewGet();
        if ($preview['role'] === 2 || $preview['role'] === 3) {
            $district = $preview['district'];
            if ($district === '' || !in_array($district, $allowed, true)) {
                $district = $allowed[0] ?? 'Gasabo';
            }
            portalPreviewSet($preview['role'], $district);
        }
    }
}

$actorId = (int) $_SESSION['user_id'];
$actorRole = (int) $_SESSION['role_id'];
$scopeDistrict = in_array($actorRole, [2, 3], true)
    ? trim((string) ($_SESSION['admin_district'] ?? $_SESSION['district'] ?? ''))
    : null;

$action = $_POST['action'] ?? '';
$db = getDB();

try {
    switch ($action) {
        case 'set-role':
            if ($actorRole !== 1) {
                throw new RuntimeException('Admin only');
            }
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newRole = (int) ($_POST['role_id'] ?? 0);
            $adminDistrict = trim($_POST['admin_district'] ?? '');
            if (!$userId || $newRole < 1 || $newRole > 4) {
                throw new RuntimeException('Invalid role');
            }
            if ($userId === $actorId && $newRole !== 1) {
                throw new RuntimeException('Cannot demote yourself');
            }
            // Only one Admin (you) — never assign role 1 to another account
            if ($newRole === 1 && $userId !== $actorId) {
                throw new RuntimeException('Admin role is reserved — assign District Manager or Moderator only');
            }
            if (in_array($newRole, [2, 3], true) && $adminDistrict === '') {
                throw new RuntimeException('District Manager and Moderator need an Akarere (district)');
            }
    $db->prepare('UPDATE users SET role_id = ?, admin_district = ? WHERE id = ?')->execute([
        $newRole,
        in_array($newRole, [2, 3], true) ? $adminDistrict : null,
        $userId,
    ]);
            syncAccountKind($db, $userId, $newRole);
            writeAuditLog($actorId, 'set-role', 'user', $userId, [
                'role_id' => $newRole,
                'admin_district' => $adminDistrict ?: null,
            ]);
            portalFlash('Role updated');
            portalRedirect($newRole <= 3 ? 'staff' : 'members');

        case 'set-status':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $status = $_POST['account_status'] ?? '';
            if (!$userId || !in_array($status, ['active', 'suspended', 'banned'], true)) {
                throw new RuntimeException('Invalid status');
            }
            if ($userId === $actorId) {
                throw new RuntimeException('Cannot change your own status');
            }
            $stmt = $db->prepare('SELECT id, role_id, district, admin_district FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('User not found');
            }
            $targetRole = (int) ($target['role_id'] ?? 4);
            if ($actorRole === 3) {
                if ($targetRole <= 3) {
                    throw new RuntimeException('Moderator / Support cannot change staff accounts');
                }
                // Trust & Safety may suspend or ban fraudulent members
            }
            if ($actorRole === 2) {
                // District Manager: members + Moderators in Akarere only — never Admin / other DMs — never ban
                if ($targetRole <= 2) {
                    throw new RuntimeException('Cannot change Admin or other District Managers');
                }
                if ($status === 'banned') {
                    throw new RuntimeException('District Manager cannot ban — escalate bans to Admin');
                }
                if ($scopeDistrict) {
                    if ($targetRole === 3) {
                        $modDistrict = trim((string) ($target['admin_district'] ?: $target['district'] ?? ''));
                        if ($modDistrict !== $scopeDistrict) {
                            throw new RuntimeException('Moderator is outside your district');
                        }
                    } elseif (($target['district'] ?? '') !== $scopeDistrict) {
                        throw new RuntimeException('Outside your district');
                    }
                }
            }
            if ($actorRole === 1) {
                // full access
            } elseif ($actorRole !== 2 && $actorRole !== 3) {
                throw new RuntimeException('Not allowed');
            }
            $db->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$status, $userId]);
            writeAuditLog($actorId, 'set-status', 'user', $userId, ['account_status' => $status]);
            portalFlash('Account status updated');
            portalRedirect($targetRole === 3 ? 'moderators' : ($targetRole <= 2 ? 'staff' : 'members'));

        case 'save-system-settings':
            if ($actorRole !== 1) {
                throw new RuntimeException('Admin only — full system controls');
            }
            require_once __DIR__ . '/../config/app.php';
            $itemFee = max(0, (int) ($_POST['item_announce_fee_rwf'] ?? $_POST['announce_fee_rwf'] ?? GUGU_ITEM_ANNOUNCE_FEE_RWF));
            $jobFee = max(0, (int) ($_POST['job_announce_fee_rwf'] ?? GUGU_JOB_ANNOUNCE_FEE_RWF));
            $momoName = trim((string) ($_POST['momo_name'] ?? ''));
            $momoNumber = preg_replace('/\s+/', '', (string) ($_POST['momo_number'] ?? ''));
            $smsUrl = trim((string) ($_POST['sms_api_url'] ?? ''));
            $smsKey = trim((string) ($_POST['sms_api_key'] ?? ''));
            $smsSender = trim((string) ($_POST['sms_sender'] ?? 'GuraGuri'));
            $sandbox = !empty($_POST['momo_sandbox']);
            if ($momoName === '' || $momoNumber === '') {
                throw new RuntimeException('MoMo name and number are required');
            }
            if (!preg_match('/^0\d{8,11}$/', $momoNumber)) {
                throw new RuntimeException('MoMo number should look like 07XXXXXXXX');
            }
            $existing = function_exists('guguLoadRuntimeSettings') ? guguLoadRuntimeSettings() : [];
            if ($smsKey === '' && !empty($existing['sms_api_key'])) {
                $smsKey = (string) $existing['sms_api_key'];
            }
            guguSaveRuntimeSettings([
                'item_announce_fee_rwf' => $itemFee,
                'job_announce_fee_rwf' => $jobFee,
                'announce_fee_rwf' => $itemFee, // legacy alias = item fee
                'momo_name' => $momoName,
                'momo_number' => $momoNumber,
                'momo_sandbox' => $sandbox,
                'sms_api_url' => $smsUrl,
                'sms_api_key' => $smsKey,
                'sms_sender' => $smsSender !== '' ? $smsSender : 'GuraGuri',
                'updated_at' => date('c'),
                'updated_by' => $actorId,
            ]);
            writeAuditLog($actorId, 'save-system-settings', 'system', null, [
                'item_announce_fee_rwf' => $itemFee,
                'job_announce_fee_rwf' => $jobFee,
                'momo_number' => $momoNumber,
                'momo_sandbox' => $sandbox,
            ]);
            portalFlash('System Controls saved — Item & Job fees + MoMo updated');
            portalRedirect('system-controls');

        case 'promote-staff':
            if ($actorRole !== 1) {
                throw new RuntimeException('Admin only');
            }
            $rawPhone = preg_replace('/\s+/', '', (string) ($_POST['phone'] ?? ''));
            $nickname = trim((string) ($_POST['nickname'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $newRole = (int) ($_POST['role_id'] ?? 0);
            $adminDistrict = trim((string) ($_POST['admin_district'] ?? ''));
            if ($rawPhone === '' || !in_array($newRole, [2, 3], true)) {
                throw new RuntimeException('Phone and role (District Manager or Moderator) required');
            }
            if ($adminDistrict === '') {
                throw new RuntimeException('Pick an Akarere for this staff account');
            }
            if (!validateRwandaPhone($rawPhone)) {
                throw new RuntimeException('Use a valid Rwanda phone (078/079/072/073…)');
            }
            $phoneE164 = formatPhone($rawPhone);
            $localPhone = '0' . substr($phoneE164, 4); // 07XXXXXXXX
            $candidates = [$phoneE164, $localPhone, '250' . substr($phoneE164, 4)];

            $stmt = $db->prepare('SELECT id, role_id, nickname, password_hash FROM users WHERE phone = ? OR phone = ? OR phone = ? LIMIT 1');
            $stmt->execute([$candidates[0], $candidates[1], $candidates[2]]);
            $target = $stmt->fetch();

            $label = $newRole === 2 ? 'District Manager' : 'Moderator';
            $province = match (true) {
                in_array($adminDistrict, ['Gasabo', 'Kicukiro', 'Nyarugenge'], true) => 'Kigali',
                in_array($adminDistrict, ['Burera', 'Gakenke', 'Gicumbi', 'Musanze', 'Rulindo'], true) => 'Northern',
                in_array($adminDistrict, ['Gisagara', 'Huye', 'Kamonyi', 'Muhanga', 'Nyamagabe', 'Nyanza', 'Nyaruguru', 'Ruhango'], true) => 'Southern',
                in_array($adminDistrict, ['Bugesera', 'Gatsibo', 'Kayonza', 'Kirehe', 'Ngoma', 'Nyagatare', 'Rwamagana'], true) => 'Eastern',
                default => 'Western',
            };

            if ($target) {
                // Existing account → promote / update staff role
                $userId = (int) $target['id'];
                if ($userId === $actorId) {
                    throw new RuntimeException('Cannot change your own role this way');
                }
                if ((int) $target['role_id'] === 1) {
                    throw new RuntimeException('Cannot demote another Admin here');
                }
                $nick = $nickname !== '' ? $nickname : ($target['nickname'] ?: $label);
                $sql = 'UPDATE users SET role_id = ?, admin_district = ?, account_status = "active", account_kind = "management",
                        nickname = ?, full_name = ?, district = ?, province = ?';
                $params = [$newRole, $adminDistrict, $nick, $nick, $adminDistrict, $province];
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        throw new RuntimeException('Password must be at least 6 characters');
                    }
                    $sql .= ', password_hash = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id = ?';
                $params[] = $userId;
                $db->prepare($sql)->execute($params);
                syncAccountKind($db, $userId, $newRole);
                writeAuditLog($actorId, 'promote-staff', 'user', $userId, [
                    'role_id' => $newRole,
                    'admin_district' => $adminDistrict,
                    'phone' => $phoneE164,
                    'mode' => 'promoted',
                ]);
                portalFlash($label . ' updated · ' . $nick . ' · ' . $localPhone . ' · ' . $adminDistrict
                    . ($password !== '' ? ' · password reset' : ''));
            } else {
                // New staff — create account directly (no member registration needed)
                if ($nickname === '') {
                    throw new RuntimeException('Nickname required when creating a new staff account');
                }
                if (strlen($password) < 6) {
                    throw new RuntimeException('Set a password (min 6 characters) for the new staff login');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare('
                    INSERT INTO users (
                        phone, password_hash, full_name, nickname, province, district, sector,
                        role_id, account_kind, account_status, admin_district, is_verified, id_status
                    ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "management", "active", ?, 1, "none")
                ')->execute([
                    $phoneE164,
                    $hash,
                    $nickname,
                    $nickname,
                    $province,
                    $adminDistrict,
                    $newRole,
                    $adminDistrict,
                ]);
                $userId = (int) $db->lastInsertId();
                syncAccountKind($db, $userId, $newRole);
                writeAuditLog($actorId, 'create-staff', 'user', $userId, [
                    'role_id' => $newRole,
                    'admin_district' => $adminDistrict,
                    'phone' => $phoneE164,
                    'mode' => 'created',
                ]);
                portalFlash($label . ' created · ' . $nickname . ' · login phone ' . $localPhone
                    . ' · password you set · Akarere ' . $adminDistrict);
            }
            portalRedirect('staff');

        case 'moderate-listing':
            $listingId = (int) ($_POST['listing_id'] ?? 0);
            $modStatus = $_POST['moderation_status'] ?? '';
            if (!$listingId || !in_array($modStatus, ['approved', 'pending', 'flagged', 'rejected'], true)) {
                throw new RuntimeException('Invalid moderation status');
            }
            $stmt = $db->prepare('SELECT id, district, business_type, category_id FROM listings WHERE id = ?');
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                throw new RuntimeException('Listing not found');
            }
            if ($scopeDistrict && $listing['district'] !== $scopeDistrict) {
                throw new RuntimeException('Outside your region');
            }
            $biz = (string) ($listing['business_type'] ?? '');
            if ($biz !== 'item' && $biz !== 'job') {
                $biz = guguBusinessTypeFromCategory((int) ($listing['category_id'] ?? 0));
            }
            // Keep moderation_status + approval_status in sync when both columns exist
            try {
                $db->prepare('UPDATE listings SET moderation_status = ?, approval_status = ?, business_type = ? WHERE id = ?')
                   ->execute([$modStatus, $modStatus, $biz, $listingId]);
            } catch (Throwable $e) {
                $db->prepare('UPDATE listings SET moderation_status = ? WHERE id = ?')->execute([$modStatus, $listingId]);
            }
            if ($modStatus === 'approved') {
                $db->prepare('UPDATE listings SET payment_status = "paid", paid_at = COALESCE(paid_at, NOW()), status = "active" WHERE id = ?')
                   ->execute([$listingId]);
            }
            if ($modStatus === 'rejected') {
                $db->prepare('UPDATE listings SET status = "rejected" WHERE id = ?')->execute([$listingId]);
            }
            writeAuditLog($actorId, 'moderate-listing', 'listing', $listingId, [
                'moderation_status' => $modStatus,
                'business_type' => $biz,
            ]);
            portalFlash($modStatus === 'approved'
                ? 'Approved — ' . guguBusinessLabel($biz) . ' post is live'
                : guguBusinessLabel($biz) . ' listing updated');
            portalRedirect($biz === 'job' ? 'job-approvals' : 'item-approvals');

        case 'mark-listing-paid':
            $listingId = (int) ($_POST['listing_id'] ?? 0);
            $note = trim($_POST['payment_note'] ?? '');
            // Confirm MoMo and publish live by default (Admin earns).
            $autoPublish = !isset($_POST['auto_publish']) || $_POST['auto_publish'] === '1' || $_POST['auto_publish'] === 1;
            if (!$listingId) {
                throw new RuntimeException('Listing required');
            }
            $stmt = $db->prepare('SELECT id, district, business_type, category_id, announce_fee_rwf, user_id, title FROM listings WHERE id = ?');
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                throw new RuntimeException('Listing not found');
            }
            if ($scopeDistrict && $listing['district'] !== $scopeDistrict) {
                throw new RuntimeException('Outside your region');
            }
            $biz = (string) ($listing['business_type'] ?? '');
            if ($biz !== 'item' && $biz !== 'job') {
                $biz = guguBusinessTypeFromCategory((int) ($listing['category_id'] ?? 0));
            }
            $feePaid = (int) ($listing['announce_fee_rwf'] ?? guguAnnounceFeeForBusiness($biz));
            $payNote = $note !== '' ? $note : 'MoMo received';
            if ($autoPublish) {
                try {
                    $db->prepare('
                        UPDATE listings SET
                          payment_status = "paid", paid_at = NOW(), payment_note = ?, business_type = ?,
                          moderation_status = "approved", approval_status = "approved", status = "active"
                        WHERE id = ?
                    ')->execute([$payNote, $biz, $listingId]);
                } catch (Throwable $e) {
                    $db->prepare('
                        UPDATE listings SET
                          payment_status = "paid", paid_at = NOW(), payment_note = ?, business_type = ?,
                          moderation_status = "approved", status = "active"
                        WHERE id = ?
                    ')->execute([$payNote, $biz, $listingId]);
                }
                writeAuditLog($actorId, 'mark-listing-paid', 'listing', $listingId, [
                    'payment_note' => $payNote,
                    'business_type' => $biz,
                    'fee_rwf' => $feePaid,
                    'auto_publish' => true,
                ]);
                $sellerId = (int) ($listing['user_id'] ?? 0);
                if ($sellerId > 0 && function_exists('notify')) {
                    notify(
                        $sellerId,
                        'payment',
                        guguBusinessLabel($biz) . ' is live',
                        'Payment of ' . $feePaid . ' RWF confirmed. Your post is now published.',
                        'listing',
                        $listingId
                    );
                }
                portalFlash('Paid ' . $feePaid . ' RWF · ' . guguBusinessLabel($biz) . ' published live.');
            } else {
                $db->prepare('UPDATE listings SET payment_status = "paid", paid_at = NOW(), payment_note = ?, business_type = ? WHERE id = ?')
                   ->execute([$payNote, $biz, $listingId]);
                writeAuditLog($actorId, 'mark-listing-paid', 'listing', $listingId, [
                    'payment_note' => $payNote,
                    'business_type' => $biz,
                    'fee_rwf' => $feePaid,
                    'auto_publish' => false,
                ]);
                portalFlash('Marked as paid (' . $feePaid . ' RWF). Approve to publish.');
            }
            portalRedirect($biz === 'job' ? 'job-approvals' : 'item-approvals');

        case 'resolve-report':
            if ($actorRole === 2) {
                // Regional Manager may resolve reports in their region only
            } elseif ($actorRole !== 1 && $actorRole !== 3) {
                throw new RuntimeException('Not allowed');
            }
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $note = trim($_POST['resolution_note'] ?? '');
            if (!$reportId || !in_array($status, ['resolved', 'dismissed', 'reviewing'], true)) {
                throw new RuntimeException('Invalid report status');
            }
            if ($actorRole === 2 && $scopeDistrict) {
                $chk = $db->prepare('
                    SELECT r.id FROM reports r
                    LEFT JOIN listings l ON r.target_type = "listing" AND l.id = r.target_id
                    LEFT JOIN users u ON r.target_type = "user" AND u.id = r.target_id
                    WHERE r.id = ? AND (l.district = ? OR u.district = ?)
                    LIMIT 1
                ');
                $chk->execute([$reportId, $scopeDistrict, $scopeDistrict]);
                if (!$chk->fetch()) {
                    throw new RuntimeException('Report outside your region');
                }
            }
            $db->prepare('
                UPDATE reports SET status = ?, handled_by = ?, resolution_note = ? WHERE id = ?
            ')->execute([$status, $actorId, $note ?: null, $reportId]);
            writeAuditLog($actorId, 'resolve-report', 'report', $reportId, ['status' => $status]);
            portalFlash('Report updated');
            portalRedirect('reports');

        case 'suspend-seller':
            // Moderator / Support (and System Admin) can suspend listing seller from queue
            if ($actorRole !== 1 && $actorRole !== 3) {
                throw new RuntimeException('Admin or Moderator / Support only');
            }
            $sellerId = (int) ($_POST['user_id'] ?? 0);
            if (!$sellerId || $sellerId === $actorId) {
                throw new RuntimeException('Invalid seller');
            }
            $stmt = $db->prepare('SELECT id, role_id FROM users WHERE id = ?');
            $stmt->execute([$sellerId]);
            $target = $stmt->fetch();
            if (!$target || (int) $target['role_id'] <= 3) {
                throw new RuntimeException('Cannot suspend staff');
            }
            $db->prepare('UPDATE users SET account_status = "suspended" WHERE id = ?')->execute([$sellerId]);
            writeAuditLog($actorId, 'set-status', 'user', $sellerId, ['account_status' => 'suspended']);
            portalFlash('Seller suspended');
            portalRedirect('listings');

        case 'ban-seller':
            // Trust & Safety Desk may ban fraudulent members
            if ($actorRole !== 1 && $actorRole !== 3) {
                throw new RuntimeException('Admin or Moderator / Support only');
            }
            $sellerId = (int) ($_POST['user_id'] ?? 0);
            if (!$sellerId || $sellerId === $actorId) {
                throw new RuntimeException('Invalid user');
            }
            $stmt = $db->prepare('SELECT id, role_id FROM users WHERE id = ?');
            $stmt->execute([$sellerId]);
            $target = $stmt->fetch();
            if (!$target || (int) $target['role_id'] <= 3) {
                throw new RuntimeException('Cannot ban staff');
            }
            $db->prepare('UPDATE users SET account_status = "banned" WHERE id = ?')->execute([$sellerId]);
            writeAuditLog($actorId, 'set-status', 'user', $sellerId, ['account_status' => 'banned']);
            portalFlash('Fraudulent account banned');
            portalRedirect('listings');

        case 'review-id':
            if (!in_array($actorRole, [1, 2, 3], true)) {
                throw new RuntimeException('Staff only');
            }
            $userId = (int) ($_POST['user_id'] ?? 0);
            $decision = $_POST['id_status'] ?? '';
            $reason = trim($_POST['id_reject_reason'] ?? '');
            if (!$userId || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new RuntimeException('Invalid ID review');
            }
            $stmt = $db->prepare('SELECT id, role_id, id_status, district FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target || (int) $target['role_id'] <= 3) {
                throw new RuntimeException('Only member IDs can be reviewed');
            }
            // District Manager / Moderator: only members in their Akarere.
            if (in_array($actorRole, [2, 3], true)) {
                $allowedDistrict = $scopeDistrict !== null && $scopeDistrict !== ''
                    ? $scopeDistrict
                    : trim((string) ($_SESSION['admin_district'] ?? $_SESSION['district'] ?? ''));
                $memberDistrict = trim((string) ($target['district'] ?? ''));
                if ($allowedDistrict === '' || $memberDistrict === '' || strcasecmp($allowedDistrict, $memberDistrict) !== 0) {
                    throw new RuntimeException('You can only review member IDs in your Akarere');
                }
            }
            if ($decision === 'approved') {
                $db->prepare("UPDATE users SET id_status = 'approved', id_verified_at = NOW(), id_reject_reason = NULL, updated_at = NOW() WHERE id = ?")
                   ->execute([$userId]);
                writeAuditLog($actorId, 'review-id', 'user', $userId, ['id_status' => 'approved']);
                portalFlash('Member ID approved — they can use the app fully');
            } else {
                $db->prepare("UPDATE users SET id_status = 'rejected', id_reject_reason = ?, id_verified_at = NOW(), updated_at = NOW() WHERE id = ?")
                   ->execute([$reason !== '' ? $reason : 'Document unclear — resubmit', $userId]);
                writeAuditLog($actorId, 'review-id', 'user', $userId, ['id_status' => 'rejected', 'reason' => $reason]);
                portalFlash('Member ID rejected');
            }
            portalRedirect('id-queue');

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    portalFlash($e->getMessage(), 'err');
    portalRedirect();
}
