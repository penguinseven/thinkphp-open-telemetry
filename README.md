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


### 4. Infrastructure 监控对接

SigNoz 的 Infrastructure 监控需要采集服务器（主机）的指标（如 CPU、内存、磁盘 IO 等）。这不能仅通过 PHP 代码完成，需要在服务器上运行 **OpenTelemetry Collector**。

本扩展包会自动上报 `host.name` 属性，使得 SigNoz 可以将 PHP 的 Trace 数据与 Infrastructure 指标关联起来。

- 对接步骤

1. **在服务器上安装 OpenTelemetry Collector**
   
   请参考 SigNoz 官方文档安装 Collector（通常作为 Agent 运行在每台服务器上）：
   [SigNoz - Send Host Metrics](https://signoz.io/docs/userguide/send-host-metrics/)

2. **确保 Host Name 一致**

   本扩展包默认使用 `php_uname('n')` 作为 `host.name` 上报。
   请确保您服务器上运行的 OTel Collector 配置中，`hostmetrics` 接收器采集的主机名与 PHP 所在环境的主机名一致。

   如果运行在 Docker 容器中，建议将宿主机的主机名挂载或传递给容器，或者在 `config/open_telemetry.php` 中手动指定（需修改代码支持自定义 resource attributes，目前默认使用系统主机名）。

3. **应用层资源监控**

   本扩展包会在每个 Root Span（请求入口）结束时，自动记录当前 PHP 进程的内存使用情况：
   - `process.memory.usage`: 当前分配的内存量 (bytes)
   - `process.memory.peak_usage`: 内存使用峰值 (bytes)

   您可以在 SigNoz 的 Trace 详情中查看这些属性，或基于这些属性创建自定义仪表盘。

