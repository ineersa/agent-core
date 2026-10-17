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
    /**
     * Seal JSON from an already-open readable stream without buffering the whole body in PHP.
     *
     * The stream is copied once into owned storage. Callers must not pass unbounded
     * in-memory strings for large bodies.
     *
     * @param resource $jsonStream
     */
    public function sealJsonStream(
        string $owningRootSessionId,
        string $messageId,
        $jsonStream,
        string $mediaType,
        ?int $expectedBytes = null,
        ?string $expectedSha256 = null,
    ): RetainedToolCompletionBodyReference;

    /**
     * Inspect sealed body metadata and optionally decode JSON under an explicit byte budget.
     *
     * A null $maxDecodeBytes uses the default inspection budget. When the body exceeds
     * the effective budget, json is omitted and truncated=true. A decoded JSON null
     * retains the json key. Integrity is always validated by streaming hash.
     *
     * @return array{
     *     bytes: int,
     *     sha256: string,
     *     media_type: string,
     *     truncated: bool,
     *     json?: array<array-key, mixed>|string|int|float|bool|null,
     *     text?: string
     * }
     */
    public function inspectJson(
        RetainedToolCompletionBodyReference $reference,
        string $authorizedRootSessionId,
        ?int $maxDecodeBytes = null,
    ): array;

    public function exists(RetainedToolCompletionBodyReference $reference, string $authorizedRootSessionId): bool;

    /**
     * True when an exact sealed body is retained under the authorized root session.
     */
    public function isHeld(string $authorizedRootSessionId, string $messageId): bool;
}
