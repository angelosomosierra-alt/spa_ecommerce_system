<script>
// Slotting and Rotation — live resource grid auto-refresh, shared by
// admin/index.php and admin/therapists.php. Mirrors admin/index.php's own
// livePanels polling pattern (full innerHTML replace, pause when tab hidden).
(function () {
    "use strict";
    var container = document.getElementById("resourceGridContainer");
    if (!container) return;
    var inFlight = false, timer = null;

    function refresh() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch("resource_grid_ajax.php", { credentials: "same-origin" })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) { container.innerHTML = html; })
            .catch(function () { /* silently skip on network error */ })
            .finally(function () { inFlight = false; });
    }

    function schedule() { timer = setTimeout(function () { refresh(); schedule(); }, 60000); }
    function pause()    { clearTimeout(timer); timer = null; }
    function resume()   { if (!timer) schedule(); }

    document.addEventListener("visibilitychange", function () {
        if (document.hidden) { pause(); } else { refresh(); resume(); }
    });

    schedule();
}());
</script>
