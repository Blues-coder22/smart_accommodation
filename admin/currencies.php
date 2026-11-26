<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/currency.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
ensureCurrenciesTable($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (($_POST['action'] ?? '') === 'save') {
        $upd = $pdo->prepare('UPDATE currencies SET kes_per_unit = ?, is_enabled = ? WHERE code = ?'); $bad = 0;
        foreach ((array)($_POST['rate'] ?? []) as $code => $v) {
            $r = (float)str_replace(',', '.', (string)$v);
            if ($r <= 0 || $r > 100000) { $bad++; continue; }
            $upd->execute([$r, isset($_POST['enabled'][$code]) ? 1 : 0, strtoupper(substr((string)$code, 0, 3))]);
        }
        setFlash($bad ? 'error' : 'success', $bad ? "$bad rate(s) were not valid and were left unchanged. Rates must be above 0." : 'Currencies saved. Guests see the new rates straight away.');
    } elseif (($_POST['action'] ?? '') === 'add') {
        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['code'] ?? ''))); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60); $r = (float)str_replace(',', '.', (string)($_POST['new_rate'] ?? ''));
        if (strlen($code) !== 3 || $name === '' || $r <= 0 || $code === 'KES') setFlash('error', 'Enter a 3-letter code (not KES), a name, and a rate above 0.');
        else { $pdo->prepare('INSERT INTO currencies (code, name, kes_per_unit, is_enabled, sort_order) VALUES (?,?,?,1,99) ON DUPLICATE KEY UPDATE name = VALUES(name), kes_per_unit = VALUES(kes_per_unit), is_enabled = 1')->execute([$code, $name, $r]); setFlash('success', "$code added."); }
    }
    redirect('currencies.php');
}
$rows = $pdo->query('SELECT * FROM currencies ORDER BY sort_order, code')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currencies — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Currencies</h1>
        <p class="page-sub">Prices are stored and <strong>paid in Kenya shillings (KES)</strong>. Guests can choose another currency to see converted prices while they browse. You set the rates here (nothing is looked up online), so update them when they change. Payments, receipts and emails always stay in KES; converted amounts are shown only as an approximation.</p>
        <div class="fin-warn">The starting rates are only examples. Check them before you switch a currency on.</div>
        <form method="POST" class="fin-box">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="save">
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Show</th><th>Currency</th><th>1 unit = how many KES</th><th>Last changed</th></tr></thead>
                <tbody><?php foreach ($rows as $r): ?>
                    <tr><td><input type="checkbox" name="enabled[<?= h($r['code']) ?>]" value="1" <?= $r['is_enabled'] ? 'checked' : '' ?> aria-label="Show <?= h($r['code']) ?> to guests"></td>
                        <td><strong><?= h($r['code']) ?></strong> <span class="small"><?= h($r['name']) ?></span></td>
                        <td><input class="fin-input" style="max-width:140px" name="rate[<?= h($r['code']) ?>]" value="<?= h(rtrim(rtrim(number_format((float)$r['kes_per_unit'], 4, '.', ''), '0'), '.')) ?>" inputmode="decimal" aria-label="KES per 1 <?= h($r['code']) ?>"></td>
                        <td class="small"><?= h(date('j M Y', strtotime($r['updated_at']))) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <div class="fin-actions"><button class="fin-btn" type="submit">Save</button></div>
        </form>
        <form method="POST" class="fin-box">
            <h2>Add a currency</h2>
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="add">
            <label class="fin-field" for="cc">3-letter code (for example CHF)</label><input class="fin-input" style="max-width:120px" id="cc" name="code" maxlength="3" required>
            <label class="fin-field" for="cn">Name</label><input class="fin-input" id="cn" name="name" maxlength="60" required>
            <label class="fin-field" for="cr2">1 unit = how many KES</label><input class="fin-input" style="max-width:140px" id="cr2" name="new_rate" inputmode="decimal" required>
            <div class="fin-actions"><button class="fin-btn ghost" type="submit">Add</button></div>
        </form>
<?php appShellEnd(); ?>
</body>
</html>
