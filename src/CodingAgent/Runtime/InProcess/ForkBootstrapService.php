<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotSerializer;

/**
 * Bridge service for fork child bootstrapping.
 *
 * Loads a fork snapshot from disk and provides the DTO for further
 * composition.  Lives in AppRuntimeInternals so that AppCli (AgentCommand)
 * can reach AppAgent (Fork services) through this bridge.
 *
 * AgentCommand uses this service when --fork is passed:
 *   1. Loads the snapshot from the paths provided by the fork launcher.
 *   2. Returns the DTO to be placed in StartRunRequest::options.
 *   3. InProcessAgentSessionClient::start() reads the option and composes
 *      fork-seed messages via ForkChildMessageComposer.
 */
final readonly class ForkBootstrapService
{
    public function __construct(
        private ForkSessionSnapshotSerializer $serializer,
    ) {
    }

    /**
     * Load a fork snapshot from the given file path.
     *
     * @param string $snapshotPath Absolute path to the JSON snapshot file
     *
     * @return ForkSessionSnapshotDTO The deserialized snapshot
     *
     * @throws \RuntimeException when the file is missing, unreadable, or corrupt
     */
    public function loadSnapshot(string $snapshotPath): ForkSessionSnapshotDTO
    {
        return $this->serializer->fromFile($snapshotPath);
    }
}
