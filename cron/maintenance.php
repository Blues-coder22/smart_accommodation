<?php
/**
 * Scheduled maintenance entry point. Run from the command line only.
 *   Linux crontab (every 10 minutes):  see the line in README_v9_33.md, run: php /path/to/smart_accommodation/cron/maintenance.php
 *   Windows (XAMPP):   schtasks /create /sc minute /mo 10 /tn StayExploreCron /tr "C:\xampp\php\php.exe C:\xampp\htdocs\smart_accommodation\cron\maintenance.php"
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maintenance.php';
$r = runMaintenance($pdo);
echo date('c') . ' ' . json_encode($r) . PHP_EOL;
