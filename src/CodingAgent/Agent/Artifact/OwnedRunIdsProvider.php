<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Doctrine\DBAL\Connection;

/** Traverses existing artifact identities and durable deferred reservations. */
final readonly class OwnedRunIdsProvider
{
    public function __construct(
        private AgentArtifactRegistry $artifacts,
        private Connection $connection,
        private AgentChildRunDirectory $children,
    ) {
    }

    /** @return list<string> Includes the owner; duplicate and cyclic edges are visited once. */
    public function forOwner(string $owner): array
    {
        $runs = [$owner];
        $seen = [$owner => true];
        for ($index = 0; $index < \count($runs); ++$index) {
            $parent = $runs[$index];
            $children = $this->connection->fetchFirstColumn(<<<'SQL'
                SELECT child.child_run_id FROM deferred_subagent_child child
                JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                WHERE batch.parent_run_id = :parent
                SQL, ['parent' => $parent]);
            foreach ($this->artifacts->list($parent) as $entry) {
                // Fresh owners must resolve nested child archives, not only the
                // root registry that the directory's session-list scan can see.
                $this->children->register($entry);
                $children[] = $entry->agentRunId;
            }
            foreach ($children as $child) {
                $child = (string) $child;
                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $runs[] = $child;
                }
            }
        }
        sort($runs, \SORT_STRING);

        return $runs;
    }
}
