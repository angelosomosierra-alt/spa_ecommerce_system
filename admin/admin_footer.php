</div><!-- /admin-content -->
</div><!-- /admin-main -->
</div><!-- /admin-shell -->
<?php if (isset($extra_scripts)) echo $extra_scripts; ?>
<script src="../assets/ui-modal.js"></script>

<!-- ══ Shared PIN Gate Modal ════════════════════════════════════════════════ -->
<div id="pinGateModal" style="display:none;position:fixed;inset:0;z-index:10001;
     background:rgba(30,20,10,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:1.75rem 1.6rem;max-width:310px;width:90vw;
                box-shadow:0 20px 60px rgba(0,0,0,0.22);animation:popIn .28s cubic-bezier(.34,1.56,.64,1);">
        <div style="text-align:center;margin-bottom:1rem;">
            <div style="font-size:1.5rem;margin-bottom:0.4rem;">🔐</div>
            <div style="font-weight:700;font-size:0.98rem;color:var(--brown);" id="pgm-label">Confirm Action</div>
            <div style="font-size:0.76rem;color:var(--gray);margin-top:0.2rem;">Enter your 4-digit receptionist PIN</div>
        </div>
        <input type="password" id="pgm-pin" maxlength="4" inputmode="numeric" placeholder="• • • •"
               style="width:100%;padding:0.75rem;border:2px solid var(--border2);border-radius:10px;
                      font-size:1.5rem;text-align:center;letter-spacing:0.4em;color:var(--brown);
                      background:var(--bg3);box-sizing:border-box;font-family:monospace;transition:border-color .15s;"
               oninput="this.value=this.value.replace(/\D/g,'')"
               onkeydown="if(event.key==='Enter')confirmPinGate()">
        <div id="pgm-error" style="color:#dc2626;font-size:0.76rem;text-align:center;margin-top:0.4rem;min-height:1em;"></div>
        <div style="display:flex;gap:0.6rem;margin-top:0.9rem;">
            <button type="button" onclick="closePinGate()"
                    style="flex:1;padding:0.6rem;border:1px solid var(--border2);border-radius:8px;
                           background:var(--bg3);color:var(--brown);cursor:pointer;font-size:0.88rem;font-weight:600;">
                Cancel
            </button>
            <button type="button" onclick="confirmPinGate()"
                    style="flex:2;padding:0.6rem;border:none;border-radius:8px;
                           background:var(--gold);color:#fff;font-weight:700;cursor:pointer;font-size:0.88rem;">
                ✓ Confirm
            </button>
        </div>
    </div>
</div>

<script>
var _pgmIsCashier = <?php echo is_cashier() ? 'true' : 'false'; ?>;
var _pgmCallback  = null;

function openPinGate(label, form) {
    if (!_pgmIsCashier) { if (form) form.submit(); return; }
    _pgmCallback = function(pin) {
        var f = form.querySelector('[name="pin"]');
        if (f) f.value = pin;
        form.submit();
    };
    document.getElementById('pgm-label').textContent = label;
    document.getElementById('pgm-pin').value = '';
    document.getElementById('pgm-error').textContent = '';
    document.getElementById('pinGateModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('pgm-pin').focus(); }, 80);
}
function closePinGate() {
    document.getElementById('pinGateModal').style.display = 'none';
    _pgmCallback = null;
}
function confirmPinGate() {
    var pin = document.getElementById('pgm-pin').value.trim();
    if (!/^\d{4}$/.test(pin)) {
        document.getElementById('pgm-error').textContent = 'Enter your 4-digit PIN.';
        return;
    }
    closePinGate();
    if (_pgmCallback) _pgmCallback(pin);
}

// ── Password eye-toggle auto-attach ──────────────────────────────────────────
document.querySelectorAll('input[type="password"]').forEach(function(inp) {
    if (inp.dataset.eyeDone) return;
    if (inp.parentElement && inp.parentElement.querySelector('button[aria-label*="assword"]')) { inp.dataset.eyeDone = '1'; return; }
    inp.dataset.eyeDone = '1';
    var wrap = document.createElement('span');
    wrap.style.cssText = 'position:relative;display:block;';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    inp.style.paddingRight = '2.6rem';
    var btn = document.createElement('button');
    btn.type = 'button'; btn.textContent = '👁';
    btn.setAttribute('aria-label', 'Show password');
    btn.style.cssText = 'position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1rem;line-height:1;padding:0;color:#A07850;';
    btn.onclick = function() { var s = inp.type === 'password'; inp.type = s ? 'text' : 'password'; btn.textContent = s ? '🙈' : '👁'; };
    wrap.appendChild(btn);
});
</script>
</body>
</html>