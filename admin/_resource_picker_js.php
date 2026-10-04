<script>
// ── Resource Picker (Slotting and Rotation) — shared component ─────────────
// Used by both admin/walkin.php (Phase 4, optional) and admin/appointments.php
// (Phase 5, required for walk-in-sourced approvals). One instance per
// "prefix" so a page can host several pickers at once (e.g. one per pending
// appointment card).
var rpState = {};

function rpInit(prefix, suggestedType, excludeApptId) {
    rpState[prefix] = { type: suggestedType || 'room', suggestedType: suggestedType || 'room', start: '', duration: 0, excludeApptId: excludeApptId || 0, selected: null, selectedName: null };
    document.querySelectorAll('.resource-picker[data-prefix="' + prefix + '"] .rp-suggested-badge').forEach(function (badge) {
        badge.style.display = (badge.dataset.type === rpState[prefix].suggestedType) ? 'inline' : 'none';
    });
    rpHighlightType(prefix);
    rpHideConfirm(prefix);
}

function rpHideConfirm(prefix) {
    var confirmEl = document.getElementById('rp-confirm-' + prefix);
    if (confirmEl) confirmEl.style.display = 'none';
}

function rpShowConfirm(prefix, resourceName) {
    var confirmEl = document.getElementById('rp-confirm-' + prefix);
    var textEl    = document.getElementById('rp-confirm-text-' + prefix);
    if (textEl) textEl.textContent = resourceName;
    if (confirmEl) confirmEl.style.display = 'block';
}

function rpSelectType(prefix, type) {
    if (!rpState[prefix]) rpInit(prefix, type, 0);
    rpState[prefix].type = type;
    rpState[prefix].selected = null;
    rpState[prefix].selectedName = null;
    var input = document.getElementById('rp-input-' + prefix);
    if (input) input.value = '';
    rpHighlightType(prefix);
    rpHideConfirm(prefix);
    rpRefresh(prefix);
}

function rpSelectNone(prefix) {
    if (!rpState[prefix]) return;
    rpState[prefix].type = '';
    rpState[prefix].selected = null;
    rpState[prefix].selectedName = null;
    var input = document.getElementById('rp-input-' + prefix);
    if (input) input.value = '';
    rpHighlightType(prefix);
    rpHideConfirm(prefix);
    var optionsEl = document.getElementById('rp-options-' + prefix);
    if (optionsEl) optionsEl.innerHTML = '<div style="font-size:0.78rem;color:var(--gray);">No resource — can be assigned later.</div>';
}

// Fix 2: this tab styling is a "browsing / suggested" indicator only — it must
// stay visually distinct from (and lighter than) an actual confirmed resource
// selection, which is shown via rpShowConfirm()'s green banner + the selected
// radio's own gold highlight in rpSelect()/rpRefresh() below. Previously this
// used the same gold "selected" color, which misled receptionists into
// thinking a resource had been chosen when only the type tab was active.
function rpHighlightType(prefix) {
    document.querySelectorAll('.resource-picker[data-prefix="' + prefix + '"] .rp-type-btn').forEach(function (btn) {
        var active = btn.dataset.type === (rpState[prefix] ? rpState[prefix].type : '');
        btn.style.borderColor = active ? '#94a3b8' : (btn.dataset.type === '' ? 'var(--border2)' : 'var(--border2)');
        btn.style.background  = active ? '#f1f5f9' : (btn.dataset.type === '' ? 'transparent' : 'var(--bg3)');
    });
}

// Called once the booking date/time and duration are known (or change).
function rpSetWindow(prefix, startDatetime, durationMinutes) {
    if (!rpState[prefix]) rpInit(prefix, 'room', 0);
    rpState[prefix].start    = startDatetime;
    rpState[prefix].duration = durationMinutes;
    rpState[prefix].selected = null;
    rpState[prefix].selectedName = null;
    var input = document.getElementById('rp-input-' + prefix);
    if (input) input.value = '';
    rpHideConfirm(prefix);
    if (rpState[prefix].type) rpRefresh(prefix);
}

function rpRefresh(prefix) {
    var st = rpState[prefix];
    var optionsEl = document.getElementById('rp-options-' + prefix);
    var errEl     = document.getElementById('rp-error-' + prefix);
    if (errEl) errEl.style.display = 'none';
    if (!optionsEl || !st) return;
    if (!st.type) { optionsEl.innerHTML = '<div style="font-size:0.78rem;color:var(--gray);">No resource — can be assigned later.</div>'; return; }
    if (!st.start || !st.duration) {
        optionsEl.innerHTML = '<div style="font-size:0.78rem;color:var(--gray);">Pick a date &amp; time first.</div>';
        return;
    }
    optionsEl.innerHTML = '<div style="font-size:0.78rem;color:var(--gray);">Checking availability…</div>';
    var url = 'resource_availability.php?type=' + encodeURIComponent(st.type)
        + '&start=' + encodeURIComponent(st.start)
        + '&duration=' + encodeURIComponent(st.duration)
        + '&exclude_appt_id=' + encodeURIComponent(st.excludeApptId || 0);
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { optionsEl.innerHTML = '<div style="font-size:0.78rem;color:var(--rust);">' + data.error + '</div>'; return; }
            var resources = data.resources || [];
            if (!resources.length) {
                optionsEl.innerHTML = '<div style="font-size:0.78rem;color:#b45309;">No ' + st.type.replace('_', ' ') + ' available for this time.</div>';
                return;
            }
            var html = '';
            resources.forEach(function (r) {
                var isSel = st.selected === String(r.id);
                // Fix 2: the selected radio's row gets a solid gold fill + border + a
                // checkmark — this, plus the green confirmation banner rpShowConfirm()
                // renders, is the ONLY "confirmed selection" styling on this component.
                html += '<label data-resource-name="' + r.name.replace(/"/g, '&quot;') + '" style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.4rem 0.7rem;'
                    + 'border:1.5px solid ' + (isSel ? 'var(--gold)' : 'var(--border2)') + ';border-radius:8px;'
                    + 'margin:0 0.4rem 0.4rem 0;cursor:pointer;background:' + (isSel ? '#fff8f2' : 'var(--bg3)') + ';font-size:0.82rem;'
                    + (isSel ? 'font-weight:700;' : '') + '">'
                    + '<input type="radio" name="rp-radio-' + prefix + '" value="' + r.id + '" ' + (isSel ? 'checked' : '')
                    + ' onchange="rpSelect(\'' + prefix + '\', this.value)">'
                    + '<span class="rp-check" style="display:' + (isSel ? 'inline' : 'none') + ';">✓ </span>' + r.name
                    + '</label>';
            });
            optionsEl.innerHTML = html;
        })
        .catch(function () {
            optionsEl.innerHTML = '<div style="font-size:0.78rem;color:var(--rust);">Could not check availability.</div>';
        });
}

function rpSelect(prefix, resourceId) {
    if (!rpState[prefix]) return;
    rpState[prefix].selected = String(resourceId);
    var input = document.getElementById('rp-input-' + prefix);
    if (input) input.value = resourceId;
    var resourceName = resourceId;
    // Re-render so the chosen option highlights immediately (gold fill + checkmark).
    document.querySelectorAll('.resource-picker[data-prefix="' + prefix + '"] .rp-options label').forEach(function (lbl) {
        var radio = lbl.querySelector('input[type="radio"]');
        var isSel = radio && radio.value === String(resourceId);
        lbl.style.borderColor = isSel ? 'var(--gold)' : 'var(--border2)';
        lbl.style.background  = isSel ? '#fff8f2' : 'var(--bg3)';
        lbl.style.fontWeight  = isSel ? '700' : 'normal';
        var checkEl = lbl.querySelector('.rp-check');
        if (checkEl) checkEl.style.display = isSel ? 'inline' : 'none';
        if (isSel) resourceName = lbl.dataset.resourceName || resourceId;
    });
    rpState[prefix].selectedName = resourceName;
    rpShowConfirm(prefix, resourceName);
}

function rpGetSelected(prefix) {
    return rpState[prefix] ? rpState[prefix].selected : null;
}

// Phase 5: does this picker require a selection before its form may submit?
function rpValidateRequired(prefix) {
    var sel = rpGetSelected(prefix);
    var errEl = document.getElementById('rp-error-' + prefix);
    if (!sel) {
        if (errEl) { errEl.textContent = 'Please assign a Room / Chair / Head Spa before approving.'; errEl.style.display = 'block'; }
        return false;
    }
    return true;
}
</script>
