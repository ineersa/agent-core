<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** Holds only one bounded, unmounted display transfer. The mounted projector is untouched until validation ends. */
final class TuiBootstrapAssembler
{
    private ?SessionBootstrapDescriptorDTO $cut = null;
    private string $requestIdentity = '';
    private int $latestEpoch = 0;
    private ?\HashContext $hash = null;
    private string $buffer = '';
    private int $frames = 0;
    private int $bytes = 0;
    private int $records = 0;
    private int $viewBytes = 2;
    /** @var list<TranscriptBlock> */
    private array $blocks = [];
    /** @var array<string, true> */
    private array $ids = [];
    /** @var array<string, mixed>|null */
    private ?array $resume = null;

    public function __construct(private readonly DenormalizerInterface $denormalizer)
    {
    }

    /** @return array{blocks: list<TranscriptBlock>, resume: array<string, mixed>, cut: SessionBootstrapDescriptorDTO}|null */
    public function accept(TuiSessionState $state, RuntimeEvent $event): ?array
    {
        $identity = $state->handle?->runId."\0".$state->handle?->bootstrapRequestId;
        if ($identity !== $this->requestIdentity) {
            $this->release();
            $this->requestIdentity = $identity;
            $this->latestEpoch = 0;
        }
        if ($event->runId !== $state->handle?->runId) {
            return null;
        }
        if ('bootstrap.available' === $event->type) {
            $request = $event->payload['command_id'] ?? $event->payload['request_id'] ?? null;
            if (null === $state->handle->bootstrapRequestId || $request !== $state->handle->bootstrapRequestId) {
                return null;
            }
            $next = SessionBootstrapDescriptorDTO::fromArray($event->payload);
            if ($next->viewEpoch <= $this->latestEpoch) {
                return null;
            }
            if ($next->runId !== $event->runId || $next->canonicalSeq < $state->lastSeq) {
                throw new RuntimeTransportException('Bootstrap descriptor regresses the committed session cut.');
            }
            $this->release();
            $this->cut = $next;
            $this->latestEpoch = $next->viewEpoch;
            $this->hash = hash_init('sha256');
            $state->sessionReady = false;
            $state->bootstrapMounted = false;
            $state->bootstrapStartedAt = microtime(true);

            return null;
        }
        $cut = $this->cut;
        if (null === $cut || ($event->payload['bootstrap_id'] ?? null) !== $cut->bootstrapId
            || ($event->payload['view_epoch'] ?? null) !== $cut->viewEpoch) {
            return null;
        }
        if ('session.ready' === $event->type) {
            if (!$state->bootstrapMounted || !\is_int($event->payload['canonical_seq'] ?? null)
                || $event->payload['canonical_seq'] < $state->lastSeq || !\is_int($event->payload['end_offset'] ?? null)
                || $event->payload['end_offset'] < $cut->endOffset) {
                throw new RuntimeTransportException('Session readiness does not match the mounted bootstrap.');
            }
            // Some canonical event types have no runtime mapping. The validated
            // ready cut, not a seq-zero envelope, establishes their durable cursor.
            $state->lastSeq = $event->payload['canonical_seq'];
            $state->sessionReady = true;
            $this->release();

            return null;
        }
        if (null === $this->hash) {
            // Repeated frames/ends after mounting cannot remount or ACK again.
            return null;
        }
        if ('bootstrap.frame' === $event->type) {
            $encoded = $event->payload['data'] ?? null;
            if (($event->payload['index'] ?? null) !== $this->frames || !\is_string($encoded)
                || \strlen($encoded) > 4 * (int) ceil(SessionBootstrapSpoolStore::FRAME_BYTES / 3)) {
                throw new RuntimeTransportException('Invalid bootstrap frame order or size.');
            }
            $chunk = base64_decode($encoded, true);
            if (false === $chunk || '' === $chunk || \strlen($chunk) > SessionBootstrapSpoolStore::FRAME_BYTES
                || $this->bytes + \strlen($chunk) > $cut->bytes) {
                throw new RuntimeTransportException('Invalid bootstrap frame bytes.');
            }
            ++$this->frames;
            $this->bytes += \strlen($chunk);
            hash_update($this->hash, $chunk);
            $this->buffer .= $chunk;
            while (false !== ($newline = strpos($this->buffer, "\n"))) {
                $line = substr($this->buffer, 0, $newline);
                $this->buffer = substr($this->buffer, $newline + 1);
                $this->record($line, $cut);
            }

            return null;
        }
        if ('bootstrap.end' === $event->type) {
            $end = SessionBootstrapDescriptorDTO::fromArray($event->payload);
            if ($end->toArray() !== $cut->toArray() || ($event->payload['frames'] ?? null) !== $this->frames
                || $this->bytes !== $cut->bytes || $this->records !== $cut->records || '' !== $this->buffer
                || null === $this->resume || !hash_equals($cut->checksum, hash_final($this->hash))) {
                throw new RuntimeTransportException('Bootstrap checksum, count or committed cut does not match.');
            }
            $result = ['blocks' => $this->blocks, 'resume' => $this->resume, 'cut' => $cut];
            $this->clearBuffers();

            return $result;
        }

        return null;
    }

    public function release(): void
    {
        $this->cut = null;
        $this->clearBuffers();
    }

    private function record(string $line, SessionBootstrapDescriptorDTO $cut): void
    {
        $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($record) || !\is_array($record['data'] ?? null) || ++$this->records > $cut->records) {
            throw new RuntimeTransportException('Invalid bootstrap record.');
        }
        if (1 === $this->records) {
            if ('resume' !== ($record['kind'] ?? null)) {
                throw new RuntimeTransportException('Bootstrap resume metadata is missing.');
            }
            $this->resume = (new SessionResumeMetadataProjection($record['data']))->toArray();

            return;
        }
        $data = $record['data'];
        $this->viewBytes += \strlen(json_encode($data, \JSON_THROW_ON_ERROR)) + 1;
        // Transcript sequence numbers order display blocks, not canonical events.
        // One event can project several blocks; only the descriptor is the cursor.
        if ('block' !== ($record['kind'] ?? null) || ($data['run_id'] ?? null) !== $cut->runId || !\is_int($data['seq'] ?? null)
            || $data['seq'] < 0 || !\is_string($data['id'] ?? null) || '' === $data['id']
            || isset($this->ids[$data['id']]) || $this->viewBytes > 4 * 1024 * 1024 || \count($this->blocks) >= 2000) {
            throw new RuntimeTransportException('Bootstrap block identity or view budget is invalid.');
        }
        $block = $this->denormalizer->denormalize($data, TranscriptBlock::class);
        if (!$block instanceof TranscriptBlock) {
            throw new RuntimeTransportException('Invalid bootstrap transcript block.');
        }
        $this->ids[$block->id] = true;
        $this->blocks[] = $block;
    }

    private function clearBuffers(): void
    {
        $this->hash = null;
        $this->buffer = '';
        $this->frames = $this->bytes = $this->records = 0;
        $this->viewBytes = 2;
        $this->blocks = $this->ids = [];
        $this->resume = null;
    }
}
