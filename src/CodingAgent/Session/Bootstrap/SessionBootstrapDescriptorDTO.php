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

    /** @return array{schema: int, run_id: string, bootstrap_id: string, view_epoch: int, canonical_seq: int, end_offset: int, selected_anchor: int, records: int, bytes: int, checksum: string} */
    public function toArray(): array
    {
        return ['schema' => 1, 'run_id' => $this->runId, 'bootstrap_id' => $this->bootstrapId, 'view_epoch' => $this->viewEpoch,
            'canonical_seq' => $this->canonicalSeq, 'end_offset' => $this->endOffset, 'selected_anchor' => $this->selectedAnchor,
            'records' => $this->records, 'bytes' => $this->bytes, 'checksum' => $this->checksum];
    }
}
