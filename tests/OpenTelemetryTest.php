<?php

namespace Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use tpOpenTelemetry\service\OpenTelemetry;

class OpenTelemetryTest extends TestCase
{
    public function testStartSpan()
    {
        $service = new OpenTelemetry();
        $span = $service->startSpan('test-span');

        $this->assertIsArray($span);
        $this->assertArrayHasKey('trace_id', $span);
        $this->assertArrayHasKey('span_id', $span);
        $this->assertEquals('test-span', $span['name']);
        $this->assertEquals(1, $span['kind']); // SERVER
        $this->assertArrayHasKey('start_time_unix_nano', $span);
    }

    public function testEndSpan()
    {
        // Guzzle Client uses magic methods for post, so we need to add it to the mock
        $mockClient = $this->getMockBuilder(Client::class)
                           ->addMethods(['post'])
                           ->getMock();

        $mockClient->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('/v1/traces'),
                $this->callback(function($options) {
                    $json = $options['json'];
                    return isset($json['resource_spans'][0]['scope_spans'][0]['spans'][0]);
                })
            )
            ->willReturn(new Response(200));

        $service = new OpenTelemetry($mockClient);
        
        $span = $service->startSpan('test-span');
        $service->endSpan($span);
    }

    public function testLog()
    {
        $mockClient = $this->getMockBuilder(Client::class)
                           ->addMethods(['post'])
                           ->getMock();

        $mockClient->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('/v1/logs'),
                $this->callback(function($options) {
                    $json = $options['json'];
                    return isset($json['resource_logs'][0]['scope_logs'][0]['log_records'][0]);
                })
            )
            ->willReturn(new Response(200));

        $service = new OpenTelemetry($mockClient);
        
        $service->log('INFO', 'Test log message');
    }
}
