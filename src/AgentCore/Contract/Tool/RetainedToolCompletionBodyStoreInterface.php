<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;

/**
 * Sealed owned bodies for oversized retained tool_completion evidence.
 *
 * Implementations stream copies, validate hashes on read, and refuse path
 * traversal / foreign-session access. Bodies survive ordinary cleanup until
 * authorized root-session deletion removes the owning session tree.
 */
interface RetainedToolCompletionBodyStoreInterface
{
    public function sealJson(
        string $owningRootSessionId,
        string $messageId,
        string $json,
        string $mediaType,
    ): RetainedToolCompletionBodyReference;

    /**
     * @return array{bytes: int, sha256: string, media_type: string, json: array<string, mixed>}
     */
    public function readJson(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): array;

    public function exists(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): bool;
}
