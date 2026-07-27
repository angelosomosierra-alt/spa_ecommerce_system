/* ui-modal.js — spa-themed uiAlert / uiConfirm replacing native alert / confirm */
(function () {
    'use strict';

    function ensureStyles() {
        if (document.getElementById('_uim_styles')) return;
        var s = document.createElement('style');
        s.id = '_uim_styles';
        s.textContent =
            '#_uim_overlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:99990;display:flex;align-items:center;justify-content:center;padding:1rem;animation:_uimFI .17s ease}' +
            '@keyframes _uimFI{from{opacity:0}to{opacity:1}}' +
            '#_uim_box{background:#FAF3E8;border-radius:14px;padding:1.7rem 2rem 1.5rem;min-width:270px;max-width:420px;width:100%;box-shadow:0 18px 55px rgba(59,42,26,.28);animation:_uimSI .2s ease}' +
            '@keyframes _uimSI{from{transform:translateY(-10px);opacity:0}to{transform:translateY(0);opacity:1}}' +
            '#_uim_title{font-size:1.1rem;font-weight:700;color:#3B2A1A;margin-bottom:.55rem;letter-spacing:.01em}' +
            '#_uim_msg{font-size:.9rem;color:#5C3D1E;line-height:1.65;margin-bottom:1.4rem;white-space:pre-wrap}' +
            '#_uim_btns{display:flex;gap:.65rem;justify-content:flex-end}' +
            '._uim_btn{padding:.5rem 1.25rem;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;border:none;line-height:1.4;transition:background .16s,transform .1s}' +
            '._uim_btn:active{transform:scale(.97)}' +
            '._uim_btn_ok{background:#3B2A1A;color:#FAF3E8}._uim_btn_ok:hover{background:#C96A2C}' +
            '._uim_btn_yes{background:#C96A2C;color:#fff}._uim_btn_yes:hover{background:#A94F1D}' +
            '._uim_btn_no{background:#EAD8C0;color:#3B2A1A}._uim_btn_no:hover{background:#d4bfa3}';
        document.head.appendChild(s);
    }

    function showModal(title, msg, buttons) {
        ensureStyles();
        var overlay = document.createElement('div');
        overlay.id = '_uim_overlay';
        overlay.innerHTML =
            '<div id="_uim_box">' +
            '<div id="_uim_title">' + (title || '') + '</div>' +
            '<div id="_uim_msg"></div>' +
            '<div id="_uim_btns"></div>' +
            '</div>';
        overlay.querySelector('#_uim_msg').textContent = msg;
        var btnsEl = overlay.querySelector('#_uim_btns');
        buttons.forEach(function (b) {
            var btn = document.createElement('button');
            btn.className = '_uim_btn ' + b.cls;
            btn.textContent = b.label;
            btn.onclick = function () { overlay.remove(); document.removeEventListener('keydown', onKey); b.resolve(); };
            btnsEl.appendChild(btn);
        });
        function onKey(e) {
            if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', onKey); buttons[0].resolve(); }
            if (e.key === 'Enter') { var last = btnsEl.lastChild; if (last) last.click(); }
        }
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        setTimeout(function () { var l = btnsEl.lastChild; if (l) l.focus(); }, 40);
    }

    window.uiAlert = function (msg, title) {
        return new Promise(function (resolve) {
            showModal(title || 'Notice', msg, [
                { label: 'OK', cls: '_uim_btn_ok', resolve: resolve }
            ]);
        });
    };

    window.uiConfirm = function (msg, title) {
        return new Promise(function (resolve) {
            showModal(title || 'Confirm', msg, [
                { label: 'Cancel', cls: '_uim_btn_no', resolve: function () { resolve(false); } },
                { label: 'Yes',    cls: '_uim_btn_yes', resolve: function () { resolve(true); } }
            ]);
        });
    };
})();
