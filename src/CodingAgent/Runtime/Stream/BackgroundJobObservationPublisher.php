<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;

final readonly class BackgroundJobObservationPublisher
{
    public function __construct(
        private RuntimeEventSinkInterface $local,
        private StdoutRuntimeEventSink $worker,
        private RuntimeEventEmitter $controller,
        private LoggerInterface $logger,
        private HatfieldSessionStore $sessions,
        private RunOperationalProjectionRepository $relationships,
        private AgentChildRunDirectory $children,
    ) {
    }

    public function publish(string $sessionId, string $jobId, bool $started): void
    {
        try {
            $root = $this->rootSession($sessionId);
            if (null === $root) {
                return;
            }
            $event = new RuntimeEvent(
                type: ($started ? RuntimeEventTypeEnum::BackgroundJobStarted : RuntimeEventTypeEnum::BackgroundJobEnded)->value,
                runId: $root,
                seq: 0,
                payload: ['job_id' => $jobId, 'owner_run_id' => $sessionId],
            );
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

    private function rootSession(string $runId): ?string
    {
        $visited = [];
        while ('' !== $runId && !isset($visited[$runId])) {
            if ((string) (int) $runId === $runId && $this->sessions->exists($runId)) {
                return $runId;
            }
            $visited[$runId] = true;
            // Active runs have an indexed parent edge. Consult artifact storage
            // only when that disposable projection is absent (e.g. after exit).
            $relationship = $this->relationships->findRelationship($runId);
            $parent = null !== $relationship ? $relationship['parent_run_id'] : $this->children->locate($runId)?->parentRunId;
            if (null === $parent) {
                return null;
            }
            $runId = $parent;
        }

        return null;
    }
}
