<?php
// Terminal use:  php database/run_setup.php   (creates every table and column the site needs; safe to repeat)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/schema_setup.php';
$r = runSchemaSetup($pdo);
foreach ($r['messages'] as $m) echo $m . PHP_EOL;
foreach ($r['tables'] as $t => $ok) echo ($ok ? '[ok]      ' : '[MISSING] ') . $t . PHP_EOL;
