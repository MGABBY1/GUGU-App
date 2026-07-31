<?php
/**
 * Runtime platform settings (Super Admin editable).
 * Overrides defaults from config/app.php when present.
 */
function guguRuntimeSettingsPath(): string {
    return __DIR__ . '/runtime_settings.json';
}

function guguLoadRuntimeSettings(): array {
    $path = guguRuntimeSettingsPath();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function guguSaveRuntimeSettings(array $settings): void {
    $path = guguRuntimeSettingsPath();
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode settings');
    }
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Could not write runtime_settings.json — check folder permissions');
    }
}

/**
 * GUGU marketplace monetization settings
 * Announce fee: every item / job / announcement pays this, then Admin approves.
 */
$__guguRuntime = guguLoadRuntimeSettings();

if (!defined('GUGU_ANNOUNCE_FEE_RWF')) {
    define('GUGU_ANNOUNCE_FEE_RWF', (int) ($__guguRuntime['announce_fee_rwf'] ?? 1000));
}
if (!defined('GUGU_MOMO_NUMBER')) {
    define('GUGU_MOMO_NUMBER', (string) ($__guguRuntime['momo_number'] ?? '0781111111'));
}
if (!defined('GUGU_MOMO_NAME')) {
    define('GUGU_MOMO_NAME', (string) ($__guguRuntime['momo_name'] ?? 'Gura & Gurisha Admin'));
}
if (!defined('GUGU_MOMO_SANDBOX')) {
    define('GUGU_MOMO_SANDBOX', !empty($__guguRuntime['momo_sandbox']));
}
/** SMS gateway placeholders — set real values for production */
if (!defined('GUGU_SMS_API_URL')) {
    define('GUGU_SMS_API_URL', (string) ($__guguRuntime['sms_api_url'] ?? ''));
}
if (!defined('GUGU_SMS_API_KEY')) {
    define('GUGU_SMS_API_KEY', (string) ($__guguRuntime['sms_api_key'] ?? ''));
}
if (!defined('GUGU_SMS_SENDER')) {
    define('GUGU_SMS_SENDER', (string) ($__guguRuntime['sms_sender'] ?? 'GuraGuri'));
}

unset($__guguRuntime);
