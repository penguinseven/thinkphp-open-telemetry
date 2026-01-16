# thinkphp-open-telemetry

ThinkPHP 的 OpenTelemetry 扩展包，用于接入 SigNoz 等可观测性平台。

## 功能特性

- HTTP 请求追踪 (Middleware)
- 队列任务追踪 (Queue Listener)
- 自动传播 Trace ID
- 支持 HTTP 长连接 (Keep-Alive) 以提高性能

## 安装

```bash
composer require penguin-seven/thinkphp-open-telemetry
```

## 配置

在 `config/open_telemetry.php` 中配置（如果没有该文件，请创建）：

```php
return [
    'enabled' => env('OTEL_ENABLED', true),
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
    'service_name' => env('OTEL_SERVICE_NAME', 'thinkphp-app'),
];
```

## 使用方法

### 1. HTTP 请求追踪

在 `app/middleware.php` 中注册中间件：

```php
return [
    // ...
    \tpOpenTelemetry\middleware\Telemetry::class,
];
```

### 2. 队列任务追踪 (TraceQueueListener)

要开启队列任务的追踪，需要在项目的 `config/event.php` 中配置事件监听器。

`TraceQueueListener` 会自动监听任务的开始、结束和失败事件，并生成相应的 Span。

打开 `config/event.php`，在 `listen` 数组中添加以下配置：

```php
return [
    // ...
    'listen' => [
        // ... 其他监听器
        
        // OpenTelemetry 队列追踪
        'think\queue\event\JobProcessing' => ['tpOpenTelemetry\middleware\TraceQueueListener'],
        'think\queue\event\JobProcessed'  => ['tpOpenTelemetry\middleware\TraceQueueListener'],
        'think\queue\event\JobFailed'     => ['tpOpenTelemetry\middleware\TraceQueueListener'],
    ],
];
```

配置完成后，ThinkPHP Queue Worker 在处理任务时会自动上报 Trace 数据。
由于使用了 HTTP 长连接，Worker 进程在长驻内存运行时会复用与 SigNoz 的连接，确保高性能。

### 3. 手动埋点

```php
use tpOpenTelemetry\service\OpenTelemetry;

$otel = app(OpenTelemetry::class);

// 开始一个 Span
$span = $otel->startSpan('my-custom-operation');

try {
    // 业务逻辑...
    $otel->log('INFO', 'Something happened');
} catch (\Exception $e) {
    // 记录异常
} finally {
    // 结束 Span
    $otel->endSpan($span);
}
```
