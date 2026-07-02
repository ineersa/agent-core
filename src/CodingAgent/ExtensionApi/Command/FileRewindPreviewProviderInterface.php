<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

interface FileRewindPreviewProviderInterface
{
    /** @return list<FileRewindPreviewEntryDTO> */
    public function previewForTurn(string $sessionId, int $turnNo): array;

    public function hasCheckpointForTurn(string $sessionId, int $turnNo): bool;
}
