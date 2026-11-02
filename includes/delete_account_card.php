<?php
// Shared "Delete account permanently" card for the tourist and host profile pages.
// Expects $delete_errors (array) from the page.
?>
    <div class="form-card" style="border:1px solid #e6b7b7">
        <h3 style="color:#a12b2b">Delete account permanently</h3>
        <p style="font-size:13px;color:var(--text-secondary)">This cannot be undone. Your name, email, phone number, photo and password are erased for good, your favourites and chat messages are removed, and nobody (including an administrator) can recover or sign in to the account. Bookings, payments and reviews stay on record for the other people involved, but they show as &ldquo;Deleted user&rdquo;. You cannot delete the account while you have an upcoming or pending booking.</p>
        <?php if (!empty($delete_errors)): ?>
            <div class="alert alert-error" style="margin-bottom:14px">
                <?php foreach ($delete_errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Permanently delete your account? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="delete_account" value="1">
            <div class="form-group">
                <label for="delete_password">Current password</label>
                <input type="password" id="delete_password" name="delete_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="delete_confirm">Type DELETE to confirm</label>
                <input type="text" id="delete_confirm" name="delete_confirm" autocomplete="off" required>
            </div>
            <button type="submit" class="btn-save" style="background:#7a1c1c;color:#fff">Delete my account permanently</button>
        </form>
    </div>
