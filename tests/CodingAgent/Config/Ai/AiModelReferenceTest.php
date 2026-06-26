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

        self::assertSame('deepseek', $ref->providerId);
        self::assertSame('deepseek-v4-pro', $ref->modelName);
        self::assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testParseWithMultiSegmentProvider(): void
    {
        $ref = AiModelReference::parse('llama_cpp/flash');

        self::assertSame('llama_cpp', $ref->providerId);
        self::assertSame('flash', $ref->modelName);
    }

    public function testParseWithMultiSegmentModel(): void
    {
        $ref = AiModelReference::parse('zai/glm-5v-turbo');

        self::assertSame('zai', $ref->providerId);
        self::assertSame('glm-5v-turbo', $ref->modelName);
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
        self::assertNotNull($ref);
        self::assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testTryParseInvalidReturnsNull(): void
    {
        self::assertNull(AiModelReference::tryParse('no-slash'));
        self::assertNull(AiModelReference::tryParse('/empty-provider'));
        self::assertNull(AiModelReference::tryParse('empty-model/'));
        self::assertNull(AiModelReference::tryParse(''));
    }

    public function testToStringRoundTrip(): void
    {
        $original = 'zai/glm-5.1';
        self::assertSame($original, AiModelReference::parse($original)->toString());
    }
}
