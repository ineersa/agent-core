<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;

interface TuiSessionActivityContextInterface extends TuiSessionMetadataContextInterface
{
    /** Null means current main activity is unavailable. */
    public function isMainActive(): ?bool;

    /** Number of unsettled agents, or null when inventory is unavailable. */
    public function getBackgroundAgents(): ?int;

    /** Number of nonterminal background jobs, or null when inventory is unavailable. */
    public function getBackgroundJobs(): ?int;

    /** Read both counts together. Pass false to reuse the latest host sample without querying. */
    public function getBackgroundSnapshot(bool $refresh = true): TuiBackgroundSnapshotDTO;
}
