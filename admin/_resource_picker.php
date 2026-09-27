<?php
/**
 * Reusable resource-picker component (Slotting and Rotation).
 * Include with these variables already set in scope:
 *   $rp_prefix       — unique id prefix for this instance (e.g. "walkin", or
 *                       "res82" per-appointment on a page with many cards)
 *   $rp_field_name   — form field name for the hidden resource_id input
 *   $rp_required     — bool: shows a "required" marker (Phase 5 approval flow)
 * JS side lives in _resource_picker_js.php — include that once per page,
 * this markup partial can be included multiple times on the same page.
 */
$rp_field_name = $rp_field_name ?? 'resource_id';
$rp_required   = $rp_required   ?? false;
?>
<div class="resource-picker" data-prefix="<?php echo htmlspecialchars($rp_prefix); ?>">
    <div style="font-size:0.78rem;color:var(--gray);font-weight:600;margin-bottom:0.4rem;">
        🛎️ Assign Room / Chair / Head Spa <?php if ($rp_required): ?><span class="required">*</span><?php else: ?><span style="font-weight:400;">(optional)</span><?php endif; ?>
    </div>
    <div style="display:flex;gap:0.4rem;margin-bottom:0.5rem;" class="rp-type-tabs">
        <button type="button" class="rp-type-btn" data-type="room"
                onclick="rpSelectType('<?php echo $rp_prefix; ?>','room')"
                style="padding:0.35rem 0.7rem;border:1.5px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;font-size:0.8rem;">🚪 Room<span class="rp-suggested-badge" data-type="room" style="display:none;font-size:0.65rem;font-weight:400;color:var(--gray);"> · suggested</span></button>
        <button type="button" class="rp-type-btn" data-type="chair"
                onclick="rpSelectType('<?php echo $rp_prefix; ?>','chair')"
                style="padding:0.35rem 0.7rem;border:1.5px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;font-size:0.8rem;">💺 Chair<span class="rp-suggested-badge" data-type="chair" style="display:none;font-size:0.65rem;font-weight:400;color:var(--gray);"> · suggested</span></button>
        <button type="button" class="rp-type-btn" data-type="head_spa"
                onclick="rpSelectType('<?php echo $rp_prefix; ?>','head_spa')"
                style="padding:0.35rem 0.7rem;border:1.5px solid var(--border2);border-radius:8px;background:var(--bg3);cursor:pointer;font-size:0.8rem;">🧖 Head Spa<span class="rp-suggested-badge" data-type="head_spa" style="display:none;font-size:0.65rem;font-weight:400;color:var(--gray);"> · suggested</span></button>
        <?php if (!$rp_required): ?>
        <button type="button" class="rp-type-btn" data-type=""
                onclick="rpSelectNone('<?php echo $rp_prefix; ?>')"
                style="padding:0.35rem 0.7rem;border:1.5px dashed var(--border2);border-radius:8px;background:transparent;cursor:pointer;font-size:0.8rem;color:var(--gray);">— None / Assign Later —</button>
        <?php endif; ?>
    </div>
    <div id="rp-options-<?php echo $rp_prefix; ?>" class="rp-options">
        <div style="font-size:0.78rem;color:var(--gray);">Pick a date &amp; time first.</div>
    </div>
    <!-- Fix 2: unmistakable, separate from the merely-suggested tab styling above —
         only appears once an actual resource radio has been clicked (rpSelect()). -->
    <div id="rp-confirm-<?php echo $rp_prefix; ?>" style="display:none;margin-top:0.5rem;padding:0.4rem 0.65rem;background:rgba(22,163,74,0.1);border:1px solid rgba(22,163,74,0.35);border-radius:7px;font-size:0.78rem;font-weight:700;color:#0a3622;">
        ✓ <span id="rp-confirm-text-<?php echo $rp_prefix; ?>"></span> selected
    </div>
    <div id="rp-error-<?php echo $rp_prefix; ?>" style="display:none;font-size:0.75rem;color:var(--rust);margin-top:0.3rem;"></div>
    <input type="hidden" name="<?php echo htmlspecialchars($rp_field_name); ?>" id="rp-input-<?php echo $rp_prefix; ?>" value="">
</div>
