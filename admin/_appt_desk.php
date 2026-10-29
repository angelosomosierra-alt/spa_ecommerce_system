<?php
/**
 * _appt_desk.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Appointments "desk" view (the default view of appointments.php; the old
 * board is still at appointments.php?view=classic).
 *
 * Board: Needs therapist · Booked · In service · Done, one next-step button
 * per card. Tapping a card opens a side panel with the services (therapist per
 * person, room, add-ons and package sessions), the bill, and Reschedule /
 * Cancel / Decline / Pay for group.
 *
 * Saves nothing itself: every button posts to an EXISTING action in
 * appointments.php (assign_person_slot, assign_extra_therapist, set_resource,
 * approve_pending, checkin_appointment, complete, reschedule, cancel, decline,
 * assign_session, checkin_session, complete_session, complete_extra_session,
 * remove_extra_service, revert_complete, pay_group), then reloads the board
 * from appointments.php?ajax=desk_data. Those actions answer with a
 * redirect + flash message, which desk_data hands back for the toast.
 *
 * $DESK_MODAL = true before including it (the Dashboard): the board stays
 * hidden and the panel opens as a centered window, from
 * window.openApptPanel(appointment id) (the Live Resource Grid's blocks).
 * ─────────────────────────────────────────────────────────────────────────────
 */
$DESK_MODAL = !empty($DESK_MODAL);
?>
<?php if ($DESK_MODAL): ?>
<!-- On the Dashboard the rest of the board stays hidden below (the Live
     Resource Grid replaces it) -- but a pending online booking has no room
     yet, so it never appears in that grid. Without this it would be
     invisible on the Dashboard entirely; only this one lane stays visible
     here so staff still see it needs a therapist. -->
<div id="dkPendingLane" class="dk-board" style="margin-bottom:1.5rem;"></div>
<div hidden><?php endif; ?>
<div class="dk-top">
    <div class="dk-bar">
        <input type="text" id="dkQ" class="dk-search" placeholder="Search name, service, therapist" aria-label="Search appointments">
        <div class="dk-bar" id="dkShow" role="group" aria-label="Which days to show"></div>
    </div>
    <a href="appointments.php?view=classic" class="dk-classic">Classic view</a>
</div>
<div id="dkBoard" class="dk-board"><div class="dk-empty">Loading appointments&hellip;</div></div>

<section class="dk-done">
    <div class="dk-done-h">
        <h2>Completed</h2>
        <span class="dk-hint" id="dkDoneRange"></span>
    </div>
    <div id="dkDoneList"></div>
    <button type="button" class="dk-btn" id="dkDoneMore" style="margin-top:0.6rem;">Show 7 more days</button>
</section>
<?php if ($DESK_MODAL): ?></div><?php endif; ?>

<div class="dk-scrim" id="dkScrim" hidden></div>
<aside class="dk-drawer<?php echo $DESK_MODAL ? ' dk-modal' : ''; ?>" id="dkDrawer" hidden aria-label="Appointment details"<?php echo $DESK_MODAL ? ' role="dialog" aria-modal="true"' : ''; ?>></aside>
<div class="dk-toast" id="dkToast" hidden></div>

<style>
.dk-top { display:flex; justify-content:space-between; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:1rem; }
.dk-bar { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
.dk-search { padding:0.5rem 0.85rem; border:1px solid var(--border2); border-radius:20px; background:#fff; min-width:240px; font:inherit; font-size:0.85rem; color:var(--brown); }
.dk-classic { font-size:0.8rem; color:var(--gray); }
.dk-chip { border:1px solid var(--border2); background:#fff; color:var(--brown); border-radius:20px; padding:0.35rem 0.8rem; font-size:0.78rem; font-weight:700; cursor:pointer; }
.dk-chip.sel { background:var(--rust); border-color:var(--rust); color:#fff; }
.dk-chip.sm { padding:0.2rem 0.6rem; font-size:0.72rem; }
.dk-btn { border:1px solid var(--border2); background:#fff; color:var(--brown); border-radius:8px; padding:0.45rem 0.8rem; font-weight:700; cursor:pointer; font-size:0.82rem; }
.dk-btn.primary { background:var(--rust); border-color:var(--rust); color:#fff; }
.dk-btn.ok { background:var(--green); border-color:var(--green); color:#fff; }
.dk-btn.danger { color:var(--red); border-color:var(--red); background:transparent; }
.dk-btn:disabled { opacity:0.45; cursor:not-allowed; }

.dk-board { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:0.85rem; align-items:start; }
@media (max-width:1100px) { .dk-board { grid-template-columns:repeat(2, minmax(0,1fr)); } }
.dk-slipk { display:inline-block; margin-top:0.35rem; border-radius:999px; padding:0.2rem 0.65rem; font-size:0.74rem; font-weight:700; }
.dk-slipk.spa { background:#DDF0EA; color:#1F6F5C; }
.dk-slipk.aes { background:#F7E3F0; color:#9B3D7A; }
.dk-day { font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--brown-md); margin:0.35rem 0.25rem 0; }
.dk-day.over { color:var(--red); }
.dk-done { margin-top:1.4rem; background:#fff; border:1px solid var(--border2); border-radius:14px; padding:0.85rem 1rem; }
.dk-done-h { display:flex; justify-content:space-between; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.6rem; }
.dk-done-h h2 { margin:0; font-size:0.95rem; color:var(--brown); }
.dk-done-h input[type=date] { padding:0.35rem 0.5rem; border:1px solid var(--border2); border-radius:8px; background:var(--bg3); color:var(--brown); font:inherit; font-size:0.82rem; }
.dk-drow2 { display:grid; grid-template-columns:70px minmax(0,1.2fr) minmax(0,1.6fr) minmax(0,1fr) auto; gap:0.6rem; align-items:center; padding:0.55rem 0.4rem; border-top:1px solid var(--border2); font-size:0.82rem; color:var(--brown); cursor:pointer; }
.dk-drow2:hover { background:var(--bg3); }
.dk-dsum { cursor:default; font-weight:800; background:var(--bg3); }
.dk-drow2 .amt { font-weight:800; font-variant-numeric:tabular-nums; text-align:right; }
.dk-drow2 small { color:var(--gray); display:block; font-size:0.7rem; }
@media (max-width:760px) { .dk-drow2 { grid-template-columns:60px 1fr auto; } .dk-drow2 .hide-sm { display:none; } }
@media (max-width:640px)  { .dk-board { grid-template-columns:1fr; } .dk-search { min-width:0; flex:1; } }
.dk-lane { background:var(--bg3); border:1px solid var(--border2); border-radius:14px; padding:0.65rem; display:flex; flex-direction:column; gap:0.55rem; min-height:110px; }
.dk-lane h2 { margin:0.15rem 0.25rem 0.2rem; font-size:0.74rem; text-transform:uppercase; letter-spacing:0.08em; color:var(--brown); display:flex; justify-content:space-between; gap:0.5rem; }
.dk-lane h2 small { display:block; text-transform:none; letter-spacing:0; font-weight:600; color:var(--gray); font-size:0.7rem; margin-top:2px; }
.dk-count { background:#fff; border-radius:20px; padding:1px 8px; font-size:0.72rem; height:fit-content; }
.dk-empty { color:var(--gray); font-size:0.8rem; text-align:center; padding:1.2rem 0.4rem; }

.dk-card { background:#fff; border:1px solid var(--border2); border-left:4px solid var(--stripe, var(--border2)); border-radius:11px; padding:0.65rem; display:flex; flex-direction:column; gap:0.45rem; cursor:pointer; }
.dk-card:hover { border-color:var(--gold); }
.dk-card.sel { box-shadow:0 0 0 2px var(--rust); }
.dk-ctop { display:flex; justify-content:space-between; gap:0.5rem; align-items:baseline; }
.dk-time { font-weight:800; color:var(--brown); font-variant-numeric:tabular-nums; }
.dk-name { font-weight:700; font-size:0.9rem; color:var(--brown); }
.dk-svcs { display:flex; flex-direction:column; gap:2px; font-size:0.76rem; color:var(--brown-md); }
.dk-meta { font-size:0.72rem; color:var(--gray); }
.dk-pills { display:flex; flex-wrap:wrap; gap:4px; }
.dk-pill { font-size:0.66rem; font-weight:800; border-radius:20px; padding:2px 7px; white-space:nowrap; }
.p-unpaid { background:var(--amber-dim); color:var(--amber); }
.p-paid { background:var(--green-dim); color:var(--green); }
.p-down { background:#e1eafb; color:#2f63b8; }
.p-src { background:var(--bg3); color:var(--brown-md); }
.p-need { background:var(--red-dim); color:var(--red); }
.p-pkg { background:var(--rust-dim); color:var(--rust); }

.dk-scrim { position:fixed; inset:0; background:rgba(59,42,26,0.45); z-index:1000; }
.dk-drawer { position:fixed; top:0; right:0; bottom:0; width:min(580px, 100%); background:#fff; z-index:1001; display:flex; flex-direction:column; box-shadow:-12px 0 40px rgba(0,0,0,0.2); }
.dk-dh { padding:0.85rem 1rem; border-bottom:1px solid var(--border2); display:flex; flex-direction:column; gap:0.5rem; background:var(--bg3); }
.dk-drow { display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap; }
.dk-dh h3 { margin:0; font-size:1.1rem; color:var(--brown); }
.dk-x { border:0; background:none; font-size:1.1rem; cursor:pointer; color:var(--gray); }
.dk-steps { display:flex; gap:4px; }
.dk-step { flex:1; text-align:center; font-size:0.68rem; font-weight:800; padding:5px 4px; border-radius:6px; background:#fff; color:var(--gray); }
.dk-step.done { background:var(--green-dim); color:var(--green); }
.dk-step.now { background:var(--rust); color:#fff; }
.dk-db { flex:1; overflow-y:auto; padding:0.85rem 1rem; display:flex; flex-direction:column; gap:0.9rem; }
.dk-sec { display:flex; flex-direction:column; gap:0.5rem; }
.dk-sec > h4 { margin:0; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.08em; color:var(--gray); display:flex; justify-content:space-between; align-items:center; }
.dk-svc { border:1px solid var(--border2); border-radius:10px; padding:0.6rem; display:flex; flex-direction:column; gap:0.5rem; }
.dk-svch { display:flex; justify-content:space-between; gap:0.5rem; align-items:baseline; color:var(--brown); }
.dk-svch b { font-size:0.88rem; }
.dk-svch small { color:var(--gray); }
.dk-amt { font-weight:800; font-variant-numeric:tabular-nums; }
.dk-g2 { display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; }
.dk-lbl { font-size:0.7rem; font-weight:700; color:var(--brown-md); display:block; margin-bottom:3px; }
.dk-drawer select, .dk-drawer input[type=text], .dk-drawer input[type=date], .dk-drawer input[type=time], .dk-drawer input[type=datetime-local], .dk-drawer input[type=password], .dk-drawer input[type=number] { width:100%; padding:0.45rem 0.55rem; border:1px solid var(--border2); border-radius:8px; background:var(--bg3); color:var(--brown); font:inherit; font-size:0.85rem; box-sizing:border-box; }
.dk-drawer select.missing { border-color:var(--red); background:var(--red-dim); }
.dk-srow { display:grid; grid-template-columns:24px 1fr auto; gap:0.5rem; align-items:center; padding:0.4rem 0.5rem; border-radius:8px; background:var(--bg3); font-size:0.78rem; color:var(--brown); }
.dk-dot { width:22px; height:22px; border-radius:50%; display:grid; place-items:center; font-size:0.68rem; font-weight:800; background:var(--border2); color:var(--brown-md); }
.dk-dot.done { background:var(--green); color:#fff; }
.dk-dot.sched { background:#2f63b8; color:#fff; }
.dk-dot.in { background:var(--rust); color:#fff; }
.dk-srow small { color:var(--gray); display:block; }
.dk-inline { border:1px dashed var(--gold); border-radius:10px; padding:0.6rem; display:flex; flex-direction:column; gap:0.5rem; background:var(--bg3); }
.dk-bill { background:var(--bg3); border-radius:10px; padding:0.6rem 0.75rem; display:flex; flex-direction:column; gap:3px; font-variant-numeric:tabular-nums; }
.dk-bill div { display:flex; justify-content:space-between; gap:0.5rem; font-size:0.8rem; color:var(--brown-md); }
.dk-bill .big { font-size:1.05rem; font-weight:800; color:var(--brown); }
.dk-df { border-top:1px solid var(--border2); padding:0.75rem 1rem; display:flex; gap:0.5rem; flex-wrap:wrap; background:#fff; align-items:center; }
.dk-hint { font-size:0.72rem; color:var(--gray); margin:0; line-height:1.45; }
.dk-err { font-size:0.76rem; color:var(--red); margin:0; }
.dk-toast { position:fixed; left:50%; bottom:18px; transform:translateX(-50%); background:var(--brown); color:#fff; padding:0.65rem 1rem; border-radius:10px; font-weight:700; font-size:0.84rem; max-width:calc(100% - 32px); z-index:1100; }
.dk-toast.bad { background:var(--red); }
.dk-drawer[hidden], .dk-scrim[hidden], .dk-toast[hidden] { display:none !important; }
/* Dashboard: the same panel, centered */
.dk-drawer.dk-modal { top:50%; left:50%; right:auto; bottom:auto; transform:translate(-50%, -50%); width:min(640px, calc(100% - 32px)); max-height:calc(100% - 48px); border-radius:16px; overflow:hidden; box-shadow:0 24px 60px rgba(0,0,0,0.35); }
/* Group card: guests booked together share one card per lane */
.dk-gcard .dk-grows { display:flex; flex-direction:column; gap:0.45rem; }
.dk-grow { border:1px solid var(--border2); border-radius:9px; padding:0.5rem 0.6rem; display:flex; flex-direction:column; gap:0.3rem; cursor:pointer; background:#fff; }
.dk-grow:hover, .dk-grow.sel { border-color:var(--gold); background:var(--bg3); }
.dk-grow-top { display:flex; justify-content:space-between; gap:0.5rem; align-items:baseline; }
.dk-grow-top b { font-size:0.88rem; }
.dk-grow .dk-btn { align-self:stretch; padding:0.35rem 0.6rem; font-size:0.8rem; }
/* Add service picker */
.dk-add-q { width:100%; box-sizing:border-box; border:1px solid var(--border2); border-radius:8px; padding:0.5rem 0.65rem; background:#fff; font:inherit; }
.dk-add-list { display:flex; flex-direction:column; gap:0.35rem; max-height:300px; overflow:auto; }
.dk-add-item { border:1px solid var(--border2); border-radius:8px; padding:0.45rem 0.55rem; background:#fff; }
.dk-add-item b { font-size:0.84rem; }
.dk-add-item small { color:var(--gray); margin-left:0.3rem; }
.dk-add-durs { display:flex; flex-wrap:wrap; gap:0.3rem; margin-top:0.35rem; }
.dk-add-dur { border:1px solid var(--gold); background:#fff; color:var(--brown); border-radius:7px; padding:0.2rem 0.5rem; font-size:0.74rem; font-weight:700; cursor:pointer; }
.dk-add-dur:hover { background:var(--rust); border-color:var(--rust); color:#fff; }
</style>

<script>
(function () {
    var CSRF = <?php echo json_encode(generate_csrf_token()); ?>;
    var MODAL = <?php echo $DESK_MODAL ? 'true' : 'false'; ?>;
    var PAYS = [['cash','Cash'],['gcash','GCash'],['maya','Maya'],['qrph','QR Ph'],['card','Card'],['swiper','Swiper']];
    // Fixed-rate discounts carry their %; Voucher (₱ or %) and Celebration (%)
    // take a value typed in by the receptionist.
    var DISC = [['none','None',0],['senior','Senior 20%',20],['pwd','PWD 20%',20],['employee','Staff <?php echo STAFF_DISCOUNT_PCT; ?>%',<?php echo STAFF_DISCOUNT_PCT; ?>],['voucher','Voucher',null],['celebration','Celebration',null]];
    // Discount amount on a base, for a {disc, dv (value), vt ('fixed'|'percent')} choice.
    // elig: the part Senior / PWD / Staff / Voucher apply to (they leave out
    // Daytime promos, nails and lash, and in a booking for several people
    // count only the people who qualify); Celebration is on the whole base.
    function discAmt(base, d, elig) {
        var row = DISC.filter(function (x) { return x[0] === d.disc; })[0] || DISC[0], v = parseFloat(d.dv) || 0;
        var e = elig === undefined ? base : Math.min(elig, base);
        if (row[2] !== null) return Math.round(e * row[2]) / 100;
        if (d.disc === 'celebration') return Math.round(base * Math.min(v, 100)) / 100;
        if (d.disc === 'voucher') return d.vt === 'percent' ? Math.round(e * Math.min(v, 100)) / 100 : Math.min(v, e);
        return 0;
    }
    function voucherLabel(vs, no) { return VSCOPES[vs || 'all'] + (no && no.trim() ? ' · ' + no.trim() : ''); }
    function isPersonDisc(t) { return t === 'senior' || t === 'pwd' || t === 'employee' || t === 'voucher'; }
    // Voucher kinds (VOUCHER_SCOPES in config.php): each is for its own services.
    var VSCOPES = <?php echo json_encode(VOUCHER_SCOPES); ?>;
    // Eligible part of one booking for the discount and people chosen in the panel.
    function eligOf(x, isMain) {
        var e = S.disc === 'voucher' ? ((x.bill.velig || {})[S.vs || 'all'] ?? x.bill.order_total + x.bill.addons) : x.bill.eligible;
        if (isMain && x.people > 1 && isPersonDisc(S.disc)) e = e * Math.min(S.dp || 1, x.people) / x.people;
        return e;
    }
    function discNeedsValue(d) { return (d.disc === 'voucher' || d.disc === 'celebration') && !(parseFloat(d.dv) > 0); }

    var LANES = [
        { k: 'pending',   l: 'Needs therapist', s: 'Online bookings to confirm', c: 'var(--red)' },
        { k: 'assigned',  l: 'Booked',          s: 'Waiting for the customer',   c: '#2f63b8' },
        { k: 'approved',  l: 'In service',      s: 'Checked in',                 c: 'var(--green)' }
    ];
    var D = null, TH = {}, RES = {};
    var S = { q: '', open: null, panel: null, busy: false, doneDays: 7, todayOnly: false };
    // "Today only" is remembered on this computer (per receptionist screen).
    try { S.todayOnly = localStorage.getItem('dkTodayOnly') === '1'; } catch (e) {}

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function pesoS(n) { return peso(n).replace(/\.00$/, ''); }
    function dt(s) { return new Date(String(s).replace(' ', 'T')); }
    function tm(s) { return dt(s).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }); }
    function dayLabel(s) { var d = String(s).slice(0, 10); if (d === D.today) return 'Today'; var t = new Date(D.today + 'T00:00'); t.setDate(t.getDate() + 1); if (d === ymd(t)) return 'Tomorrow'; t.setDate(t.getDate() - 2); if (d === ymd(t)) return 'Yesterday'; return dt(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }); }
    function byId(id) { return (D.appointments || []).filter(function (a) { return a.id === id; })[0]; }
    // Server messages can arrive HTML-escaped (&#039; etc.); show them as text.
    function unescape(m) { var el = document.createElement('textarea'); el.innerHTML = String(m).replace(/<[^>]*>/g, ''); return el.value; }
    var tt; function toast(m, bad) { var t = $('dkToast'); t.textContent = unescape(m); t.className = 'dk-toast' + (bad ? ' bad' : ''); t.hidden = false; clearTimeout(tt); tt = setTimeout(function () { t.hidden = true; }, 4200); }

    // ── Server calls ──────────────────────────────────────────────────────
    function load(closeOnOk) {
        return fetch('appointments.php?ajax=desk_data&done_days=' + S.doneDays, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                D = data; TH = {}; RES = {};
                D.therapists.forEach(function (t) { TH[t.id] = t; });
                D.resources.forEach(function (r) { RES[r.id] = r; });
                var failed = !!(D.flash && D.flash.type === 'danger');
                if (D.flash && D.flash.message) toast(D.flash.message, failed);
                renderBoard(); renderDone();
                // A main step that worked (confirm / check in / complete) closes
                // the panel; anything else keeps it open on the same booking.
                if (S.open) { if (byId(S.open) && !(closeOnOk && !failed)) renderDrawer(); else closeDrawer(); }
            })
            .catch(function () { $('dkBoard').innerHTML = '<div class="dk-empty">Could not load appointments. Refresh the page.</div>'; });
    }
    // json=true: the action answers JSON itself. Otherwise it answers with a
    // redirect + flash message; desk_data returns that message on reload.
    function post(action, appt, fields, json, closeOnOk, okMsg) {
        if (S.busy) return Promise.resolve();
        S.changed = true;
        var fd = new FormData();
        fd.append('csrf_token', CSRF); fd.append('action', action); fd.append('appt_id', appt);
        if (json) fd.append('ajax', '1');
        if (D.cashier) { var pin = $('dkPin'); fd.append('pin', pin ? pin.value : ''); }
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        S.busy = true;
        return fetch('appointments.php', { method: 'POST', body: fd, credentials: 'same-origin', redirect: json ? 'follow' : 'manual' })
            .then(function (r) { return json ? r.json() : null; })
            .then(function (res) {
                S.busy = false;
                var bad = json && res && res.success === false;
                if (bad) toast(res.message || 'That did not work.', true);
                else if (json && res && res.warning) toast(res.warning, true);
                else if (json && okMsg) toast(okMsg);
                else if (json && closeOnOk) toast('Saved');
                return load(closeOnOk && !bad);
            })
            .catch(function () { S.busy = false; toast('Could not reach the server. Try again.', true); });
    }

    // ── Helpers about a booking ───────────────────────────────────────────
    function slotOf(a, n) { return (a.slots.filter(function (s) { return s.slot === n; })[0] || {}).therapist_id; }
    function hasSlotRow(a, n) { return a.slots.some(function (s) { return s.slot === n; }); }
    function missing(a, strict) {
        var m = [];
        for (var n = 1; n <= a.people && !a.service.med; n++) {
            if (strict ? !slotOf(a, n) : !hasSlotRow(a, n)) m.push('therapist' + (a.people > 1 ? ' for person ' + n : ''));
        }
        a.addons.forEach(function (x) { if (!x.med && !x.topup && x.rows.some(function (r) { return strict ? !r.therapist_id : !(r.selected || r.therapist_id); })) m.push('therapist for ' + x.name); });
        if (!a.resource_id && !a.online) m.push('room');
        return m;
    }
    function isPkg(a) { return a.sessions && a.sessions.length > 1; }
    // The same guest's other services from one Book Now booking (each is its
    // own appointment): same group, same name, same day, still to do.
    function visitOf(a) {
        if (!a.group) return [a];
        return D.appointments.filter(function (x) {
            return x.group === a.group && x.name === a.name && x.date === a.date && x.status !== 'cancelled' && x.status !== 'declined';
        }).sort(function (x, y) { return x.when < y.when ? -1 : x.when > y.when ? 1 : x.id - y.id; });
    }
    function sibs(a) { return visitOf(a).filter(function (x) { return x.id !== a.id && x.status !== 'completed'; }); }
    function sibsDone(a) { return visitOf(a).filter(function (x) { return x.id !== a.id && x.status === 'completed'; }); }
    // Still owing: the guest's other services, done or not (a service done
    // before the last one is paid together with the last one).
    function sibsOwe(a) { return visitOf(a).filter(function (x) { return x.id !== a.id && x.rate_type !== 'influencer' && x.bill.due > 0; }); }
    // The customer is checked in once any of their services is: the whole
    // card moves to In service, and the services run one after another at
    // their booked times, each marked complete on its own.
    function guestIn(a) { return visitOf(a).some(function (x) { return x.status === 'approved' || x.status === 'completed'; }); }
    // The service after this one in the guest's visit, if any is left.
    function nextOf(a) { var v = visitOf(a), i = v.indexOf(a); return v.slice(i + 1).filter(function (x) { return x.status === 'assigned'; })[0] || null; }
    function isLastOpen(a) { return sibs(a).length === 0; }
    // A package stays "approved" in the database between sessions; on the
    // board it waits in Booked until one of its sessions is checked in.
    function laneOf(a) {
        if (isPkg(a) && a.status === 'approved' && !a.sessions.some(function (x) { return x.status === 'checked_in'; })) return 'assigned';
        if (a.status === 'assigned' && a.group && guestIn(a)) return 'approved';   // waits its turn in the checked-in visit
        return a.status;
    }
    function nextAction(a) {
        if (a.legacy_two_session) return null;
        if (a.status === 'pending')  return { k: 'approve',  l: 'Confirm booking', cls: 'primary' };
        if (a.status === 'assigned' && a.group && guestIn(a)) return { k: 'start', l: 'Start ' + a.service.name, cls: 'primary' };
        if (a.status === 'assigned') return { k: 'checkin', l: sibs(a).length ? 'Check in · ' + (sibs(a).length + 1) + ' services' : 'Check in', cls: 'primary' };
        if (a.status === 'approved' && !isPkg(a) && !isLastOpen(a)) return { k: 'complete', l: 'Mark ' + a.service.name + ' complete', cls: 'ok' };
        if (a.status === 'approved' && !isPkg(a)) { var owe = a.bill.due + sibsOwe(a).reduce(function (t, x) { return t + x.bill.due; }, 0); return { k: 'complete', l: owe > 0 ? 'Complete & pay ' + pesoS(owe) : 'Complete', cls: 'ok' }; }
        return null;
    }
    function therNames(a) {
        var ids = a.slots.map(function (s) { return s.therapist_id; });
        a.addons.forEach(function (x) { x.rows.forEach(function (r) { ids.push(r.therapist_id); }); });
        return ids.filter(Boolean).filter(function (v, i, arr) { return arr.indexOf(v) === i; }).map(function (id) { return TH[id] ? TH[id].name : ''; }).join(', ');
    }

    // ── Board ─────────────────────────────────────────────────────────────
    function renderBoard() {
        var q = S.q;
        // Today only = today plus anything overdue; later days stay one click away.
        var later = D.appointments.filter(function (a) { return a.status !== 'completed' && String(a.when).slice(0, 10) > D.today; }).length;
        $('dkShow').innerHTML =
            '<button type="button" class="dk-chip' + (!S.todayOnly ? ' sel' : '') + '" data-show="all">All days</button>' +
            '<button type="button" class="dk-chip' + (S.todayOnly ? ' sel' : '') + '" data-show="today">Today only' + (S.todayOnly && later ? ' · ' + later + ' later' : '') + '</button>';
        $('dkShow').querySelectorAll('[data-show]').forEach(function (b) { b.onclick = function () {
            S.todayOnly = b.dataset.show === 'today';
            try { localStorage.setItem('dkTodayOnly', S.todayOnly ? '1' : '0'); } catch (e) {}
            renderBoard();
        }; });
        var pendingEl = $('dkPendingLane');
        var laneSections = LANES.map(function (L) {
            var list = D.appointments.filter(function (a) {
                if (laneOf(a) !== L.k) return false;
                if (S.todayOnly && String(a.when).slice(0, 10) > D.today) return false;
                if (!q) return true;
                return (a.name + ' ' + a.service.name + ' ' + a.addons.map(function (x) { return x.name; }).join(' ') + ' ' + therNames(a)).toLowerCase().indexOf(q) >= 0;
            });
            // Sorted by time, with a heading each time the day changes
            // (Earlier days first, flagged overdue, then Today, Tomorrow, …).
            list.sort(function (x, y) { return x.when < y.when ? -1 : x.when > y.when ? 1 : 0; });
            // Bookings made together (same booking group) share one card per
            // lane and day, placed at the group's earliest time -- whether
            // that's several guests (group_size > 1) or one guest who picked
            // more than one service at once (group_size == 1, several
            // services under the one name: groupCard merges those further,
            // see its own name-bucketing below).
            var items = [], byGroup = {};
            list.forEach(function (a) {
                if (a.group) {
                    var key = a.group + '|' + String(a.when).slice(0, 10);
                    if (!byGroup[key]) { byGroup[key] = { group: true, when: a.when, list: [] }; items.push(byGroup[key]); }
                    byGroup[key].list.push(a);
                } else items.push({ group: false, when: a.when, a: a });
            });
            var html = '', lastDay = null;
            items.forEach(function (it) {
                var d = String(it.when).slice(0, 10);
                if (d !== lastDay) { html += '<div class="dk-day' + (d < D.today ? ' over' : '') + '">' + (d < D.today ? 'Overdue · ' : '') + dayLabel(it.when) + '</div>'; lastDay = d; }
                html += it.group ? (it.list.length > 1 || sibsDone(it.list[0]).length ? groupCard(it.list, L.c) : card(it.list[0], L.c)) : card(it.a, L.c);
            });
            return { k: L.k, html: '<section class="dk-lane"><h2><span>' + L.l + '<small>' + L.s + '</small></span><span class="dk-count">' + list.length + '</span></h2>' +
                (list.length ? html : '<div class="dk-empty">Nothing here.</div>') + '</section>' };
        });
        // A pending online booking has no room yet, so it never appears in
        // the Dashboard's Live Resource Grid -- #dkPendingLane (outside the
        // hidden wrapper there) keeps that one lane visible on its own.
        if (pendingEl) {
            pendingEl.innerHTML = laneSections.filter(function (x) { return x.k === 'pending'; }).map(function (x) { return x.html; }).join('');
            $('dkBoard').innerHTML = laneSections.filter(function (x) { return x.k !== 'pending'; }).map(function (x) { return x.html; }).join('');
        } else {
            $('dkBoard').innerHTML = laneSections.map(function (x) { return x.html; }).join('');
        }
        [$('dkBoard'), pendingEl].forEach(function (root) {
            if (!root) return;
            root.querySelectorAll('.dk-card[data-id], .dk-grow[data-id], .dk-srow[data-id]').forEach(function (c) {
                c.onclick = function (e) { if (e.target.closest('[data-go], [data-rmwait]')) return; e.stopPropagation(); openDrawer(+c.dataset.id); };
                c.onkeydown = function (e) { if (e.key === 'Enter' && e.target === c) openDrawer(+c.dataset.id); };
            });
            root.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { openDrawer(+b.dataset.id, b.dataset.go); }; });
            root.querySelectorAll('[data-rmwait]').forEach(function (b) { b.onclick = function (e) {
                e.stopPropagation();
                if (!confirm('Remove this service? The customer no longer wants it.')) return;
                post('cancel', +b.dataset.rmwait, { cancel_reason: 'Removed before it started' });
            }; });
        });
    }
    function payPill(a) {
        var b = a.bill;
        if (a.rate_type === 'influencer') return '<span class="dk-pill p-pkg">Influencer · ₱0</span>' + (b.due > 0 ? '<span class="dk-pill p-unpaid">Add-ons ' + pesoS(b.due) + '</span>' : '');
        if (b.gcash && b.gcash.status === 'pending') return '<span class="dk-pill p-need">Check payment · ' + pesoS(b.gcash.amount) + ' GCash</span>';
        if (b.gcash && b.gcash.status === 'rejected' && !b.paid && !b.advance) return '<span class="dk-pill p-need">GCash rejected · unpaid ' + pesoS(b.due) + '</span>';
        if (b.gcash && b.gcash.status === 'verified' && b.gcash.kind === 'full' && !b.paid) {
            return '<span class="dk-pill p-paid">Paid in full · GCash</span>' + (b.due > 0 ? '<span class="dk-pill p-unpaid">Add-ons ' + pesoS(b.due) + '</span>' : '');
        }
        if (b.paid && b.due <= 0) return '<span class="dk-pill p-paid">Paid' + (b.method ? ' · ' + esc(b.method) : '') + '</span>';
        if (b.advance > 0 && !b.paid) return '<span class="dk-pill p-down">Down ' + pesoS(b.advance) + ' · bal ' + pesoS(b.due) + '</span>';
        return '<span class="dk-pill p-unpaid">' + (b.paid ? 'Add-ons unpaid ' : 'Unpaid ') + pesoS(b.due) + '</span>';
    }
    function card(a, stripe) {
        var na = nextAction(a), th = therNames(a);
        var pkg = isPkg(a) ? ' <span class="dk-pill p-pkg">' + a.sessions.filter(function (s) { return s.status === 'completed'; }).length + '/' + a.sessions.length + ' sessions</span>' : '';
        return '<article class="dk-card' + (S.open === a.id ? ' sel' : '') + '" data-id="' + a.id + '" style="--stripe:' + stripe + '" tabindex="0">' +
            '<div class="dk-ctop"><span class="dk-time">' + tm(a.when) + '</span><span class="dk-pill p-src">' + esc(a.source) + '</span></div>' +
            '<div class="dk-name">' + esc(a.name) + (a.people > 1 ? ' <span class="dk-meta">· ' + a.people + ' people</span>' : '') + '</div>' +
            '<div class="dk-svcs"><span>' + esc(a.service.name) + ' · ' + a.service.mins + 'm' + pkg + '</span>' +
                a.addons.map(function (x) { if (x.topup) return '<span>+ upgrade difference</span>'; return '<span>+ ' + esc(x.name) + (x.rows.length > 1 ? ' <span class="dk-pill p-pkg">' + x.rows.filter(function (r) { return r.status === 'completed'; }).length + '/' + x.rows.length + '</span>' : '') + '</span>'; }).join('') + '</div>' +
            '<div class="dk-meta">' + (th ? esc(th) : '<span class="dk-pill p-need">No therapist yet</span>') + (a.resource_id && RES[a.resource_id] ? ' · ' + esc(RES[a.resource_id].name) : '') + '</div>' +
            '<div class="dk-pills">' + payPill(a) + (a.group && a.group_size > 1 ? '<span class="dk-pill p-src">Group of ' + a.group_size + '</span>' : '') + '</div>' +
            (na ? '<button type="button" class="dk-btn ' + na.cls + '" data-go="' + na.k + '" data-id="' + a.id + '">' + na.l + '</button>' : '') +
            '</article>';
    }
    // Checked in == status 'approved' or later (see stepIdx in renderDrawer).
    // A service that hasn't reached that yet is still waiting its turn.
    function isCheckedInOrDone(a) { return a.status === 'approved' || a.status === 'completed'; }
    function groupCard(list, stripe) {
        var names = list.map(function (a) { return a.name; }).filter(function (n, i, arr) { return arr.indexOf(n) === i; });
        // Owed by everyone on the card, including a guest's services already
        // done but paid together with their last one.
        var seen = {}, due = 0;
        list.concat.apply(list, list.map(sibsDone)).forEach(function (a) { if (seen[a.id]) return; seen[a.id] = true; due += a.rate_type === 'influencer' ? 0 : a.bill.due; });
        var times = list.map(function (a) { return tm(a.when); }).filter(function (t, i, arr) { return arr.indexOf(t) === i; });
        // One guest, several services booked together (Scenario 1): bucket
        // by name, earliest service in a bucket is "main" and drives that
        // bucket's own check-in/status; the rest list as waiting their turn,
        // removable with ✕ until started, opened individually once it's
        // their turn (same drawer every other booking uses).
        var buckets = [], byName = {};
        list.forEach(function (a) {
            if (!byName[a.name]) { byName[a.name] = { name: a.name, items: [] }; buckets.push(byName[a.name]); }
            byName[a.name].items.push(a);
        });
        return '<article class="dk-card dk-gcard" style="--stripe:' + stripe + '">' +
            '<div class="dk-ctop"><span class="dk-time">' + times.join(' · ') + '</span><span class="dk-pill p-src">' + esc(list[0].source) + '</span></div>' +
            '<div class="dk-name">' + esc(names.join(' & ')) + (list[0].group_size > 1 ? ' <span class="dk-meta">· group of ' + list[0].group_size + '</span>' : '') + '</div>' +
            '<div class="dk-grows">' + buckets.map(function (bk) {
                bk.items.sort(function (x, y) { return x.when < y.when ? -1 : x.when > y.when ? 1 : 0; });
                var main = bk.items[0], waiting = bk.items.slice(1), done = sibsDone(main);
                var na = nextAction(main), th = therNames(main), inVisit = guestIn(main);
                var pkg = isPkg(main) ? ' <span class="dk-pill p-pkg">' + main.sessions.filter(function (s) { return s.status === 'completed'; }).length + '/' + main.sessions.length + '</span>' : '';
                return '<div class="dk-grow' + (S.open === main.id ? ' sel' : '') + '" data-id="' + main.id + '" tabindex="0">' +
                    '<div class="dk-grow-top"><b>' + esc(main.name) + '</b>' + (inVisit ? '<span class="dk-pill p-paid">Customer checked in</span>' : (times.length > 1 ? '<span class="dk-meta">' + tm(main.when) + '</span>' : '')) + '</div>' +
                    (done.length ? '<div class="dk-sess">' + done.map(function (w) {
                        return '<div class="dk-srow" data-id="' + w.id + '" tabindex="0"><span class="dk-dot done">✓</span><span>' + esc(w.service.name) + ' — ' + peso(w.service.price) + '<small>Done · ' + tm(w.when) + '</small></span></div>';
                    }).join('') + '</div>' : '') +
                    '<div class="dk-svcs"><span>' + esc(main.service.name) + ' · ' + main.service.mins + 'm' + pkg + (main.status === 'approved' && (waiting.length || done.length) ? ' <small>· in session · ' + tm(main.when) + '</small>' : (waiting.length ? ' <small>· main service</small>' : '')) + '</span>' +
                        main.addons.map(function (x) { return '<span>+ ' + (x.topup ? 'upgrade difference' : esc(x.name)) + '</span>'; }).join('') + '</div>' +
                    '<div class="dk-meta">' + (th ? esc(th) : (main.service.med ? 'Doctor / Nurse' : '<span class="dk-pill p-need">No therapist yet</span>')) + (main.resource_id && RES[main.resource_id] ? ' · ' + esc(RES[main.resource_id].name) : '') + '</div>' +
                    '<div class="dk-pills">' + payPill(main) + '</div>' +
                    (waiting.length ? '<div class="dk-sess">' + waiting.map(function (w) {
                        var wdone = w.status === 'completed', inSession = isCheckedInOrDone(w) && !wdone;
                        var label = wdone ? 'Done' : inSession ? 'In session' : (inVisit ? 'Next · ' + tm(w.when) + ' · starts after ' + esc(main.service.name) : 'Waiting its turn · ' + tm(w.when));
                        var dot = wdone ? 'done' : inSession ? 'in' : '';
                        return '<div class="dk-srow" data-id="' + w.id + '" tabindex="0"><span class="dk-dot ' + dot + '">' + (wdone ? '✓' : inSession ? '●' : '') + '</span>' +
                            '<span>' + esc(w.service.name) + ' — ' + peso(w.service.price) + '<small>' + label + '</small></span>' +
                            (!isCheckedInOrDone(w) && !inVisit ? '<button type="button" class="dk-btn danger" data-rmwait="' + w.id + '">✕</button>' : '') +
                            '</div>';
                    }).join('') + '</div>' : '') +
                    (na ? '<button type="button" class="dk-btn ' + na.cls + '" data-go="' + na.k + '" data-id="' + main.id + '">' + na.l + '</button>' : '') +
                    '</div>';
            }).join('') + '</div>' +
            (due > 0 ? '<div class="dk-meta">' + (list[0].group_size > 1 ? 'Group still to collect: <b>' + peso(due) + '</b> · use <b>Pay for group</b> in any guest\'s panel'
                : 'Still to collect: <b>' + peso(due) + '</b> · all services are paid together') + '</div>' : '') +
            '</article>';
    }
    // ── Completed (last N days, newest first, by day; open a row for Undo) ──
    function renderDone() {
        var q = S.q;
        var list = D.appointments.filter(function (a) {
            if (a.status !== 'completed') return false;
            return !q || (a.name + ' ' + a.service.name + ' ' + therNames(a)).toLowerCase().indexOf(q) >= 0;
        });
        list.sort(function (x, y) { return x.start < y.start ? 1 : x.start > y.start ? -1 : 0; });
        $('dkDoneRange').textContent = S.doneDays === 1 ? 'Today' : 'Last ' + S.doneDays + ' days';
        var html = '', day = null, dayTotal = 0, dayCount = 0;
        function closeDay() {
            if (day !== null) html += '<div class="dk-drow2 dk-dsum"><span></span><span>' + dayCount + ' completed</span><span class="hide-sm"></span><span class="hide-sm"></span><span class="amt">' + peso(dayTotal) + '</span></div>';
        }
        list.forEach(function (a) {
            var d = String(a.start).slice(0, 10), b = a.bill, disc = b.completion_discount || b.discount, amt = b.paid ? b.paid_amount : 0;
            if (d !== day) { closeDay(); day = d; dayTotal = 0; dayCount = 0; html += '<div class="dk-day" style="margin-top:0.6rem;">' + dayLabel(a.start) + '</div>'; }
            dayTotal += amt; dayCount++;
            html += '<div class="dk-drow2" data-id="' + a.id + '" tabindex="0"><span>' + tm(a.start) + '</span>' +
                '<span><b>' + esc(a.name) + '</b><small>' + esc(a.source) + '</small></span>' +
                '<span class="hide-sm">' + esc(a.service.name) + (a.addons.length ? ' + ' + a.addons.map(function (x) { return esc(x.name); }).join(', ') : '') + '<small>' + esc(therNames(a)) + '</small></span>' +
                '<span class="hide-sm">' + (a.rate_type === 'influencer' ? 'Influencer · ₱0' : (b.paid ? 'Paid · ' + esc(b.method || '') : 'Unpaid / on account')) + (disc ? '<small>Discount ' + peso(disc) + (b.voucher_label ? ' · voucher: ' + esc(b.voucher_label) : '') + '</small>' : '') + '</span>' +
                '<span class="amt">' + peso(amt) + '</span></div>';
        });
        closeDay();
        $('dkDoneList').innerHTML = list.length ? html : '<div class="dk-empty">Nothing completed in the ' + (S.doneDays === 1 ? 'day' : 'last ' + S.doneDays + ' days') + '.</div>';
        $('dkDoneList').querySelectorAll('.dk-drow2[data-id]').forEach(function (r) { r.onclick = function () { openDrawer(+r.dataset.id); }; });
    }
    function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); } // local date, not UTC
    $('dkDoneMore').onclick = function () { S.doneDays += 7; load(); };

    $('dkQ').addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); if (D) { renderBoard(); renderDone(); } });

    // ── Drawer ────────────────────────────────────────────────────────────
    function openDrawer(id, panel) {
        S.open = id; S.panel = panel === 'checkin' || panel === 'complete' ? panel : null;
        S.method = null; S.dp = 1; S.vs = 'all'; S.disc = 'none'; S.dv = ''; S.vt = 'fixed'; S.vl = ''; S.payNow = true; S.infl = false; S.schedFor = null; S.resched = S.cancelling = S.grp = false; S.add = null; S.addProduct = null;
        renderBoard(); renderDrawer(); $('dkDrawer').hidden = false; $('dkScrim').hidden = false;
    }
    function closeDrawer() {
        S.open = null; S.pin = ''; $('dkDrawer').hidden = true; $('dkScrim').hidden = true; if (D) renderBoard();
        // Dashboard: show what changed on the Live Resource Grid.
        if (MODAL && S.changed) { S.changed = false; if (window.refreshResourceGrid) window.refreshResourceGrid(); }
    }
    $('dkScrim').onclick = closeDrawer;
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && S.open) closeDrawer(); });

    function isOvr(sel) { var o = sel.options[sel.selectedIndex]; return o && o.dataset.ovr ? '1' : ''; }
    function therSelect(qual, val, attrs, allowAny) {
        var opts = D.therapists.filter(function (t) { return qual.indexOf(t.id) >= 0; });
        // Therapists not trained for it can still be picked on purpose
        // (e.g. the customer asks for them); the server is told it's deliberate.
        var others = D.therapists.filter(function (t) { return qual.indexOf(t.id) < 0; });
        return '<select ' + attrs + (val ? '' : ' class="missing"') + '><option value="">Choose therapist…</option>' +
            (allowAny ? '<option value="__any__">Any available</option>' : '') +
            opts.map(function (t) { return '<option value="' + t.id + '"' + (t.id === val ? ' selected' : '') + '>' + esc(t.name) + (t.on ? '' : ' (off duty)') + '</option>'; }).join('') +
            (others.length ? '<optgroup label="Not trained · pick anyway">' + others.map(function (t) { return '<option value="' + t.id + '" data-ovr="1"' + (t.id === val ? ' selected' : '') + '>' + esc(t.name) + (t.on ? '' : ' (off duty)') + '</option>'; }).join('') + '</optgroup>' : '') +
            '</select>';
    }
    // ── Add service (any open booking: needs therapist, booked, checked in) ──
    // Uses the same catalogue as Book Now and posts to add_extra_service,
    // which prices it by the booking's rate, handles multi-session add-ons,
    // therapist qualification and commission.
    var CAT = null;
    function loadCat() {
        if (CAT) return Promise.resolve(CAT);
        return fetch('appointments.php?ajax=desk_catalog', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { CAT = d.services || []; return CAT; });
    }
    function canAdd(a) { return ['pending', 'assigned', 'approved'].indexOf(a.status) >= 0 && !a.legacy_two_session; }
    function durLabel(d) { return d.m + ' min' + (d.n > 1 ? ' × ' + d.n + ' sessions' : ''); }
    function addServiceHtml(a) {
        if (!canAdd(a)) return '';
        if (!S.add) return '<button type="button" class="dk-btn" id="dkAddOpen" style="align-self:flex-start;">+ Add service</button>';
        var A = S.add, up = A.mode === 'upgrade', h = '<div class="dk-inline" id="dkAddBox">' + (up ? '<b>Upgrade ' + esc(a.service.name) + ' to…</b>' : '');
        if (!CAT) return h + '<span class="dk-hint">Loading services…</span></div>';
        if (!A.svc) {
            var q = (A.q || '').toLowerCase();
            var list = CAT.filter(function (s) { return !q || (s.name + ' ' + s.cat).toLowerCase().indexOf(q) >= 0; }).slice(0, 12);
            h += '<input class="dk-add-q" id="dkAddQ" type="search" placeholder="Search a service…" autocomplete="off" value="' + esc(A.q || '') + '" aria-label="Search a service">' +
                '<div class="dk-add-list">' + (list.length ? list.map(function (s) {
                    return '<div class="dk-add-item"><b>' + esc(s.name) + '</b><small>' + esc(s.cat) + (s.med ? ' · Doctor / Nurse' : '') + '</small><div class="dk-add-durs">' +
                        s.durs.map(function (d, di) { if (up && d.n > 1) return ''; return '<button type="button" class="dk-add-dur" data-addpick="' + s.id + ',' + di + '">' + (up ? '' : '+ ') + durLabel(d) + ' · ' + pesoS(d.p) + '</button>'; }).join('') + '</div></div>';
                }).join('') : '<span class="dk-hint">No service matches.</span>') + '</div>' +
                '<div class="dk-bar"><button type="button" class="dk-btn" id="dkAddCancel">Cancel</button></div></div>';
            return h;
        }
        var s = A.svc, d = s.durs[A.di];
        if (up) {
            var newP = a.rate_type === 'influencer' ? 0 : d.p * a.people, diff = newP - a.service.price;
            return h + '<div class="dk-svch"><span>' + esc(a.service.name) + ' → <b>' + esc(s.name) + '</b> <small>' + durLabel(d) + '</small></span><span class="dk-amt">' + peso(newP) + '</span></div>' +
                '<p class="dk-hint">' + (diff >= 0 ? 'Adds ' + peso(diff) + ' to the bill' : 'Lowers the bill by ' + peso(-diff)) + (a.bill.paid && diff > 0 ? '; already paid, so the difference is collected at check-in or when completing' : '') + '. Reports will show only ' + esc(s.name) + '.</p>' +
                '<div class="dk-bar"><button type="button" class="dk-btn primary" id="dkUpGo">Upgrade</button><button type="button" class="dk-btn" id="dkAddBack">Pick another</button><button type="button" class="dk-btn" id="dkAddCancel">Cancel</button></div></div>';
        }
        var addPrice = (A.price !== undefined && A.price !== null && A.price !== '') ? A.price : d.p;
        h += '<div class="dk-svch"><span><b>' + esc(s.name) + '</b> <small>' + durLabel(d) + '</small></span><span class="dk-amt">' + peso(d.p) + '</span></div>' +
            (d.n > 1 ? '<p class="dk-hint">Adds ' + d.n + ' sessions; each one is marked done here as it happens.</p>' : '') +
            '<div class="dk-g2">' +
            (s.med ? '<div><label class="dk-lbl">Done by</label><div class="dk-hint">Doctor / Nurse</div></div>'
                   : '<div><label class="dk-lbl" for="dkAddTh">Therapist</label>' + therSelect(s.qual, A.th ? +A.th : null, 'id="dkAddTh"', false).replace('<option value="">Choose therapist…</option>', '<option value="">Assign later</option>') + '</div>') +
            (a.people > 1 ? '<div><label class="dk-lbl" for="dkAddPerson">For</label><select id="dkAddPerson">' +
                Array.apply(null, Array(a.people)).map(function (_, i) { var v = 'Person ' + (i + 1); return '<option' + (A.person === v ? ' selected' : '') + '>' + v + '</option>'; }).join('') + '</select></div>' : '') +
            '<div><label class="dk-lbl" for="dkAddPrice">Price</label><input id="dkAddPrice" type="number" min="0" step="0.01" value="' + esc(addPrice) + '"></div>' +
            '</div>' +
            (Number(addPrice) !== d.p ? '<p class="dk-hint">Overriding the usual ' + peso(d.p) + ' — e.g. set to ' + pesoS(0) + ' for a free/complimentary add-on.</p>'
                                       : '<p class="dk-hint">Charged with this booking (' + (a.rate_type === 'regular' ? 'regular rate' : esc(a.rate_type) + ' rate') + '); collected at check-in or when completing. Change the price above for a free/complimentary add-on.</p>') +
            '<div class="dk-bar"><button type="button" class="dk-btn primary" id="dkAddGo">Add to booking</button><button type="button" class="dk-btn" id="dkAddBack">Pick another</button><button type="button" class="dk-btn" id="dkAddCancel">Cancel</button></div></div>';
        return h;
    }
    function wireAdd(a) {
        var open = $('dkAddOpen');
        if (open) open.onclick = function () { S.add = { q: '', svc: null, di: 0, th: '', person: 'Person 1' }; renderDrawer(); loadCat().then(function () { if (S.add) { renderDrawer(); var q = $('dkAddQ'); if (q) q.focus(); } }); };
        var upOpen = $('dkUpOpen');
        if (upOpen) upOpen.onclick = function () { S.add = { mode: 'upgrade', q: '', svc: null, di: 0 }; renderDrawer(); loadCat().then(function () { if (S.add) { renderDrawer(); var q = $('dkAddQ'); if (q) q.focus(); } }); };
        var upGo = $('dkUpGo');
        if (upGo) upGo.onclick = function () {
            var s = S.add.svc, d = s.durs[S.add.di];
            S.add = null;
            post('upgrade_service', a.id, { new_service_id: s.id, new_duration_id: d.id || '' }, true, false, 'Upgraded to ' + s.name);
        };
        var q = $('dkAddQ');
        if (q) q.oninput = function () { S.add.q = q.value; var pos = q.selectionStart; renderDrawer(); var n = $('dkAddQ'); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (x) {} } };
        document.querySelectorAll('[data-addpick]').forEach(function (b) { b.onclick = function () {
            var p = b.dataset.addpick.split(','), s = CAT.filter(function (x) { return x.id === +p[0]; })[0];
            // Default to the booking's own therapist when qualified.
            var own = (a.slots[0] || {}).therapist_id;
            S.add.svc = s; S.add.di = +p[1]; S.add.th = own && s.qual.indexOf(own) >= 0 ? String(own) : ''; S.add.price = null;
            renderDrawer();
        }; });
        var th = $('dkAddTh'); if (th) th.onchange = function () { S.add.th = th.value; };
        var pe = $('dkAddPerson'); if (pe) pe.onchange = function () { S.add.person = pe.value; };
        var pr = $('dkAddPrice'); if (pr) pr.oninput = function () { S.add.price = pr.value; var el = $('dkAddPrice'); var pos = el ? el.selectionStart : null; renderDrawer(); var n = $('dkAddPrice'); if (n && pos !== null) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (x) {} } };
        var back = $('dkAddBack'); if (back) back.onclick = function () { S.add.svc = null; renderDrawer(); };
        var cancel = $('dkAddCancel'); if (cancel) cancel.onclick = function () { S.add = null; renderDrawer(); };
        var go = $('dkAddGo'); if (go) go.onclick = function () {
            var s = S.add.svc, d = s.durs[S.add.di];
            var priceOverride = (S.add.price !== undefined && S.add.price !== null && S.add.price !== '') ? S.add.price : d.p;
            var f = { extra_svc_id: s.id, extra_duration_id: d.id || '', extra_therapist: s.med ? '' : (S.add.th || ''), person_label: S.add.person || 'Person 1', extra_payment_method: 'cash',
                      extra_price: priceOverride,
                      override: !s.med && S.add.th && s.qual.indexOf(+S.add.th) < 0 ? '1' : '' };
            S.add = null;
            post('add_extra_service', a.id, f, true, false, s.name + ' added');
        };
    }
    // Products/vouchers bought alongside this visit (Scenario 3a). Allowed
    // even once the booking is completed -- buying something on the way out
    // is the common case this exists for. Reuses Sell Product's own save
    // logic server-side; this is just a lighter picker tagged to the visit.
    function canAddProduct(a) { return ['cancelled', 'declined'].indexOf(a.status) < 0; }
    function productsHtml(a) {
        var bought = (a.products || []).map(function (p) {
            return '<div><span>' + esc(p.name) + (p.qty > 1 ? ' ×' + p.qty : '') + '</span><span>' + peso(p.amount) + '</span></div>';
        }).join('');
        var boughtBlock = bought ? '<div class="dk-bill">' + bought + '</div>' : '';
        if (!canAddProduct(a)) return boughtBlock;
        if (!S.addProduct) return boughtBlock + '<button type="button" class="dk-btn" id="dkAddProdOpen" style="align-self:flex-start;">+ Add product</button>';
        var AP = S.addProduct;
        if (!AP.item) {
            var q = (AP.q || '').toLowerCase();
            var list = (D.products_catalog || []).filter(function (p) { return !q || (p.name + ' ' + p.cat).toLowerCase().indexOf(q) >= 0; }).slice(0, 12);
            return boughtBlock + '<div class="dk-inline" id="dkAddProdBox">' +
                '<input class="dk-add-q" id="dkAddProdQ" type="search" placeholder="Search a product…" autocomplete="off" value="' + esc(AP.q || '') + '" aria-label="Search a product">' +
                '<div class="dk-add-list">' + (list.length ? list.map(function (p) {
                    return '<div class="dk-add-item"><b>' + esc(p.name) + '</b><small>' + esc(p.cat) + ' · ' + p.stock + ' in stock</small>' +
                        '<div class="dk-add-durs"><button type="button" class="dk-add-dur" data-prodpick="' + p.id + '"' + (p.stock < 1 ? ' disabled' : '') + '>+ ' + pesoS(p.price) + '</button></div></div>';
                }).join('') : '<span class="dk-hint">No product matches.</span>') + '</div>' +
                '<div class="dk-bar"><button type="button" class="dk-btn" id="dkAddProdCancel">Cancel</button></div></div>';
        }
        var p = AP.item, qty = AP.qty || 1;
        return boughtBlock + '<div class="dk-inline" id="dkAddProdBox">' +
            '<div class="dk-svch"><span><b>' + esc(p.name) + '</b></span><span class="dk-amt">' + peso(p.price * qty) + '</span></div>' +
            '<div class="dk-g2"><div><label class="dk-lbl" for="dkAddProdQty">Quantity</label><input id="dkAddProdQty" type="number" min="1" max="' + p.stock + '" value="' + qty + '"></div></div>' +
            '<div><label class="dk-lbl">Payment method</label><div class="dk-bar">' + PAYS.map(function (m) { return '<button type="button" class="dk-chip sm' + (AP.method === m[0] ? ' sel' : '') + '" data-apm="' + m[0] + '">' + m[1] + '</button>'; }).join('') + '</div></div>' +
            '<div class="dk-bar"><button type="button" class="dk-btn primary" id="dkAddProdGo">Add &amp; charge</button><button type="button" class="dk-btn" id="dkAddProdBack">Pick another</button><button type="button" class="dk-btn" id="dkAddProdCancel">Cancel</button></div></div>';
    }
    function wireProducts(a) {
        var open = $('dkAddProdOpen');
        if (open) open.onclick = function () { S.addProduct = { q: '', item: null, qty: 1, method: 'cash' }; renderDrawer(); var q = $('dkAddProdQ'); if (q) q.focus(); };
        var q = $('dkAddProdQ');
        if (q) q.oninput = function () { S.addProduct.q = q.value; var pos = q.selectionStart; renderDrawer(); var n = $('dkAddProdQ'); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (x) {} } };
        document.querySelectorAll('[data-prodpick]').forEach(function (b) { b.onclick = function () {
            var p = (D.products_catalog || []).filter(function (x) { return x.id === +b.dataset.prodpick; })[0];
            if (!p) return;
            S.addProduct.item = p; S.addProduct.qty = 1;
            renderDrawer();
        }; });
        var qty = $('dkAddProdQty'); if (qty) qty.onchange = function () { S.addProduct.qty = Math.max(1, Math.min(S.addProduct.item.stock, +qty.value || 1)); renderDrawer(); };
        document.querySelectorAll('[data-apm]').forEach(function (b) { b.onclick = function () { S.addProduct.method = b.dataset.apm; renderDrawer(); }; });
        var back = $('dkAddProdBack'); if (back) back.onclick = function () { S.addProduct.item = null; renderDrawer(); };
        var cancel = $('dkAddProdCancel'); if (cancel) cancel.onclick = function () { S.addProduct = null; renderDrawer(); };
        var go = $('dkAddProdGo'); if (go) go.onclick = function () {
            var p = S.addProduct.item, qty = S.addProduct.qty || 1, method = S.addProduct.method || 'cash';
            S.addProduct = null;
            post('add_product_to_booking', a.id, { items: JSON.stringify([{ id: p.id, qty: qty }]), payment_method: method }, true, false, p.name + ' added');
        };
    }
    function roomSelect(a) {
        var pref = D.resources.filter(function (r) { return r.type === a.service.res; }), other = D.resources.filter(function (r) { return r.type !== a.service.res; });
        function o(r) { return '<option value="' + r.id + '"' + (r.id === a.resource_id ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }
        return '<select id="dkRoom"' + (a.resource_id ? '' : ' class="missing"') + '><option value="">Choose room…</option>' + pref.map(o).join('') +
            (other.length ? '<optgroup label="Other">' + other.map(o).join('') + '</optgroup>' : '') + '</select>';
    }
    function sessDot(st) { return st === 'completed' ? 'done' : st === 'checked_in' ? 'in' : st === 'scheduled' ? 'sched' : ''; }

    function renderDrawer() {
        var a = byId(S.open); if (!a) return;
        var b = a.bill, na = nextAction(a), locked = a.status === 'completed';
        var stepIdx = { pending: 0, assigned: 1, approved: 2, completed: 3 }[a.status];
        var steps = ['Booked', a.service.med ? 'Ready' : 'Therapist set', 'Checked in', 'Done'].map(function (l, i) { return '<span class="dk-step ' + (i < stepIdx ? 'done' : i === stepIdx ? 'now' : '') + '">' + l + '</span>'; }).join('');

        // Main service: therapist per person + room + package sessions
        // A Doctor / Nurse service has no therapist to pick.
        var slotsHtml = a.service.med ? '<div><label class="dk-lbl">Done by</label><div class="dk-hint">Doctor / Nurse</div></div>' : '';
        for (var n = 1; n <= a.people && !a.service.med; n++) {
            slotsHtml += '<div><label class="dk-lbl">Therapist' + (a.people > 1 ? ' · person ' + n : '') + '</label>' +
                (locked ? '<div class="dk-hint">' + esc(TH[slotOf(a, n)] ? TH[slotOf(a, n)].name : '—') + '</div>' : therSelect(a.service.qual, slotOf(a, n), 'data-slot="' + n + '"', a.status === 'pending')) + '</div>';
        }
        var sessHtml = '';
        if (isPkg(a)) {
            sessHtml = '<div class="dk-sess">' + a.sessions.map(function (x) {
                var label = x.status === 'completed' ? 'Done' : x.status === 'checked_in' ? 'In service now' : x.status === 'scheduled' ? dayLabel(x.date) + ' ' + tm(x.date) + (TH[x.therapist_id] ? ' · ' + TH[x.therapist_id].name : '') : 'Not scheduled yet';
                var act = '';
                if (!locked && a.status !== 'pending') {
                    if (x.status === 'checked_in') act = '<button type="button" class="dk-btn ok" data-sdone="' + x.id + '">Mark done</button>';
                    else if (x.status === 'scheduled' && x.n > 1 && a.status === 'approved' && String(x.date).slice(0, 10) <= D.today) act = '<button type="button" class="dk-btn primary" data-sin="' + x.id + '">Check in</button>';
                    else if (x.status === 'scheduled' && x.n > 1 && a.status === 'approved') act = '<button type="button" class="dk-btn" data-ssched="' + x.id + '">Change</button>';
                    else if (x.status !== 'completed' && x.n > 1) act = '<button type="button" class="dk-btn" data-ssched="' + x.id + '">' + (x.status === 'scheduled' ? 'Change' : 'Schedule') + '</button>';
                }
                return '<div class="dk-srow"><span class="dk-dot ' + sessDot(x.status) + '">' + x.n + '</span><span>Session ' + x.n + '<small>' + esc(label) + '</small></span>' + act + '</div>' +
                    (S.schedFor === x.id ? '<div class="dk-inline"><div class="dk-g2"><div><label class="dk-lbl" for="dkSd">Date &amp; time</label><input id="dkSd" type="datetime-local"></div>' +
                    (a.service.med ? '<div><label class="dk-lbl">Done by</label><div class="dk-hint">Doctor / Nurse</div></div></div>'
                        : '<div><label class="dk-lbl" for="dkSt">Therapist</label>' + therSelect(a.service.qual, x.therapist_id || slotOf(a, 1), 'id="dkSt"', false) + '</div></div>') +
                    '<div class="dk-bar"><button type="button" class="dk-btn primary" data-ssave="' + x.id + '">Save session</button><button type="button" class="dk-btn" data-sx="1">Cancel</button></div></div>' : '');
            }).join('') + '</div>' + (a.status === 'assigned' ? '<p class="dk-hint">Session 1 is checked in with the booking. Later sessions are scheduled, checked in and completed here.</p>' : '');
        }
        var mainHtml = '<div class="dk-svc"><div class="dk-svch"><span><b>' + esc(a.service.name) + '</b> <small>' + a.service.mins + ' min' + (isPkg(a) ? ' · ' + a.sessions.length + '-session package' : '') + '</small>' +
                (a.upgraded_from ? '<br><small class="dk-hint">Upgraded from ' + esc(a.upgraded_from) + '</small>' : '') + '</span><span class="dk-amt">' + peso(a.service.price) + '</span></div>' +
            (canAdd(a) && !isPkg(a) && !S.add ? '<button type="button" class="dk-btn" id="dkUpOpen" style="align-self:flex-start;">Upgrade service</button>' : '') +
            '<div class="dk-g2">' + slotsHtml + '<div><label class="dk-lbl" for="dkRoom">Room</label>' + (locked ? '<div class="dk-hint">' + esc(RES[a.resource_id] ? RES[a.resource_id].name : '—') + '</div>' : roomSelect(a)) + '</div></div>' +
            ('<div class="dk-g2"><div><label class="dk-lbl" for="dkSlip">' + (a.slip_aes ? 'Aesthetic slip #' : 'Slip #') + '</label>' + (locked ? '<div class="dk-hint">' + esc(a.slip || '—') + '</div>' : '<input id="dkSlip" type="text" maxlength="50" placeholder="' + (a.slip_aes ? 'Aesthetic slip number' : 'Service slip number') + '" value="' + esc(a.slip || '') + '">') + '</div>' +
             '<div><label class="dk-lbl">Slip type</label><span class="dk-slipk ' + (a.slip_aes ? 'aes' : 'spa') + '">' + (a.slip_aes ? 'Aesthetic slip' : 'Spa slip') + '</span></div></div>') +
            sessHtml + '</div>';

        // Add-ons (a multi-session add-on lists its sessions)
        var addHtml = a.addons.map(function (x, xi) {
            if (x.topup) return '<div class="dk-svc"><div class="dk-svch"><span><b>Upgrade difference</b> <small>' + (x.rows[0].paid ? 'paid' : 'to collect') + '</small></span><span class="dk-amt">' + peso(x.price) + '</span></div></div>';
            var th = x.rows[0].therapist_id;
            var rows = x.rows.length > 1 ? '<div class="dk-sess">' + x.rows.map(function (r) {
                return '<div class="dk-srow"><span class="dk-dot ' + (r.status === 'completed' ? 'done' : '') + '">' + r.n + '</span><span>Session ' + r.n + ' of ' + r.of + '<small>' + (r.status === 'completed' ? 'Done' : 'Not done yet') + '</small></span>' +
                    (!locked && r.status !== 'completed' && a.status === 'approved' ? '<button type="button" class="dk-btn ok" data-xdone="' + r.id + '">Mark done</button>' : '') + '</div>';
            }).join('') + '</div>' : '';
            var slipHtml = x.slip_aes ? ('<div class="dk-g2"><div><label class="dk-lbl" for="dkXSlip' + xi + '">Aesthetic slip #</label>' +
                (locked ? '<div class="dk-hint">' + esc(x.slip || '—') + '</div>' : '<input id="dkXSlip' + xi + '" type="text" maxlength="50" placeholder="Aesthetic slip number" value="' + esc(x.slip || '') + '" data-xslip="' + x.id + '">') + '</div>' +
                '<div><label class="dk-lbl">Slip type</label><span class="dk-slipk aes">Aesthetic slip</span></div></div>') : '';
            return '<div class="dk-svc"><div class="dk-svch"><span><b>' + esc(x.name) + '</b> <small>add-on' + (x.rows.length > 1 ? ' · ' + x.rows.length + ' sessions' : '') + (x.person ? ' · ' + esc(x.person) : '') + '</small></span><span class="dk-amt">' + peso(x.price) + '</span></div>' +
                (x.med ? '<div><label class="dk-lbl">Done by</label><div class="dk-hint">Doctor / Nurse</div></div>' : locked ? '<div class="dk-hint">' + esc(TH[th] ? TH[th].name : '—') + '</div>' : '<div><label class="dk-lbl">Therapist</label>' + therSelect(x.qual, th, 'data-xth="' + xi + '"', a.status === 'pending') + '</div>') +
                slipHtml +
                rows +
                (!locked && !x.rows.some(function (r) { return r.paid || r.status === 'completed'; }) ? '<button type="button" class="dk-btn danger" data-xrm="' + xi + '" style="align-self:flex-start;">Remove add-on</button>' : '') +
                '</div>';
        }).join('');

        // The guest's other services from the same booking (Book Now books one
        // appointment per service): listed here, opened on their own.
        var others = visitOf(a).filter(function (x) { return x.id !== a.id; });
        var sibHtml = others.length ? '<div class="dk-svc"><div class="dk-svch"><span><b>Also booked for ' + esc(a.name) + '</b></span></div><div class="dk-sess">' + others.map(function (x) {
            var st = x.status === 'completed' ? 'Done' : x.status === 'approved' ? 'In session' : (guestIn(a) ? 'Next' : 'Waiting its turn');
            var who = therNames(x) || (x.service.med ? 'Doctor / Nurse' : 'No therapist yet');
            return '<div class="dk-srow" data-sib="' + x.id + '" tabindex="0" style="cursor:pointer;"><span class="dk-dot' + (x.status === 'completed' ? ' done' : x.status === 'approved' ? ' in' : '') + '">' + (x.status === 'completed' ? '✓' : x.status === 'approved' ? '●' : '') + '</span>' +
                '<span>' + esc(x.service.name) + ' · ' + x.service.mins + 'm — ' + peso(x.service.price) + '<small>' + tm(x.when) + ' · ' + esc(who) + (x.resource_id && RES[x.resource_id] ? ' · ' + esc(RES[x.resource_id].name) : '') + ' · ' + st + '</small></span>' +
                '<span class="dk-hint">' + (x.bill.paid && x.bill.due <= 0 ? 'Paid' : 'Open') + ' ›</span></div>';
        }).join('') + '</div></div>' : '';

        // Bill
        var owe = sibsOwe(a);
        var addonBillLines = a.addons.map(function (x) {
            if (x.price <= 0.005) return '';
            var label = x.topup ? 'Upgrade difference' : (esc(x.name) + (x.person ? ' · ' + esc(x.person) : ''));
            var paidRows = x.rows.filter(function (r) { return r.paid; }).length;
            var tag = paidRows === x.rows.length ? ' · paid' : paidRows === 0 ? ' · to pay' : ' · partly paid';
            return '<div><span>' + label + tag + '</span><span>' + peso(x.price) + '</span></div>';
        }).join('');
        var billHtml = '<div class="dk-bill"><div><span>' + (Math.abs(b.order_total - a.service.price) > 0.01 ? 'Order total' : esc(a.service.name)) + '</span><span>' + peso(b.order_total) + '</span></div>' +
            addonBillLines +
            (b.discount ? '<div><span>Discount (' + esc(b.discount_type === 'voucher' && b.voucher_label ? 'voucher: ' + b.voucher_label : b.discount_type) + ')</span><span>−' + peso(b.discount) + '</span></div>' : '') +
            (b.advance ? '<div><span>Down payment' + (b.advance_method ? ' (' + esc(b.advance_method) + ')' : '') + '</span><span>−' + peso(b.advance) + '</span></div>' : '') +
            (b.paid ? '<div><span>Paid' + (b.method ? ' · ' + esc(b.method) : '') + '</span><span>' + peso(b.paid_amount) + '</span></div>' : '') +
            owe.map(function (x) { return '<div><span>' + esc(x.service.name) + (x.bill.paid ? ' · added services' : (x.bill.advance > 0 ? ' (less down payment)' : '')) + '</span><span>' + peso(x.bill.due) + '</span></div>'; }).join('') +
            '<div class="big"><span>To collect' + (owe.length ? ' · all services' : '') + '</span><span>' + peso(b.due + owe.reduce(function (t, x) { return t + x.bill.due; }, 0)) + '</span></div></div>';
        // GCash QR payment the customer sent with an online booking.
        var g = b.gcash, gcHtml = '';
        if (g) {
            var gk = g.kind === 'full' ? 'paid in full' : 'down payment';
            gcHtml = '<div class="dk-inline"><div class="dk-svch"><span><b>GCash ' + gk + '</b> <small>ref ' + esc(g.ref) + ' · sent ' + dayLabel(g.sent) + ', ' + tm(g.sent) + '</small></span><span class="dk-amt">' + peso(g.amount) + '</span></div>' +
                '<a class="dk-hint" href="payment_file.php?id=' + g.id + '" target="_blank" rel="noopener">View receipt screenshot ›</a>';
            if (g.status === 'pending' && !locked) {
                gcHtml += '<p class="dk-hint">Open the spa GCash and find this reference number. Check the amount, then verify.</p>' +
                    '<div class="dk-bar"><input type="number" id="dkGcAmt" min="0" step="0.01" value="' + g.amount + '" style="max-width:150px;" aria-label="Amount received"><span class="dk-hint">amount received</span></div>' +
                    (S.gcRej ? '<div><label class="dk-lbl" for="dkGcWhy">Why reject?</label><input id="dkGcWhy" type="text" placeholder="e.g. reference number not found"></div>' +
                        '<div class="dk-bar"><button type="button" class="dk-btn danger" id="dkGcRejSave">Reject payment</button><button type="button" class="dk-btn" id="dkGcRejX">Back</button></div>'
                      : '<div class="dk-bar"><button type="button" class="dk-btn ok" id="dkGcOk">Verify payment</button><button type="button" class="dk-btn danger" id="dkGcRej">Reject</button></div>');
            } else if (g.status === 'verified') {
                gcHtml += '<p class="dk-hint">Verified' + (g.by ? ' by ' + esc(g.by) : '') + ' · ' + peso(g.received) + ' received.</p>';
            } else if (g.status === 'rejected') {
                gcHtml += '<p class="dk-hint">Rejected' + (g.by ? ' by ' + esc(g.by) : '') + (g.reason ? ': ' + esc(g.reason) : '') + '. Collect payment at the spa.</p>';
            }
            gcHtml += '</div>';
        }
        var payForm = '';
        if (S.panel === 'checkin' && (!b.paid || b.due > 0)) {
            // Already paid but services were added since: only those are due now.
            payForm = '<div class="dk-inline">' + (b.paid ? '<p class="dk-hint">Already paid; the services added since come to ' + peso(b.due) + '.</p>' : '') +
                '<div class="dk-bar"><button type="button" class="dk-chip' + (S.payNow ? ' sel' : '') + '" data-pn="1">Pay now</button><button type="button" class="dk-chip' + (!S.payNow ? ' sel' : '') + '" data-pn="0">Pay later</button></div>' +
                (S.payNow ? (b.paid ? '' : discChips()) + methodChips(false) : '<p class="dk-hint">Payment will be collected when the session is done.</p>') + '</div>';
        } else if (S.panel === 'complete' && !isLastOpen(a)) {
            var nx = nextOf(a);
            payForm = '<div class="dk-inline"><p class="dk-hint">' + esc(a.service.name) + ' is done.' + (nx ? ' <b>' + esc(nx.service.name) + '</b> starts next (booked ' + tm(nx.when) + ').' : '') +
                (owe.length || b.due > 0 ? ' Payment for all services is taken when the last service is completed.' : '') + '</p></div>';
        } else if (S.panel === 'complete' && (b.due > 0 || owe.length)) {
            payForm = '<div class="dk-inline">' + (b.paid ? '' : discChips()) + methodChips(true) +
                (S.method === 'unpaid' ? '<div><label class="dk-lbl" for="dkBill">Charge to (company / hotel)</label><input id="dkBill" type="text" placeholder="' + esc(a.name) + '"></div>' : '') + '</div>';
        }

        var groupHtml = '';
        if (a.group && a.group_size > 1 && S.grp) {
            groupHtml = '<div class="dk-inline" id="dkGrp"><p class="dk-hint">Loading the group…</p></div>';
        }

        var extra = '';
        if (S.resched) extra += '<div class="dk-inline"><div class="dk-g2"><div><label class="dk-lbl" for="dkRd">New date</label><input id="dkRd" type="date" value="' + a.date + '"></div><div><label class="dk-lbl" for="dkRt">New time</label><input id="dkRt" type="time" value="' + a.start.slice(11, 16) + '"></div></div>' +
            '<div class="dk-bar"><button type="button" class="dk-btn primary" id="dkRsSave">Save new time</button><button type="button" class="dk-btn" id="dkRsX">Cancel</button></div></div>';
        if (S.cancelling) extra += '<div class="dk-inline">' + (a.status === 'pending' ? '<p class="dk-hint">Decline this online booking? The customer is notified.</p>'
            : '<div><label class="dk-lbl" for="dkCr">Why cancel?</label><input id="dkCr" type="text" placeholder="e.g. customer called to cancel"></div>') +
            '<div class="dk-bar"><button type="button" class="dk-btn danger" id="dkCxSave">' + (a.status === 'pending' ? 'Decline booking' : 'Cancel booking') + '</button><button type="button" class="dk-btn" id="dkCxX">Keep it</button></div></div>';

        var primary = na ? '<button type="button" class="dk-btn ' + na.cls + '" id="dkGo" style="flex:1;padding:0.7rem;">' + goLabel(a) + '</button>' : '';

        $('dkDrawer').innerHTML =
            '<div class="dk-dh"><div class="dk-drow"><h3>' + esc(a.name) + '</h3><button type="button" class="dk-x" id="dkX" aria-label="Close">✕</button></div>' +
            '<div class="dk-drow"><span class="dk-hint">' + dayLabel(a.start) + ', ' + tm(a.start) + ' · ' + esc(a.source) + (a.phone ? ' · ' + esc(a.phone) : '') + (a.note ? ' · “' + esc(a.note) + '”' : '') + '</span>' + (a.group && a.group_size > 1 ? '<span class="dk-pill p-src">Group of ' + a.group_size + '</span>' : '') + '</div>' +
            '<div class="dk-steps">' + steps + '</div></div>' +
            '<div class="dk-db">' +
            (a.legacy_two_session ? '<p class="dk-hint">This is an old 2-session package booking. Manage it in the <a href="appointments.php?view=classic">classic view</a>.</p>' : '') +
            '<div class="dk-sec"><h4>Services</h4>' + mainHtml + addHtml + sibHtml +
                addServiceHtml(a) +
                (!locked ? '<a class="dk-hint" href="appointments.php?view=classic&appt_date=' + a.date + '">Edit other details in the classic view</a>' : '') + '</div>' +
            '<div class="dk-sec"><h4>Products</h4>' + productsHtml(a) + '</div>' +
            '<div class="dk-sec"><h4><span>Payment</span>' + (a.group && a.group_size > 1 && groupOwes(a) && !locked ? '<button type="button" class="dk-btn" id="dkGrpOpen">' + (S.grp ? 'Back to this guest' : 'Pay for group') + '</button>' : '') + '</h4>' + gcHtml + billHtml + inflHtml(a) + payForm + groupHtml + '</div>' +
            (extra ? '<div class="dk-sec">' + extra + '</div>' : '') +
            '<p class="dk-err" id="dkErr"></p></div>' +
            '<div class="dk-df">' +
            (D.cashier ? '<input type="password" id="dkPin" maxlength="4" inputmode="numeric" placeholder="PIN" value="' + esc(S.pin || '') + '" style="width:80px;text-align:center;letter-spacing:0.2em;">' : '') +
            primary +
            (locked ? (D.full_access ? '<button type="button" class="dk-btn" id="dkUndo">Undo complete</button>' : '<span class="dk-hint">Only the owner or IT can undo a completed booking.</span>')
                : '<button type="button" class="dk-btn" id="dkRsOpen">Reschedule</button><button type="button" class="dk-btn danger" id="dkCxOpen">' + (a.status === 'pending' ? 'Decline' : 'Cancel') + '</button>') +
            '</div>';
        wire(a);
        if (a.group && a.group_size > 1 && S.grp) loadGroup(a);
    }
    // Influencer / PR: a free booking (₱0). Saved through set_rate_type so the
    // Daily Report counts it under Marketing Expense and the therapist gets the
    // influencer flat rate. Only before payment, without a down payment, and
    // not for multi-session packages.
    function inflHtml(a) {
        var b = a.bill;
        if (a.status === 'completed' || a.legacy_two_session) return '';
        if (a.rate_type === 'influencer') {
            return '<div class="dk-bar"><span class="dk-pill p-pkg">Influencer / PR · free</span><button type="button" class="dk-btn" id="dkInflOff">Back to regular price</button></div>';
        }
        if (b.paid || b.advance > 0 || isPkg(a) || (b.gcash && b.gcash.status !== 'rejected')) return '';
        if (!S.infl) return '<button type="button" class="dk-btn" id="dkInflOpen" style="align-self:flex-start;">Influencer / PR (free)</button>';
        return '<div class="dk-inline"><p class="dk-hint"><b>Make this a free Influencer / PR booking?</b> The price becomes ₱0, it is counted as a Marketing Expense in the Daily Report, and the therapist gets the influencer rate when it is completed. Add-ons are still charged.</p>' +
            '<div class="dk-bar"><button type="button" class="dk-btn primary" id="dkInflSave">Yes, make it free</button><button type="button" class="dk-btn" id="dkInflX">Keep the price</button></div></div>';
    }
    // Anyone in this booking's group still owing (a paid guest's later add-ons count).
    function groupOwes(a) {
        return D.appointments.some(function (x) { return x.group === a.group && x.status !== 'completed' && x.rate_type !== 'influencer' && x.bill.due > 0; });
    }
    function goLabel(a) {
        var na = nextAction(a), b = a.bill; if (!na) return '';
        if (na.k === 'checkin' && S.panel === 'checkin') return b.due <= 0 && !sibsOwe(a).length ? 'Check in' : (S.payNow ? 'Check in & take ' + peso(dueWithDisc(a)) : 'Check in · pay later');
        if (na.k === 'complete' && !isLastOpen(a)) return na.l;
        if (na.k === 'complete' && dueWithDisc(a) <= 0) return 'Complete';
        if (na.k === 'complete' && S.panel === 'complete') return S.method === 'unpaid' ? 'Complete · charge to account' : 'Complete & take ' + peso(dueWithDisc(a));
        return na.l;
    }
    function discChips() {
        var extra = '';
        if (S.disc === 'voucher') extra = '<div class="dk-bar" style="margin-top:0.35rem;">' + Object.keys(VSCOPES).map(function (k) { return '<button type="button" class="dk-chip sm' + ((S.vs || 'all') === k ? ' sel' : '') + '" data-vs="' + k + '">' + esc(VSCOPES[k]) + '</button>'; }).join('') + '</div>' +
            '<div class="dk-bar" style="margin-top:0.35rem;"><input type="text" id="dkVl" maxlength="60" placeholder="Voucher no. (optional)" value="' + esc(S.vl || '') + '" style="flex:1 1 200px;min-width:0;" aria-label="Voucher number">' + '</div><div class="dk-bar" style="margin-top:0.35rem;"><input type="number" id="dkDv" min="0" step="1" placeholder="' + (S.vt === 'percent' ? 'Voucher %' : 'Voucher amount ₱') + '" value="' + esc(S.dv) + '" style="max-width:150px;">' +
            '<button type="button" class="dk-chip sm' + (S.vt !== 'percent' ? ' sel' : '') + '" data-vt="fixed">₱ off</button><button type="button" class="dk-chip sm' + (S.vt === 'percent' ? ' sel' : '') + '" data-vt="percent">% off</button></div>';
        if (S.disc === 'celebration') extra = '<div class="dk-bar" style="margin-top:0.35rem;"><input type="number" id="dkDv" min="0" max="100" step="1" placeholder="Celebration %" value="' + esc(S.dv) + '" style="max-width:150px;"><span class="dk-hint">% off</span></div>';
        var a = byId(S.open), info = '';
        if (a && isPersonDisc(S.disc)) {
            var visit = [a].concat(sibsOwe(a)).filter(function (x) { return !x.bill.paid; });
            var off = [];
            visit.forEach(function (x) { if (x.service.nodisc) off.push(x.service.name); x.addons.forEach(function (y) { if (y.nodisc) off.push(y.name); }); });
            if (a.people > 1) info += '<div class="dk-bar" style="margin-top:0.35rem;align-items:center;"><span class="dk-hint">How many of the ' + a.people + ' people?</span>' +
                '<button type="button" class="dk-chip sm" data-dp="-1" aria-label="Fewer">−</button><b>' + Math.min(S.dp || 1, a.people) + '</b><button type="button" class="dk-chip sm" data-dp="1" aria-label="More">+</button></div>';
            if (S.disc === 'voucher') {
                var ve = visit.reduce(function (t, x) { return t + eligOf(x, x === a); }, 0);
                info += '<p class="dk-hint" style="margin-top:0.35rem;">' + esc(VSCOPES[S.vs || 'all']) + ': for ' + peso(ve) + ' of this bill' + (S.vs && S.vs !== 'all' ? ' (only its own services)' : ' (all services)') + '.</p>';
            } else if (off.length) info += '<p class="dk-hint" style="margin-top:0.35rem;">No ' + (DISC.filter(function (d) { return d[0] === S.disc; })[0] || ['', 'this discount'])[1].replace(/ \d+%$/, '') + ' discount on: ' + off.map(esc).join(', ') + ' (Daytime promos, nails and lash).</p>';
        }
        return '<div><label class="dk-lbl">Discount</label><div class="dk-bar">' + DISC.map(function (d) { return '<button type="button" class="dk-chip sm' + (S.disc === d[0] ? ' sel' : '') + '" data-disc="' + d[0] + '">' + d[1] + '</button>'; }).join('') + '</div>' + extra + info + '</div>';
    }
    // What will actually be collected with the discount chosen in the panel,
    // for this service and the guest's other unpaid services (paid together
    // through pay_group: discount on the full price, then the advances off).
    function dueWithDisc(a) {
        var list = [a].concat(sibsOwe(a)), base = 0, elig = 0, adv = 0, paidDue = 0;
        list.forEach(function (x) {
            var b = x.bill;
            if (b.paid) paidDue += b.due;
            else { base += b.order_total + b.addons - b.discount; elig += eligOf(x, x === a); adv += b.advance; }
        });
        return paidDue + Math.max(0, base - discAmt(base, S, elig) - adv);
    }
    // Pay this guest's services in one go (book_now_pay_group), then run the
    // step (check in / complete) on this service, which is then already paid.
    function payGuestThen(a, step) {
        var map = {}; map[a.name] = { type: S.disc, value: parseFloat(S.dv) || 0, vtype: S.vt === 'percent' ? 'percent' : 'fixed', label: S.disc === 'voucher' ? voucherLabel(S.vs, S.vl) : '', scope: S.vs || 'all' };
        return post('pay_group', a.id, { booking_group: a.group, guest_discounts: JSON.stringify(map), pay_method: S.method }).then(step);
    }
    function methodChips(allowAccount) {
        var list = PAYS.concat(allowAccount ? [['unpaid', 'Charge to account']] : []);
        return '<div><label class="dk-lbl">Payment method</label><div class="dk-bar">' + list.map(function (p) { return '<button type="button" class="dk-chip sm' + (S.method === p[0] ? ' sel' : '') + '" data-pm="' + p[0] + '">' + p[1] + '</button>'; }).join('') + '</div></div>';
    }

    function wire(a) {
        var W = $('dkDrawer'), err = function (m) { $('dkErr').textContent = m; };
        $('dkX').onclick = closeDrawer;
        var pinEl = $('dkPin'); if (pinEl) pinEl.oninput = function () { S.pin = pinEl.value; }; // kept across redraws
        wireAdd(a);
        wireProducts(a);
        W.querySelectorAll('[data-slot]').forEach(function (s) { s.onchange = function () { if (s.value) post('assign_person_slot', a.id, { person_slot: s.dataset.slot, therapist_id: s.value, override: isOvr(s) }, true); }; });
        W.querySelectorAll('[data-xth]').forEach(function (s) { s.onchange = function () {
            if (!s.value) return;
            var rows = a.addons[+s.dataset.xth].rows, chain = Promise.resolve();
            rows.forEach(function (r) { chain = chain.then(function () { return post('assign_extra_therapist', a.id, { aes_id: r.id, therapist_id: s.value }, true); }); });
        }; });
        var room = $('dkRoom'); if (room) room.onchange = function () { if (room.value) post('set_resource', a.id, { resource_id: room.value }); };
        var slip = $('dkSlip'); if (slip) slip.onchange = function () { post('set_slip', a.id, { slip_number: slip.value.trim() }); };
        W.querySelectorAll('[data-xslip]').forEach(function (inp) { inp.onchange = function () { post('set_extra_slip', a.id, { extra_id: inp.dataset.xslip, slip_number: inp.value.trim() }); }; });
        W.querySelectorAll('[data-xrm]').forEach(function (bt) { bt.onclick = function () {
            var rows = a.addons[+bt.dataset.xrm].rows, chain = Promise.resolve();
            rows.forEach(function (r) { chain = chain.then(function () { return post('remove_extra_service', a.id, { extra_id: r.id }, true); }); });
        }; });
        W.querySelectorAll('[data-xdone]').forEach(function (bt) { bt.onclick = function () { post('complete_extra_session', a.id, { extra_id: bt.dataset.xdone }, true); }; });
        W.querySelectorAll('[data-ssched]').forEach(function (bt) { bt.onclick = function () { S.schedFor = +bt.dataset.ssched; renderDrawer(); }; });
        W.querySelectorAll('[data-sx]').forEach(function (bt) { bt.onclick = function () { S.schedFor = null; renderDrawer(); }; });
        W.querySelectorAll('[data-ssave]').forEach(function (bt) { bt.onclick = function () {
            if (!$('dkSd').value) return err('Pick a date and time for this session.');
            S.schedFor = null;
            post('assign_session', a.id, { session_id: bt.dataset.ssave, session_date: $('dkSd').value, session_therapist_id: ($('dkSt') || {}).value || '' });
        }; });
        W.querySelectorAll('[data-sin]').forEach(function (bt) { bt.onclick = function () { post('checkin_session', a.id, { session_id: bt.dataset.sin }); }; });
        W.querySelectorAll('[data-sdone]').forEach(function (bt) { bt.onclick = function () { post('complete_session', a.id, { session_id: bt.dataset.sdone }); }; });
        W.querySelectorAll('[data-pn]').forEach(function (bt) { bt.onclick = function () { S.payNow = bt.dataset.pn === '1'; renderDrawer(); }; });
        W.querySelectorAll('[data-disc]').forEach(function (bt) { bt.onclick = function () { S.disc = bt.dataset.disc; S.dv = ''; renderDrawer(); }; });
        W.querySelectorAll('[data-dp]').forEach(function (bt) { bt.onclick = function () { S.dp = Math.max(1, Math.min(a.people, (S.dp || 1) + +bt.dataset.dp)); renderDrawer(); }; });
        W.querySelectorAll('[data-vt]').forEach(function (bt) { bt.onclick = function () { S.vt = bt.dataset.vt; renderDrawer(); }; });
        var vlEl = $('dkVl'); if (vlEl) vlEl.oninput = function () { S.vl = vlEl.value; };
        W.querySelectorAll('[data-vs]').forEach(function (bt) { bt.onclick = function () { S.vs = bt.dataset.vs; renderDrawer(); }; });
        var dvEl = $('dkDv'); if (dvEl) dvEl.oninput = function () { S.dv = dvEl.value; var g = $('dkGo'), a2 = byId(S.open); if (g && a2) g.textContent = goLabel(a2); };
        W.querySelectorAll('[data-pm]').forEach(function (bt) { bt.onclick = function () { S.method = bt.dataset.pm; renderDrawer(); }; });

        var go = $('dkGo');
        if (go) go.onclick = function () {
            var na = nextAction(a), b = a.bill;
            if (D.cashier && !/^\d{4}$/.test(($('dkPin') || {}).value || '') && na.k !== 'approve') return err('Enter your 4-digit PIN.');
            if (na.k === 'approve') {
                var m = missing(a, false); if (m.length) return err('Choose a ' + m.join(', ') + ' first.');
                return post('approve_pending', a.id, { resource_id: a.resource_id || '' }, true, true);
            }
            if (na.k === 'checkin') {
                var m2 = missing(a, true); if (m2.length) return err('Choose a ' + m2.join(', ') + ' first.');
                // Nothing to collect (paid, or covered by the down payment): just check in.
                if (b.due <= 0 && !sibsOwe(a).length) return post('checkin_appointment', a.id, { pay_choice: 'later' }, false, true);
                if (S.panel !== 'checkin') { S.panel = 'checkin'; S.grp = false; return renderDrawer(); }
                if (S.payNow && !S.method) return err('Choose a payment method, or pick Pay later.');
                if (S.payNow && discNeedsValue(S)) return err(S.disc === 'voucher' ? 'Type the voucher amount.' : 'Type the celebration %.');
                if (S.payNow && sibsOwe(a).length) return payGuestThen(a, function () { return post('checkin_appointment', a.id, { pay_choice: 'later' }, false, true); });
                if (!S.payNow && sibs(a).length) return post('checkin_appointment', a.id, { pay_choice: 'later' }, false, true);
                return post('checkin_appointment', a.id, { pay_choice: S.payNow ? 'now' : 'later', pay_method: S.method || 'cash', discount_type: S.payNow && !b.paid ? S.disc : 'none', voucher_type: S.vt === 'percent' ? 'percent' : 'fixed', voucher_value: S.payNow ? (parseFloat(S.dv) || 0) : 0, voucher_label: S.disc === 'voucher' ? voucherLabel(S.vs, S.vl) : '', voucher_scope: S.vs || 'all', discount_people: a.people > 1 ? Math.min(S.dp || 1, a.people) : 0 }, false, true);
            }
            if (na.k === 'start') {
                var m3 = missing(a, true); if (m3.length) return err('Choose a ' + m3.join(', ') + ' first.');
                return post('checkin_appointment', a.id, { pay_choice: 'later' }, false, true);
            }
            if (na.k === 'complete' && !isLastOpen(a)) {
                // Not the guest's last service: complete it without payment
                // (unless already paid) and start the next one at its time.
                if (S.panel !== 'complete') { S.panel = 'complete'; S.grp = false; return renderDrawer(); }
                var nx2 = nextOf(a);
                // Back to the board after; the next service shows In session on the card.
                return post('complete', a.id, { complete_pay_method: b.due > 0 ? 'later' : 'cash', complete_unpaid_billto: '', complete_disc_type: 'none', complete_voucher_type: 'cash', complete_voucher_value: 0, voucher_label: '', celebration_discount: 0, advance_payment: 0 }, false, true)
                    .then(function () { if (nx2 && !missing(nx2, true).length) return post('checkin_appointment', nx2.id, { pay_choice: 'later' }, false, true); });
            }
            if (na.k === 'complete') {
                var oweAll = b.due + sibsOwe(a).reduce(function (t, x) { return t + x.bill.due; }, 0);
                if (oweAll > 0 && S.panel !== 'complete') { S.panel = 'complete'; S.grp = false; return renderDrawer(); }
                if (oweAll > 0 && !S.method) return err('Choose how the customer paid.');
                if (b.due > 0 && !b.paid && discNeedsValue(S)) return err(S.disc === 'voucher' ? 'Type the voucher amount.' : 'Type the celebration %.');
                if (S.method !== 'unpaid' && sibsOwe(a).length) return payGuestThen(a, function () {
                    return post('complete', a.id, { complete_pay_method: S.method || 'cash', complete_unpaid_billto: '', complete_disc_type: 'none', complete_voucher_type: 'cash', complete_voucher_value: 0, voucher_label: '', celebration_discount: 0, advance_payment: 0 }, false, true);
                });
                return post('complete', a.id, {
                    // Nothing left to collect: recorded under the down payment's method.
                    complete_pay_method: S.method || (b.advance > 0 && b.advance_method ? b.advance_method : 'cash'), complete_unpaid_billto: ($('dkBill') || {}).value || '',
                    complete_disc_type: b.paid ? 'none' : S.disc, discount_people: a.people > 1 ? Math.min(S.dp || 1, a.people) : 0, complete_voucher_type: S.vt === 'percent' ? 'percent' : 'cash', complete_voucher_value: b.paid ? 0 : (parseFloat(S.dv) || 0), voucher_label: !b.paid && S.disc === 'voucher' ? voucherLabel(S.vs, S.vl) : '', voucher_scope: S.vs || 'all',
                    celebration_discount: 0, advance_payment: 0
                }, false, true);
            }
        };
        var rso = $('dkRsOpen'); if (rso) rso.onclick = function () { S.resched = !S.resched; S.cancelling = false; renderDrawer(); };
        var rsx = $('dkRsX'); if (rsx) rsx.onclick = function () { S.resched = false; renderDrawer(); };
        var rss = $('dkRsSave'); if (rss) rss.onclick = function () {
            if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return err('Enter your 4-digit PIN.');
            S.resched = false; post('reschedule', a.id, { new_date: $('dkRd').value, new_time: $('dkRt').value });
        };
        var cxo = $('dkCxOpen'); if (cxo) cxo.onclick = function () { S.cancelling = true; S.resched = false; renderDrawer(); };
        var cxx = $('dkCxX'); if (cxx) cxx.onclick = function () { S.cancelling = false; renderDrawer(); };
        var cxs = $('dkCxSave'); if (cxs) cxs.onclick = function () {
            if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return err('Enter your 4-digit PIN.');
            S.cancelling = false;
            if (a.status === 'pending') post('decline', a.id, {});
            else post('cancel', a.id, { cancel_reason: ($('dkCr') || {}).value || '' });
        };
        var undo = $('dkUndo'); if (undo) undo.onclick = function () { post('revert_complete', a.id, {}); };
        var io = $('dkInflOpen'); if (io) io.onclick = function () { S.infl = true; S.panel = null; S.grp = false; renderDrawer(); };
        var ix = $('dkInflX'); if (ix) ix.onclick = function () { S.infl = false; renderDrawer(); };
        var isv = $('dkInflSave'); if (isv) isv.onclick = function () {
            if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return err('Enter your 4-digit PIN.');
            S.infl = false; post('set_rate_type', a.id, { rate_type: 'influencer' });
        };
        var ioff = $('dkInflOff'); if (ioff) ioff.onclick = function () {
            if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return err('Enter your 4-digit PIN.');
            post('set_rate_type', a.id, { rate_type: 'regular' });
        };
        $('dkDrawer').querySelectorAll('[data-sib]').forEach(function (r) {
            r.onclick = function () { openDrawer(+r.dataset.sib); };
            r.onkeydown = function (e) { if (e.key === 'Enter') openDrawer(+r.dataset.sib); };
        });
        var gok = $('dkGcOk'); if (gok) gok.onclick = function () {
            if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return err('Enter your 4-digit PIN.');
            var amt = parseFloat($('dkGcAmt').value) || 0;
            if (amt <= 0) return err('Enter the amount received.');
            post('verify_manual_payment', a.id, { mp_id: a.bill.gcash.id, amount_received: amt });
        };
        var grj = $('dkGcRej'); if (grj) grj.onclick = function () { S.gcRej = true; renderDrawer(); };
        var grx = $('dkGcRejX'); if (grx) grx.onclick = function () { S.gcRej = false; renderDrawer(); };
        var grs = $('dkGcRejSave'); if (grs) grs.onclick = function () {
            if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return err('Enter your 4-digit PIN.');
            var why = ($('dkGcWhy').value || '').trim();
            if (!why) return err('Say why the payment is rejected.');
            S.gcRej = false; post('reject_manual_payment', a.id, { mp_id: a.bill.gcash.id, reject_reason: why });
        };
        var gpo = $('dkGrpOpen'); if (gpo) gpo.onclick = function () { S.grp = !S.grp; if (S.grp) S.panel = null; renderDrawer(); };
    }

    // ── Pay for group (same action as the classic view) ───────────────────
    function loadGroup(a) {
        fetch('appointments.php?ajax=group_bill&group=' + encodeURIComponent(a.group), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var box = $('dkGrp'); if (!box) return;
                var G = (data.guests || []).map(function (g) { g.on = true; g.disc = 'none'; g.dv = ''; g.vt = 'fixed'; g.vl = ''; return g; });
                var method = null;
                function gElig(g) { return g.disc === 'voucher' ? (g.elig || {})[g.vs || 'all'] : g.eligible; }
                function total() { return G.reduce(function (t, g) { return g.on ? t + Math.max(0, g.subtotal - discAmt(g.subtotal, g, gElig(g)) - (g.advance || 0)) : t; }, 0); }
                function draw() {
                    if (!G.length) { box.innerHTML = '<p class="dk-hint">Everyone in this group has already paid.</p>'; return; }
                    box.innerHTML = G.map(function (g, i) {
                        return '<div><label class="dk-lbl"><input type="checkbox" data-gi="' + i + '"' + (g.on ? ' checked' : '') + '> ' + esc(g.name) + ' · ' + peso(g.subtotal) + (g.advance > 0 ? ' · advance −' + peso(g.advance) : '') + (g.disc !== 'none' && g.disc !== 'celebration' && gElig(g) < g.subtotal - 0.005 ? ' · discount on ' + peso(gElig(g)) + (g.disc === 'voucher' ? ' (' + esc(VSCOPES[g.vs || 'all']) + ')' : ' (no Daytime, nails, lash)') : '') + '</label><div class="dk-bar">' +
                            DISC.map(function (d) { return '<button type="button" class="dk-chip sm' + (g.disc === d[0] ? ' sel' : '') + '" data-gd="' + i + ',' + d[0] + '">' + d[1] + '</button>'; }).join('') + '</div>' +
                            (g.disc === 'voucher' || g.disc === 'celebration' ? '<div class="dk-bar" style="margin-top:0.3rem;"><input type="number" min="0" data-gv="' + i + '" value="' + esc(g.dv) + '" placeholder="' + (g.disc === 'celebration' ? 'Celebration %' : (g.vt === 'percent' ? 'Voucher %' : 'Voucher ₱')) + '" style="max-width:130px;">' +
                                (g.disc === 'voucher' ? '<button type="button" class="dk-chip sm' + (g.vt !== 'percent' ? ' sel' : '') + '" data-gvt="' + i + ',fixed">₱ off</button><button type="button" class="dk-chip sm' + (g.vt === 'percent' ? ' sel' : '') + '" data-gvt="' + i + ',percent">% off</button>' +
                                    '<input type="text" maxlength="60" data-gvl="' + i + '" value="' + esc(g.vl) + '" placeholder="Voucher no. (optional)" style="flex:1 1 160px;min-width:0;" aria-label="Voucher number">' : '') + '</div>' +
                                (g.disc === 'voucher' ? '<div class="dk-bar" style="margin-top:0.3rem;">' + Object.keys(VSCOPES).map(function (k) { return '<button type="button" class="dk-chip sm' + ((g.vs || 'all') === k ? ' sel' : '') + '" data-gvs="' + i + ',' + k + '">' + esc(VSCOPES[k]) + '</button>'; }).join('') + '</div>' : '') : '') +
                            '</div>';
                    }).join('') +
                    '<div class="dk-bar">' + PAYS.map(function (p) { return '<button type="button" class="dk-chip sm' + (method === p[0] ? ' sel' : '') + '" data-gm="' + p[0] + '">' + p[1] + '</button>'; }).join('') + '</div>' +
                    '<button type="button" class="dk-btn ok" id="dkGrpPay">Record ' + peso(total()) + ' for the group</button>';
                    box.querySelectorAll('[data-gi]').forEach(function (c) { c.onchange = function () { G[+c.dataset.gi].on = c.checked; draw(); }; });
                    box.querySelectorAll('[data-gd]').forEach(function (c) { c.onclick = function () { var p = c.dataset.gd.split(','); G[+p[0]].disc = p[1]; G[+p[0]].dv = ''; draw(); }; });
                    box.querySelectorAll('[data-gvt]').forEach(function (c) { c.onclick = function () { var p = c.dataset.gvt.split(','); G[+p[0]].vt = p[1]; draw(); }; });
                    box.querySelectorAll('[data-gv]').forEach(function (c) { c.oninput = function () { G[+c.dataset.gv].dv = c.value; $('dkGrpPay').textContent = 'Record ' + peso(total()) + ' for the group'; }; });
                    box.querySelectorAll('[data-gm]').forEach(function (c) { c.onclick = function () { method = c.dataset.gm; draw(); }; });
                    box.querySelectorAll('[data-gvl]').forEach(function (c) { c.oninput = function () { G[+c.dataset.gvl].vl = c.value; }; });
                    box.querySelectorAll('[data-gvs]').forEach(function (c) { c.onclick = function () { var p = c.dataset.gvs.split(','); G[+p[0]].vs = p[1]; draw(); }; });
                    $('dkGrpPay').onclick = function () {
                        var map = {}, need = null;
                        G.forEach(function (g) { if (!g.on) return; if (discNeedsValue(g)) need = g.name; map[g.name] = { type: g.disc, value: parseFloat(g.dv) || 0, vtype: g.vt, label: g.disc === 'voucher' ? voucherLabel(g.vs, g.vl) : '', scope: g.vs || 'all' }; });
                        if (need) return ($('dkErr').textContent = 'Type the discount amount for ' + need + '.');
                        if (!Object.keys(map).length) return ($('dkErr').textContent = 'Tick at least one guest.');
                        if (!method) return ($('dkErr').textContent = 'Choose a payment method.');
                        if (D.cashier && !/^\d{4}$/.test($('dkPin').value)) return ($('dkErr').textContent = 'Enter your 4-digit PIN.');
                        S.grp = false;
                        post('pay_group', a.id, { booking_group: a.group, guest_discounts: JSON.stringify(map), pay_method: method });
                    };
                }
                draw();
            });
    }

    // Dashboard: open a booking from the Live Resource Grid. A customer with
    // several services from one booking opens on the service that's on now
    // (or next), with the others listed in the same panel.
    window.openApptPanel = function (id) {
        function go() {
            var a = byId(id); if (!a) return toast('That booking is no longer open. Refresh the page.', true);
            var v = visitOf(a).filter(function (x) { return x.status !== 'completed'; });
            var cur = v.filter(function (x) { return x.status === 'approved'; })[0] || v[0] || a;
            openDrawer(cur.id);
        }
        return load().then(go);
    };
    <?php if (!$DESK_MODAL && !empty($message)): ?>toast(<?php echo json_encode($message); ?>, <?php echo json_encode(($message_type ?? '') === 'danger'); ?>);<?php endif; ?>
    // Loaded on the Dashboard too (not just the standalone board) so
    // #dkPendingLane has something to show without waiting for a click.
    load();
    setInterval(function () { if (!S.open && !document.hidden) load(); }, 60000);
})();
</script>
