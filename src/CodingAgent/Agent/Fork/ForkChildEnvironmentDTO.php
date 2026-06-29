<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForkLevelEnum;

/**
 * Environment/CLI parameters for a fork child process.
 *
 * Carries all inputs passed from the parent (FORK-04 launcher) to the
 * child (FORK-03 bootstrap) so the child can start the fork run, load
 * the snapshot, compose messages, and write result artifacts.
 *
 * Immutable value object parsed from CLI options and env vars.
 */
final readonly class ForkChildEnvironmentDTO
{
    /**
     * @param string             $snapshotPath Absolute path to the JSON snapshot file
     * @param string             $resultDir    Absolute path to the result artifact directory
     * @param string             $parentRunId  Parent session run ID
     * @param string             $artifactId   Artifact identifier for this fork (within parent scope)
     * @param string             $childRunId   Child agent run ID (RFC 4122 UUID)
     * @param string             $cwd          Working directory for the child (chdir before run)
     * @param ForkLevelEnum|null $level        Resolved fork level (null = default)
     * @param string|null        $task         Task description passed from parent (optional, also embedded in snapshot)
     */
    public function __construct(
        public string $snapshotPath,
        public string $resultDir,
        public string $parentRunId,
        public string $artifactId,
        public string $childRunId,
        public string $cwd,
        public ?ForkLevelEnum $level = null,
        public ?string $task = null,
    ) {
    }
}
