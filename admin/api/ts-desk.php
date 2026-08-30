<?php
/**
 * Trust & Safety desk — live JSON for checklist stats + inline expand fragments.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/support_desk.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$district = portalSupportRequireDeskApi();
$db = getDB();
$data = portalSupportDeskData($db, $district);

$view = trim((string) ($_GET['view'] ?? 'stats'));
$returnPane = trim((string) ($_GET['return_pane'] ?? 'checklist'));
if ($returnPane === '') {
    $returnPane = 'checklist';
}

$payload = portalSupportChecklistPayload($data);
$payload['ok'] = true;
$payload['view'] = $view;
$payload['updated_at'] = date('c');

if ($view !== 'stats') {
    $payload['html'] = portalSupportRenderFragment($data, $view, $returnPane);
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
