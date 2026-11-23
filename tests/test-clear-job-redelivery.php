<?php
/** Actual clear-job methods with synthetic WP storage/hooks/Redis. No service or network. */
namespace AceMedia\RedisCache;
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__);
$fixture=[]; $checks=0;
function get_option($key,$default=[]) { return $GLOBALS['fixture']['options'][$key] ?? $default; }
function update_option($key,$value,...$args) { $GLOBALS['fixture']['writes']++; $GLOBALS['fixture']['options'][$key]=$value; }
function delete_option($key) { $GLOBALS['fixture']['deletes']++; unset($GLOBALS['fixture']['options'][$key]); }
function do_action(...$args) { $GLOBALS['fixture']['actions'][]=$args; }
function add_action(...$args) {}
function apply_filters($hook,$value,...$args) { return $value; }
function wp_parse_url($url,$part=-1) { return parse_url($url,$part); }
function home_url($path='/') { return 'https://fixture.invalid/'; }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event($at,$hook) { $GLOBALS['fixture']['schedules'][]=[$at,$hook]; }
function time() { return 1000; }
function hrtime($numeric=false) { return $GLOBALS['fixture']['elapsed']*1e9; }
// The method intentionally checks availability in the global function namespace.
eval('namespace { function wp_next_scheduled($hook) { return false; } }');
function check($passed,$label) { if (!$passed) throw new \RuntimeException('FAIL '.$label); $GLOBALS['checks']++; }
class RedeliveryRedis {
    public $scans=0; public $throw=false;
    public function scan(&$cursor,$pattern,$count) {
        $this->scans++;
        if ($this->throw) throw new \RuntimeException('Synthetic scan failure');
        $cursor=$cursor===null?1:0;
        $GLOBALS['fixture']['elapsed']+=$GLOBALS['fixture']['step'];
        return ['synthetic-key-'.$this->scans];
    }
    public function rawCommand($command,...$keys) { return count($keys); }
}
class RedeliveryConnection {
    public $connections=0; public $redis; public $available=true;
    public function __construct() { $this->redis=new RedeliveryRedis; }
    public function get_connection() { $this->connections++; return $this->available?$this->redis:null; }
    public function retry_operation($callback) { return $callback($this->redis); }
}
function setup_job($job,$step=0) {
    $GLOBALS['fixture']=['options'=>['ace_redis_cache_clear_job'=>$job],'writes'=>0,'deletes'=>0,'actions'=>[],'schedules'=>[],'elapsed'=>0,'step'=>$step];
    $connection=new RedeliveryConnection;
    return [new CacheManager($connection,[]),$connection];
}
set_error_handler(static function($level,$message,$file,$line) { if(error_reporting()&$level) throw new \ErrorException($message,0,$level,$file,$line); });
try {
    require getenv('REDIS_CLEAR_MANAGER_SOURCE') ?: dirname(__DIR__).'/includes/class-cache-manager.php';
    foreach (['complete','failed'] as $state) {
        $job=['patterns'=>['block_cache:*'],'status'=>$state,'deleted'=>4,'runs'=>2,'pattern_index'=>1,'cursor'=>null,'error'=>$state==='failed'?'Synthetic original failure':'','updated_at'=>999];
        [$manager,$connection]=setup_job($job);
        check($manager->process_clear_cache_job(1)===$job,$state.' result preserved exactly');
        check($connection->connections===0 && $connection->redis->scans===0,$state.' redelivery makes no Redis I/O');
        check($fixture['writes']===0 && $fixture['deletes']===0,$state.' redelivery does not rewrite stored state');
        check($fixture['actions']===[] && $fixture['schedules']===[],$state.' redelivery neither publishes nor reschedules');
    }
    foreach (['queued','running'] as $state) {
        [$manager,$connection]=setup_job(['patterns'=>['block_cache:*'],'status'=>$state,'deleted'=>0,'runs'=>0,'pattern_index'=>0,'cursor'=>null],2);
        $partial=$manager->process_clear_cache_job(1);
        check($partial['status']==='running' && $partial['cursor']===1,$state.' processes one bounded batch');
        check($partial['deleted']===1 && $partial['runs']===1,$state.' progress is recorded');
        check($fixture['actions']===[] && count($fixture['schedules'])===1,$state.' incomplete work schedules continuation only');
        $fixture['elapsed']=0;
        $finished=$manager->process_clear_cache_job(1);
        check($finished['status']==='complete' && $finished['deleted']===2 && $finished['runs']===2,$state.' continuation completes normally');
        check(count($fixture['actions'])===1 && $fixture['actions'][0]===['ace_rc_cache_cleared','async_all',2],$state.' completion publishes once');
        $connections=$connection->connections;$writes=$fixture['writes'];$schedules=count($fixture['schedules']);
        check($manager->process_clear_cache_job(1)===$finished,$state.' completed redelivery preserves result');
        check($connection->connections===$connections && $fixture['writes']===$writes,$state.' completed redelivery has no Redis or storage I/O');
        check(count($fixture['actions'])===1 && count($fixture['schedules'])===$schedules,$state.' duplicate callback does not republish/reschedule');
    }
    [$manager,$connection]=setup_job(['patterns'=>['block_cache:*'],'status'=>'running','cursor'=>null]);$connection->redis->throw=true;
    $failed=$manager->process_clear_cache_job(1);
    check($failed['status']==='failed' && $failed['error']==='Synthetic scan failure','in-flight failure persists original error');
    $connections=$connection->connections;$writes=$fixture['writes'];
    check($manager->process_clear_cache_job(1)===$failed && $connection->connections===$connections && $fixture['writes']===$writes,'failed callback is not silently retried');
    foreach (['complete','failed'] as $state) {
        [$manager,$connection]=setup_job(['patterns'=>['block_cache:*'],'status'=>$state,'deleted'=>99,'runs'=>7]);
        $queued=$manager->start_async_cache_clear('blocks');
        check($queued['status']==='queued' && $queued['deleted']===0 && $queued['runs']===0,'explicit async clear creates new job after '.$state);
        $finished=$manager->process_clear_cache_job(1);
        check($finished['status']==='complete' && $finished['deleted']===2,'new async clear processes after '.$state);
        check(count($fixture['actions'])===1,'new async clear publishes once after '.$state);
        [$manager,$connection]=setup_job(['patterns'=>['block_cache:*'],'status'=>$state,'deleted'=>99,'runs'=>7]);
        $result=$manager->clear_all_cache();
        check($result['success'] && $result['status']==='complete' && $result['cleared']===8,'manual all clear replaces terminal job after '.$state);
        check(count($fixture['actions'])===1,'manual all clear publishes once after '.$state);
    }
    foreach ([[],['patterns'=>[],'status'=>'complete']] as $missing) {
        [$manager,$connection]=setup_job($missing);
        check($manager->process_clear_cache_job(1)===['status'=>'missing'] && $fixture['deletes']===1 && $connection->connections===0,'missing/malformed pattern cleanup retained');
    }
    echo json_encode(['php'=>PHP_VERSION,'checks'=>$checks,'scope'=>'Actual methods, synthetic WP storage/hooks and Redis. Terminal idempotence, pending/in-flight continuation, failure and explicit new manual jobs. Not a stock WP/Redis worker journey.']),"\n";
} finally { restore_error_handler(); }
