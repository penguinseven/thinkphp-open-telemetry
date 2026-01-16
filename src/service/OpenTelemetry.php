<?php
// app/common/service/OpenTelemetryService.php
namespace tpOpenTelemetry\service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LogTrace\TraceId;
use think\facade\Log;

class OpenTelemetry
{
    private $client;
    private $endpoint;
    private $serviceName;
    private $enabled;

    private $tracesId;
    private $spanId;
    private $currentSpan;

    public function __construct($client = null)
    {
        $this->enabled = config('open_telemetry.enabled', true);
        $this->endpoint = config('open_telemetry.endpoint', 'http://localhost:4318');
        $this->serviceName = config('open_telemetry.service_name', 'thinkphp-api');

        $this->setTraceId(TraceId::getTraceId());

        $this->client = $client ?: new Client([
            'timeout' => 1.0, // Increase timeout for better reliability
            'headers' => [
                'Content-Type' => 'application/json',
                'Connection' => 'keep-alive',
            ],
            'http_version' => '1.1',
        ]);
    }

    /**
     * 发送追踪数据到Signoz
     */
    public function sendTrace($spanData)
    {
        if (!$this->enabled) {
            return;
        }

        $traceData = [
            'resource_spans' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $this->serviceName],
                            ],
                            [
                                'key' => 'telemetry.sdk.name',
                                'value' => ['stringValue' => 'opentelemetry'],
                            ],
                            [
                                'key' => 'telemetry.sdk.language',
                                'value' => ['stringValue' => 'php'],
                            ],
                            [
                                'key' => 'telemetry.sdk.version',
                                'value' => ['stringValue' => '1.0'],
                            ],
                            [
                                'key' => 'process.pid',
                                'value' => ['intValue' => getmypid()],
                            ],
                            [
                                'key' => 'host.name',
                                'value' => ['stringValue' => php_uname('n')],
                            ],
                            [
                                'key' => 'host.arch',
                                'value' => ['stringValue' => php_uname('m')],
                            ],
                            [
                                'key' => 'os.type',
                                'value' => ['stringValue' => php_uname('s')],
                            ],
                            [
                                'key' => 'os.version',
                                'value' => ['stringValue' => php_uname('r')],
                            ],
                            [
                                'key' => 'php.version',
                                'value' => ['stringValue' => phpversion()],
                            ],
                            [
                                'key' => 'http.scheme',
                                'value' => ['stringValue' => request()->isSsl() ? 'https' : 'http'],
                            ],
                        ],
                    ],
                    'scope_spans' => [
                        [
                            'scope' => [
                                'name' => 'yiyun-signoz-sdk',
                                'version' => '1.0.0',
                            ],
                            'spans' => [$spanData],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->client->post($this->endpoint . '/v1/traces', [
                'json' => $traceData,
            ]);
        } catch (GuzzleException $e) {
            Log::error('Failed to send telemetry data to: ' . $this->endpoint . ' Error: ' . $e->getMessage());
        }
    }

    /**
     * 发送日志数据到SigNoZ
     *
     * @param $logData
     * @return void
     */
    public function sendLog($logData)
    {
        if (!$this->enabled) {
            return;
        }

        $logsData = [
            'resource_logs' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $this->serviceName],
                            ],
                        ],
                    ],
                    'scope_logs' => [
                        [
                            'scope' => [
                                'name' => 'yiyun-signoz-sdk',
                                'version' => '1.0.0',
                            ],
                            'log_records' => [$logData],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->client->post($this->endpoint . '/v1/logs', [
                'json' => $logsData,
            ]);
        } catch (GuzzleException $e) {
            Log::error('Failed to send log data to: ' . $this->endpoint . ' Error: ' . $e->getMessage());
        }
    }

    /**
     * 开始一个Span
     */
    public function startSpan($name, $attributes = [], $parentSpanId = null)
    {
        if (!$this->enabled) {
            return null;
        }

        // Generate a new Span ID for every startSpan call
        $this->spanId = bin2hex(random_bytes(8));

        $spanData = [
            'trace_id'             => $this->getTraceId(),
            'span_id'              => $this->spanId,
            'name'                 => $name,
            'kind'                 => 1, // SERVER
            'start_time_unix_nano' => (int)(microtime(true) * 1000000000),
            'end_time_unix_nano'   => 0, // Will be set when ending the span
            'attributes'           => $this->formatAttributes($attributes),
            'events'               => [], // Array of span events
            'status'               => [
                'code' => 1, // UNSET
            ],
        ];

        if ($parentSpanId) {
            $spanData['parent_span_id'] = $parentSpanId;
        }

        // 保存到类属性中，以便后续结束span
        $spanData['start_time'] = microtime(true);

        return $spanData;
    }

    public function getCurrentSpan()
    {
        return $this->currentSpan;
    }

    public function setCurrentSpan($span)
    {
        $this->currentSpan = $span;
    }

    /**
     * 结束一个Span
     */
    public function endSpan($spanData = null)
    {
        if ($spanData === null) {
            $spanData = $this->currentSpan;
            $this->currentSpan = null;
        }

        if (!$this->enabled || !$spanData) {
            return;
        }

        $spanData['end_time_unix_nano'] = (int)(microtime(true) * 1000000000);
        $duration = ($spanData['end_time_unix_nano'] - $spanData['start_time_unix_nano']) / 1000000; // Duration in milliseconds
        $spanData['attributes'][] = [
            'key' => 'http.duration_ms',
            'value' => ['doubleValue' => $duration],
        ];

        $this->sendTrace($spanData);
    }

    /**
     * 记录一个事件到Span
     */
    public function addEventToSpan(&$spanData, $name, $timestamp = null, $attributes = [])
    {
        if (!$this->enabled || !$spanData) {
            return;
        }

        $event = [
            'name' => $name,
            'time_unix_nano' => $timestamp ? (int)($timestamp * 1000000000) : (int)(microtime(true) * 1000000000),
            'attributes' => $this->formatAttributes($attributes),
        ];

        $spanData['events'][] = $event;
    }

    /**
     * 记录日志
     *
     * @param       $severityText
     * @param       $body
     * @param array $attributes
     * @return void
     */
    public function log($severityText, $body, array $attributes = [])
    {
        if (!$this->enabled) {
            return;
        }

        // 准备日志属性
        $request = request();
        $attributes = array_merge([
            'level'        => $severityText,
            'host.name'    => php_uname('n'),
            'php.version'  => phpversion(),
            'service.name' => $this->serviceName,
            'http.method' => $request->method(),
            'http.url' => $request->url(),
            'http.client_ip' => $request->ip(),
            'user_agent.original' => $request->header('user-agent') ?: 'unknown',
        ], $attributes);

        $logData = [
            'trace_id'        => $this->getTraceId(),
            'span_id'         => $this->getSpanId(),
            'time_unix_nano'  => (int)(microtime(true) * 1000000000),
            'severity_text'   => $severityText,
            'severity_number' => $this->getSeverityNumber($severityText),
            'body'            => ['stringValue' => $body],
            'attributes'      => $this->formatAttributes($attributes),
        ];

        $this->sendLog($logData);
    }

    /**
     * 获取日志级别数字
     */
    private function getSeverityNumber($severityText)
    {
        $severityMap = [
            'TRACE' => 1,
            'DEBUG' => 5,
            'INFO' => 9,
            'WARN' => 13,
            'ERROR' => 17,
            'FATAL' => 21,
        ];

        $level = strtoupper($severityText);
        return $severityMap[$level] ?? 9; // Default to INFO
    }

    /**
     * 格式化属性
     */
    private function formatAttributes($attributes)
    {
        $formatted = [];

        foreach ($attributes as $key => $value) {
            $attr = ['key' => $key];

            if (is_string($value)) {
                $attr['value'] = ['stringValue' => $value];
            } elseif (is_int($value)) {
                $attr['value'] = ['intValue' => $value];
            } elseif (is_float($value)) {
                $attr['value'] = ['doubleValue' => $value];
            } elseif (is_bool($value)) {
                $attr['value'] = ['boolValue' => $value];
            } elseif (is_array($value) || is_object($value)) {
                // 将数组或对象转换为JSON字符串
                $jsonValue = json_encode($value);
                $attr['value'] = ['stringValue' => $jsonValue !== false ? $jsonValue : 'null'];
            } else {
                // 处理其他类型，如null或资源
                $strValue = $value !== null ? strval($value) : 'null';
                $attr['value'] = ['stringValue' => $strValue];
            }

            $formatted[] = $attr;
        }

        return $formatted;
    }

    /**
     * 生成Span ID (16进制字符串，16个字符)
     */
    private function getSpanId()
    {
        return  $this->spanId = $this->spanId ?: bin2hex(random_bytes(8));
    }

    /**
     * 生成Trace ID (16进制字符串，32个字符)
     */
    public function getTraceId()
    {
        return $this->tracesId;
    }

    /**
     * @param string $traceId
     * @return void
     */
    public function setTraceId(string $traceId)
    {
        // 548f1c24-b23b-466f-9e2a-20a1879d8c60 去掉所有中画线
        $this->tracesId = str_replace('-', '', strtolower($traceId));
    }
}