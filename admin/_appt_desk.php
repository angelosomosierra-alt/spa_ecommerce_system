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
 * ─────────────────────────────────────────────────────────────────────────────
 */
?>
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
        <div class="dk-bar">
            <button type="button" class="dk-chip sm" id="dkDonePrev" aria-label="Previous day">‹</button>
            <input type="date" id="dkDoneDate" aria-label="Completed on">
            <button type="button" class="dk-chip sm" id="dkDoneNext" aria-label="Next day">›</button>
        </div>
    </div>
    <div id="dkDoneList"></div>
</section>

<div class="dk-scrim" id="dkScrim" hidden></div>
<aside class="dk-drawer" id="dkDrawer" hidden aria-label="Appointment details"></aside>
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
.dk-day { font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--brown-md); margin:0.35rem 0.25rem 0; }
.dk-day.over { color:var(--red); }
.dk-done { margin-top:1.4rem; background:#fff; border:1px solid var(--border2); border-radius:14px; padding:0.85rem 1rem; }
.dk-done-h { display:flex; justify-content:space-between; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.6rem; }
.dk-done-h h2 { margin:0; font-size:0.95rem; color:var(--brown); }
.dk-done-h input[type=date] { padding:0.35rem 0.5rem; border:1px solid var(--border2); border-radius:8px; background:var(--bg3); color:var(--brown); font:inherit; font-size:0.82rem; }
.dk-drow2 { display:grid; grid-template-columns:70px minmax(0,1.2fr) minmax(0,1.6fr) minmax(0,1fr) auto; gap:0.6rem; align-items:center; padding:0.55rem 0.4rem; border-top:1px solid var(--border2); font-size:0.82rem; color:var(--brown); cursor:pointer; }
.dk-drow2:hover { background:var(--bg3); }
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
</style>

<script>
(function () {
    var CSRF = <?php echo json_encode(generate_csrf_token()); ?>;
    var PAYS = [['cash','Cash'],['gcash','GCash'],['maya','Maya'],['qrph','QR Ph'],['card','Card'],['swiper','Swiper']];
    // Fixed-rate discounts carry their %; Voucher (₱ or %) and Celebration (%)
    // take a value typed in by the receptionist.
    var DISC = [['none','None',0],['senior','Senior 20%',20],['pwd','PWD 20%',20],['employee','Staff 50%',50],['voucher','Voucher',null],['celebration','Celebration',null]];
    // Discount amount on a base, for a {disc, dv (value), vt ('fixed'|'percent')} choice.
    function discAmt(base, d) {
        var row = DISC.filter(function (x) { return x[0] === d.disc; })[0] || DISC[0], v = parseFloat(d.dv) || 0;
        if (row[2] !== null) return Math.round(base * row[2]) / 100;
        if (d.disc === 'celebration') return Math.round(base * Math.min(v, 100)) / 100;
        if (d.disc === 'voucher') return d.vt === 'percent' ? Math.round(base * Math.min(v, 100)) / 100 : Math.min(v, base);
        return 0;
    }
    function discNeedsValue(d) { return (d.disc === 'voucher' || d.disc === 'celebration') && !(parseFloat(d.dv) > 0); }

    var LANES = [
        { k: 'pending',   l: 'Needs therapist', s: 'Online bookings to confirm', c: 'var(--red)' },
        { k: 'assigned',  l: 'Booked',          s: 'Waiting for the customer',   c: '#2f63b8' },
        { k: 'approved',  l: 'In service',      s: 'Checked in',                 c: 'var(--green)' }
    ];
    var D = null, TH = {}, RES = {};
    var S = { q: '', open: null, panel: null, busy: false, doneDate: null, todayOnly: false };
    // "Today only" is remembered on this computer (per receptionist screen).
    try { S.todayOnly = localStorage.getItem('dkTodayOnly') === '1'; } catch (e) {}

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function pesoS(n) { return peso(n).replace(/\.00$/, ''); }
    function dt(s) { return new Date(String(s).replace(' ', 'T')); }
    function tm(s) { return dt(s).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }); }
    function dayLabel(s) { var d = String(s).slice(0, 10); if (d === D.today) return 'Today'; var t = new Date(D.today + 'T00:00'); t.setDate(t.getDate() + 1); if (d === ymd(t)) return 'Tomorrow'; return dt(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }); }
    function byId(id) { return (D.appointments || []).filter(function (a) { return a.id === id; })[0]; }
    // Server messages can arrive HTML-escaped (&#039; etc.); show them as text.
    function unescape(m) { var el = document.createElement('textarea'); el.innerHTML = String(m).replace(/<[^>]*>/g, ''); return el.value; }
    var tt; function toast(m, bad) { var t = $('dkToast'); t.textContent = unescape(m); t.className = 'dk-toast' + (bad ? ' bad' : ''); t.hidden = false; clearTimeout(tt); tt = setTimeout(function () { t.hidden = true; }, 4200); }

    // ── Server calls ──────────────────────────────────────────────────────
    function load(closeOnOk) {
        return fetch('appointments.php?ajax=desk_data' + (S.doneDate ? '&done_date=' + S.doneDate : ''), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                D = data; TH = {}; RES = {};
                D.therapists.forEach(function (t) { TH[t.id] = t; });
                D.resources.forEach(function (r) { RES[r.id] = r; });
                var failed = !!(D.flash && D.flash.type === 'danger');
                if (D.flash && D.flash.message) toast(D.flash.message, failed);
                if (!S.doneDate) S.doneDate = D.done_date;
                renderBoard(); renderDone();
                // A main step that worked (confirm / check in / complete) closes
                // the panel; anything else keeps it open on the same booking.
                if (S.open) { if (byId(S.open) && !(closeOnOk && !failed)) renderDrawer(); else closeDrawer(); }
            })
            .catch(function () { $('dkBoard').innerHTML = '<div class="dk-empty">Could not load appointments. Refresh the page.</div>'; });
    }
    // json=true: the action answers JSON itself. Otherwise it answers with a
    // redirect + flash message; desk_data returns that message on reload.
    function post(action, appt, fields, json, closeOnOk) {
        if (S.busy) return Promise.resolve();
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
        for (var n = 1; n <= a.people; n++) {
            if (strict ? !slotOf(a, n) : !hasSlotRow(a, n)) m.push('therapist' + (a.people > 1 ? ' for person ' + n : ''));
        }
        a.addons.forEach(function (x) { if (x.rows.some(function (r) { return strict ? !r.therapist_id : !(r.selected || r.therapist_id); })) m.push('therapist for ' + x.name); });
        if (!a.resource_id && !a.online) m.push('room');
        return m;
    }
    function isPkg(a) { return a.sessions && a.sessions.length > 1; }
    // A package stays "approved" in the database between sessions; on the
    // board it waits in Booked until one of its sessions is checked in.
    function laneOf(a) {
        if (isPkg(a) && a.status === 'approved' && !a.sessions.some(function (x) { return x.status === 'checked_in'; })) return 'assigned';
        return a.status;
    }
    function nextAction(a) {
        if (a.legacy_two_session) return null;
        if (a.status === 'pending')  return { k: 'approve',  l: 'Confirm booking', cls: 'primary' };
        if (a.status === 'assigned') return { k: 'checkin',  l: 'Check in', cls: 'primary' };
        if (a.status === 'approved' && !isPkg(a)) return { k: 'complete', l: a.bill.due > 0 ? 'Complete & pay ' + pesoS(a.bill.due) : 'Complete', cls: 'ok' };
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
        $('dkBoard').innerHTML = LANES.map(function (L) {
            var list = D.appointments.filter(function (a) {
                if (laneOf(a) !== L.k) return false;
                if (S.todayOnly && String(a.when).slice(0, 10) > D.today) return false;
                if (!q) return true;
                return (a.name + ' ' + a.service.name + ' ' + a.addons.map(function (x) { return x.name; }).join(' ') + ' ' + therNames(a)).toLowerCase().indexOf(q) >= 0;
            });
            // Sorted by time, with a heading each time the day changes
            // (Earlier days first, flagged overdue, then Today, Tomorrow, …).
            list.sort(function (x, y) { return x.when < y.when ? -1 : x.when > y.when ? 1 : 0; });
            var html = '', lastDay = null;
            list.forEach(function (a) {
                var d = String(a.when).slice(0, 10);
                if (d !== lastDay) { html += '<div class="dk-day' + (d < D.today ? ' over' : '') + '">' + (d < D.today ? 'Overdue · ' : '') + dayLabel(a.when) + '</div>'; lastDay = d; }
                html += card(a, L.c);
            });
            return '<section class="dk-lane"><h2><span>' + L.l + '<small>' + L.s + '</small></span><span class="dk-count">' + list.length + '</span></h2>' +
                (list.length ? html : '<div class="dk-empty">Nothing here.</div>') + '</section>';
        }).join('');
        $('dkBoard').querySelectorAll('.dk-card').forEach(function (c) {
            c.onclick = function (e) { if (e.target.closest('[data-go]')) return; openDrawer(+c.dataset.id); };
        });
        $('dkBoard').querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { openDrawer(+b.dataset.id, b.dataset.go); }; });
    }
    function payPill(a) {
        var b = a.bill;
        if (a.rate_type === 'influencer') return '<span class="dk-pill p-pkg">Influencer · ₱0</span>' + (b.due > 0 ? '<span class="dk-pill p-unpaid">Add-ons ' + pesoS(b.due) + '</span>' : '');
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
                a.addons.map(function (x) { return '<span>+ ' + esc(x.name) + (x.rows.length > 1 ? ' <span class="dk-pill p-pkg">' + x.rows.filter(function (r) { return r.status === 'completed'; }).length + '/' + x.rows.length + '</span>' : '') + '</span>'; }).join('') + '</div>' +
            '<div class="dk-meta">' + (th ? esc(th) : '<span class="dk-pill p-need">No therapist yet</span>') + (a.resource_id && RES[a.resource_id] ? ' · ' + esc(RES[a.resource_id].name) : '') + '</div>' +
            '<div class="dk-pills">' + payPill(a) + (a.group && a.group_size > 1 ? '<span class="dk-pill p-src">Group of ' + a.group_size + '</span>' : '') + '</div>' +
            (na ? '<button type="button" class="dk-btn ' + na.cls + '" data-go="' + na.k + '" data-id="' + a.id + '">' + na.l + '</button>' : '') +
            '</article>';
    }
    // ── Completed (one day at a time; open a row for details or Undo) ────
    function renderDone() {
        $('dkDoneDate').value = S.doneDate;
        var q = S.q;
        var list = D.appointments.filter(function (a) {
            if (a.status !== 'completed') return false;
            return !q || (a.name + ' ' + a.service.name + ' ' + therNames(a)).toLowerCase().indexOf(q) >= 0;
        });
        var total = list.reduce(function (t, a) { return t + (a.bill.paid ? a.bill.paid_amount : 0); }, 0);
        $('dkDoneList').innerHTML = list.length
            ? list.map(function (a) {
                var b = a.bill, disc = b.completion_discount || b.discount;
                return '<div class="dk-drow2" data-id="' + a.id + '" tabindex="0"><span>' + tm(a.start) + '</span>' +
                    '<span><b>' + esc(a.name) + '</b><small>' + esc(a.source) + '</small></span>' +
                    '<span class="hide-sm">' + esc(a.service.name) + (a.addons.length ? ' + ' + a.addons.map(function (x) { return esc(x.name); }).join(', ') : '') + '<small>' + esc(therNames(a)) + '</small></span>' +
                    '<span class="hide-sm">' + (a.rate_type === 'influencer' ? 'Influencer · ₱0' : (b.paid ? 'Paid · ' + esc(b.method || '') : 'Unpaid / on account')) + (disc ? '<small>Discount ' + peso(disc) + '</small>' : '') + '</span>' +
                    '<span class="amt">' + peso(b.paid ? b.paid_amount : 0) + '</span></div>';
            }).join('') + '<div class="dk-drow2" style="cursor:default;font-weight:800;"><span></span><span>' + list.length + ' completed</span><span class="hide-sm"></span><span class="hide-sm"></span><span class="amt">' + peso(total) + '</span></div>'
            : '<div class="dk-empty">Nothing completed on this day.</div>';
        $('dkDoneList').querySelectorAll('.dk-drow2[data-id]').forEach(function (r) { r.onclick = function () { openDrawer(+r.dataset.id); }; });
    }
    function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); } // local date, not UTC
    function shiftDone(days) { var d = new Date(S.doneDate + 'T00:00'); d.setDate(d.getDate() + days); S.doneDate = ymd(d); load(); }
    $('dkDonePrev').onclick = function () { shiftDone(-1); };
    $('dkDoneNext').onclick = function () { shiftDone(1); };
    $('dkDoneDate').onchange = function () { if (this.value) { S.doneDate = this.value; load(); } };

    $('dkQ').addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); if (D) { renderBoard(); renderDone(); } });

    // ── Drawer ────────────────────────────────────────────────────────────
    function openDrawer(id, panel) {
        S.open = id; S.panel = panel === 'checkin' || panel === 'complete' ? panel : null;
        S.method = null; S.disc = 'none'; S.dv = ''; S.vt = 'fixed'; S.payNow = true; S.infl = false; S.schedFor = null; S.resched = S.cancelling = S.grp = false;
        renderBoard(); renderDrawer(); $('dkDrawer').hidden = false; $('dkScrim').hidden = false;
    }
    function closeDrawer() { S.open = null; S.pin = ''; $('dkDrawer').hidden = true; $('dkScrim').hidden = true; if (D) renderBoard(); }
    $('dkScrim').onclick = closeDrawer;
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && S.open) closeDrawer(); });

    function therSelect(qual, val, attrs, allowAny) {
        var opts = D.therapists.filter(function (t) { return qual.indexOf(t.id) >= 0; });
        return '<select ' + attrs + (val ? '' : ' class="missing"') + '><option value="">Choose therapist…</option>' +
            (allowAny ? '<option value="__any__">Any available</option>' : '') +
            opts.map(function (t) { return '<option value="' + t.id + '"' + (t.id === val ? ' selected' : '') + '>' + esc(t.name) + (t.on ? '' : ' (off duty)') + '</option>'; }).join('') + '</select>';
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
        var steps = ['Booked', 'Therapist set', 'Checked in', 'Done'].map(function (l, i) { return '<span class="dk-step ' + (i < stepIdx ? 'done' : i === stepIdx ? 'now' : '') + '">' + l + '</span>'; }).join('');

        // Main service: therapist per person + room + package sessions
        var slotsHtml = '';
        for (var n = 1; n <= a.people; n++) {
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
                    '<div><label class="dk-lbl" for="dkSt">Therapist</label>' + therSelect(a.service.qual, x.therapist_id || slotOf(a, 1), 'id="dkSt"', false) + '</div></div>' +
                    '<div class="dk-bar"><button type="button" class="dk-btn primary" data-ssave="' + x.id + '">Save session</button><button type="button" class="dk-btn" data-sx="1">Cancel</button></div></div>' : '');
            }).join('') + '</div>' + (a.status === 'assigned' ? '<p class="dk-hint">Session 1 is checked in with the booking. Later sessions are scheduled, checked in and completed here.</p>' : '');
        }
        var mainHtml = '<div class="dk-svc"><div class="dk-svch"><span><b>' + esc(a.service.name) + '</b> <small>' + a.service.mins + ' min' + (isPkg(a) ? ' · ' + a.sessions.length + '-session package' : '') + '</small></span><span class="dk-amt">' + peso(a.service.price) + '</span></div>' +
            '<div class="dk-g2">' + slotsHtml + '<div><label class="dk-lbl" for="dkRoom">Room</label>' + (locked ? '<div class="dk-hint">' + esc(RES[a.resource_id] ? RES[a.resource_id].name : '—') + '</div>' : roomSelect(a)) + '</div></div>' + sessHtml + '</div>';

        // Add-ons (a multi-session add-on lists its sessions)
        var addHtml = a.addons.map(function (x, xi) {
            var th = x.rows[0].therapist_id;
            var rows = x.rows.length > 1 ? '<div class="dk-sess">' + x.rows.map(function (r) {
                return '<div class="dk-srow"><span class="dk-dot ' + (r.status === 'completed' ? 'done' : '') + '">' + r.n + '</span><span>Session ' + r.n + ' of ' + r.of + '<small>' + (r.status === 'completed' ? 'Done' : 'Not done yet') + '</small></span>' +
                    (!locked && r.status !== 'completed' && a.status === 'approved' ? '<button type="button" class="dk-btn ok" data-xdone="' + r.id + '">Mark done</button>' : '') + '</div>';
            }).join('') + '</div>' : '';
            return '<div class="dk-svc"><div class="dk-svch"><span><b>' + esc(x.name) + '</b> <small>add-on' + (x.rows.length > 1 ? ' · ' + x.rows.length + ' sessions' : '') + (x.person ? ' · ' + esc(x.person) : '') + '</small></span><span class="dk-amt">' + peso(x.price) + '</span></div>' +
                (locked ? '<div class="dk-hint">' + esc(TH[th] ? TH[th].name : '—') + '</div>' : '<div><label class="dk-lbl">Therapist</label>' + therSelect(x.qual, th, 'data-xth="' + xi + '"', a.status === 'pending') + '</div>') +
                rows +
                (!locked && !x.rows.some(function (r) { return r.paid || r.status === 'completed'; }) ? '<button type="button" class="dk-btn danger" data-xrm="' + xi + '" style="align-self:flex-start;">Remove add-on</button>' : '') +
                '</div>';
        }).join('');

        // Bill
        var billHtml = '<div class="dk-bill"><div><span>' + (Math.abs(b.order_total - a.service.price) > 0.01 ? 'Order total' : esc(a.service.name)) + '</span><span>' + peso(b.order_total) + '</span></div>' +
            (b.addons ? '<div><span>Add-ons</span><span>' + peso(b.addons) + '</span></div>' : '') +
            (b.discount ? '<div><span>Discount (' + esc(b.discount_type) + ')</span><span>−' + peso(b.discount) + '</span></div>' : '') +
            (b.advance ? '<div><span>Down payment' + (b.advance_method ? ' (' + esc(b.advance_method) + ')' : '') + '</span><span>−' + peso(b.advance) + '</span></div>' : '') +
            (b.paid ? '<div><span>Paid' + (b.method ? ' · ' + esc(b.method) : '') + '</span><span>' + peso(b.paid_amount) + '</span></div>' : '') +
            '<div class="big"><span>To collect</span><span>' + peso(b.due) + '</span></div></div>';
        var payForm = '';
        if (S.panel === 'checkin' && !b.paid) {
            payForm = '<div class="dk-inline"><div class="dk-bar"><button type="button" class="dk-chip' + (S.payNow ? ' sel' : '') + '" data-pn="1">Pay now</button><button type="button" class="dk-chip' + (!S.payNow ? ' sel' : '') + '" data-pn="0">Pay later</button></div>' +
                (S.payNow ? discChips() + methodChips(false) : '<p class="dk-hint">Payment will be collected when the session is done.</p>') + '</div>';
        } else if (S.panel === 'complete' && b.due > 0) {
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
            '<div class="dk-sec"><h4>Services</h4>' + mainHtml + addHtml +
                (!locked ? '<a class="dk-hint" href="appointments.php?view=classic&appt_date=' + a.date + '">Add a service or edit details in the classic view</a>' : '') + '</div>' +
            '<div class="dk-sec"><h4><span>Payment</span>' + (a.group && a.group_size > 1 && !b.paid && !locked ? '<button type="button" class="dk-btn" id="dkGrpOpen">' + (S.grp ? 'Back to this guest' : 'Pay for group') + '</button>' : '') + '</h4>' + billHtml + inflHtml(a) + payForm + groupHtml + '</div>' +
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
        if (b.paid || b.advance > 0 || isPkg(a)) return '';
        if (!S.infl) return '<button type="button" class="dk-btn" id="dkInflOpen" style="align-self:flex-start;">Influencer / PR (free)</button>';
        return '<div class="dk-inline"><p class="dk-hint"><b>Make this a free Influencer / PR booking?</b> The price becomes ₱0, it is counted as a Marketing Expense in the Daily Report, and the therapist gets the influencer rate when it is completed. Add-ons are still charged.</p>' +
            '<div class="dk-bar"><button type="button" class="dk-btn primary" id="dkInflSave">Yes, make it free</button><button type="button" class="dk-btn" id="dkInflX">Keep the price</button></div></div>';
    }
    function goLabel(a) {
        var na = nextAction(a), b = a.bill; if (!na) return '';
        if (na.k === 'checkin' && S.panel === 'checkin') return b.paid ? 'Check in' : (S.payNow ? 'Check in & take ' + peso(dueWithDisc(a)) : 'Check in · pay later');
        if (na.k === 'complete' && S.panel === 'complete') return S.method === 'unpaid' ? 'Complete · charge to account' : 'Complete & take ' + peso(dueWithDisc(a));
        return na.l;
    }
    function discChips() {
        var extra = '';
        if (S.disc === 'voucher') extra = '<div class="dk-bar" style="margin-top:0.35rem;"><input type="number" id="dkDv" min="0" step="1" placeholder="' + (S.vt === 'percent' ? 'Voucher %' : 'Voucher amount ₱') + '" value="' + esc(S.dv) + '" style="max-width:150px;">' +
            '<button type="button" class="dk-chip sm' + (S.vt !== 'percent' ? ' sel' : '') + '" data-vt="fixed">₱ off</button><button type="button" class="dk-chip sm' + (S.vt === 'percent' ? ' sel' : '') + '" data-vt="percent">% off</button></div>';
        if (S.disc === 'celebration') extra = '<div class="dk-bar" style="margin-top:0.35rem;"><input type="number" id="dkDv" min="0" max="100" step="1" placeholder="Celebration %" value="' + esc(S.dv) + '" style="max-width:150px;"><span class="dk-hint">% off</span></div>';
        return '<div><label class="dk-lbl">Discount</label><div class="dk-bar">' + DISC.map(function (d) { return '<button type="button" class="dk-chip sm' + (S.disc === d[0] ? ' sel' : '') + '" data-disc="' + d[0] + '">' + d[1] + '</button>'; }).join('') + '</div>' + extra + '</div>';
    }
    // What will actually be collected with the discount chosen in the panel.
    function dueWithDisc(a) {
        var b = a.bill; if (b.paid) return b.due;
        var base = b.order_total + b.addons - b.discount;
        return Math.max(0, base - discAmt(base, S) - b.advance);
    }
    function methodChips(allowAccount) {
        var list = PAYS.concat(allowAccount ? [['unpaid', 'Charge to account']] : []);
        return '<div><label class="dk-lbl">Payment method</label><div class="dk-bar">' + list.map(function (p) { return '<button type="button" class="dk-chip sm' + (S.method === p[0] ? ' sel' : '') + '" data-pm="' + p[0] + '">' + p[1] + '</button>'; }).join('') + '</div></div>';
    }

    function wire(a) {
        var W = $('dkDrawer'), err = function (m) { $('dkErr').textContent = m; };
        $('dkX').onclick = closeDrawer;
        var pinEl = $('dkPin'); if (pinEl) pinEl.oninput = function () { S.pin = pinEl.value; }; // kept across redraws
        W.querySelectorAll('[data-slot]').forEach(function (s) { s.onchange = function () { if (s.value) post('assign_person_slot', a.id, { person_slot: s.dataset.slot, therapist_id: s.value }, true); }; });
        W.querySelectorAll('[data-xth]').forEach(function (s) { s.onchange = function () {
            if (!s.value) return;
            var rows = a.addons[+s.dataset.xth].rows, chain = Promise.resolve();
            rows.forEach(function (r) { chain = chain.then(function () { return post('assign_extra_therapist', a.id, { aes_id: r.id, therapist_id: s.value }, true); }); });
        }; });
        var room = $('dkRoom'); if (room) room.onchange = function () { if (room.value) post('set_resource', a.id, { resource_id: room.value }); };
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
            post('assign_session', a.id, { session_id: bt.dataset.ssave, session_date: $('dkSd').value, session_therapist_id: $('dkSt').value || '' });
        }; });
        W.querySelectorAll('[data-sin]').forEach(function (bt) { bt.onclick = function () { post('checkin_session', a.id, { session_id: bt.dataset.sin }); }; });
        W.querySelectorAll('[data-sdone]').forEach(function (bt) { bt.onclick = function () { post('complete_session', a.id, { session_id: bt.dataset.sdone }); }; });
        W.querySelectorAll('[data-pn]').forEach(function (bt) { bt.onclick = function () { S.payNow = bt.dataset.pn === '1'; renderDrawer(); }; });
        W.querySelectorAll('[data-disc]').forEach(function (bt) { bt.onclick = function () { S.disc = bt.dataset.disc; S.dv = ''; renderDrawer(); }; });
        W.querySelectorAll('[data-vt]').forEach(function (bt) { bt.onclick = function () { S.vt = bt.dataset.vt; renderDrawer(); }; });
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
                if (b.paid) return post('checkin_appointment', a.id, { pay_choice: 'later' }, false, true);
                if (S.panel !== 'checkin') { S.panel = 'checkin'; S.grp = false; return renderDrawer(); }
                if (S.payNow && !S.method) return err('Choose a payment method, or pick Pay later.');
                if (S.payNow && discNeedsValue(S)) return err(S.disc === 'voucher' ? 'Type the voucher amount.' : 'Type the celebration %.');
                return post('checkin_appointment', a.id, { pay_choice: S.payNow ? 'now' : 'later', pay_method: S.method || 'cash', discount_type: S.payNow ? S.disc : 'none', voucher_type: S.vt === 'percent' ? 'percent' : 'fixed', voucher_value: S.payNow ? (parseFloat(S.dv) || 0) : 0 }, false, true);
            }
            if (na.k === 'complete') {
                if (b.due > 0 && S.panel !== 'complete') { S.panel = 'complete'; S.grp = false; return renderDrawer(); }
                if (b.due > 0 && !S.method) return err('Choose how the customer paid.');
                if (b.due > 0 && !b.paid && discNeedsValue(S)) return err(S.disc === 'voucher' ? 'Type the voucher amount.' : 'Type the celebration %.');
                return post('complete', a.id, {
                    complete_pay_method: S.method || 'cash', complete_unpaid_billto: ($('dkBill') || {}).value || '',
                    complete_disc_type: b.paid ? 'none' : S.disc, complete_voucher_type: S.vt === 'percent' ? 'percent' : 'cash', complete_voucher_value: b.paid ? 0 : (parseFloat(S.dv) || 0),
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
        var gpo = $('dkGrpOpen'); if (gpo) gpo.onclick = function () { S.grp = !S.grp; if (S.grp) S.panel = null; renderDrawer(); };
    }

    // ── Pay for group (same action as the classic view) ───────────────────
    function loadGroup(a) {
        fetch('appointments.php?ajax=group_bill&group=' + encodeURIComponent(a.group), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var box = $('dkGrp'); if (!box) return;
                var G = (data.guests || []).map(function (g) { g.on = true; g.disc = 'none'; g.dv = ''; g.vt = 'fixed'; return g; });
                var method = null;
                function total() { return G.reduce(function (t, g) { return g.on ? t + Math.max(0, g.subtotal - discAmt(g.subtotal, g)) : t; }, 0); }
                function draw() {
                    if (!G.length) { box.innerHTML = '<p class="dk-hint">Everyone in this group has already paid.</p>'; return; }
                    box.innerHTML = G.map(function (g, i) {
                        return '<div><label class="dk-lbl"><input type="checkbox" data-gi="' + i + '"' + (g.on ? ' checked' : '') + '> ' + esc(g.name) + ' · ' + peso(g.subtotal) + '</label><div class="dk-bar">' +
                            DISC.map(function (d) { return '<button type="button" class="dk-chip sm' + (g.disc === d[0] ? ' sel' : '') + '" data-gd="' + i + ',' + d[0] + '">' + d[1] + '</button>'; }).join('') + '</div>' +
                            (g.disc === 'voucher' || g.disc === 'celebration' ? '<div class="dk-bar" style="margin-top:0.3rem;"><input type="number" min="0" data-gv="' + i + '" value="' + esc(g.dv) + '" placeholder="' + (g.disc === 'celebration' ? 'Celebration %' : (g.vt === 'percent' ? 'Voucher %' : 'Voucher ₱')) + '" style="max-width:130px;">' +
                                (g.disc === 'voucher' ? '<button type="button" class="dk-chip sm' + (g.vt !== 'percent' ? ' sel' : '') + '" data-gvt="' + i + ',fixed">₱ off</button><button type="button" class="dk-chip sm' + (g.vt === 'percent' ? ' sel' : '') + '" data-gvt="' + i + ',percent">% off</button>' : '') + '</div>' : '') +
                            '</div>';
                    }).join('') +
                    '<div class="dk-bar">' + PAYS.map(function (p) { return '<button type="button" class="dk-chip sm' + (method === p[0] ? ' sel' : '') + '" data-gm="' + p[0] + '">' + p[1] + '</button>'; }).join('') + '</div>' +
                    '<button type="button" class="dk-btn ok" id="dkGrpPay">Record ' + peso(total()) + ' for the group</button>';
                    box.querySelectorAll('[data-gi]').forEach(function (c) { c.onchange = function () { G[+c.dataset.gi].on = c.checked; draw(); }; });
                    box.querySelectorAll('[data-gd]').forEach(function (c) { c.onclick = function () { var p = c.dataset.gd.split(','); G[+p[0]].disc = p[1]; G[+p[0]].dv = ''; draw(); }; });
                    box.querySelectorAll('[data-gvt]').forEach(function (c) { c.onclick = function () { var p = c.dataset.gvt.split(','); G[+p[0]].vt = p[1]; draw(); }; });
                    box.querySelectorAll('[data-gv]').forEach(function (c) { c.oninput = function () { G[+c.dataset.gv].dv = c.value; $('dkGrpPay').textContent = 'Record ' + peso(total()) + ' for the group'; }; });
                    box.querySelectorAll('[data-gm]').forEach(function (c) { c.onclick = function () { method = c.dataset.gm; draw(); }; });
                    $('dkGrpPay').onclick = function () {
                        var map = {}, need = null;
                        G.forEach(function (g) { if (!g.on) return; if (discNeedsValue(g)) need = g.name; map[g.name] = { type: g.disc, value: parseFloat(g.dv) || 0, vtype: g.vt }; });
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

    <?php if (!empty($message)): ?>toast(<?php echo json_encode($message); ?>, <?php echo json_encode(($message_type ?? '') === 'danger'); ?>);<?php endif; ?>
    load();
    setInterval(function () { if (!S.open && !document.hidden) load(); }, 60000);
})();
</script>
