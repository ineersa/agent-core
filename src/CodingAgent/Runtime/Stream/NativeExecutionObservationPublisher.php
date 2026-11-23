<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Event\RunEventPublishedEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class NativeExecutionObservationPublisher
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
            $this->emit($event);
        } catch (\Throwable $exception) {
            $this->logger->warning('background_job.observation_failed', [
                'component' => 'background_job', 'event_type' => 'observation_failed',
                'run_id' => $sessionId, 'session_id' => $sessionId, 'exception_class' => $exception::class,
            ]);
        }
    }

    #[AsEventListener]
    public function onRunPublished(RunEventPublishedEvent $published): void
    {
        $event = $published->event;
        $type = match ($event->type) {
            RunEventTypeEnum::RunStarted->value => RuntimeEventTypeEnum::ChildExecutionStarted,
            RunEventTypeEnum::TurnAdvanced->value => true === ($event->payload['resumes_execution'] ?? false) ? RuntimeEventTypeEnum::ChildExecutionStarted : RuntimeEventTypeEnum::ChildTurnObservation,
            RunEventTypeEnum::WaitingHuman->value => RuntimeEventTypeEnum::ChildTurnObservation,
            RunEventTypeEnum::AgentCommandApplied->value => 'human_response' === ($event->payload['kind'] ?? null) ? RuntimeEventTypeEnum::ChildTurnObservation : null,
            RunEventTypeEnum::AgentEnd->value => RuntimeEventTypeEnum::ChildExecutionEnded,
            default => null,
        };
        if (null === $type || $event->seq <= 0) {
            return;
        }
        try {
            if ((string) (int) $event->runId === $event->runId && $this->sessions->exists($event->runId)) {
                return;
            }
            $parent = $this->parentRunId($event->runId);
            $root = null !== $parent ? $this->rootSession($parent) : null;
            if (null === $parent || null === $root || $root === $event->runId) {
                return;
            }
            $fields = [];
            if (RuntimeEventTypeEnum::ChildTurnObservation === $type) {
                $fields['phase'] = match ($event->type) {
                    RunEventTypeEnum::TurnAdvanced->value => 'turn',
                    RunEventTypeEnum::WaitingHuman->value => isset($event->payload['approval_context']) ? 'permission' : 'question',
                    default => 'resolved',
                };
                if ('turn' !== $fields['phase']) {
                    $requestId = $event->payload['question_id'] ?? null;
                    if (!\is_string($requestId) || '' === $requestId) {
                        return;
                    }
                    $fields['request_id'] = hash('sha256', $requestId);
                }
            }
            if (RuntimeEventTypeEnum::ChildExecutionEnded === $type) {
                $fields['outcome'] = match (RuntimeEventTranslator::agentEndType($event->payload['reason'] ?? null)) {
                    RuntimeEventTypeEnum::RunCancelled => 'cancelled',
                    RuntimeEventTypeEnum::RunFailed => 'failed',
                    default => 'unknown',
                };
            }
            $identity = ['child_run_id' => $event->runId, 'parent_run_id' => $parent, 'child_sequence' => $event->seq];
            $this->emit(new RuntimeEvent($type->value, $root, 0, $identity + $fields));
            if (RuntimeEventTypeEnum::ChildExecutionStarted === $type && RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                $this->emit(new RuntimeEvent(RuntimeEventTypeEnum::ChildTurnObservation->value, $root, 0, $identity + ['phase' => 'turn']));
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('child_execution.observation_failed', [
                'component' => 'child_execution', 'event_type' => 'observation_failed',
                'run_id' => $event->runId, 'session_id' => $event->runId, 'exception_class' => $exception::class,
            ]);
        }
    }

    private function emit(RuntimeEvent $event): void
    {
        if ($this->controller->isOpen()) {
            $this->controller->emit($event);
        } elseif ('1' === getenv('HATFIELD_CONSUMER_STDOUT_EVENTS')) {
            $this->worker->emit($event);
        } else {
            $this->local->emit($event);
        }
    }

    private function parentRunId(string $runId): ?string
    {
        // Consult artifact storage only when the disposable projection is absent.
        $relationship = $this->relationships->findRelationship($runId);

        return null !== $relationship ? $relationship['parent_run_id'] : $this->children->locate($runId)?->parentRunId;
    }

    private function rootSession(string $runId): ?string
    {
        $visited = [];
        while ('' !== $runId && !isset($visited[$runId])) {
            if ((string) (int) $runId === $runId && $this->sessions->exists($runId)) {
                return $runId;
            }
            $visited[$runId] = true;
            $parent = $this->parentRunId($runId);
            if (null === $parent) {
                return null;
            }
            $runId = $parent;
        }

        return null;
    }
}
