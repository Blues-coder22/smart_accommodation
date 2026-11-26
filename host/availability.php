<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/booking_calendar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Get host listings for selector
$listings = $pdo->prepare("
    SELECT accommodation_id, title, status
    FROM accommodations
    WHERE host_id = ? AND status = 'approved'
    ORDER BY title ASC
");
$listings->execute([$host_id]);
$listings = $listings->fetchAll();

$selected_id = (int)($_GET['listing_id']
    ?? ($listings[0]['accommodation_id'] ?? 0));

// Verify ownership
$selected = null;
if ($selected_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM accommodations
        WHERE accommodation_id = ? AND host_id = ?
    ");
    $stmt->execute([$selected_id, $host_id]);
    $selected = $stmt->fetch();
}

// Handle availability update
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_availability'])
    && $selected) {
    requireCsrf();

    $dates       = (array)($_POST['dates'] ?? []);
    $is_available = (int)($_POST['is_available'] ?? 1);

    foreach ($dates as $date) {
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

        // Skip dates with confirmed bookings
        $has_booking = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE accommodation_id = ?
            AND status IN ('pending','accepted','payment_pending','confirmed')
            AND ? BETWEEN check_in_date
            AND DATE_SUB(check_out_date, INTERVAL 1 DAY)
        ");
        $has_booking->execute([$selected_id, $date]);
        if ($has_booking->fetchColumn() > 0) continue;

        // Upsert availability
        $pdo->prepare("
            INSERT INTO availability
            (accommodation_id, date, is_available)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE is_available = ?
        ")->execute([
            $selected_id, $date,
            $is_available, $is_available
        ]);
    }
    setFlash('success', 'Availability updated successfully.');
    redirect("availability.php?listing_id=$selected_id");
}

// Get current month and next month
$view_month = sanitize($_GET['month'] ?? date('Y-m'));
$month_dt   = DateTime::createFromFormat('Y-m', $view_month);
if (!$month_dt) $month_dt = new DateTime();
$month_dt->modify('first day of this month');

$prev_month = (clone $month_dt)->modify('-1 month')->format('Y-m');
$next_month = (clone $month_dt)->modify('+1 month')->format('Y-m');

// Get availability for this month
$month_start = $month_dt->format('Y-m-01');
$month_end   = $month_dt->format('Y-m-t');

$availability = [];
if ($selected) {
    $avail_rows = $pdo->prepare("
        SELECT date, is_available FROM availability
        WHERE accommodation_id = ?
        AND date BETWEEN ? AND ?
    ");
    $avail_rows->execute([$selected_id, $month_start, $month_end]);
    foreach ($avail_rows->fetchAll() as $row) {
        $availability[$row['date']] = $row['is_available'];
    }
}

// Booked nights this month: 'confirmed' or 'pending' (awaiting your confirmation)
$booked_dates = [];
if ($selected) {
    foreach (calendarNightStates($pdo, $selected_id, $month_start, $month_end) as $k => $st) {
        if ($st === 'confirmed' || $st === 'pending') $booked_dates[$k] = $st;
    }
}

// Build calendar grid
$days_in_month = (int)$month_dt->format('t');
$first_dow     = (int)$month_dt->format('N'); // 1=Mon, 7=Sun
$today         = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .main        { padding:28px }
        .listing-select { display:flex; gap:10px; align-items:center;
                          margin-bottom:24px; flex-wrap:wrap }
        .listing-select label { font-size:13px; font-weight:500 }
        .listing-select select { padding:8px 12px;
                                 border:1px solid var(--border-strong);
                                 border-radius:6px; font-size:13px;
                                 background:var(--surface-2);
                                 color:var(--text-primary);
                                 min-width:220px }
        .cal-layout  { display:grid;
                       grid-template-columns:1fr 260px; gap:20px }
        .cal-card    { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; overflow:hidden }
        .cal-head    { display:flex; justify-content:space-between;
                       align-items:center; padding:14px 18px;
                       border-bottom:1px solid var(--border) }
        .cal-head h3 { font-size:14px; font-weight:500 }
        .cal-nav     { display:flex; gap:6px }
        .cal-nav a   { padding:5px 12px; font-size:12px;
                       border-radius:4px; text-decoration:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border) }
        .cal-nav a:hover { border-color:var(--accent); color:var(--accent) }
        .cal-grid    { padding:14px }
        .dow-row     { display:grid; grid-template-columns:repeat(7,1fr);
                       gap:4px; margin-bottom:4px }
        .dow-label   { font-size:11px; font-weight:600; text-align:center;
                       color:var(--text-secondary); padding:4px 0;
                       text-transform:uppercase; letter-spacing:.04em }
        .day-row     { display:grid; grid-template-columns:repeat(7,1fr);
                       gap:4px; margin-bottom:4px }
        .day-cell    { aspect-ratio:1; display:flex;
                       align-items:center; justify-content:center;
                       border-radius:4px; font-size:13px;
                       cursor:pointer; border:1px solid transparent;
                       user-select:none; position:relative }
        .day-cell.empty { cursor:default }
        .day-cell.today { border-color:var(--accent);
                          font-weight:600; color:var(--accent-strong) }
        .day-cell.available { background:var(--accent-soft); color:var(--accent-strong) }
        .day-cell.unavailable { background:var(--bad-bg); color:var(--bad-fg);
                                text-decoration:line-through }
        .day-cell.booked { background:#E0E7FF; color:#3730A3;
                           cursor:not-allowed }
        .day-cell.booked-pending { background:#FDE9B0; color:var(--warn-fg); cursor:default }
        .day-cell.past   { opacity:.4; cursor:default }
        .day-cell.selected { box-shadow:0 0 0 2px #126b4e;
                             z-index:1 }
        .day-cell:not(.empty):not(.booked):not(.past):hover {
            border-color:var(--border-strong) }
        .legend      { display:flex; gap:12px; padding:12px 14px;
                       border-top:1px solid var(--border);
                       flex-wrap:wrap }
        .legend-item { display:flex; align-items:center;
                       gap:5px; font-size:11px;
                       color:var(--text-secondary) }
        .legend-dot  { width:12px; height:12px; border-radius:2px }
        .actions-card{ background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:18px;
                       position:sticky; top:80px }
        .actions-card h3 { font-size:14px; font-weight:500;
                           margin-bottom:14px }
        .selected-count { font-size:13px; color:var(--text-secondary);
                          margin-bottom:14px; min-height:20px }
        .avail-options { display:flex; flex-direction:column;
                         gap:8px; margin-bottom:16px }
        .avail-opt   { display:flex; align-items:center; gap:10px;
                       padding:10px 12px;
                       border:1px solid var(--border);
                       border-radius:6px; cursor:pointer;
                       transition:.15s }
        .avail-opt:has(input:checked) { border-color:var(--accent);
                                        background:#F0FAF6 }
        .avail-opt input { accent-color:#126b4e }
        .avail-opt span  { font-size:13px; font-weight:500 }
        .avail-opt small { font-size:11px; color:var(--text-secondary);
                           display:block }
        .btn-update  { width:100%; padding:10px; background:var(--btn-hover);
                       color:#fff; border:none; border-radius:6px;
                       font-size:13px; font-weight:500; cursor:pointer }
        .btn-update:hover { background:#0a6350 }
        .btn-update:disabled { opacity:.5; cursor:not-allowed }
        .no-listing  { text-align:center; padding:48px;
                       color:var(--text-secondary); font-size:14px }
        @media(max-width:1024px){
            .sidebar   { display:none }
            .cal-layout { grid-template-columns:1fr }
            .actions-card { position:static }
        }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Availability</h1>
        <p class="page-sub">
            Select dates and mark them as available
            or unavailable.
        </p>

        <?php if (empty($listings)): ?>
            <div class="no-listing">
                You have no approved listings.
                Add a listing first to manage availability.
            </div>
        <?php else: ?>

        <!-- Listing Selector -->
        <div class="listing-select">
            <label for="listingSelect">Property:</label>
            <select id="listingSelect"
                    onchange="window.location='availability.php
                        ?listing_id='+this.value
                        +'&month=<?= $view_month ?>'">
                <?php foreach ($listings as $l): ?>
                <option value="<?= $l['accommodation_id'] ?>"
                    <?= $l['accommodation_id'] == $selected_id
                        ? 'selected' : '' ?>>
                    <?= h($l['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <form method="POST" id="availForm">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="update_availability" value="1">
            <input type="hidden" id="isAvailable" name="is_available"
                   value="1">
            <!-- Selected dates injected here by JS -->
            <div id="selectedDatesInput"></div>

        <div class="cal-layout">

            <!-- Calendar -->
            <div class="cal-card">
                <div class="cal-head">
                    <h3>
                        <?= $month_dt->format('F Y') ?>
                    </h3>
                    <div class="cal-nav">
                        <a href="?listing_id=<?= $selected_id ?>
                                 &month=<?= $prev_month ?>">
                            &larr;
                        </a>
                        <a href="?listing_id=<?= $selected_id ?>
                                 &month=<?= date('Y-m') ?>">
                            Today
                        </a>
                        <a href="?listing_id=<?= $selected_id ?>
                                 &month=<?= $next_month ?>">
                            &rarr;
                        </a>
                    </div>
                </div>

                <div class="cal-grid">
                    <!-- Day of week headers -->
                    <div class="dow-row">
                        <?php foreach (
                            ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']
                            as $d): ?>
                        <div class="dow-label"><?= $d ?></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Calendar days -->
                    <?php
                    $cell = 1;
                    $total_cells = $first_dow - 1 + $days_in_month;
                    $rows = ceil($total_cells / 7);
                    ?>
                    <?php for ($row = 0; $row < $rows; $row++): ?>
                    <div class="day-row">
                        <?php for ($col = 1; $col <= 7; $col++): ?>
                        <?php
                        $day_num = ($row * 7) + $col - ($first_dow - 1);
                        if ($day_num < 1 || $day_num > $days_in_month) {
                            echo '<div class="day-cell empty"></div>';
                            continue;
                        }
                        $date_str = $month_dt->format('Y-m-')
                                    . str_pad($day_num, 2, '0', STR_PAD_LEFT);
                        $is_today    = ($date_str === $today);
                        $is_past     = ($date_str < $today);
                        $is_booked   = isset($booked_dates[$date_str]);
                        $avail_val   = $availability[$date_str] ?? null;
                        $is_avail    = ($avail_val === '1'
                                        || $avail_val === 1);
                        $is_unavail  = ($avail_val === '0'
                                        || $avail_val === 0);

                        $classes = ['day-cell'];
                        if ($is_today)   $classes[] = 'today';
                        if ($is_past)    $classes[] = 'past';
                        if ($is_booked)  $classes[] = ($booked_dates[$date_str] === 'pending') ? 'booked-pending' : 'booked';
                        elseif ($is_avail)   $classes[] = 'available';
                        elseif ($is_unavail) $classes[] = 'unavailable';

                        $clickable = !$is_past && !$is_booked;
                        ?>
                        <div class="<?= implode(' ', $classes) ?>"
                             data-date="<?= $date_str ?>"
                             <?php if ($clickable): ?>
                             onclick="toggleDay(this)"
                             <?php endif; ?>
                             title="<?= $date_str ?>">
                            <?= $day_num ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Legend -->
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-dot"
                             style="background:var(--accent-soft);
                                    border:1px solid var(--accent-line)">
                        </div>
                        Available
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot"
                             style="background:var(--bad-bg);
                                    border:1px solid var(--bad-line)">
                        </div>
                        Unavailable
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot"
                             style="background:#E0E7FF;
                                    border:1px solid #A5B4FC">
                        </div>
                        Booked (confirmed)
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot"
                             style="background:#FDE9B0;
                                    border:1px solid #F2C35B">
                        </div>
                        Booked (awaiting your confirmation)
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot"
                             style="border:1.5px solid var(--accent)">
                        </div>
                        Today
                    </div>
                </div>
            </div>

            <!-- Actions Panel -->
            <div class="actions-card">
                <h3>Update availability</h3>
                <div class="selected-count" id="selectedCount">
                    No dates selected
                </div>

                <div class="avail-options">
                    <label class="avail-opt">
                        <input type="radio" name="avail_choice"
                               value="1" checked
                               onchange="setAvail(1)">
                        <div>
                            <span>Mark as available</span>
                            <small>
                                Guests can book these dates
                            </small>
                        </div>
                    </label>
                    <label class="avail-opt">
                        <input type="radio" name="avail_choice"
                               value="0"
                               onchange="setAvail(0)">
                        <div>
                            <span>Mark as unavailable</span>
                            <small>
                                Block these dates from booking
                            </small>
                        </div>
                    </label>
                </div>

                <button type="submit" class="btn-update"
                        id="updateBtn" disabled
                        onclick="prepareSubmit()">
                    Update selected dates
                </button>

                <div style="margin-top:12px;font-size:12px;
                             color:var(--text-secondary);
                             line-height:1.5">
                    Booked dates cannot be changed.
                    Past dates are locked.
                </div>
            </div>

        </div>
        </form>

        <?php endif; ?>

    <?php appShellEnd(); ?>

<script>
let selectedDates = new Set();
let isAvailable   = 1;

function toggleDay(el) {
    const date = el.dataset.date;
    if (selectedDates.has(date)) {
        selectedDates.delete(date);
        el.classList.remove('selected');
    } else {
        selectedDates.add(date);
        el.classList.add('selected');
    }
    updateCount();
}

function updateCount() {
    const count = selectedDates.size;
    const el    = document.getElementById('selectedCount');
    el.textContent = count > 0
        ? count + ' date' + (count > 1 ? 's' : '') + ' selected'
        : 'No dates selected';
    document.getElementById('updateBtn').disabled = (count === 0);
}

function setAvail(val) {
    isAvailable = val;
    document.getElementById('isAvailable').value = val;
}

function prepareSubmit() {
    const container = document.getElementById('selectedDatesInput');
    container.innerHTML = '';
    selectedDates.forEach(date => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'dates[]';
        input.value = date;
        container.appendChild(input);
    });
}
</script>

</body>
</html>