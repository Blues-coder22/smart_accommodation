<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (($_SESSION['role'] ?? '') === 'host') { redirect(appBasePath() . '/host/messages.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); }
if (($_SESSION['role'] ?? '') !== 'tourist') { redirect(appBasePath() . '/admin/dashboard.php'); }
require __DIR__ . '/includes/messages_page.php';
