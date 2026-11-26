<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

use Ineersa\Hatfield\ExtensionApi\Session\DTO\BackgroundSnapshotDTO;

/**
 * Session selected in the interactive process.
 *
 * Other processes have no selected session. Reads are synchronous; call them
 * from listeners or commands, not in a tight loop.
 */
interface SessionContextInterface
{
    /** Selected session ID, or an empty string when none is selected. */
    public function getSessionId(): string;

    /** Canonical picker title of the selected session, or null when unavailable. */
    public function getSessionDisplayTitle(): ?string;

    /** Reads background agents, scripts and schedules of the selected session. */
    public function getBackgroundSnapshot(): BackgroundSnapshotDTO;
}
