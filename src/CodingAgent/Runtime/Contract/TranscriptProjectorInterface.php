<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/**
 * Projects stable runtime event arrays into transcript blocks.
 *
 * The interface lives at the runtime boundary so TUI code can consume the
 * projector without importing the concrete Symfony EventDispatcher pipeline.
 */
interface TranscriptProjectorInterface
{
    /**
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    public function accept(array $event): void;

    /** @return list<TranscriptBlock> */
    public function blocks(): array;

    public function reset(): void;
}
