<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class StepDispatcher
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $executionBus,
        private LoggerInterface $logger,
        private EventDispatcherInterface $events,
    ) {
    }

    /** @param list<object> $actions */
    public function dispatchCoordinationActions(array $actions): void
    {
        foreach ($actions as $action) {
            // Unrouted local coordinators complete before canonical publication.
            $this->commandBus->dispatch($action);
        }
    }

    /** @param list<object> $effects */
    public function dispatchEffects(array $effects): void
    {
        /** @var array<array-key, int> $accepted */
        $accepted = [];
        /** @var array<array-key, int> $failed */
        $failed = [];
        foreach ($effects as $effect) {
            $message = $effect instanceof Envelope ? $effect->getMessage() : $effect;
            if (!$message instanceof AbstractAgentBusMessage) {
                throw new \RuntimeException('Unsupported owner transition message.');
            }
            $bus = $message instanceof ExecuteLlmStep || $message instanceof ExecuteToolCall || $message instanceof ExecuteShellToolCall || $message instanceof ExecuteCompactionStep
                ? $this->executionBus : $this->commandBus;
            try {
                $bus->dispatch($effect);
                $accepted[$message->runId()] = ($accepted[$message->runId()] ?? 0) + 1;
            } catch (\Throwable $exception) {
                $failed[$message->runId()] = ($failed[$message->runId()] ?? 0) + 1;
                $this->logger->warning('runtime.effect_send_failed', [
                    'run_id' => $message->runId(),
                    'session_id' => $message->runId(),
                    'component' => 'step_dispatcher',
                    'event_type' => 'runtime.effect_send_failed',
                    'message_type' => $message::class,
                    'exception_class' => $exception::class,
                ]);
            }
        }
        foreach ($failed as $runId => $count) {
            $runId = (string) $runId;
            try {
                $this->events->dispatch(new EffectDispatchFailedEvent($runId, $accepted[$runId] ?? 0, $count));
            } catch (\Throwable $exception) {
                $this->logger->warning('runtime.effect_send_failure_notification_failed', [
                    'run_id' => $runId,
                    'session_id' => $runId,
                    'component' => 'step_dispatcher',
                    'event_type' => 'runtime.effect_send_failure_notification_failed',
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
