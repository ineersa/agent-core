<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

/**
 * Typed RunEvent.payload envelope for run_started.
 *
 * Wire shape: { step_id?, payload: { metadata: {...}, ... } }.
 * Extra keys (step_id, etc.) are ignored by ObjectNormalizer.
 */
final readonly class RunStartedEventPayloadDTO
{
    public function __construct(
        public RunStartedPayloadDTO $payload,
    ) {
    }
}
