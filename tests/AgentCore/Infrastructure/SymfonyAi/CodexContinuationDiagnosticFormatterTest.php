<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\CodexContinuationDiagnosticFormatter;
use PHPUnit\Framework\TestCase;

final class CodexContinuationDiagnosticFormatterTest extends TestCase
{
    public function testBothItemsAndWhitespaceSurviveCaptureWithIndexedPaths(): void
    {
        $expected = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => "First.\nSecond."]]];
        $current = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => "First.\nSecond.Extra."]]];
        $context = CodexContinuationDiagnosticFormatter::format([
            'first_mismatch_index' => 120,
            'mismatch_response_item_offset' => 1,
            'mismatch_field_path' => 'content[0].text',
            'current_item' => $current,
            'expected_item' => $expected,
        ]);

        $this->assertSame('input[120].content[0].text', $context['current_path']);
        $this->assertSame('output[1].content[0].text', $context['expected_path']);
        $this->assertSame($expected, json_decode($context['expected_item']['json'], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame($current, json_decode($context['current_item']['json'], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertFalse($context['current_item']['truncated']);
        $this->assertFalse($context['current_item']['redacted']);
    }

    public function testCredentialsAndCiphertextAreRedactedWithoutErasingOtherText(): void
    {
        $context = CodexContinuationDiagnosticFormatter::format([
            'current_item' => [
                'type' => 'function_call',
                'arguments' => '{"path":"after","password":"two secret words","access_token":"oauth-secret"}',
                'encrypted_content' => 'reasoning-ciphertext',
                'apiKey' => 'another-secret',
            ],
            'expected_item' => ['content' => "before\nAPI sk-proj-testsecret and Bearer token-secret"],
        ]);
        $json = json_encode($context, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('two secret words', $json);
        $this->assertStringNotContainsString('oauth-secret', $json);
        $this->assertStringNotContainsString('reasoning-ciphertext', $json);
        $this->assertStringNotContainsString('another-secret', $json);
        $this->assertStringNotContainsString('sk-proj-testsecret', $json);
        $this->assertStringNotContainsString('token-secret', $json);
        $this->assertStringContainsString('after', $json);
        $this->assertStringContainsString('before', $json);
        $this->assertTrue($context['current_item']['redacted']);
    }

    public function testOversizedItemsAreMarkedTruncatedAndRemainUtf8(): void
    {
        $context = CodexContinuationDiagnosticFormatter::format([
            'first_mismatch_index' => 0,
            'mismatch_field_path' => 'content',
            'current_item' => ['content' => str_repeat('é', 20_000)],
            'expected_item' => ['content' => 'old'],
        ]);
        $this->assertSame('input[0].content', $context['expected_path']);
        $this->assertTrue($context['current_item']['truncated']);
        $this->assertLessThanOrEqual(16_384, \strlen($context['current_item']['json']));
        $this->assertTrue(mb_check_encoding($context['current_item']['json'], 'UTF-8'));
        $this->assertGreaterThan(16_384, $context['current_item']['bytes']);
    }
}
