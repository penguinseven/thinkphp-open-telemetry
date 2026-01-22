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
    // 可选：指定主机名，用于与 Infrastructure 监控关联。默认使用 php_uname('n')
    'host_name' => env('OTEL_RESOURCE_ATTRIBUTES_HOST_NAME', php_uname('n')),
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

### 对接步骤

1. **部署 OpenTelemetry Collector (Host Metrics)**
   
   为了方便部署，本项目提供了 Docker Compose 示例配置。
   
   请查看目录：`examples/host-metrics/`
   
   - `otel-collector-config.yaml`: 包含 `hostmetrics` 接收器的完整配置
   - `docker-compose.yaml`: 一键启动 Collector 的配置
   
   **快速启动：**
   ```bash
   cd examples/host-metrics
   docker-compose up -d
   ```
   
   该 Collector 会采集主机的 CPU、内存、磁盘等指标，并上报给 SigNoz。
   *注意：请修改 `otel-collector-config.yaml` 中的 endpoint 指向您的 SigNoz 服务地址。*

2. **确保 Host Name 一致**

   SigNoz 通过 `host.name` 关联 Trace 和 Host Metrics。
   
   - **Host Metrics Collector**: 默认会上报宿主机的主机名。
   - **PHP 应用**: 默认使用 `php_uname('n')`。
   
   如果 PHP 运行在 Docker 容器中，容器的主机名（Container ID）通常与宿主机不同。
   为了实现关联，请在 `config/open_telemetry.php` 中配置 `host_name`，使其与宿主机一致：
   
   ```php
   'host_name' => 'your-host-name', // 或者通过环境变量 OTEL_RESOURCE_ATTRIBUTES_HOST_NAME 注入
   ```

3. **应用层资源监控**

   本扩展包会在每个 Root Span（请求入口）结束时，自动记录当前 PHP 进程的内存使用情况：
   - `process.memory.usage`: 当前分配的内存量 (bytes)
   - `process.memory.peak_usage`: 内存使用峰值 (bytes)

   您可以在 SigNoz 的 Trace 详情中查看这些属性，或基于这些属性创建自定义仪表盘。

