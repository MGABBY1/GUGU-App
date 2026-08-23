<?php
/**
 * Add Jobs (Akazi) category for Karrot-style part-time jobs.
 */
require __DIR__ . '/includes/helpers.php';

$db = getDB();
$db->exec("
  INSERT INTO categories (id, name_rw, name_en, icon, sort_order)
  VALUES (11, 'Akazi', 'Jobs', 'job', 10)
  ON DUPLICATE KEY UPDATE
    name_rw = VALUES(name_rw),
    name_en = VALUES(name_en),
    icon = VALUES(icon),
    sort_order = VALUES(sort_order)
");

$row = $db->query('SELECT id, name_en, name_rw FROM categories WHERE id = 11')->fetch(PDO::FETCH_ASSOC);
echo 'Jobs category: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
