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
                throw new RuntimeException('System Administrator only');
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
            portalRedirect($newRole <= 3 ? 'permissions' : 'members');

        case 'set-status':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $status = $_POST['account_status'] ?? '';
            if (!$userId || !in_array($status, ['active', 'suspended', 'banned'], true)) {
                throw new RuntimeException('Invalid status');
            }
            if ($userId === $actorId) {
                throw new RuntimeException('Cannot change your own status');
            }
            $stmt = $db->prepare('SELECT id, role_id, district FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('User not found');
            }
            if ($actorRole === 3) {
                if ((int) $target['role_id'] <= 3) {
                    throw new RuntimeException('Moderator / Support cannot change staff accounts');
                }
                // Trust & Safety may suspend or ban fraudulent members
            }
            if ($actorRole === 2) {
                if ((int) $target['role_id'] <= 2) {
                    throw new RuntimeException('Cannot change this account');
                }
                if ($status === 'banned') {
                    throw new RuntimeException('District Manager may suspend only — escalate bans to Trust & Safety');
                }
                if ($scopeDistrict && $target['district'] !== $scopeDistrict) {
                    throw new RuntimeException('Outside your district');
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
            $targetRole = (int) ($target['role_id'] ?? 4);
            portalRedirect($targetRole <= 3 ? 'permissions' : 'members');

        case 'save-system-settings':
            if ($actorRole !== 1) {
                throw new RuntimeException('System Administrator only');
            }
            require_once __DIR__ . '/../config/app.php';
            $fee = max(0, (int) ($_POST['announce_fee_rwf'] ?? GUGU_ANNOUNCE_FEE_RWF));
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
                'announce_fee_rwf' => $fee,
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
                'announce_fee_rwf' => $fee,
                'momo_number' => $momoNumber,
                'momo_sandbox' => $sandbox,
            ]);
            portalFlash('System Controls saved — MoMo gateway & fee updated');
            portalRedirect('system-controls');

        case 'promote-staff':
            if ($actorRole !== 1) {
                throw new RuntimeException('System Administrator only');
            }
            $phone = preg_replace('/\s+/', '', (string) ($_POST['phone'] ?? ''));
            $newRole = (int) ($_POST['role_id'] ?? 0);
            $adminDistrict = trim((string) ($_POST['admin_district'] ?? ''));
            if ($phone === '' || !in_array($newRole, [2, 3], true)) {
                throw new RuntimeException('Phone and role (District Manager or Moderator) required');
            }
            if ($adminDistrict === '') {
                throw new RuntimeException('Pick an Akarere for this staff account');
            }
            // Normalize phone variants
            $candidates = [$phone];
            if (str_starts_with($phone, '0')) {
                $candidates[] = '+250' . substr($phone, 1);
                $candidates[] = '250' . substr($phone, 1);
            }
            $stmt = $db->prepare('SELECT id, role_id, nickname FROM users WHERE phone = ? OR phone = ? OR phone = ? LIMIT 1');
            $stmt->execute([$candidates[0], $candidates[1] ?? $phone, $candidates[2] ?? $phone]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('No account found for that phone — member must register first');
            }
            $userId = (int) $target['id'];
            if ($userId === $actorId) {
                throw new RuntimeException('Cannot change your own role this way');
            }
            $db->prepare('UPDATE users SET role_id = ?, admin_district = ?, account_status = "active" WHERE id = ?')
                ->execute([$newRole, $adminDistrict, $userId]);
            syncAccountKind($db, $userId, $newRole);
            writeAuditLog($actorId, 'promote-staff', 'user', $userId, [
                'role_id' => $newRole,
                'admin_district' => $adminDistrict,
                'phone' => $phone,
            ]);
            $label = $newRole === 2 ? 'District Manager' : 'Moderator';
            portalFlash($label . ' created for ' . ($target['nickname'] ?: $phone) . ' · ' . $adminDistrict);
            portalRedirect('permissions');

        case 'moderate-listing':
            $listingId = (int) ($_POST['listing_id'] ?? 0);
            $modStatus = $_POST['moderation_status'] ?? '';
            if (!$listingId || !in_array($modStatus, ['approved', 'pending', 'flagged', 'rejected'], true)) {
                throw new RuntimeException('Invalid moderation status');
            }
            $stmt = $db->prepare('SELECT id, district FROM listings WHERE id = ?');
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                throw new RuntimeException('Listing not found');
            }
            if ($scopeDistrict && $listing['district'] !== $scopeDistrict) {
                throw new RuntimeException('Outside your region');
            }
            $db->prepare('UPDATE listings SET moderation_status = ? WHERE id = ?')->execute([$modStatus, $listingId]);
            if ($modStatus === 'approved') {
                $db->prepare('UPDATE listings SET payment_status = "paid", paid_at = COALESCE(paid_at, NOW()), status = "active" WHERE id = ?')
                   ->execute([$listingId]);
            }
            if ($modStatus === 'rejected') {
                $db->prepare('UPDATE listings SET status = "sold" WHERE id = ?')->execute([$listingId]);
            }
            writeAuditLog($actorId, 'moderate-listing', 'listing', $listingId, [
                'moderation_status' => $modStatus,
            ]);
            portalFlash($modStatus === 'approved'
                ? 'Approved — post is live (fee confirmed for Admin)'
                : 'Listing updated');
            portalRedirect('listings');

        case 'mark-listing-paid':
            $listingId = (int) ($_POST['listing_id'] ?? 0);
            $note = trim($_POST['payment_note'] ?? '');
            if (!$listingId) {
                throw new RuntimeException('Listing required');
            }
            $stmt = $db->prepare('SELECT id, district FROM listings WHERE id = ?');
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                throw new RuntimeException('Listing not found');
            }
            if ($scopeDistrict && $listing['district'] !== $scopeDistrict) {
                throw new RuntimeException('Outside your region');
            }
            $db->prepare('UPDATE listings SET payment_status = "paid", paid_at = NOW(), payment_note = ? WHERE id = ?')
               ->execute([$note !== '' ? $note : 'MoMo received', $listingId]);
            writeAuditLog($actorId, 'mark-listing-paid', 'listing', $listingId, ['payment_note' => $note]);
            portalFlash('Marked as paid (1000 RWF). You can Approve to publish.');
            portalRedirect('listings');

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
                throw new RuntimeException('Moderator / Support or System Administrator only');
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
                throw new RuntimeException('Moderator / Support or System Administrator only');
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
            if ($actorRole !== 1 && $actorRole !== 3) {
                throw new RuntimeException('Trust & Safety or System Administrator only');
            }
            $userId = (int) ($_POST['user_id'] ?? 0);
            $decision = $_POST['id_status'] ?? '';
            $reason = trim($_POST['id_reject_reason'] ?? '');
            if (!$userId || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new RuntimeException('Invalid ID review');
            }
            $stmt = $db->prepare('SELECT id, role_id, id_status FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target || (int) $target['role_id'] <= 3) {
                throw new RuntimeException('Only member IDs can be reviewed');
            }
            if ($decision === 'approved') {
                $db->prepare("UPDATE users SET id_status = 'approved', id_verified_at = NOW(), id_reject_reason = NULL WHERE id = ?")
                   ->execute([$userId]);
                writeAuditLog($actorId, 'review-id', 'user', $userId, ['id_status' => 'approved']);
                portalFlash('Member ID approved — they can use the app fully');
            } else {
                $db->prepare("UPDATE users SET id_status = 'rejected', id_reject_reason = ?, id_verified_at = NULL WHERE id = ?")
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
