<?php

namespace Tests;

use GuzzleHttp\Client;
use tpOpenTelemetry\service\OpenTelemetry;
use ReflectionClass;

class IntegrationTest extends TestCase
{
    public function testRealConnectionReuse()
    {
        // Get the service (Singleton)
        $service = $this->app->make(OpenTelemetry::class);

        // Verify configuration
        $reflection = new ReflectionClass($service);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        /** @var Client $client */
        $client = $clientProp->getValue($service);
        $config = $client->getConfig();

        $this->assertEquals('keep-alive', $config['headers']['Connection'] ?? null, 'Keep-Alive header should be present');

        // We can optionally verify http_version if we set it (which is good practice for keep-alive)
        // $this->assertEquals('1.1', $config['version'] ?? '1.1', 'HTTP Version should be 1.1');

        echo "\n[IntegrationTest] Sending real requests to " . config('open_telemetry.endpoint') . " ...\n";

        // Request 1: Start and End Span
        $span = $service->startSpan('integration-test-span-1');
        $span['attributes'][] = ['key' => 'test.id', 'value' => ['intValue' => 1]];
        $service->endSpan($span);
        
        // Request 2: Log
        $service->log('INFO', 'Integration test log message', ['test.id' => 2]);

        // Request 3: Another Span
        $span2 = $service->startSpan('integration-test-span-2');
        $service->endSpan($span2);
        
        $this->assertNotEquals($span['span_id'], $span2['span_id'], 'Span IDs should be different for different spans');
        
        // Verify Singleton/Reuse again just to be sure
        $service2 = $this->app->make(OpenTelemetry::class);
        $this->assertSame($service, $service2);
        
        $client2 = $clientProp->getValue($service2);
        $this->assertSame($client, $client2, 'Client should be reused across requests');
        
        echo "[IntegrationTest] Requests initiated. (Check SigNoz or Logs for results)\n";
    }
}
