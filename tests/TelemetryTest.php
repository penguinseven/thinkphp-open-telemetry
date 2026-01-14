<?php

namespace Tests;

use tpOpenTelemetry\middleware\Telemetry;
use tpOpenTelemetry\service\OpenTelemetry;
use think\Request;
use think\Response;
use think\Container;

class TelemetryTest extends TestCase
{
    public function testHandle()
    {
        // Mock OpenTelemetry service
        $openTelemetryMock = $this->createMock(OpenTelemetry::class);
        $openTelemetryMock->expects($this->once())
            ->method('startSpan')
            ->willReturn(['trace_id' => '123', 'span_id' => '456', 'attributes' => []]);
        
        $openTelemetryMock->expects($this->once())
            ->method('endSpan')
            ->with($this->isType('array'));

        // Bind mock to container
        Container::getInstance()->instance(OpenTelemetry::class, $openTelemetryMock);

        // Mock Request
        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('baseUrl')->willReturn('/test');
        $request->expects($this->any())->method('method')->willReturn('GET');
        $request->expects($this->any())->method('url')->willReturn('http://localhost/test');
        $request->expects($this->any())->method('ip')->willReturn('127.0.0.1');
        $request->expects($this->any())->method('header')->willReturn('PHPUnit');
        $request->expects($this->any())->method('host')->willReturn('localhost');
        $request->expects($this->any())->method('isSsl')->willReturn(false);
        $request->expects($this->any())->method('domain')->willReturn('localhost');
        
        // Mock Response
        $response = $this->createMock(Response::class);
        $response->expects($this->any())->method('getCode')->willReturn(200);

        // Next closure
        $next = function ($req) use ($response) {
            return $response;
        };

        $middleware = new Telemetry();
        $res = $middleware->handle($request, $next);

        $this->assertSame($response, $res);
    }
}
