<?php
namespace tpOpenTelemetry\driver;

use DateTime;
use DateTimeZone;
use LogTrace\TraceId;
use think\App;
use think\contract\LogHandlerInterface;
use think\log\driver\File;
use tpOpenTelemetry\service\OpenTelemetry;

/**
 * TraceFileLog类，将traceId添加到日志中
 * 直接继承File类并实现LogHandlerInterface接口
 */
class TraceFileLog extends File implements LogHandlerInterface
{
    /**
     * @var
     */
    protected $openTelemetryService;

    public function __construct(App $app, array $config = [])
    {
        parent::__construct($app, $config);
        $this->openTelemetryService = app(OpenTelemetry::class);
    }

    /**
     * 重写写入方法，添加traceId支持
     * @param array $log
     * @return bool
     * @throws \DateInvalidTimeZoneException
     * @throws \ErrorException
     */
    public function save(array $log): bool
    {
        $destination = $this->getMasterLogFile();

        $path = dirname($destination);
        // 解决高并发导致mkdir(): File exists 报错问题
        set_error_handler(function ($type, $msg, $file, $line) use ($path) {
            if (!is_dir($path)) {
                throw new \ErrorException("[$path] $msg", 0, $type, $file, $line);
            }
        });

        !is_dir($path) && mkdir($path, 0755, true);

        restore_error_handler();

        $info = [];

        // 日志信息封装
        $time = DateTime::createFromFormat('0.u00 U', microtime())
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format($this->config['time_format']);

        foreach ($log as $type => $val) {
            $message = [];
            foreach ($val as $msg) {
                if (!is_string($msg) && !is_array($msg)) {
                    $msg = var_export($msg, true);
                }

                $traceId = TraceId::getTraceId();

                // 推送日志到SigNoZ
                $this->pushLogTo($type, $msg);

                $message[] = $this->config['json'] ?
                    json_encode(['time' => $time, 'type' => $type, 'msg' => $msg, 'traceId' => $traceId], $this->config['json_options']) :
                    sprintf($this->config['format'], $time, $type, $msg);
            }

            if (true === $this->config['apart_level'] || in_array($type, $this->config['apart_level'])) {
                // 独立记录的日志级别
                $filename = $this->getApartLevelFile($path, $type);
                $this->write($message, $filename);
                continue;
            }

            $info[$type] = $message;
        }

        if ($info) {
            return $this->write($info, $destination);
        }
        
        return true;
    }

    /**
     * 推送日志到SigNoZ
     * 
     * @param string $level
     * @param        $message
     * @return void
     */
    protected function pushLogTo(string $level, $message)
    {
        try {
            // 格式化日志消息
            $logBody = is_array($message) ? json_encode($message) : (string)$message;

            // 发送日志到SigNoZ
            $this->openTelemetryService->log($level, $logBody);
        } catch (\Exception $e) {
            // 如果推送失败，记录错误但不影响主流程
            error_log('Failed to push log to SigNoZ: ' . $e->getMessage());
        }
    }
}