<?php
// config/open_telemetry.php

return [
    'enabled' => env('OTEL_ENABLED', true),
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
    'service_name' => env('OTEL_SERVICE_NAME', 'thinkphp-app'),
    'host_name' => env('OTEL_RESOURCE_ATTRIBUTES_HOST_NAME', php_uname('n')),
];