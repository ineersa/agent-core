<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/** Native publication adapter: freeze first accepted result, then reuse CompleteDeferredToolCall. */
#[AsEventListener]
final readonly class CodeModeProcessedResultSubscriber
{
    public function __construct(
        private CodeModeExecutionRepository $executions,
        private CodeModeResultArtifactStore $artifacts,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CodeModeExecutionService $execution,
        private RunLockManager $locks,
        private MessageBusInterface $commandBus,
        private CompleteDeferredToolCallHandler $completions,
    ) {
    }

    public function __invoke(ProcessedToolResultDTO $event): void
    {
        $result = $event->result;
        $row = $this->executions->invocation($result->runId(), $result->toolCallId);
        if (null === $row) {
            return;
        }
        $identity = $row['identity'];
        if ('completed' === $this->deferred->status($identity)) {
            // Controller loss may retire an abandoned host before its blocked
            // worker returns. Its immutable invocation files can already be gone.
            $event->handled = true;

            return;
        }
        $invocationReference = $this->deferred->findInvocationReferenceByDeferredId($identity)
            ?? throw new \RuntimeException('Code-mode owner lost its deferred invocation reference.');
        $call = $this->artifacts->invocation($invocationReference);
        if (!$this->execution->sameInvocation($call, $event->invocation) || !$this->execution->matches($result, $call)) {
            throw new \RuntimeException('Code-mode terminal result does not match its frozen invocation.');
        }
        $publish = $this->locks->synchronized($result->runId(), function () use ($result, $identity): bool {
            $current = $this->executions->invocation($result->runId(), $result->toolCallId);
            if (null === $current || $current['identity'] !== $identity) {
                throw new \RuntimeException('Code-mode owner changed before terminal publication.');
            }
            if (null !== $current['interruption_reason']) {
                // The host has physically returned. Preserve raw/processed evidence,
                // but never let an abandoned invocation continue the current run.
                // Controller recovery owns the interrupted terminal's ordinary
                // delivery. A late worker must not retire its still-pending send.
                if (!\in_array($current['interruption_reason'], [CodeModeInterruptionReasonEnum::ControllerRestarted->value, CodeModeInterruptionReasonEnum::ControllerShutdown->value], true)) {
                    $this->completions->settle($identity, $result->runId(), $result->turnNo(), $result->stepId());
                }

                return false;
            }
            if (!(bool) $current['ready'] && !$result->isError) {
                throw new \RuntimeException('Code-mode terminal result arrived before host readiness.');
            }
            // First accepted terminal evidence is immutable across redelivery.
            $this->deferred->acceptResult($identity, $this->artifacts->publish($result));

            return true;
        });
        $event->handled = true;
        if (!$publish) {
            return;
        }
        $content = $result->result['content'] ?? [];
        $details = $result->result['details'] ?? null;
        $this->commandBus->dispatch(new CompleteDeferredToolCall(
            $identity,
            \is_array($content) ? $content : [],
            \is_array($details) ? $details : null,
            $result->isError,
            $result->error,
        ));
    }
}
