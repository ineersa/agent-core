<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

/**
 * Request-local sequential observation ids for Reflector/Dropper model prompts.
 *
 * Canonical SHA-256 observation ids stay the persisted identity. Models see short
 * decimal ids ("1", "2", …) assigned in the same deterministic observation order
 * used by Reflector/Dropper input assembly. Tool handlers map local ids back to
 * canonical ids before hashing, ranking, or persistence.
 */
final class RequestLocalObservationIdMap
{
    /**
     * @param array<string, string> $localToCanonical
     * @param array<string, string> $canonicalToLocal
     */
    private function __construct(
        private readonly array $localToCanonical,
        private readonly array $canonicalToLocal,
    ) {
    }

    /**
     * @param list<array{observation_id: string, timestamp?: string}> $observations
     */
    public static function forObservations(array $observations): self
    {
        $ordered = $observations;
        usort($ordered, static function (array $a, array $b): int {
            $byTs = strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
            if (0 !== $byTs) {
                return $byTs;
            }

            return strcmp((string) $a['observation_id'], (string) $b['observation_id']);
        });

        /** @var array<string, string> $localToCanonical */
        $localToCanonical = [];
        /** @var array<string, string> $canonicalToLocal */
        $canonicalToLocal = [];
        $n = 0;
        foreach ($ordered as $observation) {
            $canonical = (string) $observation['observation_id'];
            if ('' === $canonical || isset($canonicalToLocal[$canonical])) {
                continue;
            }
            ++$n;
            $local = (string) $n;
            $localToCanonical[$local] = $canonical;
            $canonicalToLocal[$canonical] = $local;
        }

        return new self($localToCanonical, $canonicalToLocal);
    }

    public function localId(string $canonicalObservationId): ?string
    {
        return $this->canonicalToLocal[$canonicalObservationId] ?? null;
    }

    public function canonicalId(string $localId): ?string
    {
        return $this->localToCanonical[$localId] ?? null;
    }
}
