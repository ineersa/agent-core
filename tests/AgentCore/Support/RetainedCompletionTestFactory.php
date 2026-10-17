<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;

final class RetainedCompletionTestFactory
{
    public static function envelopeFactory(?FakeRetainedToolCompletionBodyStore $bodies = null): RetainedToolCompletionEnvelopeFactory
    {
        $bodies ??= new FakeRetainedToolCompletionBodyStore();
        $codec = self::codec();

        return new RetainedToolCompletionEnvelopeFactory($bodies, $codec);
    }

    public static function codec(): RetainedToolCompletionEnvelopeCodec
    {
        return new RetainedToolCompletionEnvelopeCodec(AttributeSerializerValidatorTestFactory::serializer(withBackedEnumNormalizer: true));
    }
}
