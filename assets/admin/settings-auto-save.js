(function(){
    var panel = document.getElementById('ace-rc-scope-panel');
    var btn = document.getElementById('ace-rc-scope-panel-toggle-btn');
    if (!panel || !btn) return;

    var key = 'ace_rc_scope_panel_collapsed';
    var textEl = btn.querySelector('.ace-rc-scope-panel-toggle-text');
    var iconEl = btn.querySelector('.dashicons');

    function applyState(collapsed) {
        panel.classList.toggle('is-collapsed', collapsed);
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (textEl) textEl.textContent = collapsed ? 'Show guide' : 'Hide guide';
        if (iconEl) {
            iconEl.classList.remove('dashicons-visibility', 'dashicons-hidden');
            iconEl.classList.add(collapsed ? 'dashicons-hidden' : 'dashicons-visibility');
        }
    }

    var collapsed = false;
    try {
        collapsed = window.localStorage.getItem(key) === '1';
    } catch (e) {}
    applyState(collapsed);

    btn.addEventListener('click', function(){
        collapsed = !panel.classList.contains('is-collapsed');
        applyState(collapsed);
        try {
            window.localStorage.setItem(key, collapsed ? '1' : '0');
        } catch (e) {}
    });
})();
