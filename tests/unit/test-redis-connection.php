<?php
/**
 * Redis Connection Tests
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\RedisConnection;

class RedisConnectionTest extends TestCase {
    
    private $connection;
    private $settings;
    
    public function setUp(): void {
        $this->settings = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'ttl' => 3600,
            'enable_tls' => false
        ];
        
        $this->connection = new RedisConnection($this->settings);
    }
    
    public function testConnectionInstantiation() {
        $this->assertInstanceOf(RedisConnection::class, $this->connection);
    }
    
    public function testConnectionStatus() {
        // A unit test must not contact the workstation's real Redis service.
        $connection = new class($this->settings) extends RedisConnection {
            public function get_connection($force_reconnect = false, $bypass_circuit_breaker = false) {
                return null;
            }
        };
        $status = $connection->get_status();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('connected', $status);
        $this->assertArrayHasKey('status', $status);
        $this->assertIsBool($status['connected']);
        $this->assertIsString($status['status']);
        $this->assertFalse($status['connected']);
        $this->assertSame('Connection failed', $status['status']);
    }
    
    public function testMemoryLimitParsing() {
        $reflection = new ReflectionClass($this->connection);
        $method = $reflection->getMethod('parse_memory_limit');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        
        $this->assertEquals(268435456, $method->invokeArgs($this->connection, ['256M']));
        $this->assertEquals(1073741824, $method->invokeArgs($this->connection, ['1G']));
        $this->assertEquals(1024, $method->invokeArgs($this->connection, ['1K']));
        $this->assertEquals(1048576, $method->invokeArgs($this->connection, ['1m']));
    }
    
    public function tearDown(): void {
        if ($this->connection) {
            $this->connection->close_connection();
        }
    }
}
