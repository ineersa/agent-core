<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Only a spool reader, one encoded frame and the delivered cut survive a slow screen. */
final class SessionBootstrapDelivery
{
    private ?string $runId = null;
    private ?SessionBootstrapDescriptorDTO $descriptor = null;
    /** @var \Generator<int, RuntimeEvent>|null */
    private ?\Generator $stream = null;
    private ?string $timeout = null;
    private bool $ended = false;
    private ?string $requestId = null;
    private ?string $commandId = null;

    public function __construct(private readonly SessionBootstrapSpoolStore $spools, private readonly SessionBootstrapTransfer $transfer,
        private readonly RuntimeEventEmitter $emitter, private readonly LoggerInterface $logger)
    {
    }

    /** Called before async AttachRun dispatch, not when the descriptor eventually arrives. */
    public function begin(string $runId, string $commandId): void
    {
        $this->cancel();
        $this->runId = $runId;
        $this->commandId = $commandId;
        $this->emitter->beginBootstrapOutput();
        $this->emitter->setBootstrapFilter($this->observe(...));
        $this->timeout = EventLoop::delay(60, function (): void {
            $runId = $this->runId ?? '';
            $this->cancel();
            $this->emitter->emitTransfer(new RuntimeEvent(RuntimeEventTypeEnum::ProtocolError->value, $runId, 0,
                ['error' => 'Session bootstrap timed out before attachment completed.']));
        });
    }

    public function expect(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /** @return bool true means consumed, not forwarded */
    public function observe(RuntimeEvent $event): bool
    {
        if (\in_array($event->type, [RuntimeEventTypeEnum::CommandAck->value, RuntimeEventTypeEnum::CommandRejected->value, RuntimeEventTypeEnum::ProtocolError->value], true)) {
            return false;
        }
        if (null === $this->runId || $event->runId !== $this->runId) {
            return null !== $this->runId && '' !== $event->runId;
        }
        if (RuntimeEventTypeEnum::BootstrapAvailable->value === $event->type) {
            if (($event->payload['request_id'] ?? null) !== $this->requestId) {
                return true;
            }
            try {
                $descriptor = SessionBootstrapDescriptorDTO::fromArray($event->payload);
                if ($descriptor->runId !== $this->runId || null !== $this->descriptor) {
                    throw new \RuntimeException('Bootstrap availability does not match the pending attach.');
                }
                $this->descriptor = $descriptor;
                $this->stream = $this->transfer->frames($descriptor);
                $this->emitter->emitTransfer(new RuntimeEvent($event->type, $event->runId, 0,
                    $event->payload + ['command_id' => $this->commandId]), function () use ($descriptor): void {
                        if ($this->descriptor?->bootstrapId === $descriptor->bootstrapId) {
                            $this->pump();
                        }
                    });
            } catch (\Throwable $exception) {
                $this->fail($exception);
            }

            return true;
        }

        // Seq-zero stream deltas never become a durable cursor or retained backlog.
        return true;
    }

    public function acknowledge(SessionBootstrapDescriptorDTO $descriptor): void
    {
        if (!$this->ended || null === $this->descriptor || $descriptor->toArray() !== $this->descriptor->toArray()) {
            throw new \RuntimeException('Bootstrap acknowledgement is stale or incomplete.');
        }
        $this->spools->acknowledge($descriptor);
        $this->ended = false;
        $this->stream = $this->wireSuffix($this->transfer->catchUp($descriptor), $descriptor);
        $this->pump();
    }

    #[AsEventListener]
    public function onShutdown(ControllerSessionShutdownEvent $event): void
    {
        $this->cancel();
    }

    public function cancel(bool $removeSpool = true): void
    {
        if (null !== $this->timeout) {
            EventLoop::cancel($this->timeout);
            $this->timeout = null;
        }
        $this->stream = null;
        if ($removeSpool && null !== $this->runId) {
            $this->spools->cancel($this->runId);
        }
        $this->runId = null;
        $this->descriptor = null;
        $this->ended = false;
        $this->requestId = null;
        $this->commandId = null;
        if ($removeSpool) {
            $this->emitter->cancelBootstrapOutput();
        } else {
            $this->emitter->setBootstrapFilter(null);
        }
    }

    public function cancelForRun(string $runId): void
    {
        if ($this->runId !== $runId) {
            throw new \InvalidArgumentException('Bootstrap cancellation run does not match the active transfer.');
        }
        $this->cancel();
    }

    private function pump(): void
    {
        if (null === $this->stream || null === $this->descriptor) {
            return;
        }
        try {
            if (!$this->stream->valid()) {
                $this->stream = null;

                return;
            }
            $event = $this->stream->current();
            $id = $this->descriptor->bootstrapId;
            $this->emitter->emitTransfer($event, function () use ($id, $event): void {
                if (null === $this->descriptor || $this->descriptor->bootstrapId !== $id || null === $this->stream) {
                    return;
                }
                if (RuntimeEventTypeEnum::BootstrapEnd->value === $event->type) {
                    $this->ended = true;
                    $this->stream = null;

                    return;
                }
                if (RuntimeEventTypeEnum::SessionReady->value === $event->type) {
                    $this->emitter->whenDrained(fn () => $this->completeReady($event, $id));

                    return;
                }
                try {
                    $this->stream->next();
                    $this->pump();
                } catch (\Throwable $exception) {
                    $this->fail($exception);
                }
            });
        } catch (\Throwable $exception) {
            $this->fail($exception);
        }
    }

    private function completeReady(RuntimeEvent $event, string $id): void
    {
        if ($this->descriptor?->bootstrapId !== $id) {
            return;
        }
        // Notifications can be lost while output waits on the pipe. The archive,
        // not the observed high-water, decides whether the delivered cut is current.
        try {
            $cut = $this->transfer->committedCut($this->descriptor->runId);
        } catch (\Throwable $exception) {
            $this->fail($exception);

            return;
        }
        if ($cut['sequence'] < $event->payload['canonical_seq'] || $cut['end_offset'] < $event->payload['end_offset']) {
            $this->fail(new \RuntimeException('Canonical archive changed before live forwarding.'));

            return;
        }
        if ($cut['sequence'] > $event->payload['canonical_seq'] || $cut['end_offset'] > $event->payload['end_offset']) {
            $cursor = new SessionBootstrapDescriptorDTO($this->descriptor->runId, $id, $this->descriptor->viewEpoch,
                $event->payload['canonical_seq'], $event->payload['end_offset'], $this->descriptor->selectedAnchor,
                $this->descriptor->records, $this->descriptor->bytes, $this->descriptor->checksum);
            $this->stream = $this->wireSuffix($this->transfer->catchUp($cursor), $cursor);
            $this->pump();

            return;
        }
        $this->cancel(false);
        $this->emitter->finishBootstrapOutput();
    }

    /** Canonical records may exceed an IPC frame, but the pipe never receives an oversized line.
     * @param \Generator<int, RuntimeEvent> $events
     *
     * @return \Generator<int, RuntimeEvent> */
    private function wireSuffix(\Generator $events, SessionBootstrapDescriptorDTO $descriptor): \Generator
    {
        foreach ($events as $event) {
            if (RuntimeEventTypeEnum::SessionReady->value === $event->type) {
                yield $event;
                continue;
            }
            $encoded = JsonlCodec::encodeEvent($event);
            $length = \strlen($encoded);
            if ($length > 16 * 1024 * 1024) {
                throw new \LengthException('Bootstrap suffix record exceeds its byte budget.');
            }
            for ($offset = 0; $offset < $length; $offset += SessionBootstrapSpoolStore::FRAME_BYTES) {
                yield new RuntimeEvent(RuntimeEventTypeEnum::BootstrapSuffix->value, $event->runId, 0,
                    ['bootstrap_id' => $descriptor->bootstrapId, 'view_epoch' => $descriptor->viewEpoch,
                        'canonical_seq' => $event->seq, 'index' => intdiv($offset, SessionBootstrapSpoolStore::FRAME_BYTES),
                        'data' => base64_encode(substr($encoded, $offset, SessionBootstrapSpoolStore::FRAME_BYTES)),
                        'last' => $offset + SessionBootstrapSpoolStore::FRAME_BYTES >= $length]);
            }
            unset($encoded, $event);
        }
    }

    private function fail(\Throwable $exception): void
    {
        $runId = $this->runId ?? '';
        $this->logger->warning('session.bootstrap.delivery_failed', ['run_id' => $runId, 'session_id' => $runId,
            'component' => 'session_bootstrap', 'event_type' => 'session.bootstrap.delivery_failed', 'exception_class' => $exception::class]);
        $this->cancel();
        $this->emitter->emitTransfer(new RuntimeEvent(RuntimeEventTypeEnum::ProtocolError->value, $runId, 0,
            ['error' => 'Session bootstrap failed before attachment completed.']));
    }
}
