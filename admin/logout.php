<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: /gugu-app/app/?login=1');
exit;
