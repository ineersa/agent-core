<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

/**
 * Typed inner StartRunPayload subset used by child-run consumers.
 *
 * Wire shape under RunEvent.payload.payload: { metadata: {...}, system_prompt?, messages? }.
 * Only metadata is required for child classification/identity reads.
 */
final readonly class RunStartedPayloadDTO
{
    public function __construct(
        public RunStartedMetadataDTO $metadata,
    ) {
    }
}
