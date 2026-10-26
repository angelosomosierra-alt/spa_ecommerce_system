<?php
/**
 * _book_now_modal.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Book Now modal: several guests, several services each, for today (Dashboard
 * button) or another day (a day on the calendar → openBookNow('YYYY-MM-DD')).
 * Another day needs a down payment, taken here. "Continue a package" books
 * the next session of a customer's multi-session package. The receptionist adds services per guest; the planner below assigns
 * every service a start time, therapist (today's rotation, qualified, free)
 * and room (right type, free), runs a guest's services back-to-back and
 * guests side by side, and can reorder a guest's services to cut waiting.
 *
 * Data comes from index.php?ajax=book_now_data (see _book_now.php), fetched
 * each time the modal opens. Book saves through index.php?ajax=book_now_submit
 * (book_now_save): unpaid, no discount. Payment and discounts are collected in
 * appointments.php at check-in / Mark Complete, or for several guests at once
 * with "Pay for group".
 *
 * Usage: include once on the Dashboard, after QB_CSRF is defined.
 * ─────────────────────────────────────────────────────────────────────────────
 */
?>
<div class="modal-overlay" id="bookNowModal">
    <div class="modal-box" style="max-width:1720px;width:96vw;">
        <div class="modal-box-header">
            <span class="modal-box-title">Book Now <span class="bn-sub" id="bnSub">· walk-in · today</span></span>
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <label class="bn-when">Date <input type="date" id="bnDate"></label>
                <label class="bn-when">Start <input type="time" id="bnStart" step="300"></label>
                <button class="modal-box-close" type="button" onclick="closeBookNow()">✕</button>
            </div>
        </div>
        <div class="modal-box-body" style="padding:0;">
            <div id="bnLoading" class="bn-loading">Loading services, therapists and rooms&hellip;</div>
            <div id="bnApp" hidden>
                <div class="bn-guests" id="bnGuests"></div>
                <div class="bn-body">
                    <div class="bn-col">
                        <div class="bn-tabs2"><button type="button" data-mode="new" class="on">New services</button><button type="button" data-mode="pkg">Continue a package</button></div>
                        <p class="bn-ct">Add <span id="bnAddWhat">services</span> to <span id="bnAddTo">Guest 1</span></p>
                        <input type="text" id="bnQ" class="bn-input" placeholder="Search services&hellip;" autocomplete="off" aria-label="Search services">
                        <div class="bn-chips" id="bnCats"></div>
                        <div class="bn-svcs" id="bnSvcs"></div>
                        <p class="bn-hint">Tap a duration to add it to the selected guest. <b>+ All guests</b> adds it to everyone in one tap.</p>
                    </div>
                    <div class="bn-col">
                        <p class="bn-ct">Plan &middot; therapist, room and time are assigned automatically</p>
                        <div class="bn-opts">
                            <label><input type="checkbox" id="bnOptSame" checked> Keep the same therapist for a guest's next service</label>
                            <label><input type="checkbox" id="bnOptOrder" checked> Reorder a guest's services to avoid waiting</label>
                        </div>
                        <div id="bnSaved"></div>
                        <div id="bnPlan" class="bn-plan"></div>
                    </div>
                    <div class="bn-col">
                        <p class="bn-ct">Estimated total</p>
                        <div class="bn-bill" id="bnBill"></div>
                        <div class="bn-sum" id="bnSum"></div>
                        <div class="bn-dp" id="bnDp" hidden></div>
                        <p class="bn-hint" id="bnPayHint">Payment and discounts (Senior, PWD, Staff, voucher) are taken in <b>Appointments</b> at check-in or when done. A group can pay together there with <b>Pay for group</b>.</p>
                        <p class="bn-miss" id="bnMiss"></p>
                        <button type="button" class="bn-book" id="bnBookBtn">Book</button>
                    </div>
                </div>
                <div class="bn-prev">
                    <p class="bn-ct" style="margin-bottom:0.4rem;">Room preview &middot; <span id="bnPrevDay">today</span></p>
                    <div class="bn-tl-scroll"><div class="bn-tl" id="bnTl"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
#bookNowModal .modal-box { max-height:92vh; display:flex; flex-direction:column; }
#bookNowModal .modal-box-body { flex:1; overflow-y:auto; }
.bn-sub { color:var(--gray); font-weight:500; font-size:0.85rem; }
.bn-loading { padding:3rem; text-align:center; color:var(--gray); }
.bn-when { display:flex; align-items:center; gap:0.35rem; font-size:0.72rem; font-weight:700; color:var(--gray); }
.bn-when input { border:1px solid var(--border2); border-radius:7px; padding:0.3rem 0.45rem; font:inherit; font-size:0.82rem; color:var(--brown); background:#fff; }
.bn-tabs2 { display:flex; gap:0; border:1px solid var(--border2); border-radius:9px; overflow:hidden; }
.bn-tabs2 button { flex:1; border:0; background:var(--bg3); color:var(--brown-md); font-weight:700; font-size:0.78rem; padding:0.45rem; cursor:pointer; }
.bn-tabs2 button.on { background:var(--rust); color:#fff; }
.bn-pkg { border:1px solid var(--border2); border-radius:9px; padding:0.5rem 0.6rem; display:flex; flex-direction:column; gap:0.3rem; }
.bn-pkg b { font-size:0.84rem; color:var(--brown); }
.bn-pkg .meta { font-size:0.72rem; color:var(--gray); }
.bn-pkg .prog { display:flex; gap:3px; }
.bn-pkg .prog i { width:16px; height:5px; border-radius:3px; background:var(--border2); }
.bn-pkg .prog i.d { background:var(--green); }
.bn-dp[hidden] { display:none; }
.bn-dp { border:2px solid var(--rust); border-radius:10px; padding:0.6rem 0.7rem; display:flex; flex-direction:column; gap:0.45rem; }
.bn-dp input { width:100%; box-sizing:border-box; border:1px solid var(--border2); border-radius:8px; padding:0.45rem 0.6rem; font:inherit; font-weight:700; }
.bn-ct { font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--gray); margin:0; }
.bn-hint { font-size:0.72rem; color:var(--gray); line-height:1.45; margin:0; }
.bn-input { width:100%; padding:0.55rem 0.7rem; border:1px solid var(--border2); border-radius:8px; background:var(--bg3); color:var(--brown); font:inherit; font-size:0.85rem; box-sizing:border-box; }
.bn-chips { display:flex; gap:0.35rem; flex-wrap:wrap; }
.bn-chip { border:1px solid var(--border2); background:var(--bg3); color:var(--brown); border-radius:20px; padding:0.3rem 0.65rem; font-size:0.75rem; font-weight:700; cursor:pointer; }
.bn-chip.sel { background:var(--rust); border-color:var(--rust); color:#fff; }

.bn-guests { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; padding:0.65rem 1rem; border-bottom:1px solid var(--border); background:var(--bg3); position:sticky; top:0; z-index:6; }
.bn-gtab { display:flex; align-items:center; gap:0.5rem; padding:0.35rem 0.6rem; border-radius:10px; border:2px solid transparent; background:#fff; cursor:pointer; }
.bn-gtab.on { border-color:var(--gc); }
.bn-gtab .sw { width:10px; height:10px; border-radius:50%; background:var(--gc); flex:none; }
.bn-gtab input { border:0; background:none; font-weight:700; width:9.5em; padding:2px 0; color:var(--brown); font:inherit; font-size:0.85rem; }
.bn-gtab input:focus { outline:none; }
.bn-gtab input.gs { width:5.5em; font-weight:500; border-left:1px solid var(--border2); padding-left:0.45rem; }
.bn-gtab input.gs.aes { width:8.5em; }
.bn-gtab .cnt { font-size:0.7rem; color:var(--gray); white-space:nowrap; }
.bn-gtab .rm { border:0; background:none; color:var(--gray); cursor:pointer; padding:0 2px; }
.bn-addg { border:1px dashed var(--gold); background:none; border-radius:10px; padding:0.45rem 0.8rem; font-weight:700; cursor:pointer; color:var(--brown-md); font-size:0.82rem; }
.bn-kbd { font-size:0.64rem; border:1px solid var(--border2); border-bottom-width:2px; border-radius:4px; padding:0 4px; color:var(--gray); font-weight:700; }

.bn-body { display:grid; grid-template-columns:300px 1fr 290px; }
.bn-col { padding:0.9rem 1rem; display:flex; flex-direction:column; gap:0.65rem; min-width:0; border-right:1px solid var(--border); }
.bn-col:last-child { border-right:0; }
@media (max-width:1100px) { .bn-body { grid-template-columns:1fr 1fr; } .bn-col:last-child { grid-column:1 / -1; border-top:1px solid var(--border); } .bn-col:nth-child(2) { border-right:0; } }
@media (max-width:720px)  { .bn-body { grid-template-columns:1fr; } .bn-col { border-right:0; border-bottom:1px solid var(--border); } }

.bn-svcs { display:flex; flex-direction:column; gap:0.4rem; max-height:52vh; overflow-y:auto; padding-right:2px; }
.bn-svc { border:1px solid var(--border2); border-radius:9px; padding:0.5rem 0.6rem; display:flex; flex-direction:column; gap:0.4rem; }
.bn-svc .nm { font-weight:700; font-size:0.84rem; color:var(--brown); display:flex; justify-content:space-between; gap:0.4rem; }
.bn-svc .nm span { color:var(--gray); font-weight:600; font-size:0.66rem; text-align:right; }
.bn-durs { display:flex; flex-wrap:wrap; gap:0.3rem; }
.bn-dur { border:1px solid var(--gold); background:#fff; color:var(--brown); border-radius:7px; padding:0.25rem 0.5rem; font-size:0.74rem; font-weight:700; cursor:pointer; font-variant-numeric:tabular-nums; }
.bn-dur:hover { background:var(--rust); border-color:var(--rust); color:#fff; }
.bn-dur.all { border-style:dashed; color:var(--brown-md); }
.bn-dur.all:hover { color:#fff; }

.bn-opts { display:flex; flex-wrap:wrap; gap:0.9rem; font-size:0.78rem; color:var(--brown-md); }
.bn-opts label { display:flex; align-items:center; gap:0.35rem; cursor:pointer; }
.bn-saved { background:var(--green-dim); color:var(--green); border-radius:8px; padding:0.45rem 0.65rem; font-size:0.76rem; font-weight:700; }
.bn-plan { display:flex; flex-direction:column; gap:0.65rem; }
.bn-gcard { border:1px solid var(--border2); border-left:4px solid var(--gc); border-radius:10px; padding:0.6rem 0.75rem; display:flex; flex-direction:column; gap:0.5rem; }
.bn-gcard.on { box-shadow:0 0 0 2px var(--gc) inset; }
.bn-gch { display:flex; justify-content:space-between; align-items:baseline; gap:0.5rem; flex-wrap:wrap; }
.bn-gch b { font-size:0.9rem; color:var(--brown); }
.bn-gch span { font-size:0.72rem; color:var(--gray); }
.bn-empty { font-size:0.78rem; color:var(--gray); padding:0.5rem; border:1px dashed var(--border2); border-radius:8px; text-align:center; }
.bn-line { display:grid; grid-template-columns:auto 1fr auto; gap:0.45rem 0.65rem; align-items:center; padding:0.5rem; background:var(--bg3); border-radius:8px; }
.bn-line .tm { font-weight:800; font-size:0.78rem; color:var(--brown); font-variant-numeric:tabular-nums; white-space:nowrap; }
.bn-line .tm span { color:var(--gray); font-weight:600; }
.bn-line .sv { min-width:0; }
.bn-line .sv b { display:block; font-size:0.82rem; color:var(--brown); }
.bn-line .sv small { color:var(--gray); font-size:0.7rem; }
.bn-ib { border:0; background:none; cursor:pointer; color:var(--gray); padding:2px 5px; border-radius:5px; font-size:0.8rem; }
.bn-ib:hover { background:var(--bg4); color:var(--brown); }
.bn-assign { grid-column:1 / -1; display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; }
.bn-pick { position:relative; }
.bn-pk { border:1px solid var(--border2); background:#fff; color:var(--brown); border-radius:7px; padding:0.25rem 0.5rem; font-size:0.74rem; font-weight:700; cursor:pointer; display:inline-flex; gap:0.3rem; align-items:center; }
.bn-pk .l { color:var(--gray); font-weight:600; }
.bn-pk.locked { border-color:var(--rust); }
.bn-pk .none { color:var(--red); }
.bn-auto { font-size:0.62rem; font-weight:800; color:var(--green); letter-spacing:0.04em; }
.bn-wait { font-size:0.7rem; font-weight:700; color:var(--amber); background:var(--amber-dim); border-radius:6px; padding:2px 7px; }
.bn-menu-div { font-size:0.66rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:var(--gray); padding:0.45rem 0.6rem 0.2rem; border-top:1px solid var(--border2); margin-top:0.2rem; }
.bn-menu { position:absolute; top:calc(100% + 4px); left:0; z-index:20; background:#fff; border:1px solid var(--border2); border-radius:9px; box-shadow:var(--shadow-lg); min-width:230px; max-height:300px; overflow-y:auto; padding:4px; display:flex; flex-direction:column; }
.bn-menu button { display:flex; justify-content:space-between; gap:0.6rem; text-align:left; border:0; background:none; padding:0.45rem 0.55rem; border-radius:6px; cursor:pointer; font-size:0.78rem; color:var(--brown); }
.bn-menu button:hover:not(:disabled) { background:var(--bg3); }
.bn-menu button:disabled { opacity:0.5; cursor:not-allowed; }
.bn-menu .s { font-size:0.68rem; font-weight:700; }
.bn-menu .s.free { color:var(--green); } .bn-menu .s.busy { color:var(--amber); } .bn-menu .s.off { color:var(--gray); }

.bn-bill { display:flex; flex-direction:column; gap:0.5rem; }
.bn-brow { border:1px solid var(--border2); border-radius:9px; padding:0.5rem 0.6rem; display:flex; flex-direction:column; gap:0.4rem; }
.bn-brow .t { display:flex; justify-content:space-between; font-weight:700; font-size:0.82rem; color:var(--brown); font-variant-numeric:tabular-nums; }
.bn-brow .t i { font-style:normal; display:inline-block; width:9px; height:9px; border-radius:50%; background:var(--gc); margin-right:6px; }
.bn-brow .ln { display:flex; justify-content:space-between; gap:0.5rem; font-size:0.74rem; color:var(--brown-md); font-variant-numeric:tabular-nums; }
.bn-sum { background:var(--bg3); border-radius:10px; padding:0.65rem 0.75rem; display:flex; flex-direction:column; gap:0.25rem; font-variant-numeric:tabular-nums; }
.bn-sum div { display:flex; justify-content:space-between; font-size:0.8rem; color:var(--brown-md); }
.bn-sum .big { font-size:1.1rem; font-weight:800; color:var(--brown); }
.bn-book { border:0; border-radius:10px; background:var(--rust); color:#fff; font-weight:800; padding:0.85rem; cursor:pointer; font-size:0.95rem; }
.bn-book:disabled { background:var(--border); color:var(--gray); cursor:not-allowed; }
.bn-miss { font-size:0.72rem; color:var(--red); min-height:1em; margin:0; }

.bn-prev { border-top:1px solid var(--border); padding:0.65rem 1rem 0.9rem; }
.bn-tl-scroll { overflow-x:auto; }
.bn-tl { min-width:760px; }
.bn-tr { display:grid; grid-template-columns:90px 1fr; border-bottom:1px solid var(--border); }
.bn-tr:last-child { border-bottom:0; }
.bn-tn { font-size:0.72rem; font-weight:700; color:var(--brown); padding:0.35rem 0; }
.bn-tk { position:relative; height:30px; }
.bn-th { position:relative; height:18px; }
.bn-th span { position:absolute; font-size:0.64rem; color:var(--gray); transform:translateX(-50%); white-space:nowrap; }
.bn-blk { position:absolute; top:3px; bottom:3px; border-radius:5px; background:var(--bg4); color:var(--brown); font-size:0.64rem; padding:2px 5px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
.bn-blk.new { background:var(--gc); color:#fff; font-weight:700; }
.bn-now { position:absolute; top:0; bottom:0; width:2px; background:var(--red); }
</style>

<script>
(function () {
    var GC = ['#C96A2C', '#2f7d8c', '#7a5bb0', '#4f8a3a'];
    var DAY_END = 24 * 60;

    var D = null;          // data snapshot from ajax=book_now_data
    var SVC = {}, TH = {}; // id → row
    var FIRST = 0;         // earliest start: now (rounded up to 5 min) or the Start picked
    var uid = 1, S = null, PLAN = null, SAVED = 0;
    var DAY = '';          // booking date, YYYY-MM-DD

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmt(m) { var h = Math.floor(m / 60) % 24, mm = m % 60; return (h % 12 || 12) + ':' + (mm < 10 ? '0' : '') + mm + (h >= 12 ? ' PM' : ' AM'); }
    function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function pesoShort(n) { return peso(n).replace(/\.00$/, ''); }
    function ov(a, ad, b, bd) { return a < b + bd && b < a + ad; }
    function gname(g, i) { return g.name.trim() || 'Guest ' + (i + 1); }
    function newGuest() { return { id: uid++, name: '', slip: '', slipAes: '', items: [] }; }

    // ── Open / close ──────────────────────────────────────────────────────
    // date: 'YYYY-MM-DD' from the calendar; none = today (Dashboard button).
    function hhmm(m) { return (m < 600 ? '0' : '') + Math.floor(m / 60) + ':' + (m % 60 < 10 ? '0' : '') + (m % 60); }
    function load(date, keep) {
        $('bnApp').hidden = true; $('bnLoading').hidden = false;
        $('bnLoading').textContent = 'Loading services, therapists and rooms…';
        fetch('index.php?ajax=book_now_data' + (date ? '&date=' + encodeURIComponent(date) : ''), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (data) {
                D = data; SVC = {}; TH = {}; DAY = D.date;
                D.services.forEach(function (s) { SVC[s.id] = s; });
                D.therapists.forEach(function (t) { TH[t.id] = t; });
                FIRST = Math.ceil(D.now / 5) * 5;
                if (keep && S) {
                    // Date changed: keep the guests, re-read their services
                    // from the new snapshot (who is trained / on duty that day).
                    S.guests.forEach(function (g) {
                        g.items = g.items.filter(function (it) { return SVC[it.svc.id]; });
                        g.items.forEach(function (it) { it.svc = SVC[it.svc.id]; it.lockT = null; it.lockR = null; });
                    });
                    S.open = null; S.saving = false;
                } else {
                    S = { guests: [newGuest()], active: 0, cat: 'All', open: null, saving: false, mode: 'new', dp: '', dpm: 'gcash' };
                    $('bnQ').value = ''; $('bnOptOrder').checked = true; $('bnOptSame').checked = true;
                }
                var nd = new Date(); $('bnDate').min = nd.getFullYear() + '-' + ('0' + (nd.getMonth() + 1)).slice(-2) + '-' + ('0' + nd.getDate()).slice(-2);
                $('bnDate').value = DAY;
                $('bnStart').value = hhmm(FIRST);
                $('bnLoading').hidden = true; $('bnApp').hidden = false;
                renderAll();
                if (!keep) { var first = $('bnGuests').querySelector('input'); if (first) first.focus(); }
            })
            .catch(function () { $('bnLoading').textContent = 'Could not load booking data. Close and try again.'; });
    }
    window.openBookNow = function (date) {
        $('bookNowModal').classList.add('active');
        load(typeof date === 'string' ? date : null, false);
    };
    $('bnDate').addEventListener('change', function () { if (this.value && this.value !== DAY) load(this.value, true); });
    $('bnStart').addEventListener('change', function () {
        if (!D || !/^\d{2}:\d{2}$/.test(this.value)) return;
        var a = this.value.split(':'), m = +a[0] * 60 + +a[1];
        var min = D.today ? Math.ceil(D.now / 5) * 5 : 0;
        FIRST = Math.max(min, Math.round(m / 5) * 5);
        this.value = hhmm(FIRST);
        renderAll();
    });
    function subtitle() {
        var d = new Date(DAY + 'T00:00:00');
        $('bnSub').textContent = D.today ? '· today, starting ' + fmt(FIRST)
            : '· ' + d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }) + ', starting ' + fmt(FIRST) + ' · down payment needed';
        $('bnPrevDay').textContent = D.today ? 'today' : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
    }
    window.closeBookNow = function () { $('bookNowModal').classList.remove('active'); };

    // ── Planner ───────────────────────────────────────────────────────────
    // Guests run side by side; a guest's own services run back-to-back.
    // Lines are placed round-robin (every guest's 1st service, then every
    // guest's 2nd…) so the group starts together and rotation stays fair:
    // whoever just got a service moves to the back of the queue.
    function canDo(t, it) { return t.on && it.svc.qual.indexOf(t.id) >= 0; }
    function thFree(id, t, d, busy) { return !busy.some(function (b) { return b.t.indexOf(id) >= 0 && ov(t, d, b.s, b.d); }); }
    function roomFree(id, t, d, busy) { return !busy.some(function (b) { return b.r === id && ov(t, d, b.s, b.d); }); }
    // MED: a Doctor / Nurse service has no therapist -- it only needs a room.
    var MED = -1, LATER = -2; // LATER: booked now, therapist chosen in Appointments
    function pickTh(it, t, d, busy, rot, prefer) {
        if (it.svc.med) return MED;
        if (it.lockT === 'later') return LATER;
        if (it.lockT) return thFree(it.lockT, t, d, busy) ? it.lockT : null;
        var order = rot.slice();
        if (prefer && order.indexOf(prefer) >= 0) { order.splice(order.indexOf(prefer), 1); order.unshift(prefer); }
        for (var i = 0; i < order.length; i++) {
            var th = TH[order[i]];
            if (!th.brk && canDo(th, it) && thFree(th.id, t, d, busy)) return th.id;
        }
        return null;
    }
    function pickRoom(it, t, d, busy, prefer) {
        if (it.lockR) return roomFree(it.lockR, t, d, busy) ? it.lockR : null;
        var pool = D.resources.filter(function (r) { return r.type === it.svc.res; });
        pool.sort(function (a, b) { return (b.id === prefer) - (a.id === prefer); });
        for (var i = 0; i < pool.length; i++) if (roomFree(pool[i].id, t, d, busy)) return pool[i].id;
        return null;
    }
    function place(orders, sameTh) {
        var busy = D.busy.slice();
        var rot = D.therapists.filter(function (t) { return t.on; }).map(function (t) { return t.id; });
        var guestEnd = orders.map(function () { return FIRST; });
        var lastTh = orders.map(function () { return null; });
        var lastR = orders.map(function () { return null; });
        var out = orders.map(function () { return []; });
        var rounds = Math.max.apply(null, orders.map(function (o) { return o.length; }).concat([0]));
        var wait = 0, firstWait = 0;
        for (var k = 0; k < rounds; k++) {
            orders.forEach(function (list, gi) {
                var it = list[k]; if (!it) return;
                var d = it.dur.m, earliest = guestEnd[gi], found = null;
                for (var t = earliest; t <= DAY_END - d && !found; t += 5) {
                    var th = pickTh(it, t, d, busy, rot, sameTh ? lastTh[gi] : null);
                    if (!th) continue;
                    var rm = pickRoom(it, t, d, busy, lastR[gi]);
                    if (!rm) continue;
                    found = { s: t, t: th, r: rm };
                }
                // Nobody free (or trained) today: still book it with a room and
                // time, therapist to be chosen later in Appointments.
                for (var t2 = earliest; t2 <= DAY_END - d && !found; t2 += 5) {
                    var rm2 = pickRoom(it, t2, d, busy, lastR[gi]);
                    if (rm2) found = { s: t2, t: LATER, r: rm2, auto: true };
                }
                if (!found) found = { s: earliest, t: null, r: null };
                found.waited = found.s - earliest; wait += found.waited;
                if (k === 0) firstWait += found.waited;
                found.item = it; out[gi].push(found);
                if (found.t) {
                    busy.push({ r: found.r, t: [found.t], s: found.s, d: d });
                    var ri = rot.indexOf(found.t); if (ri >= 0) { rot.splice(ri, 1); rot.push(found.t); }
                }
                guestEnd[gi] = found.s + d; if (found.t > 0) lastTh[gi] = found.t; lastR[gi] = found.r;
            });
        }
        return { lines: out, wait: wait, firstWait: firstWait, end: Math.max.apply(null, guestEnd.concat([FIRST])) };
    }
    function perms(a) {
        if (a.length <= 1) return [a.slice()];
        var r = [];
        a.forEach(function (x, i) { perms(a.slice(0, i).concat(a.slice(i + 1))).forEach(function (p) { r.push([x].concat(p)); }); });
        return r;
    }
    function plan() {
        var sameTh = $('bnOptSame').checked, reorder = $('bnOptOrder').checked;
        var base = S.guests.map(function (g) { return g.items.slice(); });
        var asIs = place(base, sameTh), best = asIs;
        if (reorder) {
            // Try every order of each guest's services (up to 3 each, capped
            // at 400 combinations) and keep the one with the least waiting,
            // then the most guests starting right away, then the earliest finish.
            var combos = [[]];
            base.map(function (l) { return l.length <= 3 ? perms(l) : [l]; }).forEach(function (opts) {
                var n = [];
                combos.forEach(function (c) { opts.forEach(function (o) { if (n.length < 400) n.push(c.concat([o])); }); });
                combos = n;
            });
            combos.forEach(function (c) {
                var p = place(c, sameTh);
                if (p.wait < best.wait || (p.wait === best.wait && (p.firstWait < best.firstWait || (p.firstWait === best.firstWait && p.end < best.end)))) best = p;
            });
        }
        SAVED = asIs.wait - best.wait;
        PLAN = best;
    }

    // ── Guests ────────────────────────────────────────────────────────────
    function renderGuests() {
        var h = S.guests.map(function (g, i) {
            var sub = g.items.reduce(function (a, it) { return a + it.dur.p; }, 0);
            return '<div class="bn-gtab' + (i === S.active ? ' on' : '') + '" data-i="' + i + '" style="--gc:' + GC[i % 4] + '">' +
                '<span class="sw"></span><input type="text" class="gn" value="' + esc(g.name) + '" placeholder="Guest ' + (i + 1) + ' name" data-i="' + i + '" aria-label="Guest ' + (i + 1) + ' name">' +
                // Aesthetic treatments are written on their own slip series.
                ((!g.items.length || g.items.some(function (it) { return !it.svc.aes; })) ? '<input type="text" class="gs" value="' + esc(g.slip) + '" placeholder="Slip #" maxlength="50" data-i="' + i + '" aria-label="Guest ' + (i + 1) + ' slip number">' : '') +
                (g.items.some(function (it) { return it.svc.aes; }) ? '<input type="text" class="gs aes" value="' + esc(g.slipAes) + '" placeholder="Aesthetic slip #" maxlength="50" data-i="' + i + '" aria-label="Guest ' + (i + 1) + ' aesthetic slip number">' : '') +
                '<span class="cnt">' + g.items.length + ' svc · ' + pesoShort(sub) + '</span>' +
                (S.guests.length > 1 ? '<button class="rm" type="button" data-rm="' + i + '" title="Remove guest">✕</button>' : '') + '</div>';
        }).join('');
        h += (S.guests.length < 4 ? '<button class="bn-addg" type="button" id="bnAddG">+ Add guest</button>' : '') +
             '<span class="bn-hint" style="margin-left:auto;"><span class="bn-kbd">Alt</span>+<span class="bn-kbd">1</span>…<span class="bn-kbd">4</span> switch guest · <span class="bn-kbd">/</span> search</span>';
        $('bnGuests').innerHTML = h;
        $('bnGuests').querySelectorAll('.bn-gtab').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (e.target.closest('.rm') || S.active === +el.dataset.i) return;
                S.active = +el.dataset.i;
                if (e.target.tagName === 'INPUT') { // keep the cursor in the name box
                    $('bnGuests').querySelectorAll('.bn-gtab').forEach(function (t) { t.classList.toggle('on', +t.dataset.i === S.active); });
                    $('bnAddTo').textContent = gname(S.guests[S.active], S.active);
                    renderCatalog(); renderPlan(); return;
                }
                renderAll();
            });
        });
        $('bnGuests').querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('input', function () {
                if (inp.classList.contains('aes')) { S.guests[+inp.dataset.i].slipAes = inp.value; return; }
                if (inp.classList.contains('gs')) { S.guests[+inp.dataset.i].slip = inp.value; return; }
                S.guests[+inp.dataset.i].name = inp.value;
                $('bnAddTo').textContent = gname(S.guests[S.active], S.active);
                renderPlan(); renderBill(); renderPreview();
            });
            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('bnQ').focus(); } });
        });
        $('bnGuests').querySelectorAll('.rm').forEach(function (b) {
            b.onclick = function () { S.guests.splice(+b.dataset.rm, 1); S.active = Math.min(S.active, S.guests.length - 1); renderAll(); };
        });
        var add = $('bnAddG');
        if (add) add.onclick = function () {
            S.guests.push(newGuest()); S.active = S.guests.length - 1; renderAll();
            var ins = $('bnGuests').querySelectorAll('input.gn'); ins[ins.length - 1].focus();
        };
        $('bnAddTo').textContent = gname(S.guests[S.active], S.active);
    }

    // ── Catalog ───────────────────────────────────────────────────────────
    function renderCatalog() {
        document.querySelectorAll('#bookNowModal .bn-tabs2 button').forEach(function (b) { b.classList.toggle('on', b.dataset.mode === S.mode); });
        $('bnAddWhat').textContent = S.mode === 'pkg' ? 'a package session' : 'services';
        $('bnQ').placeholder = S.mode === 'pkg' ? 'Search customer name…' : 'Search services…';
        $('bnCats').hidden = S.mode === 'pkg';
        if (S.mode === 'pkg') { renderPackages(); return; }
        var cats = ['All'];
        D.services.forEach(function (s) { if (cats.indexOf(s.cat) < 0) cats.push(s.cat); });
        $('bnCats').innerHTML = cats.map(function (c) { return '<button type="button" class="bn-chip' + (S.cat === c ? ' sel' : '') + '" data-c="' + esc(c) + '">' + esc(c) + '</button>'; }).join('');
        $('bnCats').querySelectorAll('.bn-chip').forEach(function (b) { b.onclick = function () { S.cat = b.dataset.c; renderCatalog(); }; });

        var q = $('bnQ').value.trim().toLowerCase();
        var list = D.services.filter(function (s) { return (S.cat === 'All' || s.cat === S.cat) && (!q || s.name.toLowerCase().indexOf(q) >= 0); });
        $('bnSvcs').innerHTML = list.length ? list.map(function (s) {
            return '<div class="bn-svc"><div class="nm">' + esc(s.name) + '<span>' + esc(s.cat) + '</span></div><div class="bn-durs">' +
                s.durs.map(function (d, di) { return '<button type="button" class="bn-dur" data-s="' + s.id + '" data-d="' + di + '"' + (d.n > 1 ? ' title="' + d.n + '-session package: session 1 now, the rest scheduled later in Appointments"' : '') + '>+ ' + d.m + ' min' + (d.n > 1 ? ' × ' + d.n + ' sessions' : '') + ' · ' + pesoShort(d.p) + (d.promo ? ' promo' : '') + '</button>'; }).join('') +
                (S.guests.length > 1 ? '<button type="button" class="bn-dur all" data-s="' + s.id + '" data-d="0" data-all="1">+ All guests</button>' : '') +
                '</div></div>';
        }).join('') : '<p class="bn-hint">No service matches “' + esc($('bnQ').value) + '”.</p>';
        $('bnSvcs').querySelectorAll('.bn-dur').forEach(function (b) {
            b.onclick = function () {
                var s = SVC[+b.dataset.s], d = s.durs[+b.dataset.d];
                (b.dataset.all ? S.guests : [S.guests[S.active]]).forEach(function (g) {
                    g.items.push({ key: uid++, svc: s, dur: d, lockT: null, lockR: null });
                });
                renderAll();
            };
        });
    }
    // Customers with sessions left on a package: in the system (paid with
    // the package) or started before it was tracked (price still due).
    function pkgTaken(f) {
        return S.guests.some(function (g) { return g.items.some(function (it) { return it.pkg && it.pkg.kind === f.kind && it.pkg.id === f.id; }); });
    }
    function renderPackages() {
        var q = $('bnQ').value.trim().toLowerCase();
        var list = (D.followups || []).filter(function (f) { return SVC[f.service_id] && (!q || f.who.toLowerCase().indexOf(q) >= 0 || f.service.toLowerCase().indexOf(q) >= 0); });
        $('bnSvcs').innerHTML = '<p class="bn-hint">Customers who still have sessions left on a package. Picking one adds the next session at the price still due.</p>' +
            (list.length ? list.map(function (f) {
                var i = D.followups.indexOf(f), prog = '';
                for (var k = 1; k <= f.total; k++) prog += '<i class="' + (k < f.next ? 'd' : '') + '"></i>';
                var taken = pkgTaken(f);
                return '<div class="bn-pkg"><b>' + esc(f.who) + '</b><div class="meta">' + esc(f.service) + ' · ' + f.total + '-session package</div>' +
                    '<div class="prog">' + prog + '</div>' +
                    '<div class="meta">' + (f.last ? 'Last session ' + esc(f.last) + ' · ' : '') + esc(f.paid_note) + '</div>' +
                    '<div class="bn-durs"><button type="button" class="bn-dur" data-f="' + i + '"' + (taken ? ' disabled' : '') + '>' + (taken ? 'Added' : '+ Session ' + f.next + ' · ' + (f.due > 0 ? pesoShort(f.due) + ' to pay' : 'already paid')) + '</button></div></div>';
            }).join('') : '<p class="bn-hint">' + (q ? 'No customer matches “' + esc($('bnQ').value) + '”.' : 'No packages with sessions left.') + '</p>');
        $('bnSvcs').querySelectorAll('[data-f]').forEach(function (b) {
            b.onclick = function () {
                var f = D.followups[+b.dataset.f], g = S.guests[S.active];
                if (pkgTaken(f)) return;
                if (!g.name.trim()) g.name = f.who;
                g.items.push({ key: uid++, svc: SVC[f.service_id], dur: { id: null, m: f.m, p: f.due, n: 1 }, pkg: f, lockT: null, lockR: null });
                renderAll();
            };
        });
    }
    document.querySelectorAll('#bookNowModal .bn-tabs2 button').forEach(function (b) {
        b.onclick = function () { if (!S) return; S.mode = b.dataset.mode; $('bnQ').value = ''; renderCatalog(); };
    });
    $('bnQ').addEventListener('input', function () { if (S) renderCatalog(); });
    $('bnQ').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { var f = $('bnSvcs').querySelector('.bn-dur:not(:disabled)'); if (f) { e.preventDefault(); f.click(); $('bnQ').select(); } }
    });

    // ── Plan ──────────────────────────────────────────────────────────────
    function findItem(key) { var f = null; S.guests.forEach(function (g) { g.items.forEach(function (it) { if (it.key === key) f = it; }); }); return f; }
    function busyExcept(L) {
        var b = D.busy.slice();
        PLAN.lines.forEach(function (ls) { ls.forEach(function (x) { if (x !== L && x.t) b.push({ r: x.r, t: [x.t], s: x.s, d: x.item.dur.m }); }); });
        return b;
    }
    function thMenu(it, L) {
        var busy = busyExcept(L), d = it.dur.m;
        // Who can actually take it comes first (free, then busy / on break),
        // in rotation order; then not trained; then off duty. The #number is
        // still each therapist's place in today's rotation.
        var rows = D.therapists.map(function (t, i) {
            var st = !t.on ? ['off', 'Off duty', 3] : (it.svc.qual.indexOf(t.id) < 0 ? ['off', 'Not trained', 2] :
                     (t.brk ? ['busy', 'On break', 1] : (thFree(t.id, L.s, d, busy) ? ['free', 'Free', 0] : ['busy', 'Busy then', 1])));
            return { t: t, i: i, st: st };
        });
        rows.sort(function (a, b) { return a.st[2] - b.st[2] || a.i - b.i; });
        var shownDivider = false;
        return '<div class="bn-menu"><button type="button" data-pt="' + it.key + ',auto"><span>Auto (next in rotation)</span></button>' +
            '<button type="button" data-pt="' + it.key + ',later"><span>Assign later</span><span class="s busy">Choose in Appointments</span></button>' +
            rows.map(function (r) {
                var t = r.t, st = r.st, div = '';
                // Not trained but on duty: can still be picked on purpose.
                var anyway = st[1] === 'Not trained' && t.on;
                if (st[2] >= 2 && !shownDivider) { shownDivider = true; div = '<div class="bn-menu-div">Not trained or off duty</div>'; }
                return div + '<button type="button" data-pt="' + it.key + ',' + t.id + '"' + (st[0] === 'off' && !anyway ? ' disabled' : '') + (anyway ? ' title="Not trained for this service — pick anyway"' : '') + '><span>' + (t.on ? '#' + (r.i + 1) + ' ' : '') + esc(t.name) + '</span><span class="s ' + (anyway ? 'busy' : st[0]) + '">' + (anyway ? 'Not trained · pick anyway' : st[1]) + '</span></button>';
            }).join('') + '</div>';
    }
    function roomMenu(it, L) {
        var busy = busyExcept(L), d = it.dur.m;
        var pool = D.resources.filter(function (r) { return r.type === it.svc.res; });
        return '<div class="bn-menu"><button type="button" data-pr="' + it.key + ',auto"><span>Auto (first free)</span></button>' +
            (pool.length ? pool.map(function (r) {
                var free = roomFree(r.id, L.s, d, busy);
                return '<button type="button" data-pr="' + it.key + ',' + r.id + '"><span>' + esc(r.name) + '</span><span class="s ' + (free ? 'free' : 'busy') + '">' + (free ? 'Free' : 'Taken then') + '</span></button>';
            }).join('') : '<button type="button" disabled><span>No active ' + it.svc.res.replace('_', ' ') + ' set up</span></button>') + '</div>';
    }
    function renderPlan() {
        $('bnSaved').innerHTML = SAVED > 0 ? '<div class="bn-saved">Reordered services to cut waiting by ' + SAVED + ' min.</div>' : '';
        $('bnPlan').innerHTML = S.guests.map(function (g, gi) {
            var lines = PLAN.lines[gi] || [];
            var last = lines[lines.length - 1];
            var body = lines.length ? lines.map(function (L) {
                var it = L.item, th = TH[L.t], rm = D.resources.filter(function (r) { return r.id === L.r; })[0];
                var idx = g.items.indexOf(it);
                return '<div class="bn-line">' +
                    '<span class="tm">' + fmt(L.s) + '<br><span>–' + fmt(L.s + it.dur.m) + '</span></span>' +
                    '<span class="sv"><b>' + esc(it.svc.name) + '</b><small>' + (it.pkg ? 'Session ' + it.pkg.next + ' of ' + it.pkg.total + ' · ' + it.dur.m + ' min · ' + (it.dur.p > 0 ? pesoShort(it.dur.p) + ' due' : 'already paid')
                        : (it.dur.n > 1 ? 'Session 1 of ' + it.dur.n + ' · ' : '') + it.dur.m + ' min · ' + pesoShort(it.dur.p) + (it.dur.n > 1 ? ' package' : '')) + '</small></span>' +
                    '<span><button class="bn-ib" type="button" title="Move up" data-mv="' + gi + ',' + idx + ',-1">↑</button><button class="bn-ib" type="button" title="Move down" data-mv="' + gi + ',' + idx + ',1">↓</button><button class="bn-ib" type="button" title="Remove" data-del="' + gi + ',' + idx + '">✕</button></span>' +
                    '<span class="bn-assign">' +
                        (it.svc.med
                            ? '<span class="bn-pick"><button class="bn-pk" type="button" disabled title="Done by a doctor or nurse — no therapist needed"><span class="l">Done by</span>Doctor / Nurse</button></span>'
                            : '<span class="bn-pick"><button class="bn-pk' + (it.lockT ? ' locked' : '') + '" type="button" data-open="t' + it.key + '"><span class="l">Therapist</span>' + (th ? esc(th.name) + (it.svc.qual.indexOf(L.t) < 0 ? ' <span class="none">(not trained)</span>' : '') : (L.t === LATER ? '<span class="none">Assign later</span>' : '<span class="none">none free</span>')) + (it.lockT ? ' 🔒' : '') + '</button>' + (S.open === 't' + it.key ? thMenu(it, L) : '') + '</span>') +
                        '<span class="bn-pick"><button class="bn-pk' + (it.lockR ? ' locked' : '') + '" type="button" data-open="r' + it.key + '"><span class="l">Room</span>' + (rm ? esc(rm.name) : '<span class="none">none free</span>') + (it.lockR ? ' 🔒' : '') + '</button>' + (S.open === 'r' + it.key ? roomMenu(it, L) : '') + '</span>' +
                        (!it.lockT && !it.lockR && (th || it.svc.med) && rm ? '<span class="bn-auto">AUTO</span>' : '') +
                        (L.t === LATER && L.auto ? '<span class="bn-wait">No trained therapist free · assign later</span>' : '') +
                        (L.waited > 0 ? '<span class="bn-wait">⚠ waits ' + L.waited + ' min</span>' : '') +
                    '</span></div>';
            }).join('') : '<div class="bn-empty">No services yet. Pick from the list on the left.</div>';
            return '<div class="bn-gcard' + (gi === S.active ? ' on' : '') + '" style="--gc:' + GC[gi % 4] + '" data-g="' + gi + '">' +
                '<div class="bn-gch"><b>' + esc(gname(g, gi)) + '</b><span>' + (last ? 'Done by ' + fmt(last.s + last.item.dur.m) : '') + '</span></div>' + body + '</div>';
        }).join('');

        var P = $('bnPlan');
        P.querySelectorAll('.bn-gcard').forEach(function (c) {
            c.addEventListener('click', function (e) { if (e.target.closest('button')) return; if (S.active !== +c.dataset.g) { S.active = +c.dataset.g; renderAll(); } });
        });
        P.querySelectorAll('[data-open]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); S.open = S.open === b.dataset.open ? null : b.dataset.open; renderPlan(); }; });
        P.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () { var a = b.dataset.del.split(','); S.guests[+a[0]].items.splice(+a[1], 1); renderAll(); }; });
        P.querySelectorAll('[data-mv]').forEach(function (b) {
            b.onclick = function () {
                var a = b.dataset.mv.split(','), list = S.guests[+a[0]].items, i = +a[1], j = i + +a[2];
                if (j < 0 || j >= list.length) return;
                var t = list[i]; list[i] = list[j]; list[j] = t;
                $('bnOptOrder').checked = false; // a manual order wins over auto-reorder
                renderAll();
            };
        });
        P.querySelectorAll('[data-pt]').forEach(function (b) { b.onclick = function () { var a = b.dataset.pt.split(','); findItem(+a[0]).lockT = a[1] === 'auto' ? null : (a[1] === 'later' ? 'later' : +a[1]); S.open = null; renderAll(); }; });
        P.querySelectorAll('[data-pr]').forEach(function (b) { b.onclick = function () { var a = b.dataset.pr.split(','); findItem(+a[0]).lockR = a[1] === 'auto' ? null : +a[1]; S.open = null; renderAll(); }; });
    }
    document.addEventListener('click', function (e) {
        if (S && S.open && !e.target.closest('.bn-pick')) { S.open = null; renderPlan(); }
    });

    // ── Estimated total (no payment here) ─────────────────────────────────
    function renderBill() {
        var total = 0, n = 0;
        $('bnBill').innerHTML = S.guests.map(function (g, gi) {
            var sub = g.items.reduce(function (a, it) { return a + it.dur.p; }, 0);
            total += sub; n += g.items.length;
            return '<div class="bn-brow" style="--gc:' + GC[gi % 4] + '"><div class="t"><span><i></i>' + esc(gname(g, gi)) + '</span><span>' + peso(sub) + '</span></div>' +
                g.items.map(function (it) { return '<div class="ln"><span>' + esc(it.svc.name) + ' · ' + (it.pkg ? 'Session ' + it.pkg.next + ' of ' + it.pkg.total : (it.dur.n > 1 ? it.dur.n + ' sessions × ' : '') + it.dur.m + ' min') + '</span><span>' + pesoShort(it.dur.p) + '</span></div>'; }).join('') +
                '</div>';
        }).join('');
        $('bnSum').innerHTML =
            '<div><span>' + S.guests.length + ' guest' + (S.guests.length > 1 ? 's' : '') + ' · ' + n + ' service' + (n !== 1 ? 's' : '') + '</span><span></span></div>' +
            '<div class="big"><span>Total before discounts</span><span>' + peso(total) + '</span></div>' +
            (n ? '<div><span>Everyone done by</span><span>' + fmt(PLAN.end) + '</span></div>' : '');

        // Another day: a down payment holds the slot (suggested 50%).
        var fut = !D.today, sugg = Math.round(total / 2);
        $('bnDp').hidden = !fut || total <= 0;
        if (fut && total > 0) {
            var methods = [['cash', 'Cash'], ['gcash', 'GCash'], ['maya', 'Maya'], ['qrph', 'QR Ph'], ['card', 'Card']];
            $('bnDp').innerHTML = '<p class="bn-ct">Down payment</p>' +
                '<p class="bn-hint">Booked ahead, so a down payment holds the slot. Suggested 50%: <b>' + peso(sugg) + '</b>.</p>' +
                '<input type="number" id="bnDpAmt" min="1" step="0.01" max="' + total + '" value="' + (S.dp === '' ? sugg : esc(S.dp)) + '" aria-label="Down payment amount">' +
                '<div class="bn-chips">' + methods.map(function (m) { return '<button type="button" class="bn-chip' + (S.dpm === m[0] ? ' sel' : '') + '" data-m="' + m[0] + '">' + m[1] + '</button>'; }).join('') + '</div>';
            $('bnDpAmt').oninput = function () { S.dp = this.value; renderMiss(); };
            $('bnDp').querySelectorAll('[data-m]').forEach(function (b) { b.onclick = function () { S.dpm = b.dataset.m; renderBill(); }; });
        }
        $('bnPayHint').innerHTML = fut
            ? 'The balance, and any discount (Senior, PWD, Staff, voucher), is collected in <b>Appointments</b> on the day.'
            : 'Payment and discounts (Senior, PWD, Staff, voucher) are taken in <b>Appointments</b> at check-in or when done. A group can pay together there with <b>Pay for group</b>.';
        renderMiss();
        subtitle();
    }
    function dpAmount() { var el = $('bnDpAmt'); return el && !$('bnDp').hidden ? parseFloat(el.value) || 0 : 0; }
    function renderMiss() {
        var n = 0, total = 0;
        S.guests.forEach(function (g) { n += g.items.length; g.items.forEach(function (it) { total += it.dur.p; }); });
        var miss = [];
        S.guests.forEach(function (g, gi) { if (!g.items.length) miss.push(gname(g, gi) + ' has no service'); });
        if (PLAN.lines.some(function (ls) { return ls.some(function (L) { return !L.t || !L.r; }); })) miss.push('a service has no free therapist or room that day');
        if (!D.today && total > 0 && dpAmount() <= 0) miss.push('the down payment');
        $('bnMiss').textContent = miss.length ? 'Still needed: ' + miss.join(', ') : '';
        $('bnBookBtn').disabled = miss.length > 0 || S.saving;
        $('bnBookBtn').textContent = S.saving ? 'Booking…' : 'Book ' + S.guests.length + ' guest' + (S.guests.length > 1 ? 's' : '') + ' · ' + n + ' service' + (n !== 1 ? 's' : '');
    }

    // ── Room preview ──────────────────────────────────────────────────────
    function renderPreview() {
        if (!D.resources.length) { $('bnTl').innerHTML = '<p class="bn-hint">No active rooms set up yet. Add them in Resources.</p>'; return; }
        var start = Math.max(0, Math.floor(Math.min(D.now, FIRST) / 60) * 60 - 60);
        var end = Math.min(DAY_END, Math.max(start + 8 * 60, Math.ceil((PLAN.end + 30) / 60) * 60));
        var span = end - start;
        function x(m) { return ((m - start) / span * 100) + '%'; }
        function w(m) { return (m / span * 100) + '%'; }
        var h = '<div class="bn-tr"><div></div><div class="bn-th">';
        for (var m = start; m <= end; m += 60) h += '<span style="left:' + x(m) + '">' + fmt(m).replace(':00', '') + '</span>';
        h += '</div></div>';
        D.resources.forEach(function (r) {
            var blocks = D.busy.filter(function (b) { return b.r === r.id && b.s + b.d > start && b.s < end; }).map(function (b) {
                return '<div class="bn-blk" style="left:' + x(Math.max(b.s, start)) + ';width:' + w(Math.min(b.s + b.d, end) - Math.max(b.s, start)) + '">' + esc(b.who) + '</div>';
            }).join('');
            PLAN.lines.forEach(function (ls, gi) {
                ls.forEach(function (L) {
                    if (L.r !== r.id) return;
                    blocks += '<div class="bn-blk new" style="--gc:' + GC[gi % 4] + ';left:' + x(L.s) + ';width:' + w(L.item.dur.m) + '">' + esc(gname(S.guests[gi], gi)) + (TH[L.t] ? ' · ' + esc(TH[L.t].name) : '') + '</div>';
                });
            });
            h += '<div class="bn-tr"><div class="bn-tn">' + esc(r.name) + '</div><div class="bn-tk">' + blocks + (D.today ? '<div class="bn-now" style="left:' + x(D.now) + '"></div>' : '') + '</div></div>';
        });
        $('bnTl').innerHTML = h;
    }

    function renderAll() { plan(); renderGuests(); renderCatalog(); renderPlan(); renderBill(); renderPreview(); }
    $('bnOptSame').onchange = function () { if (S) renderAll(); };
    $('bnOptOrder').onchange = function () { if (S) renderAll(); };

    // ── Book ──────────────────────────────────────────────────────────────
    // Sends exactly what the plan shows; the server re-checks every line
    // (price, duration, training, duty, clashes) before saving anything.
    $('bnBookBtn').onclick = function () {
        if (this.disabled) return;
        var guests = S.guests.map(function (g, gi) {
            return {
                name: g.name.trim(),
                slip: (g.slip || '').trim(),
                slip_aes: (g.slipAes || '').trim(),
                items: (PLAN.lines[gi] || []).map(function (L) {
                    return { service_id: L.item.svc.id, duration_id: L.item.dur.id, therapist_id: L.t > 0 ? L.t : 0, resource_id: L.r, start: L.s,
                             override: L.t > 0 && L.item.svc.qual.indexOf(L.t) < 0,
                             kind: L.item.pkg ? L.item.pkg.kind : 'new', ref_id: L.item.pkg ? L.item.pkg.id : 0 };
                })
            };
        });
        S.saving = true; renderBill();
        fetch('index.php?ajax=book_now_submit', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': QB_CSRF },
            body: new URLSearchParams({ guests: JSON.stringify(guests), date: DAY, dp_amount: D.today ? 0 : dpAmount(), dp_method: S.dpm }).toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                S.saving = false;
                if (res.ok) { closeBookNow(); uiAlert(res.message).then(function () { location.reload(); }); }
                else { renderBill(); uiAlert(res.message || 'Could not save the booking.'); }
            })
            .catch(function () { S.saving = false; renderBill(); uiAlert('Could not reach the server. Please try again.'); });
    };

    document.addEventListener('keydown', function (e) {
        if (!$('bookNowModal').classList.contains('active') || !S) return;
        if (e.key === 'Escape') { closeBookNow(); return; }
        if (e.altKey && /^[1-4]$/.test(e.key) && S.guests[+e.key - 1]) { e.preventDefault(); S.active = +e.key - 1; renderAll(); }
        else if (e.key === '/' && !/INPUT|TEXTAREA/.test(document.activeElement.tagName)) { e.preventDefault(); $('bnQ').focus(); }
    });
})();
</script>
