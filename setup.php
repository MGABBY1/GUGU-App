<?php
/**
 * GUGU App - Database Setup Script
 * Open: http://localhost/gugu-app/setup.php
 *
 * SAFE: Only creates/updates the "GUGUapDB" database.
 * Your other projects (e.g. ikaze) are NOT touched.
 */

require_once __DIR__ . '/config/database.php';

$messages = [];
$success = false;

$allowedDb = 'GUGUapDB';
if (DB_NAME !== $allowedDb) {
    $messages[] = 'Error: Invalid database name. Setup aborted for safety.';
} else {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$allowedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $messages[] = "Database \"{$allowedDb}\" is ready (created if it did not exist).";
        $messages[] = 'Your ikaze and other databases were NOT modified.';

        $pdo->exec("USE `{$allowedDb}`");

        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS.*?;/is', '', $sql);
        $sql = preg_replace('/USE GUGUapDB;/i', '', $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        $messages[] = 'All GUGU App tables are ready.';

        // Portal migrations: add columns only when missing so existing data is untouched
        $addColumn = function (string $table, string $column, string $definition) use ($pdo, $allowedDb): bool {
            $check = $pdo->prepare('
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ');
            $check->execute([$allowedDb, $table, $column]);
            if ((int) $check->fetchColumn() > 0) return false;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            return true;
        };

        $added = 0;
        $added += (int) $addColumn('users', 'role', "ENUM('member','moderator','district_manager','super_admin') NOT NULL DEFAULT 'member'");
        $added += (int) $addColumn('users', 'is_banned', 'TINYINT(1) NOT NULL DEFAULT 0');
        $added += (int) $addColumn('users', 'managed_district', 'VARCHAR(50) DEFAULT NULL');
        $added += (int) $addColumn('users', 'language', "VARCHAR(5) NOT NULL DEFAULT 'rw'");
        $added += (int) $addColumn('listings', 'approval_status', "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'");
        $added += (int) $addColumn('listings', 'rejection_reason', 'VARCHAR(255) DEFAULT NULL');
        $added += (int) $addColumn('listings', 'quantity', 'INT NOT NULL DEFAULT 1');

        $messages[] = $added > 0
            ? "Member/Administrative portal columns added ({$added}). Existing rows kept their data."
            : 'Portal columns already present — nothing changed.';

        // The original backend phone keeps super admin access
        $pdo->prepare("UPDATE users SET role = 'super_admin' WHERE phone = ? AND role = 'member'")
            ->execute(['+250789999999']);

        // Copy data from old gugu_app database if GUGUapDB is empty
        $oldDb = 'gugu_app';
        $oldExists = (bool) $pdo->query("SHOW DATABASES LIKE '{$oldDb}'")->fetch();
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($oldExists && $userCount === 0) {
            $oldUsers = (int) $pdo->query("SELECT COUNT(*) FROM `{$oldDb}`.users")->fetchColumn();
            if ($oldUsers > 0) {
                foreach (['users', 'listings', 'listing_images', 'sessions', 'favorites', 'chat_rooms', 'messages', 'reviews', 'search_alerts'] as $table) {
                    $has = $pdo->query("SHOW TABLES FROM `{$oldDb}` LIKE '{$table}'")->fetch();
                    if ($has) {
                        $pdo->exec("INSERT IGNORE INTO `{$allowedDb}`.`{$table}` SELECT * FROM `{$oldDb}`.`{$table}`");
                    }
                }
                $messages[] = "Amakuru yakuwe muri gugu_app → GUGUapDB ({$oldUsers} users).";
            }
        }

        // Demo listings if still empty
        $count = (int) $pdo->query('SELECT COUNT(*) FROM listings')->fetchColumn();
        if ($count === 0) {
            $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
            if ($userId > 0) {
                $demos = [
                    ['iPhone 13 Pro — 128GB', 'Telefoni nziza, ifite bateri nziza. Gura uko uri hafi!', 450000, 2, 'Gasabo'],
                    ['Sofa nini — ibara ry\'umweru', 'Sofa nziza y\'imbaho, imwe gusa ikoreshwa.', 85000, 3, 'Kicukiro'],
                    ['Nike Air Max — size 42', 'Inkweto z\'umukino, zishya gusa rimwe.', 35000, 4, 'Gasabo'],
                    ['MacBook Air M1 2020', 'Laptop nziza yo gukorera no kwandika.', 680000, 2, 'Nyarugenge'],
                    ['Fridge SAMSUNG — ikora neza', 'Frigo nini, ikora neza cyane.', 120000, 9, 'Gasabo'],
                ];
                $ins = $pdo->prepare('INSERT INTO listings (user_id, category_id, title, description, price, province, district) VALUES (?, ?, ?, ?, ?, "Kigali", ?)');
                foreach ($demos as [$title, $desc, $price, $catId, $dist]) {
                    $ins->execute([$userId, $catId, $title, $desc, $price, $dist]);
                }
                $messages[] = 'Demo ibicuruzwa 5 byashyizweho — reba mu app!';
            }
        }

        $success = true;
    } catch (PDOException $e) {
        $messages[] = 'Error: ' . $e->getMessage();
        $messages[] = 'Make sure MySQL is running in XAMPP.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GUGU App Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #FFF0EB, #F8F9FA);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            text-align: center;
        }
        .logo { font-size: 3rem; margin-bottom: 8px; }
        h1 { color: #FF6B35; font-size: 1.8rem; margin-bottom: 4px; }
        .tagline { color: #6C757D; margin-bottom: 24px; }
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 0.9rem;
            text-align: left;
        }
        .message.success { background: #D4EDDA; color: #155724; }
        .message.error { background: #F8D7DA; color: #721C24; }
        .message.info { background: #E7F3FF; color: #004085; }
        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #FF6B35, #F7931E);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
        }
        .url { margin-top: 16px; font-size: 0.85rem; color: #495057; word-break: break-all; }
        .url code { background: #F8F9FA; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="logo">🛒</div>
        <h1>GUGU App Setup</h1>
        <p class="tagline">GuraCyangwaGurisha · 3G Market</p>

        <div class="message info">This setup only uses the <strong>GUGUapDB</strong> database. Your ikaze project databases are safe.</div>

        <?php foreach ($messages as $msg): ?>
            <div class="message <?= $success ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <a href="/gugu-app/" class="btn">Open GUGU App →</a>
            <p class="url">App URL: <code>http://localhost/gugu-app/</code></p>
        <?php else: ?>
            <p class="url">Start <strong>Apache</strong> and <strong>MySQL</strong> in XAMPP, then refresh this page.</p>
        <?php endif; ?>
    </div>
</body>
</html>
