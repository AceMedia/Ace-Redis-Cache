(function(){
                // Toggle visibility of Clear Cache button based on Enable Cache checkbox
                var enableCb = document.getElementById('enable_cache');
                var actionsPanel = document.querySelector('.cache-actions-panel .cache-action-buttons');
                if (enableCb && actionsPanel) {
                    var updateVisibility = function() {
                        var enabled = !!enableCb.checked;
                        actionsPanel.style.display = enabled ? '' : 'none';
                        // Toggle diagnostics test buttons state
                        var testBtn = document.getElementById('ace-redis-cache-test-btn');
                        var testWRBtn = document.getElementById('ace-redis-cache-test-write-btn');
                        if (testBtn) testBtn.disabled = !enabled;
                        if (testWRBtn) testWRBtn.disabled = !enabled;
                    };
                    enableCb.addEventListener('change', updateVisibility);
                    // Ensure correct state on load as well (in case PHP didn't render expected state)
                    updateVisibility();
                }
            })();
