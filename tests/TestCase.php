<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use think\Container;
use think\Config;
use think\Request;
use think\Log;

class TestCase extends BaseTestCase
{
    protected $app;

    protected function setUp(): void
    {
        parent::setUp();

        // Load ThinkPHP helpers manually since they are not autoloaded by composer in this context
        $helperPath = __DIR__ . '/../vendor/topthink/framework/src/helper.php';
        if (file_exists($helperPath)) {
            require_once $helperPath;
        }
        
        // Ensure Container instance is set
        $this->app = Container::getInstance();
        
        $container = $this->app;
        
        // Mock Config
        // In ThinkPHP 6, Config is a class, not just a simple array.
        // We can mock the 'config' service.
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(function($key, $default = null) {
            $configs = [
                'open_telemetry.enabled' => true,
                'open_telemetry.endpoint' => 'http://localhost:4318',
                'open_telemetry.service_name' => 'test-service',
            ];
            // Handle 'open_telemetry.enabled' vs 'open_telemetry'
            if (isset($configs[$key])) {
                return $configs[$key];
            }
            return $default;
        });
        $container->instance('config', $config);

        // Mock Request
        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('isSsl')->willReturn(false);
        $request->expects($this->any())->method('method')->willReturn('GET');
        $request->expects($this->any())->method('url')->willReturn('http://localhost/test');
        $request->expects($this->any())->method('baseUrl')->willReturn('/test');
        $request->expects($this->any())->method('ip')->willReturn('127.0.0.1');
        $request->expects($this->any())->method('host')->willReturn('localhost');
        $request->expects($this->any())->method('domain')->willReturn('localhost');
        $request->expects($this->any())->method('header')->willReturn('PHPUnit');
        $container->instance('request', $request);

        // Mock Log (Facade underlying instance)
        $log = $this->createMock(Log::class);
        $container->instance('log', $log);
    }

    protected function tearDown(): void
    {
        // Clean up container if needed, though usually not strictly required for simple tests
        // Container::setInstance(null); 
        parent::tearDown();
    }
}
