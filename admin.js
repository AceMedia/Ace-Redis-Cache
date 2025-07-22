jQuery(function($){
    function updateStatus() {
        $('#ace-redis-cache-connection').text('Checking...');
        $('#ace-redis-cache-size').text('-');
        $.post(AceRedisCacheAjax.ajax_url, {
            action: 'ace_redis_cache_status',
            nonce: AceRedisCacheAjax.nonce
        }, function(resp){
            if(resp.success) {
                $('#ace-redis-cache-connection').text(resp.data.status);
                $('#ace-redis-cache-size').text(
                    resp.data.size + ' keys (' + resp.data.size_kb + ' KB)'
                );
            } else {
                $('#ace-redis-cache-connection').text('Error');
                $('#ace-redis-cache-size').text('-');
            }
        });
    }
    $('#ace-redis-cache-test-btn').on('click', function(e){
        e.preventDefault();
        updateStatus();
    });
    // Auto-load status on page load
    updateStatus();
});
