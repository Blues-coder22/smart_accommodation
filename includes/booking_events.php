<?php
/** Notes on a booking that an administrator may need later (for example why it was completed early). Created automatically. */
if (!function_exists('ensureBookingEventsTable')) {
    function ensureBookingEventsTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS booking_events (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                actor_id INT NULL,
                event VARCHAR(30) NOT NULL,
                note VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_booking (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}
if (!function_exists('logBookingEvent')) {
    function logBookingEvent($pdo, $booking_id, $actor_id, $event, $note = '') {
        ensureBookingEventsTable($pdo);
        try {
            $pdo->prepare("INSERT INTO booking_events (booking_id, actor_id, event, note) VALUES (?,?,?,?)")
                ->execute([(int)$booking_id, $actor_id ? (int)$actor_id : null, $event, $note !== '' ? mb_substr($note, 0, 500) : null]);
        } catch (Throwable $e) {}
    }
}
