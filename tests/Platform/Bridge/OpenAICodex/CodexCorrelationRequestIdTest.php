<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexCorrelationRequestId;

final class CodexCorrelationRequestIdTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testGenerateProducesUuidVersion7(): void
    {
        $id = CodexCorrelationRequestId::generate();

        self::assertUuidVersion7($id);
    }

    public function testResolveGeneratesUuidVersion7AndAugmentsRunIdWhenNoExplicitIdentifiers(): void
    {
        [$id, $options] = CodexCorrelationRequestId::resolve([], []);

        self::assertUuidVersion7($id);
        $this->assertSame($id, $options['run_id']);
    }

    public function testResolvePreservesExplicitRunId(): void
    {
        [$id, $options] = CodexCorrelationRequestId::resolve(['run_id' => 'caller-run-abc'], []);

        $this->assertSame('caller-run-abc', $id);
        $this->assertSame('caller-run-abc', $options['run_id']);
    }

    public function testResolveUsesExplicitPromptCacheKeyWhenRunIdAbsent(): void
    {
        [$id, $options] = CodexCorrelationRequestId::resolve([], ['prompt_cache_key' => 'cache-key-xyz']);

        $this->assertSame('cache-key-xyz', $id);
        $this->assertArrayNotHasKey('run_id', $options);
    }

    public function testResolvePrefersExplicitRunIdOverPromptCacheKey(): void
    {
        [$id, $options] = CodexCorrelationRequestId::resolve(
            ['run_id' => 'run-wins'],
            ['prompt_cache_key' => 'cache-loses'],
        );

        $this->assertSame('run-wins', $id);
        $this->assertSame('run-wins', $options['run_id']);
    }
}
