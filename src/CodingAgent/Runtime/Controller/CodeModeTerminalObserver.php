<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalIdentity;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalReportHandler;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Messenger\Envelope;

final class CodeModeTerminalObserver
{
    /** @var array<string, array<string, int|null>> Null marks a completed recovery send. */
    private array $exits = [];

    public function __construct(
        private readonly CodeModeProcessOwner $processes,
        private readonly DeferredToolCompletionRepositoryInterface $deferred,
        private readonly LoggerInterface $logger,
        private readonly ToolPayloadStoreInterface $payloads,
        private readonly CodeModeTerminalReportHandler $completion,
        private readonly CodeModeReplyTransportFactory $replies,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function observe(string $sessionId, string $runId): void
    {
        $ids = $this->processes->pendingControllerInvocationIds($runId);
        $this->exits[$runId] = array_intersect_key($this->exits[$runId] ?? [], array_fill_keys($ids, true));
        foreach ($ids as $id) {
            try {
                $this->settle($runId, $id);
            } catch (\Throwable $error) {
                $this->logger->warning('code_mode.terminal_observation_failed', [
                    'run_id' => $runId, 'session_id' => $sessionId, 'component' => 'tool.code_mode',
                    'event_type' => 'code_mode.terminal_observation_failed', 'error_type' => $error::class,
                ]);
            }
        }
    }

    private function settle(string $runId, string $id): void
    {
        $invocation = $this->processes->invocation($runId, $id);
        $metadata = $this->deferred->findMetadataByDeferredId($id);
        if (null === $metadata || $runId !== $metadata->reference->runId || 'code_mode' !== $metadata->reference->toolName) {
            return;
        }
        if ('completed' === $metadata->status || null !== $metadata->acceptedResultReference) {
            unset($this->exits[$runId][$id]);

            return;
        }
        if (null === $invocation || 'pending' !== $metadata->status) {
            return;
        }
        $call = $this->deferred->findByDeferredId($id);
        if (null === $call) {
            return;
        }
        $explicit = $call->arguments['timeout_seconds'] ?? null;
        $explicit = \is_int($explicit) ? $explicit : null;
        $deadline = $this->processes->deadline($runId, $id, null !== $metadata->backgroundedAt, $explicit, $call->timeoutSeconds);
        $exited = false;
        $failure = $this->processes->observeTerminal($runId, $id, $deadline, $metadata->interruptionReason, static function () use (&$exited): ?array {
            $exited = true;

            return null;
        });
        if (null !== $failure) {
            $this->completion->complete($call, $invocation['workspace'], $failure);

            return;
        }
        if (!$exited) {
            return;
        }
        $now = $this->clock->now()->getTimestamp();
        if (!\array_key_exists($id, $this->exits[$runId])) {
            $this->exits[$runId][$id] = $now;
        }
        $observed = $this->exits[$runId][$id];
        if (null === $observed || $now - $observed < 60) {
            return;
        }
        $reference = $this->payloads->findFirstResult(CodeModeTerminalIdentity::forInvocation($runId, $id));
        if (null === $reference) {
            $this->completion->complete($call, $invocation['workspace'], ['ok' => false, 'error' => 'Code-mode subprocess exited without returning a value.']);
        } else {
            $transport = $this->replies->terminal();
            try {
                $transport->send(new Envelope(new CodeModeTerminalReportDTO($id, $reference)));
            } finally {
                $transport->close();
            }
        }
        $this->exits[$runId][$id] = null;
    }
}
