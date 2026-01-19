<?php

namespace tpOpenTelemetry\middleware;

use tpOpenTelemetry\service\OpenTelemetry;
use think\queue\event\JobProcessing;
use think\queue\event\JobProcessed;
use think\queue\event\JobFailed;

class TraceQueueListener
{
    public function handle($event)
    {
        $class = get_class($event);
        
        if ($class === JobProcessing::class || $event instanceof JobProcessing) {
            $this->handleJobProcessing($event);
        } elseif ($class === JobProcessed::class || $event instanceof JobProcessed) {
            $this->handleJobProcessed($event);
        } elseif ($class === JobFailed::class || $event instanceof JobFailed) {
            $this->handleJobFailed($event);
        }
    }

    protected function handleJobProcessing($event)
    {
        /** @var OpenTelemetry $service */
        $service = app(OpenTelemetry::class);
        $job = $event->job;
        
        $payload = $job->payload('data');
        $traceId = $payload['trace_id'] ?? null;
        $parentSpanId = $payload['span_id'] ?? null;

        if ($traceId) {
            $service->setTraceId($traceId);
        }
        
        $spanName = 'queue.process ' . $job->getName();
        $attributes = [
            'messaging.system' => 'think-queue',
            'messaging.destination' => $job->getQueue(),
            'messaging.operation' => 'process',
            'job.name' => $job->getName(),
            'job.id' => $job->getJobId(),
            'job.attempts' => $job->attempts(),
        ];
        
        $span = $service->startSpan($spanName, $attributes, $parentSpanId);
        $service->setCurrentSpan($span);
    }

    protected function handleJobProcessed($event)
    {
        /** @var OpenTelemetry $service */
        $service = app(OpenTelemetry::class);
        $service->endSpan();
    }

    protected function handleJobFailed($event)
    {
        /** @var OpenTelemetry $service */
        $service = app(OpenTelemetry::class);
        $span = $service->getCurrentSpan();
        
        if ($span) {
            $e = $event->exception;
            if ($e) {
                $span['attributes'][] = [
                    'key' => 'exception.message',
                    'value' => ['stringValue' => $e->getMessage()],
                ];
                $span['attributes'][] = [
                    'key' => 'exception.stacktrace',
                    'value' => ['stringValue' => $e->getTraceAsString()],
                ];
                $span['status'] = [
                    'code' => 2, // ERROR
                    'message' => $e->getMessage(),
                ];
            } else {
                 $span['status'] = [
                    'code' => 2, // ERROR
                    'message' => 'Job Failed Unknown Error',
                ];
            }

            $service->setCurrentSpan($span);
            $service->endSpan();
        }
    }
}
