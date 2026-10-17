<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Scope-authorized read of one immutable native artifact handoff generation.
 *
 * Implementations must refuse foreign parents/artifacts and validate integrity
 * metadata against the sealed generation. C4 uses this for retained evidence
 * only; it is not a public retrieval tool API.
 */
interface RetainedNativeArtifactHandoffReaderInterface
{
    /**
     * @return array{
     *     bytes: int,
     *     sha256: string,
     *     media_type: string,
     *     truncated: bool,
     *     text?: string
     * }
     */
    public function inspectHandoff(
        string $authorizedRootSessionId,
        string $parentRunId,
        string $artifactId,
        string $handoffId,
        int $expectedBytes,
        string $expectedSha256,
        string $expectedMediaType,
        ?int $maxDecodeBytes = null,
    ): array;
}
