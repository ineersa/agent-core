<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Transport serializer round-trip test for {@see ExecuteCompactionStep}.
 *
 * Thesis: ExecuteCompactionStep — which carries serialized AgentMessage
 * arrays as summarizationMessages and retainedTailMessages — survives
 * a full Symfony Serializer encode/decode round-trip.  The llm transport
 * uses the default Symfony Serializer (not PhpSerializer), so the
 * serialized shape must be transport-safe.
 */
final class ExecuteCompactionStepSerializerTest extends TestCase
{
    public function testRoundTripThroughSymfonySerializer(): void
    {
        $summarizationMessages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Summarize this.']]],
        ];

        $retainedTailMessages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Recent question.']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Recent answer.']]],
        ];

        $step = new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-compact-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            model: 'openai/gpt-4.1-mini',
            thinkingLevel: 'low',
            summarizationMessages: $summarizationMessages,
            retainedTailMessages: $retainedTailMessages,
            messagesCompacted: 10,
            messagesRetained: 5,
            firstRetainedIndex: 10,
            tokenEstimateBefore: 42000,
            trigger: 'manual',
        );

        $serializer = $this->createSerializer();

        // Encode to JSON.
        $json = $serializer->serialize($step, 'json');
        self::assertIsString($json);
        self::assertNotEmpty($json);

        // Decode back.
        $decoded = $serializer->deserialize($json, ExecuteCompactionStep::class, 'json');
        self::assertInstanceOf(ExecuteCompactionStep::class, $decoded);

        // Assert scalar properties survive round-trip.
        self::assertSame('run-1', $decoded->runId());
        self::assertSame(5, $decoded->turnNo());
        self::assertSame('step-compact-1', $decoded->stepId());
        self::assertSame(1, $decoded->attempt());
        self::assertSame('key-1', $decoded->idempotencyKey());
        self::assertSame('openai/gpt-4.1-mini', $decoded->model);
        self::assertSame('low', $decoded->thinkingLevel);
        self::assertSame(10, $decoded->messagesCompacted);
        self::assertSame(5, $decoded->messagesRetained);
        self::assertSame(10, $decoded->firstRetainedIndex);
        self::assertSame(42000, $decoded->tokenEstimateBefore);
        self::assertSame('manual', $decoded->trigger);

        // Assert message arrays survive round-trip.
        self::assertCount(\count($summarizationMessages), $decoded->summarizationMessages);
        self::assertSame('Summarize this.', $decoded->summarizationMessages[0]['content'][0]['text']);

        self::assertCount(\count($retainedTailMessages), $decoded->retainedTailMessages);
        self::assertSame('Recent question.', $decoded->retainedTailMessages[0]['content'][0]['text']);
        self::assertSame('Recent answer.', $decoded->retainedTailMessages[1]['content'][0]['text']);
    }

    public function testEmptyMessageArraysRoundTrip(): void
    {
        $step = new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-2',
            attempt: 1,
            idempotencyKey: 'key-2',
            model: '',
            thinkingLevel: null,
            summarizationMessages: [],
            retainedTailMessages: [],
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            tokenEstimateBefore: 0,
            trigger: 'auto',
        );

        $serializer = $this->createSerializer();
        $json = $serializer->serialize($step, 'json');
        $decoded = $serializer->deserialize($json, ExecuteCompactionStep::class, 'json');

        self::assertInstanceOf(ExecuteCompactionStep::class, $decoded);
        self::assertSame([], $decoded->summarizationMessages);
        self::assertSame([], $decoded->retainedTailMessages);
        self::assertSame('auto', $decoded->trigger);
        self::assertNull($decoded->thinkingLevel);
    }

    private function createSerializer(): Serializer
    {
        $propertyInfo = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        return new Serializer(
            normalizers: [
                new BackedEnumNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(propertyTypeExtractor: $propertyInfo),
            ],
            encoders: [new JsonEncoder()],
        );
    }
}
