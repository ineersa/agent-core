<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkResolvedConfigDTO;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: ForkConfigResolver exposes forks.model and null means session-model fallback at execution time.
 */
#[CoversClass(ForkConfigResolver::class)]
#[CoversClass(ForkResolvedConfigDTO::class)]
#[CoversClass(ForksConfigDTO::class)]
final class ForkConfigResolverTest extends TestCase
{
    public function testResolveReturnsConfiguredModel(): void
    {
        $resolver = new ForkConfigResolver(new ForksConfigDTO(model: 'openai/gpt-4'));

        $this->assertSame('openai/gpt-4', $resolver->resolve()->resolvedModel);
    }

    public function testResolveReturnsNullWhenModelUnset(): void
    {
        $resolver = new ForkConfigResolver(new ForksConfigDTO());

        $this->assertNull($resolver->resolve()->resolvedModel);
    }

    public function testResolveTreatsBlankModelAsNull(): void
    {
        $resolver = new ForkConfigResolver(new ForksConfigDTO(model: '   '));

        $this->assertNull($resolver->resolve()->resolvedModel);
    }
}
