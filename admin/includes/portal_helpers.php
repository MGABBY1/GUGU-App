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
    // Use ?pane= — browsers drop #hash on HTTP Location redirects
    $url = '/gugu-app/admin/dashboard.php';
    $pane = trim(ltrim($pane, '#/'));
    if ($pane !== '') {
        $url .= '?pane=' . rawurlencode($pane);
    }
    header('Location: ' . $url);
    exit;
}

function portalActionForm(string $action, array $fields, string $btnLabel, string $btnClass = 'btn-sm'): string {
    $html = '<form method="post" action="/gugu-app/admin/actions.php" class="portal-inline-form">';
    $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $value) . '">';
    }
    $html .= '<button type="submit" class="' . htmlspecialchars($btnClass) . '">' . htmlspecialchars($btnLabel) . '</button>';
    $html .= '</form>';
    return $html;
}
