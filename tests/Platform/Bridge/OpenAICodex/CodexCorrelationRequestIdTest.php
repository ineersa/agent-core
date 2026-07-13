<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexCorrelationProvenance;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexCorrelationRequestId;

final class CodexCorrelationRequestIdTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testResolveGeneratesUuidVersion7AndAugmentsRunIdWhenNoExplicitIdentifiers(): void
    {
        $resolution = CodexCorrelationRequestId::resolve([], []);

        self::assertUuidVersion7($resolution->id);
        $this->assertSame($resolution->id, $resolution->options['run_id']);
        $this->assertSame(CodexCorrelationProvenance::Generated, $resolution->provenance);
        self::assertUuidVersion7($resolution->idFor401Retry());
        $this->assertNotSame($resolution->id, $resolution->idFor401Retry());
    }

    public function testResolveTreatsEmptyPromptCacheKeyAsAbsentAndGeneratesUuidVersion7(): void
    {
        $resolution = CodexCorrelationRequestId::resolve([], ['prompt_cache_key' => '']);

        self::assertUuidVersion7($resolution->id);
        $this->assertSame($resolution->id, $resolution->options['run_id']);
        $this->assertSame(CodexCorrelationProvenance::Generated, $resolution->provenance);
    }

    public function testResolvePreservesExplicitRunId(): void
    {
        $resolution = CodexCorrelationRequestId::resolve(['run_id' => 'caller-run-abc'], []);

        $this->assertSame('caller-run-abc', $resolution->id);
        $this->assertSame('caller-run-abc', $resolution->options['run_id']);
        $this->assertSame(CodexCorrelationProvenance::ExplicitRunId, $resolution->provenance);
        $this->assertSame('caller-run-abc', $resolution->idFor401Retry());
    }

    public function testResolveUsesExplicitPromptCacheKeyWhenRunIdAbsent(): void
    {
        $resolution = CodexCorrelationRequestId::resolve([], ['prompt_cache_key' => 'cache-key-xyz']);

        $this->assertSame('cache-key-xyz', $resolution->id);
        $this->assertArrayNotHasKey('run_id', $resolution->options);
        $this->assertSame(CodexCorrelationProvenance::ExplicitPromptCacheKey, $resolution->provenance);
        $this->assertSame('cache-key-xyz', $resolution->idFor401Retry());
    }

    public function testResolvePrefersExplicitRunIdOverPromptCacheKey(): void
    {
        $resolution = CodexCorrelationRequestId::resolve(
            ['run_id' => 'run-wins'],
            ['prompt_cache_key' => 'cache-loses'],
        );

        $this->assertSame('run-wins', $resolution->id);
        $this->assertSame('run-wins', $resolution->options['run_id']);
        $this->assertSame(CodexCorrelationProvenance::ExplicitRunId, $resolution->provenance);
    }
}
