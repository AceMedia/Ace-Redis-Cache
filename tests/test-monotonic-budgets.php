<?php
/** Synthetic clocks, Redis and HTTP. No hosted queue, network or scheduler is used. */
namespace AceMedia\RedisCache;
if (PHP_SAPI !== 'cli') { exit; }
eval('namespace { function wp_next_scheduled() { return false; } }');
define('ABSPATH',__DIR__); define('HOUR_IN_SECONDS',3600);
$fixture=['wall'=>1000.0,'elapsed'=>0.0,'wall_step'=>0,'fetches'=>0,'options'=>[],'scheduled'=>[]]; $checks=0;
function microtime($float=false){return $GLOBALS['fixture']['wall'];}
function hrtime($number=false){return $GLOBALS['fixture']['elapsed']*1e9;}
function time(){return (int)$GLOBALS['fixture']['wall'];}
function get_option($key,$default=false){return $GLOBALS['fixture']['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['fixture']['options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['fixture']['options'][$key]);}
function do_action(...$args){}
function apply_filters($name,$value,...$args){return ['ace_rc_prime_time_budget'=>1,'ace_rc_cpu_count'=>4,'ace_rc_sitemap_prime_batch_size'=>10][$name]??$value;}
function wp_next_scheduled(...$args){return false;}
function wp_schedule_single_event($at,$hook){$GLOBALS['fixture']['scheduled'][]=[$at,$hook];}
function sys_getloadavg(){return [0,0,0];}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function home_url(){return 'https://example.invalid';}
function wp_remote_get($url,$args){$GLOBALS['fixture']['fetches']++;$GLOBALS['fixture']['elapsed']+=($GLOBALS['fixture']['elapsed_step']??2);$GLOBALS['fixture']['wall']+=$GLOBALS['fixture']['wall_step'];return [];}
function is_wp_error($value){return false;}
function advance(){ $GLOBALS['fixture']['elapsed']+=($GLOBALS['fixture']['elapsed_step']??2); $GLOBALS['fixture']['wall']+=$GLOBALS['fixture']['wall_step']; }
function check($ok,$label){if(!$ok)throw new \RuntimeException('FAIL '.$label);$GLOBALS['checks']++;}
function prop($object,$name,$value){$p=(new \ReflectionObject($object))->getProperty($name);if(PHP_VERSION_ID<80100)$p->setAccessible(true);$p->setValue($object,$value);}
class FixtureRedis {
 public $scans=0;
 public function scan(&$cursor,$pattern,$count){$this->scans++;$cursor=$this->scans>=3?0:$this->scans;advance();return ['synthetic-key-'.$this->scans];}
 public function rawCommand($command,...$args){return count($args);}
}
class FixtureConnection {
 public $redis;
 public function __construct(){$this->redis=new FixtureRedis;}
 public function get_connection(){return $this->redis;}
 public function retry_operation($callback){return $callback($this->redis);}
}
set_error_handler(static function($level,$message,$file,$line){if(error_reporting()&$level)throw new \ErrorException($message,0,$level,$file,$line);});
try {
 require getenv('REDIS_BUDGET_MANAGER_SOURCE') ?: dirname(__DIR__).'/includes/class-cache-manager.php';
 require getenv('REDIS_BUDGET_PLUGIN_SOURCE') ?: dirname(__DIR__).'/includes/class-ace-redis-cache.php';
 foreach ([-3600,0,3600] as $step) {
  $fixture=['wall'=>1000.0,'elapsed'=>0.0,'wall_step'=>$step,'fetches'=>0,'options'=>[],'scheduled'=>[]];
  $manager=(new \ReflectionClass(CacheManager::class))->newInstanceWithoutConstructor();$connection=new FixtureConnection;prop($manager,'redis_connection',$connection);
  $fixture['options']['ace_redis_cache_clear_job']=['patterns'=>['fixture:*'],'status'=>'running'];
  $result=$manager->process_clear_cache_job(1);
  check($connection->redis->scans===1,'clear batch respects elapsed budget with wall step '.$step);
  check($result['status']==='running'&&$result['cursor']===1&&$result['deleted']===1,'clear batch saves continuation cursor and count');
  check($result['updated_at']===(int)$fixture['wall'],'clear job update timestamp remains wall-clock time');
  check(count($fixture['scheduled'])===1 && $fixture['scheduled'][0][0]===(int)$fixture['wall']+1,'clear continuation schedule remains wall-clock time');
  $manager->process_clear_cache_job(1);$complete=$manager->process_clear_cache_job(1);
  check($complete['status']==='complete'&&$complete['deleted']===3,'clear job resumes to completion without lost scans');
  $fixture=['wall'=>1000.0,'elapsed'=>0.0,'wall_step'=>$step,'fetches'=>0,'options'=>['ace_rc_sitemap_prime_queue'=>['https://example.invalid/a/'=>1,'https://example.invalid/b/'=>1,'https://example.invalid/c/'=>1]],'scheduled'=>[]];
  $plugin=(new \ReflectionClass(AceRedisCache::class))->newInstanceWithoutConstructor();prop($plugin,'settings',[]);prop($plugin,'cache_manager',null);
  $plugin->run_sitemap_prime_batch();
  check($fixture['fetches']===1,'primer stops at elapsed budget with wall step '.$step);
  check(array_keys($fixture['options']['ace_rc_sitemap_prime_queue'])===['https://example.invalid/b/','https://example.invalid/c/'],'primer preserves remaining URLs');
  check($fixture['scheduled'][0][0]===(int)$fixture['wall']+30,'primer continuation remains wall-clock scheduled');
  $plugin->run_sitemap_prime_batch();$plugin->run_sitemap_prime_batch();
  check($fixture['fetches']===3&&!isset($fixture['options']['ace_rc_sitemap_prime_queue']),'primer completes remaining work exactly once');
 }
 foreach ([-3600,3600] as $step) {
  $fixture=['wall'=>1000.0,'elapsed'=>0.0,'elapsed_step'=>0.2,'wall_step'=>$step,'fetches'=>0,'options'=>[],'scheduled'=>[]];
  $manager=(new \ReflectionClass(CacheManager::class))->newInstanceWithoutConstructor();$connection=new FixtureConnection;prop($manager,'redis_connection',$connection);
  $fixture['options']['ace_redis_cache_clear_job']=['patterns'=>['fixture:*'],'status'=>'running'];$result=$manager->process_clear_cache_job(1);
  check($connection->redis->scans===3&&$result['status']==='complete','fast clear finishes despite wall-clock jump '.$step);
  check($fixture['scheduled']===[],'completed clear does not schedule needless continuation');
  $fixture=['wall'=>1000.0,'elapsed'=>0.0,'elapsed_step'=>0.2,'wall_step'=>$step,'fetches'=>0,'options'=>['ace_rc_sitemap_prime_queue'=>['https://example.invalid/a/'=>1,'https://example.invalid/b/'=>1,'https://example.invalid/c/'=>1]],'scheduled'=>[]];
  $plugin=(new \ReflectionClass(AceRedisCache::class))->newInstanceWithoutConstructor();prop($plugin,'settings',[]);prop($plugin,'cache_manager',null);$plugin->run_sitemap_prime_batch();
  check($fixture['fetches']===3&&!isset($fixture['options']['ace_rc_sitemap_prime_queue']),'fast primer completes despite wall-clock jump '.$step);
  check($fixture['scheduled']===[],'completed primer does not schedule needless continuation');
 }
 echo json_encode(['php'=>PHP_VERSION,'checks'=>$checks,'scope'=>'Actual cache-clear and primer methods with synthetic clocks, options, Redis and captured HTTP. No network, database or hosted scheduler. Wall-clock timestamps retained.']),"\n";
} finally {restore_error_handler();}
