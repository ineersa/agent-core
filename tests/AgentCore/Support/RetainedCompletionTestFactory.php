<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Application\Tool\SourceRunOwningRootSessionResolver;
use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedOutputCapBodyPromoterInterface;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RetainedCompletionTestFactory
{
    public static function envelopeFactory(
        ?FakeRetainedToolCompletionBodyStore $bodies = null,
        ?OwningRootSessionResolverInterface $owningRoots = null,
        ?RetainedOutputCapBodyPromoterInterface $promoter = null,
        ?LoggerInterface $logger = null,
    ): RetainedToolCompletionEnvelopeFactory {
        $bodies ??= new FakeRetainedToolCompletionBodyStore();
        $codec = self::codec();

        return new RetainedToolCompletionEnvelopeFactory(
            $bodies,
            $codec,
            $owningRoots ?? new SourceRunOwningRootSessionResolver(),
            $promoter,
            $logger ?? new NullLogger(),
        );
    }

    public static function codec(): RetainedToolCompletionEnvelopeCodec
    {
        return new RetainedToolCompletionEnvelopeCodec(AttributeSerializerValidatorTestFactory::serializer(withBackedEnumNormalizer: true));
    }
}
