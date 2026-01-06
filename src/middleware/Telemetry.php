<?php
namespace tpOpenTelemetry\middleware;

use tpOpenTelemetry\service\OpenTelemetry;
use think\Request;
use think\Response;

class Telemetry
{
    /**
     * @param Request  $request
     * @param \Closure $next
     * @return Response
     * @throws \Throwable
     */
    public function handle(Request $request, \Closure $next)
    {
        $openTelemetryService = app(OpenTelemetry::class);

        $span = $openTelemetryService->startSpan($request->baseUrl() . ' Telemetry.php' . $request->method(), [
            'http.method'         => $request->method(),
            'http.url'            => $request->url(),
            'http.route'          => $request->baseUrl(),
            'http.client_ip'      => $request->ip(),
            'user_agent.original' => $request->header('user-agent') ?: 'unknown',
            'http.target'         => $request->url(),
            'http.host'           => $request->host(),
            'http.scheme'         => $request->isSsl() ? 'https' : 'http',
            'http.flavor'         => '1.1',
            'server.address'      => $request->domain(),
            'url.full'            => $request->url(),
            'url.path'            => $request->baseUrl(),
            'user.id'             => $request->visitor['user_id'] ?? null,  // 如果有用户ID的话
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($span) {
                $span['attributes'][] = [
                    'key' => 'http.status_code',
                    'value' => ['intValue' => $response->getCode()]
                ];
                
                // 根据状态码设置span状态
                if ($response->getCode() >= 400 && $response->getCode() < 600) {
                    $span['status'] = [
                        'code' => 2, // ERROR
                        'message' => 'HTTP Error ' . $response->getCode()
                    ];
                } else {
                    $span['status'] = [
                        'code' => 0, // OK
                    ];
                }
            }

            return $response;
        } catch (\Throwable $e) {
            if ($span) {
                $span['attributes'][] = [
                    'key' => 'error.type',
                    'value' => ['stringValue' => get_class($e)]
                ];
                $span['attributes'][] = [
                    'key' => 'error.message',
                    'value' => ['stringValue' => $e->getMessage()]
                ];
                $span['attributes'][] = [
                    'key' => 'error.stack',
                    'value' => ['stringValue' => $e->getTraceAsString()]
                ];
                
                $span['status'] = [
                    'code' => 2, // ERROR
                    'message' => $e->getMessage()
                ];
                
                // 记录错误日志
                $openTelemetryService->log('ERROR', $e->getMessage(), [
                    'error.type' => get_class($e),
                    'error.file' => $e->getFile(),
                    'error.line' => $e->getLine(),
                    'http.route' => $request->baseUrl(),
                    'http.method' => $request->method(),
                ]);
            }
            throw $e;
        } finally {
            if ($span) {
                $openTelemetryService->endSpan($span);
            }
        }
    }
}