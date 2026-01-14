<?php

namespace Tests;

use tpOpenTelemetry\driver\TraceFileLog;
use tpOpenTelemetry\service\OpenTelemetry;
use think\App;
use think\Container;

class TraceFileLogTest extends TestCase
{
    public function testSave()
    {
        // Mock OpenTelemetry Service
        $openTelemetryMock = $this->createMock(OpenTelemetry::class);
        $openTelemetryMock->expects($this->atLeastOnce())
            ->method('log')
            ->with(
                $this->stringContains('info'),
                $this->stringContains('test message')
            );

        // Bind OpenTelemetry to Container
        Container::getInstance()->instance(OpenTelemetry::class, $openTelemetryMock);

        // Mock App
        $app = $this->createMock(App::class);
        $app->method('getRuntimePath')->willReturn(sys_get_temp_dir() . '/');

        // Config for File driver
        $config = [
            'path' => sys_get_temp_dir() . '/logs/',
            'time_format' => 'c',
            'format' => '[%s][%s] %s',
            'json' => false,
            'apart_level' => [],
        ];

        // Instantiate TraceFileLog
        // Note: TraceFileLog constructor calls app(OpenTelemetry::class)
        $driver = new TraceFileLog($app, $config);

        // Test save method
        $log = [
            'info' => ['test message']
        ];
        
        // We need to suppress errors or ensure the directory is writable. 
        // sys_get_temp_dir() should be writable.
        // Also TraceFileLog writes to file, we might want to clean up or mock the file writing part if possible.
        // But File driver writes to file system. 
        // For unit test, we care about OpenTelemetry log call.
        
        $result = $driver->save($log);

        $this->assertTrue($result);
    }
}
