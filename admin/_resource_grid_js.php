<script>
// Slotting and Rotation — live resource grid auto-refresh, shared by
// admin/index.php and admin/therapists.php. Mirrors admin/index.php's own
// livePanels polling pattern (full innerHTML replace, pause when tab hidden).
(function () {
    "use strict";
    var container = document.getElementById("resourceGridContainer");
    if (!container) return;
    var inFlight = false, timer = null;

    // Scroll the timeline's internal viewport so "now" is a bit below the
    // top edge (context above it), instead of always resetting to 9 AM —
    // the whole grid is a full innerHTML replace on every refresh, so this
    // has to be re-applied each time, not just once on page load.
    function scrollToNow() {
        var el = document.getElementById("resourceTimelineScroll");
        if (!el) return;
        var startHour = parseFloat(el.dataset.startHour || "9");
        var pxPerMin  = parseFloat(el.dataset.pxPerMin  || "2");
        var now = new Date();
        var nowMinFromStart = (now.getHours() * 60 + now.getMinutes()) - startHour * 60;
        if (nowMinFromStart < 0) { el.scrollTop = 0; return; }
        el.scrollTop = Math.max(0, nowMinFromStart * pxPerMin - 100);
    }

    function refresh() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        var el = document.getElementById("resourceTimelineScroll");
        var interactive = el && el.dataset.interactive === "1";
        fetch("resource_grid_ajax.php" + (interactive ? "?interactive=1" : ""), { credentials: "same-origin" })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) { container.innerHTML = html; scrollToNow(); })
            .catch(function () { /* silently skip on network error */ })
            .finally(function () { inFlight = false; });
    }

    function schedule() { timer = setTimeout(function () { refresh(); schedule(); }, 60000); }
    function pause()    { clearTimeout(timer); timer = null; }
    function resume()   { if (!timer) schedule(); }

    document.addEventListener("visibilitychange", function () {
        if (document.hidden) { pause(); } else { refresh(); resume(); }
    });

    // Dashboard (interactive grid): a block opens its booking's panel; a
    // customer's other services light up with it.
    window.refreshResourceGrid = function () { inFlight = false; refresh(); };
    container.addEventListener("click", function (e) {
        var b = e.target.closest(".rg-blk");
        if (b && window.openApptPanel) window.openApptPanel(+b.dataset.appt);
    });
    function light(e, on) {
        var b = e.target.closest(".rg-blk[data-visit]"); if (!b) return;
        container.querySelectorAll(".rg-blk").forEach(function (o) {
            if (o.dataset.visit === b.dataset.visit) o.style.boxShadow = on ? "0 0 0 2px #7A3FC4" : "";
        });
    }
    container.addEventListener("mouseover", function (e) { light(e, true); });
    container.addEventListener("mouseout", function (e) { light(e, false); });

    scrollToNow();
    schedule();
}());
</script>
