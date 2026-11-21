<?php
// The receipt is now one shared page at the site root so hosts and admins can open it with their own sign-in.
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . $base . '/receipt.php?' . $q . (strpos($q, 'as=') === false ? '&as=tourist' : ''));
exit;
