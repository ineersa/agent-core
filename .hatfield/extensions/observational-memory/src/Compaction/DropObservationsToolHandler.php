<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;

/**
 * In-process Dropper tool — accumulates proposed active observation ids only.
 *
 * Unknown and duplicate ids are filtered (never cause arbitrary drops). Optional
 * reason is accepted but not persisted. Final selection is deterministic server code.
 */
final class DropObservationsToolHandler implements ExtensionToolHandlerInterface
{
    /** @var list<string> first-proposal-order unique ids */
    private array $proposedIds = [];

    /** @var array<string, true> */
    private array $proposedSet = [];

    /**
     * @param array<string, true> $allowedObservationIds
     */
    public function __construct(
        private readonly array $allowedObservationIds,
        private readonly int $maxDropsAllowed,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        $ids = $arguments['ids'] ?? null;
        if (!\is_array($ids) || [] === $ids) {
            return [
                'status' => 'rejected',
                'error' => 'invalid_arguments',
                'message' => 'drop_observations requires a non-empty ids list.',
            ];
        }

        // Optional reason is accepted for model ergonomics but never stored.
        unset($arguments['reason']);

        $added = 0;
        $missing = 0;
        $duplicateInRequest = 0;
        $duplicateInRun = 0;
        $seenInRequest = [];

        foreach ($ids as $id) {
            if (!\is_string($id) || '' === trim($id)) {
                ++$missing;
                continue;
            }
            $id = trim($id);
            if (!isset($this->allowedObservationIds[$id])) {
                ++$missing;
                continue;
            }
            if (isset($seenInRequest[$id])) {
                ++$duplicateInRequest;
                continue;
            }
            $seenInRequest[$id] = true;
            if (isset($this->proposedSet[$id])) {
                ++$duplicateInRun;
                continue;
            }
            $this->proposedSet[$id] = true;
            $this->proposedIds[] = $id;
            ++$added;
        }

        return [
            'status' => 'accepted',
            'added' => $added,
            'missing' => $missing,
            'duplicate_in_request' => $duplicateInRequest,
            'duplicate_in_run' => $duplicateInRun,
            'total_candidates' => \count($this->proposedIds),
            'max_drops_allowed' => $this->maxDropsAllowed,
            'guidance' => \sprintf(
                'Queued %d drop candidate%s. Candidates this run: %d. Maximum drops allowed: %d.',
                $added,
                1 === $added ? '' : 's',
                \count($this->proposedIds),
                $this->maxDropsAllowed,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function proposedIds(): array
    {
        return $this->proposedIds;
    }
}
