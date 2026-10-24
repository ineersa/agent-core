<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Execution\MessageExecutionStrategyInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\AckStamp;

use function Amp\async;
use function Amp\Future\awaitFirst;

/**
 * Same-process cooperative Messenger strategy for overlapping LLM handlers.
 *
 * Schedules bus dispatch inside Amp futures and yields through wait() so the
 * unmodified Symfony 8.2 Worker can admit up to the configured capacity without
 * spawning ParallelExecutionStrategy child processes.
 */
final class FiberMessageExecutionStrategy implements MessageExecutionStrategyInterface
{
    private int $nextId = 0;

    /** @var array<int, Future<array{int, Envelope, string, bool, ?\Throwable}>> */
    private array $pending = [];

    /** @var array<int, DeferredCancellation> */
    private array $cancellations = [];

    private int $peakInFlight = 0;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly int $concurrencyLimit = 4,
    ) {
        if ($this->concurrencyLimit < 1) {
            throw new \InvalidArgumentException('FiberMessageExecutionStrategy concurrency limit must be a positive integer.');
        }
    }

    public function execute(Envelope $envelope, string $transportName, callable $onHandled): void
    {
        $id = ++$this->nextId;
        $cancellation = new DeferredCancellation();
        $this->cancellations[$id] = $cancellation;
        $bus = $this->bus;

        $this->pending[$id] = async(static function () use ($bus, $envelope, $transportName, $id, $cancellation): array {
            $acked = false;
            $error = null;
            $handledEnvelope = $envelope;

            $ack = static function (Envelope $e, ?\Throwable $err = null) use (&$handledEnvelope, &$acked, &$error): void {
                $handledEnvelope = $e;
                $acked = true;
                $error = $err;
            };

            try {
                $handledEnvelope = $bus->dispatch($envelope->with(new AckStamp($ack)));
            } catch (\Throwable $e) {
                return [$id, $handledEnvelope, $transportName, $acked, $e];
            }

            $cancellation->getCancellation()->throwIfRequested();

            return [$id, $handledEnvelope, $transportName, $acked, $error];
        });

        $this->peakInFlight = max($this->peakInFlight, \count($this->pending));
        $this->drainReady($onHandled);
    }

    public function shouldPauseConsumption(): bool
    {
        return \count($this->pending) >= $this->concurrencyLimit;
    }

    /**
     * @phpstan-impure
     */
    public function wait(callable $onHandled, ?float $timeout = null): bool
    {
        if ([] === $this->pending) {
            return false;
        }

        $deadline = null === $timeout ? null : hrtime(true) + (int) (1e9 * $timeout);

        while ([] !== $this->pending) {
            $heartbeat = null === $deadline ? 0.1 : min(0.1, ($deadline - hrtime(true)) / 1e9);
            if ($heartbeat <= 0) {
                return false;
            }

            try {
                /** @var array{int, Envelope, string, bool, ?\Throwable} $winner */
                $winner = awaitFirst($this->pending, new TimeoutCancellation($heartbeat));
            } catch (CancelledException) {
                continue;
            }

            [$id, $envelope, $transportName, $acked, $error] = $winner;
            unset($this->pending[$id], $this->cancellations[$id]);
            $onHandled($envelope, $transportName, $acked, $error);

            return true;
        }

        return false;
    }

    public function flush(callable $onHandled, bool|float $force = false): bool
    {
        $handled = $this->drainReady($onHandled);

        if ([] === $this->pending) {
            return $handled;
        }

        // Match ParallelExecutionStrategy: only true force drains in-flight work.
        if (true === $force) {
            do {
                $handled = $this->wait($onHandled) || $handled;
            } while (0 < $this->pendingCount());
        }

        return $handled;
    }

    public function shutdown(): void
    {
        foreach ($this->cancellations as $cancellation) {
            $cancellation->cancel();
        }
        $this->cancellations = [];
        $this->pending = [];
    }

    public function peakInFlight(): int
    {
        return $this->peakInFlight;
    }

    /**
     * @phpstan-impure
     */
    public function pendingCount(): int
    {
        return \count($this->pending);
    }

    /**
     * @phpstan-impure
     */
    private function drainReady(callable $onHandled): bool
    {
        $handled = false;
        foreach ($this->pending as $id => $future) {
            if (!$future->isComplete()) {
                continue;
            }
            unset($this->pending[$id], $this->cancellations[$id]);
            [$doneId, $envelope, $transportName, $acked, $error] = $future->await();
            unset($doneId);
            $onHandled($envelope, $transportName, $acked, $error);
            $handled = true;
        }

        return $handled;
    }
}
