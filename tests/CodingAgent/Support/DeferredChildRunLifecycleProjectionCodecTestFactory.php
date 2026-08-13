<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionCodec;

/**
 * Test-only codec builder mirroring FrameworkBundle attribute serializer + validator.
 *
 * Prefer the container service in KernelTestCase paths; use this only when the
 * test cannot boot the container.
 */
final class DeferredChildRunLifecycleProjectionCodecTestFactory
{
    public static function create(): DeferredChildRunLifecycleProjectionCodec
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);

        return new DeferredChildRunLifecycleProjectionCodec($serializer, $validator);
    }
}
