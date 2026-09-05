<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Cli;

/**
 * Interprets jbcontext status JSON for eligibility.
 *
 * Eligibility is repository-scoped: status indexes are keyed by git-remote
 * repositoryId, so any checkout of a previously indexed repository can qualify
 * when that checkout also has a local .idea directory.
 */
final class JbcontextStatusParser
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function hasExistingSnapshot(array $payload): bool
    {
        if (($payload['type'] ?? null) !== 'status_result') {
            return false;
        }

        $indices = $payload['indices'] ?? null;
        if (!\is_array($indices) || [] === $indices) {
            return false;
        }

        foreach ($indices as $index) {
            if (!\is_array($index)) {
                continue;
            }
            $snapshots = $index['snapshots'] ?? null;
            if (\is_array($snapshots) && [] !== $snapshots) {
                return true;
            }
        }

        return false;
    }
}
