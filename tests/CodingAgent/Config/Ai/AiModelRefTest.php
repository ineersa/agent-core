<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiModelRef;
use PHPUnit\Framework\TestCase;

class AiModelRefTest extends TestCase
{
    public function testParseValidRef(): void
    {
        $ref = AiModelRef::parse('deepseek/deepseek-v4-pro');

        self::assertSame('deepseek', $ref->providerId);
        self::assertSame('deepseek-v4-pro', $ref->modelName);
        self::assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testParseWithMultiSegmentProvider(): void
    {
        $ref = AiModelRef::parse('llama_cpp/flash');

        self::assertSame('llama_cpp', $ref->providerId);
        self::assertSame('flash', $ref->modelName);
    }

    public function testParseWithMultiSegmentModel(): void
    {
        $ref = AiModelRef::parse('zai/glm-5v-turbo');

        self::assertSame('zai', $ref->providerId);
        self::assertSame('glm-5v-turbo', $ref->modelName);
    }

    public function testParseMissingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelRef::parse('deepseek-v4-pro');
    }

    public function testParseEmptyProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelRef::parse('/model');
    }

    public function testParseEmptyModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelRef::parse('provider/');
    }

    public function testParseEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid model reference');

        AiModelRef::parse('');
    }

    public function testTryParseValid(): void
    {
        $ref = AiModelRef::tryParse('deepseek/deepseek-v4-pro');
        self::assertNotNull($ref);
        self::assertSame('deepseek/deepseek-v4-pro', $ref->toString());
    }

    public function testTryParseInvalidReturnsNull(): void
    {
        self::assertNull(AiModelRef::tryParse('no-slash'));
        self::assertNull(AiModelRef::tryParse('/empty-provider'));
        self::assertNull(AiModelRef::tryParse('empty-model/'));
        self::assertNull(AiModelRef::tryParse(''));
    }

    public function testToStringRoundTrip(): void
    {
        $original = 'zai/glm-5.1';
        self::assertSame($original, AiModelRef::parse($original)->toString());
    }
}
