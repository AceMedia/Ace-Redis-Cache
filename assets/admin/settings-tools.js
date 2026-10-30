(function(){
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.ace-redis-sidebar .nav-tab'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.tab-content'));
    if (!tabs.length || !panels.length) return;

    var animating = false;

    function setActiveTab(target) {
        tabs.forEach(function(tab){
            tab.classList.toggle('nav-tab-active', tab.getAttribute('href') === target);
        });
    }

    function transitionTo(target, updateHash) {
        var next = document.querySelector(target);
        var current = document.querySelector('.tab-content.active');
        if (!next || animating || current === next) {
            if (next) setActiveTab(target);
            return;
        }

        animating = true;
        setActiveTab(target);

        if (updateHash && history && history.replaceState) {
            history.replaceState(null, '', target);
        }

        current.classList.add('is-leaving');

        window.setTimeout(function(){
            current.classList.remove('active', 'is-leaving');
            next.classList.add('active');
            animating = false;
        }, 170);
    }

    tabs.forEach(function(tab){
        tab.addEventListener('click', function(e){
            var target = tab.getAttribute('href');
            if (!target || target.charAt(0) !== '#') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            transitionTo(target, true);
        }, true);
    });

    window.addEventListener('hashchange', function(){
        var hash = window.location.hash;
        if (hash && document.querySelector(hash)) {
            transitionTo(hash, false);
        }
    });
})();
