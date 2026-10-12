<?php
/**
 * _sell_product_modal.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Sell Product window on the Dashboard: counter product sales, paid right
 * away. Tap products (live stock shown, sold-out disabled, + stops at what's
 * left), then customer (optional), slip, discount, payment and Record sale.
 * Data: index.php?ajax=sell_product_data; save: ajax=sell_product_submit
 * (see _sell_product.php — stock is re-checked and locked there).
 *
 * Usage: include once on the Dashboard, after QB_CSRF and QB_PAYMENT_METHODS
 * are defined.
 * ─────────────────────────────────────────────────────────────────────────────
 */
?>
<div class="modal-overlay" id="sellProductModal">
    <div class="modal-box" style="max-width:1280px;width:96vw;">
        <div class="modal-box-header">
            <span class="modal-box-title">Sell Product <span class="sp-sub">· counter sale · paid now</span></span>
            <button class="modal-box-close" type="button" onclick="closeSellProduct()">✕</button>
        </div>
        <div class="modal-box-body" style="padding:0;">
            <div id="spLoading" class="sp-loading">Loading products&hellip;</div>
            <div id="spApp" class="sp-body" hidden>
                <div class="sp-col">
                    <p class="sp-ct">Tap a product to add it</p>
                    <input type="text" id="spQ" class="sp-input" placeholder="Search products&hellip;" autocomplete="off" aria-label="Search products">
                    <div class="sp-chips" id="spCats"></div>
                    <div class="sp-grid" id="spGrid"></div>
                    <p class="sp-hint"><span class="sp-kbd">/</span> search · <span class="sp-kbd">Enter</span> adds the first match · tap again to add one more</p>
                </div>
                <div class="sp-col">
                    <p class="sp-ct">This sale</p>
                    <div class="sp-cart" id="spCart"></div>
                    <div class="sp-two">
                        <div><label class="sp-lbl" for="spCust">Customer <span style="font-weight:500;color:var(--gray);">(optional)</span></label><input type="text" id="spCust" class="sp-input" placeholder="Walk-in"></div>
                        <div><label class="sp-lbl" for="spSlip">Slip number</label><input type="text" id="spSlip" class="sp-input" placeholder="e.g. 1108-2026"></div>
                    </div>
                    <div>
                        <label class="sp-lbl">Discount</label>
                        <div class="sp-chips" id="spDiscs"></div>
                        <div id="spVWrap" hidden style="margin-top:0.4rem;"><input type="number" id="spVAmt" class="sp-input" min="0" step="10" placeholder="Voucher amount (₱)"></div>
                    </div>
                    <div>
                        <label class="sp-lbl">Payment method</label>
                        <div class="sp-chips" id="spPays"></div>
                    </div>
                    <div class="sp-sum" id="spSum"></div>
                    <div class="sp-change" id="spCashBox" hidden>
                        <label for="spTend">Cash received</label>
                        <input type="number" id="spTend" class="sp-input" min="0" step="50" placeholder="0.00">
                        <span>Change <b id="spChg">₱0.00</b></span>
                    </div>
                    <p class="sp-miss" id="spMiss"></p>
                    <button type="button" class="sp-sell" id="spSellBtn">Record sale</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
#sellProductModal .modal-box { max-height:92vh; display:flex; flex-direction:column; }
#sellProductModal .modal-box-body { flex:1; overflow-y:auto; }
.sp-sub { color:var(--gray); font-weight:500; font-size:0.85rem; }
.sp-loading { padding:3rem; text-align:center; color:var(--gray); }
.sp-body { display:grid; grid-template-columns:1fr 360px; }
.sp-body[hidden] { display:none; }
@media (max-width:860px) { .sp-body { grid-template-columns:1fr; } .sp-col + .sp-col { border-left:0 !important; border-top:1px solid var(--border); } }
.sp-col { padding:0.9rem 1rem; display:flex; flex-direction:column; gap:0.65rem; min-width:0; }
.sp-col + .sp-col { border-left:1px solid var(--border); }
.sp-ct { font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--gray); margin:0; }
.sp-hint { font-size:0.72rem; color:var(--gray); line-height:1.45; margin:0; }
.sp-kbd { font-size:0.64rem; border:1px solid var(--border2); border-bottom-width:2px; border-radius:4px; padding:0 4px; color:var(--gray); font-weight:700; }
.sp-input { width:100%; padding:0.55rem 0.7rem; border:1px solid var(--border2); border-radius:8px; background:var(--bg3); color:var(--brown); font:inherit; font-size:0.85rem; box-sizing:border-box; }
.sp-chips { display:flex; gap:0.35rem; flex-wrap:wrap; }
.sp-chip { border:1px solid var(--border2); background:var(--bg3); color:var(--brown); border-radius:20px; padding:0.3rem 0.65rem; font-size:0.75rem; font-weight:700; cursor:pointer; }
.sp-chip.sel { background:var(--rust); border-color:var(--rust); color:#fff; }

.sp-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(165px, 1fr)); gap:0.5rem; max-height:56vh; overflow-y:auto; padding:2px; }
.sp-tile { text-align:left; border:1px solid var(--border2); background:#fff; color:var(--brown); border-radius:10px; padding:0.6rem; cursor:pointer; display:flex; flex-direction:column; gap:0.35rem; position:relative; font:inherit; }
.sp-tile:hover:not(:disabled) { border-color:var(--gold); background:var(--bg3); }
.sp-tile:disabled { cursor:not-allowed; opacity:0.5; }
.sp-tile .sw { height:52px; border-radius:7px; background:var(--bg4) center/cover no-repeat; display:grid; place-items:center; font-size:0.64rem; font-weight:800; color:var(--brown-md); letter-spacing:0.06em; text-transform:uppercase; }
.sp-tile b { font-size:0.84rem; line-height:1.25; }
.sp-tile .row { display:flex; justify-content:space-between; align-items:center; gap:0.35rem; }
.sp-tile .pr { font-weight:800; font-variant-numeric:tabular-nums; }
.sp-stock { font-size:0.66rem; font-weight:800; border-radius:20px; padding:2px 7px; white-space:nowrap; }
.sp-stock.ok { background:var(--green-dim); color:var(--green); }
.sp-stock.low { background:var(--amber-dim); color:var(--amber); }
.sp-stock.out { background:var(--red-dim); color:var(--red); }
.sp-incart { position:absolute; top:6px; right:6px; background:var(--rust); color:#fff; border-radius:20px; font-size:0.68rem; font-weight:800; padding:1px 7px; }

.sp-cart { display:flex; flex-direction:column; gap:0.4rem; }
.sp-ci { display:grid; grid-template-columns:1fr auto auto; gap:0.5rem; align-items:center; padding:0.5rem; background:var(--bg3); border-radius:8px; }
.sp-ci b { font-size:0.82rem; display:block; color:var(--brown); }
.sp-ci small { color:var(--gray); font-size:0.7rem; font-variant-numeric:tabular-nums; }
.sp-qty { display:flex; align-items:center; border:1px solid var(--border2); border-radius:8px; overflow:hidden; background:#fff; }
.sp-qty button { border:0; background:#fff; color:var(--brown); width:28px; height:28px; cursor:pointer; font-weight:800; }
.sp-qty button:disabled { opacity:0.35; cursor:not-allowed; }
.sp-qty span { min-width:26px; text-align:center; font-weight:800; font-variant-numeric:tabular-nums; color:var(--brown); }
.sp-ci .amt { font-weight:800; font-variant-numeric:tabular-nums; min-width:70px; text-align:right; color:var(--brown); }
.sp-ci .cap { grid-column:1 / -1; font-size:0.68rem; color:var(--amber); font-weight:700; }
.sp-empty { font-size:0.8rem; color:var(--gray); padding:0.9rem; border:1px dashed var(--border2); border-radius:8px; text-align:center; }
.sp-two { display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; }
.sp-lbl { font-size:0.72rem; font-weight:700; color:var(--brown-md); display:block; margin-bottom:0.25rem; }
.sp-sum { background:var(--bg3); border-radius:10px; padding:0.65rem 0.75rem; display:flex; flex-direction:column; gap:0.25rem; font-variant-numeric:tabular-nums; }
.sp-sum div { display:flex; justify-content:space-between; font-size:0.8rem; color:var(--brown-md); }
.sp-sum .big { font-size:1.15rem; font-weight:800; color:var(--brown); }
.sp-change { display:flex; justify-content:space-between; align-items:center; gap:0.5rem; font-size:0.8rem; color:var(--brown-md); }
.sp-change[hidden] { display:none; }
.sp-change .sp-input { max-width:130px; }
.sp-change b { font-variant-numeric:tabular-nums; color:var(--green); }
.sp-sell { border:0; border-radius:10px; background:var(--rust); color:#fff; font-weight:800; padding:0.85rem; cursor:pointer; font-size:0.95rem; }
.sp-sell:disabled { background:var(--border); color:var(--gray); cursor:not-allowed; }
.sp-miss { font-size:0.72rem; color:var(--red); min-height:1em; margin:0; }
</style>

<script>
(function () {
    var DISC = [{ k: 'none', l: 'None' }, { k: 'senior', l: 'Senior 20%', pct: 20 }, { k: 'pwd', l: 'PWD 20%', pct: 20 }, { k: 'employee', l: 'Staff 50%', pct: 50 }, { k: 'voucher', l: 'Voucher' }];
    var D = null, P = {}, S = null;

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function pesoShort(n) { return peso(n).replace(/\.00$/, ''); }
    function line(id) { return S.cart.filter(function (c) { return c.id === id; })[0]; }
    function fresh() { return { cat: 'All', cart: [], disc: 'none', pay: QB_PAYMENT_METHODS.length ? QB_PAYMENT_METHODS[0].value : 'cash', saving: false }; }
    function clearInputs() { ['spQ', 'spCust', 'spSlip', 'spVAmt', 'spTend'].forEach(function (id) { $(id).value = ''; }); }

    window.openSellProduct = function () {
        $('sellProductModal').classList.add('active');
        $('spApp').hidden = true; $('spLoading').hidden = false;
        $('spLoading').textContent = 'Loading products…';
        fetch('index.php?ajax=sell_product_data', { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (data) {
                D = data; P = {};
                D.products.forEach(function (p) { P[p.id] = p; });
                S = fresh(); clearInputs();
                $('spLoading').hidden = true; $('spApp').hidden = false;
                render(); $('spQ').focus();
            })
            .catch(function () { $('spLoading').textContent = 'Could not load products. Close and try again.'; });
    };
    window.closeSellProduct = function () { $('sellProductModal').classList.remove('active'); };

    function add(id) {
        var p = P[id], l = line(id);
        if (!p || p.stock <= 0) return;
        if (l) { if (l.qty < p.stock) l.qty++; }
        else S.cart.push({ id: id, qty: 1 });
        render();
    }

    function renderShelf() {
        var cats = ['All'];
        D.products.forEach(function (p) { if (cats.indexOf(p.cat) < 0) cats.push(p.cat); });
        $('spCats').innerHTML = cats.map(function (c) { return '<button type="button" class="sp-chip' + (S.cat === c ? ' sel' : '') + '" data-c="' + esc(c) + '">' + esc(c) + '</button>'; }).join('');
        $('spCats').querySelectorAll('.sp-chip').forEach(function (b) { b.onclick = function () { S.cat = b.dataset.c; renderShelf(); }; });
        var q = $('spQ').value.trim().toLowerCase();
        var list = D.products.filter(function (p) { return (S.cat === 'All' || p.cat === S.cat) && (!q || (p.name + ' ' + p.cat).toLowerCase().indexOf(q) >= 0); });
        $('spGrid').innerHTML = !D.products.length ? '<p class="sp-hint">No products yet. Add them in Products.</p>' : (list.length ? list.map(function (p) {
            var l = line(p.id), st = p.stock <= 0 ? ['out', 'Sold out'] : (p.stock <= D.low ? ['low', p.stock + ' left'] : ['ok', p.stock + ' left']);
            return '<button type="button" class="sp-tile" data-id="' + p.id + '"' + (p.stock <= 0 ? ' disabled' : '') + '>' +
                (l ? '<span class="sp-incart">× ' + l.qty + '</span>' : '') +
                '<span class="sw"' + (p.image ? ' style="background-image:url(\'' + esc(p.image) + '\')"' : '') + '>' + (p.image ? '' : esc(p.cat)) + '</span>' +
                '<b>' + esc(p.name) + '</b>' +
                '<span class="row"><span class="pr">' + pesoShort(p.price) + '</span><span class="sp-stock ' + st[0] + '">' + st[1] + '</span></span></button>';
        }).join('') : '<p class="sp-hint">No product matches “' + esc($('spQ').value) + '”.</p>');
        $('spGrid').querySelectorAll('.sp-tile').forEach(function (b) { b.onclick = function () { add(+b.dataset.id); }; });
    }

    function totals() {
        var sub = S.cart.reduce(function (a, c) { return a + P[c.id].price * c.qty; }, 0);
        var d = DISC.filter(function (x) { return x.k === S.disc; })[0], disc = 0;
        if (d.pct) disc = Math.round(sub * d.pct) / 100;
        if (d.k === 'voucher') disc = Math.min(sub, parseFloat($('spVAmt').value) || 0);
        return { sub: sub, disc: disc, tot: Math.max(0, sub - disc) };
    }

    function renderSale() {
        $('spCart').innerHTML = S.cart.length ? S.cart.map(function (c) {
            var p = P[c.id], full = c.qty >= p.stock;
            return '<div class="sp-ci"><span><b>' + esc(p.name) + '</b><small>' + peso(p.price) + ' each</small></span>' +
                '<span class="sp-qty"><button type="button" data-m="' + p.id + '" aria-label="One less">−</button><span>' + c.qty + '</span><button type="button" data-p="' + p.id + '" aria-label="One more"' + (full ? ' disabled' : '') + '>+</button></span>' +
                '<span class="amt">' + peso(p.price * c.qty) + '</span>' +
                (full ? '<span class="cap">That\'s all ' + p.stock + ' in stock.</span>' : '') + '</div>';
        }).join('') : '<div class="sp-empty">No products yet. Tap one on the left.</div>';
        $('spCart').querySelectorAll('[data-p]').forEach(function (b) { b.onclick = function () { add(+b.dataset.p); }; });
        $('spCart').querySelectorAll('[data-m]').forEach(function (b) {
            b.onclick = function () { var l = line(+b.dataset.m); l.qty--; if (l.qty <= 0) S.cart.splice(S.cart.indexOf(l), 1); render(); };
        });

        $('spDiscs').innerHTML = DISC.map(function (d) { return '<button type="button" class="sp-chip' + (S.disc === d.k ? ' sel' : '') + '" data-k="' + d.k + '">' + d.l + '</button>'; }).join('');
        $('spDiscs').querySelectorAll('.sp-chip').forEach(function (b) { b.onclick = function () { S.disc = b.dataset.k; renderSale(); }; });
        $('spVWrap').hidden = S.disc !== 'voucher';
        $('spPays').innerHTML = QB_PAYMENT_METHODS.map(function (pm) { return '<button type="button" class="sp-chip' + (S.pay === pm.value ? ' sel' : '') + '" data-p="' + pm.value + '">' + esc(pm.label) + '</button>'; }).join('');
        $('spPays').querySelectorAll('.sp-chip').forEach(function (b) { b.onclick = function () { S.pay = b.dataset.p; renderSale(); }; });

        var t = totals(), n = S.cart.reduce(function (a, c) { return a + c.qty; }, 0);
        $('spSum').innerHTML = '<div><span>' + n + ' item' + (n !== 1 ? 's' : '') + '</span><span>' + peso(t.sub) + '</span></div>' +
            (t.disc ? '<div><span>Discount</span><span>−' + peso(t.disc) + '</span></div>' : '') +
            '<div class="big"><span>Collect now</span><span>' + peso(t.tot) + '</span></div>';
        $('spCashBox').hidden = S.pay !== 'cash' || !S.cart.length;
        var tend = parseFloat($('spTend').value) || 0;
        $('spChg').textContent = peso(Math.max(0, tend - t.tot));

        var miss = [];
        if (!S.cart.length) miss.push('a product');
        if (S.disc === 'voucher' && !(parseFloat($('spVAmt').value) > 0)) miss.push('voucher amount');
        if (S.pay === 'cash' && $('spTend').value && tend < t.tot) miss.push('cash received is less than the total');
        $('spMiss').textContent = miss.length ? 'Still needed: ' + miss.join(', ') : '';
        $('spSellBtn').disabled = miss.length > 0 || S.saving;
        $('spSellBtn').textContent = S.saving ? 'Recording…' : 'Record sale · ' + peso(t.tot);
    }

    function render() { renderShelf(); renderSale(); }

    $('spQ').addEventListener('input', function () { if (S) renderShelf(); });
    $('spQ').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { var f = $('spGrid').querySelector('.sp-tile:not(:disabled)'); if (f) { e.preventDefault(); f.click(); $('spQ').select(); } }
    });
    $('spVAmt').addEventListener('input', function () { if (S) renderSale(); });
    $('spTend').addEventListener('input', function () { if (S) renderSale(); });

    $('spSellBtn').onclick = function () {
        if (this.disabled) return;
        S.saving = true; renderSale();
        fetch('index.php?ajax=sell_product_submit', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': QB_CSRF },
            body: new URLSearchParams({
                items: JSON.stringify(S.cart), customer: $('spCust').value, slip: $('spSlip').value,
                discount_type: S.disc, voucher_amount: $('spVAmt').value || 0, payment_method: S.pay
            }).toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                S.saving = false;
                if (!res.ok) { renderSale(); uiAlert(res.message || 'Could not record the sale.'); return; }
                uiAlert(res.message);
                // Ready for the next customer, with fresh stock counts.
                openSellProduct();
            })
            .catch(function () { S.saving = false; renderSale(); uiAlert('Could not reach the server. Please try again.'); });
    };

    document.addEventListener('keydown', function (e) {
        if (!$('sellProductModal').classList.contains('active') || !S) return;
        if (e.key === 'Escape') { closeSellProduct(); return; }
        if (e.key === '/' && !/INPUT|TEXTAREA/.test(document.activeElement.tagName)) { e.preventDefault(); $('spQ').focus(); }
    });
})();
</script>
