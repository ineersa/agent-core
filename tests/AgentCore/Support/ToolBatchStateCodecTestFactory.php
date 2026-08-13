<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateCodec;

/**
 * Test-only codec builder mirroring FrameworkBundle attribute serializer + validator.
 *
 * Prefer the container service in KernelTestCase paths; use this only when the
 * test cannot boot the container.
 */
final class ToolBatchStateCodecTestFactory
{
    public static function create(): ToolBatchStateCodec
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();

        return new ToolBatchStateCodec($serializer, $validator);
    }
}
