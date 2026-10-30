// Fallback initializer: if main admin JS failed to bind tabs or cache actions.
(function(){
    if (window.AceRedisCacheAdminFallbackApplied) return; // avoid duplicate
    window.AceRedisCacheAdminFallbackApplied = true;
    var tabs = document.querySelectorAll('.ace-redis-sidebar .nav-tab');
    if (!tabs.length) return;
    // Detect if primary handler attached by checking for a data-flag on first click
    var first = tabs[0];
    var anyActive = document.querySelector('.tab-content.active');
    // If no active class present, activate first
    if (!anyActive) {
        var firstHref = first.getAttribute('href');
        if (firstHref) {
            var tc = document.querySelector(firstHref);
            if (tc) { tc.classList.add('active'); }
            first.classList.add('nav-tab-active');
        }
    }
    tabs.forEach(function(a){
        a.addEventListener('click', function(e){
            // If jQuery handler already prevented default, ignore fallback
            if (e.defaultPrevented) return;
            e.preventDefault();
            var target = a.getAttribute('href');
            if (!target || target.charAt(0) !== '#') return;
            document.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
            a.classList.add('nav-tab-active');
            document.querySelectorAll('.tab-content').forEach(function(div){ div.classList.remove('active'); });
            var panel = document.querySelector(target);
            if (panel) panel.classList.add('active');
            if (history && history.replaceState) { history.replaceState(null, '', target); }
        });
    });

    // Clear cache fallback
    var flushBtn = document.getElementById('ace-redis-cache-flush-btn');
    if (flushBtn && typeof jQuery === 'undefined') {
        flushBtn.addEventListener('click', function(){
            if (!window.fetch) { alert('Fetch API not available'); return; }
            var restBase = (window.ace_redis_admin && ace_redis_admin.rest_url) ? ace_redis_admin.rest_url : (window.location.origin + '/wp-json/');
            var url = restBase.replace(/\/$/, '') + '/ace-redis-cache/v1/flush-cache';
            fetch(url, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','X-WP-Nonce': (window.ace_redis_admin? ace_redis_admin.rest_nonce : '')}, body:'nonce='+(window.ace_redis_admin? encodeURIComponent(ace_redis_admin.nonce):'') })
                .then(r=>r.json()).then(function(json){
                    if (json && json.success) {
                        flushBtn.disabled = false;
                        flushBtn.blur();
                        alert('Cache cleared');
                    } else { alert('Failed to clear cache'); }
                }).catch(function(){ alert('Error clearing cache'); });
        });
    }

    // Block cache flush
    var bcFlush = document.getElementById('ace-block-cache-flush-btn');
    if (bcFlush) {
        bcFlush.addEventListener('click', function(){
            var status = document.getElementById('ace-block-cache-flush-status');
            var restBase = (window.ace_redis_admin && ace_redis_admin.rest_url) ? ace_redis_admin.rest_url : (window.location.origin + '/wp-json/');
            bcFlush.disabled = true;
            fetch(restBase.replace(/\/$/, '') + '/ace-redis-cache/v1/block-cache/flush', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','X-WP-Nonce': (window.ace_redis_admin ? ace_redis_admin.rest_nonce : '')}, body:'nonce=' + (window.ace_redis_admin ? encodeURIComponent(ace_redis_admin.nonce) : '') })
                .then(function(r){ return r.json(); })
                .then(function(json){ status.textContent = (json && json.success) ? 'Block cache flushed.' : ((json && json.message) || 'Flush failed.'); })
                .catch(function(){ status.textContent = 'Flush failed.'; })
                .then(function(){ bcFlush.disabled = false; });
        });
    }

    // Code snippet copy buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.ace-copy-btn');
        if (!btn) return;
        var targetId = btn.getAttribute('data-target');
        var pre = targetId ? document.getElementById(targetId) : null;
        if (!pre) return;
        navigator.clipboard.writeText(pre.textContent).then(function() {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'Copy';
                btn.classList.remove('copied');
            }, 2000);
        });
    });
})();
