<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Replays canonical child RunEvents into transcript blocks and runtime replay events.
 *
 * Intended for replay-on-enter of a selected child live view, not for per-tick polling.
 */
interface ChildRunTranscriptSnapshotProviderInterface
{
    public function snapshot(string $runId): ChildRunTranscriptSnapshotDTO;
}
