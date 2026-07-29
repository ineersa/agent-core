<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

/**
 * Deterministic CompactRun replacement text from an active generation (task §I).
 *
 * Source refs stay SQLite-only; never render model free-form replacement_text.
 */
final class ActiveMemoryRenderer
{
    public const string HEADER = <<<'TEXT'
These are condensed memories from earlier in this session.

- Reflections: stable, long-lived facts about the user, project, decisions, and constraints. New reflection lines may include ids in brackets.
- Observations: timestamped events from the conversation history, in chronological order. Observation lines include ids in brackets.

Treat these as past records. When entries conflict, the most recent observation reflects the latest known state. Work that prior observations describe as completed should not be redone unless the user explicitly asks to revisit it.

When exact source context is needed for precision or traceability, use the recall tool with the relevant observation or reflection id. This is especially useful when a reflection materially affects a decision or is too compressed to continue confidently. Do not use recall as broad search or inject raw source unless it is needed.
TEXT;
    private const int DISPLAY_ID_LEN = 12;

    /**
     * @param list<array{reflection_id: string, content: string, position?: int}>                        $reflections
     * @param list<array{observation_id: string, content: string, relevance: string, timestamp: string}> $observations
     */
    public static function render(array $reflections, array $observations): string
    {
        if ([] === $reflections && [] === $observations) {
            return '';
        }

        usort($reflections, static function (array $a, array $b): int {
            $pa = (int) ($a['position'] ?? 0);
            $pb = (int) ($b['position'] ?? 0);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp((string) $a['reflection_id'], (string) $b['reflection_id']);
        });

        usort($observations, static function (array $a, array $b): int {
            $byTs = strcmp((string) $a['timestamp'], (string) $b['timestamp']);
            if (0 !== $byTs) {
                return $byTs;
            }

            return strcmp((string) $a['observation_id'], (string) $b['observation_id']);
        });

        $lines = [self::HEADER, '', '## Reflections'];
        if ([] === $reflections) {
            $lines[] = '(none)';
        } else {
            foreach ($reflections as $reflection) {
                $lines[] = \sprintf('[%s] %s', self::displayId((string) $reflection['reflection_id']), $reflection['content']);
            }
        }

        $lines[] = '';
        $lines[] = '## Observations';
        if ([] === $observations) {
            $lines[] = '(none)';
        } else {
            foreach ($observations as $observation) {
                $lines[] = \sprintf(
                    '[%s] %s [%s] %s',
                    self::displayId((string) $observation['observation_id']),
                    $observation['timestamp'],
                    $observation['relevance'],
                    $observation['content'],
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Model-facing compacted-memory IDs match /om-view: lowercase first 12 hex chars.
     * Stored SHA-256 identities remain full length in SQLite/generation links.
     */
    private static function displayId(string $id): string
    {
        $id = strtolower($id);
        if (\strlen($id) <= self::DISPLAY_ID_LEN) {
            return $id;
        }

        return substr($id, 0, self::DISPLAY_ID_LEN);
    }
}
