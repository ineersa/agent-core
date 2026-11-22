<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory;
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
        private readonly CodeModeReplyTransportFactory $replies,
        private readonly RunOperationalStatusReaderInterface $status,
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
        if (null === $invocation || (null === $invocation['standaloneTurn'] && (
            null === $metadata || $runId !== $metadata->reference->runId || 'code_mode' !== $metadata->reference->toolName
            || DeferredToolCompletionStatusEnum::Completed === $metadata->status
        ))) {
            return;
        }
        $deadline = $this->processes->deadline($runId, $id, null !== $metadata?->backgroundedAt);
        $interruption = $metadata?->interruptionReason?->value;
        if (null !== $invocation['standaloneTurn'] && (new RunCancellationToken($this->status, $runId))->isCancellationRequested()) {
            $interruption = 'cancelled';
            if (!isset($invocation['interruption'])) {
                $this->processes->recordInterruption($runId, $id, $interruption);
            }
        }
        $exited = false;
        $failure = $this->processes->observeTerminal($runId, $id, $deadline, $interruption, static function () use (&$exited): ?array {
            $exited = true;

            return null;
        });
        if (!$exited && null === $failure) {
            return;
        }
        $now = $this->clock->now()->getTimestamp();
        if (!\array_key_exists($id, $this->exits[$runId])) {
            $this->exits[$runId][$id] = $now;
        }
        $observed = $this->exits[$runId][$id];
        if (null === $observed || (null === $failure && $now - $observed < 60)) {
            return;
        }
        $transport = $this->replies->terminal();
        try {
            $transport->send(new Envelope(new CodeModeTerminalReportDTO($runId, $id, $failure['error'] ?? null)));
        } finally {
            $transport->close();
        }
        $this->exits[$runId][$id] = null;
    }
}
