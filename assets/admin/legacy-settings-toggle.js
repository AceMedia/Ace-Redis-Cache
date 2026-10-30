(function(){
                    var enableInputs = document.querySelectorAll('input[name="ace_redis_cache_settings[enabled]"]');
                    var actions = document.getElementById('ace-redis-inline-cache-actions');
                    if (enableInputs.length && actions) {
                        var update = function(){
                            var checked = false;
                            enableInputs.forEach(function(el){ if (el.checked) checked = true; });
                            actions.style.display = checked ? '' : 'none';
                            var testBtn = document.getElementById('ace-redis-cache-test-btn');
                            if (testBtn) testBtn.disabled = !checked;
                        };
                        enableInputs.forEach(function(el){ el.addEventListener('change', update); });
                        update();
                    }
                })();
