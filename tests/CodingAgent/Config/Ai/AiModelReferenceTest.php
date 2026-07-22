<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use PHPUnit\Framework\TestCase;

class AiModelReferenceTest extends TestCase
{
    public function testParseValidReference(): void
    {
        $ref = AiModelReference::parse('deepseek/deepseek-v4-pro');

        $this->assertSame('deepseek', $ref->providerId);
        $this->assertSame('deepseek-v4-pro', $ref->modelName);
        $this->assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testParseWithMultiSegmentProvider(): void
    {
        $ref = AiModelReference::parse('llama_cpp/flash');

        $this->assertSame('llama_cpp', $ref->providerId);
        $this->assertSame('flash', $ref->modelName);
    }

    public function testParseWithMultiSegmentModel(): void
    {
        $ref = AiModelReference::parse('zai/glm-5v-turbo');

        $this->assertSame('zai', $ref->providerId);
        $this->assertSame('glm-5v-turbo', $ref->modelName);
    }

    public function testParseMissingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelReference::parse('deepseek-v4-pro');
    }

    public function testParseEmptyProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelReference::parse('/model');
    }

    public function testParseEmptyModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelReference::parse('provider/');
    }

    public function testParseEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelReference::parse('');
    }

    public function testTryParseValid(): void
    {
        $ref = AiModelReference::tryParse('deepseek/deepseek-v4-pro');
        $this->assertNotNull($ref);
        $this->assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testTryParseInvalidReturnsNull(): void
    {
        $this->assertNull(AiModelReference::tryParse('no-slash'));
        $this->assertNull(AiModelReference::tryParse('/empty-provider'));
        $this->assertNull(AiModelReference::tryParse('empty-model/'));
        $this->assertNull(AiModelReference::tryParse(''));
    }

    public function testToStringRoundTrip(): void
    {
        $original = 'zai/glm-5.1';
        $this->assertSame($original, AiModelReference::parse($original)->toString());
    }
}
