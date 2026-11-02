<?php
/**
 * JSON endpoint behind the "Find photos" button on the admin map (osm_attractions.php).
 *   GET  ?action=search&name=...&lat=...&lng=...   -> suggested photos with credit and licence
 *   POST action=import  attraction_id, titles[]    -> downloads the ticked photos and saves them with their credit
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commons_photos.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

if ($action === 'search') {
    $name = html_entity_decode(trim((string)($_GET['name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $lat  = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng  = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lat < -90 || $lat > 90)    $lat = null;
    if ($lng === false || $lng < -180 || $lng > 180)  $lng = null;
    if ($name === '' && ($lat === null || $lng === null)) {
        echo json_encode(['photos' => [], 'error' => 'Nothing to search for.']); exit;
    }
    echo json_encode(commonsSearch($name, $lat, $lng), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($action === 'import') {
    requireCsrf();
    $aid = (int)($_POST['attraction_id'] ?? 0);
    $exists = $pdo->prepare('SELECT 1 FROM attractions WHERE attraction_id = ?');
    $exists->execute([$aid]);
    if (!$aid || !$exists->fetchColumn()) { echo json_encode(['saved' => 0, 'errors' => ['That attraction was not found.']]); exit; }
    $titles = (array)($_POST['titles'] ?? []);
    $res = importCommonsPhotos($pdo, $aid, $titles, '../uploads/attractions');
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'ping') {   // can PHP itself reach Commons? (needed only when a NEW attraction is saved with photos)
    [$j, $e] = commonsQuery(['meta' => 'siteinfo']);
    echo json_encode(['ok' => $j !== null, 'error' => $e]); exit;
}

if ($action === 'import_files') {   // photos downloaded by the browser and uploaded here
    requireCsrf();
    $aid = (int)($_POST['attraction_id'] ?? 0);
    $exists = $pdo->prepare('SELECT 1 FROM attractions WHERE attraction_id = ?');
    $exists->execute([$aid]);
    if (!$aid || !$exists->fetchColumn()) { echo json_encode(['saved' => 0, 'errors' => ['That attraction was not found.']]); exit; }
    $meta = json_decode((string)($_POST['meta'] ?? '[]'), true);
    echo json_encode(saveCommonsUploadedFiles($pdo, $aid, $_FILES['files'] ?? [], is_array($meta) ? $meta : [], '../uploads/attractions'), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown request.']);
