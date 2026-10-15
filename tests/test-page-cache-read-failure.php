<?php
// Standalone fault injection: never connects to Redis or changes a running service.
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
require dirname(__DIR__) . '/includes/class-cache-manager.php';
$check=static function($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";};
$connect=static function($client){return new class($client){
    public $calls=0;
    private $client;
    public function __construct($client){$this->client=$client;}
    public function get_connection(){++$this->calls;return $this->client;}
};};
foreach ([1,2,3] as $failAt) {
    $client=new class($failAt) {
        public $calls=0;
        private $failAt;
        public function __construct($n){$this->failAt=$n;}
        public function get($key){if(++$this->calls===$this->failAt)throw new RedisException('Injected read failure');return false;}
    };
    $connection=$connect($client);
    $manager=new AceMedia\RedisCache\CacheManager($connection,['enable_minification'=>1]);
    $check(null===$manager->get_with_minification('fixture',false),"read failure at lookup $failAt becomes a miss");
    $check(null===$manager->get_with_minification('fixture',false) && false===$manager->set_with_minification('fixture','Fresh rendered page') && $client->calls===$failAt && $connection->calls===1,'no repeated page-cache I/O after failure');
}
foreach(['page_cache_min:fixture','page_cache:fixture','fixture'] as $hitKey){
    $client=new class($hitKey) {
        private $hitKey;
        public function __construct($key){$this->hitKey=$key;}
        public function get($key){return $key===$this->hitKey ? 'gz6:'.gzencode('Healthy cached HTML') : false;}
    };
    $manager=new AceMedia\RedisCache\CacheManager($connect($client),['enable_minification'=>1]);
    $check('Healthy cached HTML'===$manager->get_with_minification('fixture',false),'healthy minified/original/legacy compressed response preserved: '.$hitKey);
}
