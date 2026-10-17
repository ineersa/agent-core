<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;

/**
 * Scope-authorized internal reader for retained tool_completion evidence.
 *
 * Resolves canonical accepted receipts without loading full session/model state.
 * Missing/corrupt/unsupported/foreign references yield typed bounded failures and
 * never re-execute tools.
 */
interface RetainedToolCompletionEvidenceReaderInterface
{
    /**
     * @return array{
     *     envelope: RetainedToolCompletionEnvelope,
     *     body: array{
     *         kind: string,
     *         media_type: string,
     *         bytes: int,
     *         sha256: string,
     *         truncated: bool,
     *         inline?: array<string, mixed>,
     *         json?: array<string, mixed>,
     *         text?: string
     *     }
     * }
     */
    public function read(
        string $authorizedRootSessionId,
        string $sourceRunId,
        string $messageId,
        ?int $maxBodyBytes = null,
        ?int $acceptedSourceSeq = null,
    ): array;
}
