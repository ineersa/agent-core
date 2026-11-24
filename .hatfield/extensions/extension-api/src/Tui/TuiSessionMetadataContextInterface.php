<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

interface TuiSessionMetadataContextInterface extends TuiExtensionContextInterface
{
    /** Canonical picker title for the current persisted session, or null when unavailable. */
    public function getSessionDisplayTitle(): ?string;
}
