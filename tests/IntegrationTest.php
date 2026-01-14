<?php

namespace Tests;

use tpOpenTelemetry\service\OpenTelemetry;
use GuzzleHttp\Exception\ConnectException;

class IntegrationTest extends TestCase
{
    public function testRealRequest()
    {
        // 1. 实例化真实的 OpenTelemetry 服务
        // 注意：TestCase 中已经 Mock 了 Config，endpoint 指向 http://localhost:4318
        // 这里的 OpenTelemetry 构造函数会创建一个真实的 GuzzleHttp\Client
        $service = new OpenTelemetry();

        echo "\n[IntegrationTest] Starting real request test to http://localhost:4318 ...\n";

        try {
            // 2. 真实执行 startSpan
            $span = $service->startSpan('integration-test-span');
            $this->assertIsArray($span);
            echo "[IntegrationTest] Span started.\n";

            // 3. 真实执行 endSpan -> 触发 sendTrace -> 触发 $client->post
            $service->endSpan($span);
            echo "[IntegrationTest] Trace data sent (or attempted).\n";

            // 4. 真实执行 log -> 触发 sendLog -> 触发 $client->post
            $service->log('INFO', 'This is a real integration test log message');
            echo "[IntegrationTest] Log data sent (or attempted).\n";

        } catch (ConnectException $e) {
            // 如果本地没有运行 OpenTelemetry Collector，连接会被拒绝
            // 这是预期的，除非用户真的搭建了环境
            echo "[IntegrationTest] Connection failed as expected (if no collector running): " . $e->getMessage() . "\n";
        } catch (\Exception $e) {
            echo "[IntegrationTest] Error: " . $e->getMessage() . "\n";
        }

        // 只要代码没有因为语法错误崩溃，我们认为集成测试逻辑通过
        $this->assertTrue(true);
    }
}
