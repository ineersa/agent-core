<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Bootstrap;

/** A private spool token and its immutable committed cut. No execution state crosses this boundary. */
final readonly class SessionBootstrapDescriptorDTO
{
    public function __construct(
        public string $runId,
        public string $bootstrapId,
        public int $viewEpoch,
        public int $canonicalSeq,
        public int $endOffset,
        public int $selectedAnchor,
        public int $records,
        public int $bytes,
        public string $checksum,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (1 !== ($data['schema'] ?? null) || !\is_string($data['run_id'] ?? null) || '' === $data['run_id']
            || !\is_string($data['bootstrap_id'] ?? null) || 1 !== preg_match('/^[a-f0-9]{32}$/D', $data['bootstrap_id'])
            || !\is_string($data['checksum'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $data['checksum'])) {
            throw new \InvalidArgumentException('Invalid bootstrap descriptor.');
        }
        foreach (['view_epoch', 'canonical_seq', 'end_offset', 'records', 'bytes', 'selected_anchor'] as $field) {
            if (!\is_int($data[$field] ?? null) || $data[$field] < ('selected_anchor' === $field ? 0 : 1)) {
                throw new \InvalidArgumentException('Invalid bootstrap committed cut.');
            }
        }
        if ($data['records'] > 2001 || $data['bytes'] > SessionBootstrapSpoolStore::MAX_BYTES) {
            throw new \LengthException('Bootstrap descriptor exceeds its transfer budget.');
        }

        return new self($data['run_id'], $data['bootstrap_id'], $data['view_epoch'], $data['canonical_seq'],
            $data['end_offset'], $data['selected_anchor'], $data['records'], $data['bytes'], $data['checksum']);
    }

    /** @return array{schema: int, run_id: string, bootstrap_id: string, view_epoch: int, canonical_seq: int, end_offset: int, selected_anchor: int, records: int, bytes: int, checksum: string} */
    public function toArray(): array
    {
        return ['schema' => 1, 'run_id' => $this->runId, 'bootstrap_id' => $this->bootstrapId, 'view_epoch' => $this->viewEpoch,
            'canonical_seq' => $this->canonicalSeq, 'end_offset' => $this->endOffset, 'selected_anchor' => $this->selectedAnchor,
            'records' => $this->records, 'bytes' => $this->bytes, 'checksum' => $this->checksum];
    }
}
