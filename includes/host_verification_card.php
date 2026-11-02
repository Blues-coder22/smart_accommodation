<?php
// Host identity verification card (host profile). Needs $pdo, $user_id (host) and $verify_errors (array).
$hv = getHostVerification($pdo, $user_id);
$hv_status = $hv['status'] ?? 'unverified';
?>
    <div class="form-card" id="verification">
        <h3>Host identity (ID) verification <?php if ($hv_status === 'verified') echo hostVerifiedBadge(); ?></h3>
        <?php if ($hv_status === 'verified'): ?>
            <p style="font-size:13px;color:var(--text-secondary)">You are a verified host<?= !empty($hv['decided_at']) ? ' (since ' . h(date('d M Y', strtotime($hv['decided_at']))) . ')' : '' ?>. Guests see a &ldquo;Verified host&rdquo; badge on your listings. For your privacy your ID number and photo were erased once an administrator checked them.</p>
        <?php elseif ($hv_status === 'pending'): ?>
            <p style="font-size:13px"><strong>Request received.</strong> Submitted <?= h(date('d M Y H:i', strtotime($hv['submitted_at']))) ?>. An administrator will review it and email you the result.</p>
        <?php else: ?>
            <?php if ($hv_status === 'rejected'): ?>
                <div class="alert alert-error" style="margin-bottom:14px"><p><strong>Not approved.</strong> <?= h($hv['reason'] ?: 'Please check your details and try again.') ?></p></div>
            <?php else: ?>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px">Verification helps protect guests and hosts. An administrator checks your ID, then your listings show a &ldquo;Verified host&rdquo; badge. Only administrators can see your ID photo, and it is erased as soon as they decide.</p>
            <?php endif; ?>
            <?php if (!empty($verify_errors)): ?>
                <div class="alert alert-error" style="margin-bottom:14px"><?php foreach ($verify_errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="submit_verification" value="1">
                <div class="form-group"><label for="legal_name">Legal name (as on your ID)</label>
                    <input type="text" id="legal_name" name="legal_name" maxlength="120" required value="<?= h($_POST['legal_name'] ?? ($hv['legal_name'] ?? '')) ?>"></div>
                <div class="form-group"><label for="id_type">ID type</label>
                    <select id="id_type" name="id_type"><option>National ID</option><option>Passport</option></select></div>
                <div class="form-group"><label for="id_number">ID or passport number</label>
                    <input type="text" id="id_number" name="id_number" maxlength="14" required placeholder="e.g. 12345678" autocomplete="off"></div>
                <div class="form-group"><label for="business_ref">Property proof reference (optional)</label>
                    <input type="text" id="business_ref" name="business_ref" maxlength="60" placeholder="Title deed, lease, permit or business no." value="<?= h($_POST['business_ref'] ?? ($hv['business_ref'] ?? '')) ?>"></div>
                <div class="form-group"><label for="id_photo">Photo of your ID</label>
                    <input type="file" id="id_photo" name="id_photo" accept="image/jpeg,image/png,image/webp" required>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:4px">JPG, PNG or WebP, up to 4MB. Make sure all four corners and the text are readable.</div></div>
                <div class="form-group"><label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="consent" value="1" required> I confirm these details are mine and correct, and I agree that an administrator may check them.</label></div>
                <button type="submit" class="btn-save"><?= $hv_status === 'rejected' ? 'Resubmit for verification' : 'Submit for verification' ?></button>
            </form>
        <?php endif; ?>
    </div>
