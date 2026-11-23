<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;

final readonly class BackgroundJobObservationPublisher
{
    public function __construct(
        private RuntimeEventSinkInterface $local,
        private StdoutRuntimeEventSink $worker,
        private RuntimeEventEmitter $controller,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(string $sessionId, string $jobId, bool $started): void
    {
        $event = new RuntimeEvent(
            type: ($started ? RuntimeEventTypeEnum::BackgroundJobStarted : RuntimeEventTypeEnum::BackgroundJobEnded)->value,
            runId: $sessionId,
            seq: 0,
            payload: ['job_id' => $jobId],
        );
        try {
            if ($this->controller->isOpen()) {
                $this->controller->emit($event);
            } elseif ('1' === getenv('HATFIELD_CONSUMER_STDOUT_EVENTS')) {
                $this->worker->emit($event);
            } else {
                $this->local->emit($event);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('background_job.observation_failed', [
                'component' => 'background_job', 'event_type' => 'observation_failed',
                'run_id' => $sessionId, 'session_id' => $sessionId, 'exception_class' => $exception::class,
            ]);
        }
    }
}
