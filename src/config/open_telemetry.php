<?php
// config/open_telemetry.php
use app\common\constants\GeneralConstants;

return [
    'enabled' => env('OTEL_ENABLED', true),
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
    'service_name' => env('OTEL_SERVICE_NAME', 'thinkphp-app'),
];