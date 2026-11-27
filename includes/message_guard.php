<?php
/**
 * v9.38.1: protects the chat from repeated and flooded messages.
 *  - A message identical to one the same person just sent in the same conversation (a double click, or the page re-sending after a
 *    dropped connection when the first send had in fact worked) is not saved twice; the existing message is returned instead.
 *  - A person can send only a limited number of messages per minute across all conversations.
 * $table is 'messages' or 'support_messages' only.
 */
if (!function_exists('messageRecentDuplicateId')) {
    function messageRecentDuplicateId($pdo, $table, $threadId, $senderId, $body, $seconds = 15) {
        if (!in_array($table, ['messages', 'support_messages'], true)) return 0;
        $q = $pdo->prepare("SELECT message_id FROM $table WHERE thread_id = ? AND sender_id = ? AND body = ? AND created_at >= (NOW() - INTERVAL " . (int)$seconds . " SECOND) ORDER BY message_id DESC LIMIT 1");
        $q->execute([(int)$threadId, (int)$senderId, $body]);
        return (int)$q->fetchColumn();
    }
}
if (!function_exists('messageRateLimited')) {
    function messageRateLimited($pdo, $table, $senderId, $max = 20, $seconds = 60) {
        if (!in_array($table, ['messages', 'support_messages'], true)) return false;
        $q = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE sender_id = ? AND created_at >= (NOW() - INTERVAL " . (int)$seconds . " SECOND)");
        $q->execute([(int)$senderId]);
        return (int)$q->fetchColumn() >= (int)$max;
    }
}

if (!function_exists('messageAccountProblem')) {
    /**
     * v9.38.1: guest/host chat is blocked when either account is not active (suspended or banned), so nobody writes to someone who
     * can no longer answer and believes they are still active. Returns '' when both are fine, otherwise the text to show the sender.
     */
    function messageAccountProblem($pdo, $senderId, $recipientId) {
        $q = $pdo->prepare('SELECT user_id, status FROM users WHERE user_id IN (?, ?)');
        $q->execute([(int)$senderId, (int)$recipientId]);
        $st = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        $bad = function ($id) use ($st) { $id = (int)$id; return !array_key_exists($id, $st) || ($st[$id] !== null && $st[$id] !== 'active'); };
        if ($bad($senderId))    return 'Your account is restricted, so you cannot send messages right now.';
        if ($bad($recipientId)) return 'This account is suspended or unavailable, so your message was not sent.';
        return '';
    }
}