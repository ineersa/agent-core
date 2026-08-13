<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotCodec;

/**
 * Test-only codec builder mirroring FrameworkBundle attribute serializer + validator.
 *
 * Prefer the container service in KernelTestCase paths; use this only when the
 * test cannot boot the container.
 */
final class SubagentProgressSnapshotCodecTestFactory
{
    public static function create(): SubagentProgressSnapshotCodec
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();

        return new SubagentProgressSnapshotCodec($serializer, $validator);
    }
}
