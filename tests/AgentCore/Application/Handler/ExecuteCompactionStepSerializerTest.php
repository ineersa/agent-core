<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer as MessengerSerializer;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Transport serializer round-trip test for compaction Messenger messages.
 *
 * Thesis: nested {@see AgentMessage} objects on ExecuteCompactionStep
 * (llm transport / Symfony Serializer) and CompactionStepResult
 * (run_control / native PhpSerializer) survive encode/decode with order,
 * content, metadata, optional fields, and timestamps intact — without
 * producer/consumer manual toArray()/fromPayload() reconstruction.
 */
final class ExecuteCompactionStepSerializerTest extends TestCase
{
    public function testExecuteCompactionStepNestedMessagesSurviveMessengerSymfonySerializer(): void
    {
        $summarizationMessages = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Summarize this.']],
                timestamp: new \DateTimeImmutable('2024-06-01T12:00:00+00:00'),
                metadata: ['source' => 'history'],
            ),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Prior answer.']],
            ),
        ];

        $retainedTailMessages = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Recent question.']],
                name: 'alice',
            ),
            new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => 'tool output']],
                toolCallId: 'call-1',
                toolName: 'read_file',
                details: ['path' => '/tmp/x'],
                isError: true,
            ),
        ];

        $step = new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-compact-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            model: 'openai/gpt-4.1-mini',
            modelOptions: ['thinking_level' => 'low'],
            summarizationMessages: $summarizationMessages,
            retainedTailMessages: $retainedTailMessages,
            messagesCompacted: 10,
            messagesRetained: 5,
            firstRetainedIndex: 10,
            tokenEstimateBefore: 42000,
            trigger: 'manual',
            continueAfterCompaction: true,
            hookMetadata: ['hook' => 'meta'],
        );

        $messengerSerializer = new MessengerSerializer($this->createProductionLikeSerializer(), 'json');
        $encoded = $messengerSerializer->encode(new Envelope($step));
        $decoded = $messengerSerializer->decode($encoded)->getMessage();

        $this->assertInstanceOf(ExecuteCompactionStep::class, $decoded);
        $this->assertSame('run-1', $decoded->runId());
        $this->assertSame(5, $decoded->turnNo());
        $this->assertSame('step-compact-1', $decoded->stepId());
        $this->assertSame(1, $decoded->attempt());
        $this->assertSame('key-1', $decoded->idempotencyKey());
        $this->assertSame('openai/gpt-4.1-mini', $decoded->model);
        $this->assertSame(['thinking_level' => 'low'], $decoded->modelOptions);
        $this->assertSame(10, $decoded->messagesCompacted);
        $this->assertSame(5, $decoded->messagesRetained);
        $this->assertSame(10, $decoded->firstRetainedIndex);
        $this->assertSame(42000, $decoded->tokenEstimateBefore);
        $this->assertSame('manual', $decoded->trigger);
        $this->assertTrue($decoded->continueAfterCompaction);
        $this->assertSame(['hook' => 'meta'], $decoded->hookMetadata);

        $this->assertCount(2, $decoded->summarizationMessages);
        $this->assertContainsOnlyInstancesOf(AgentMessage::class, $decoded->summarizationMessages);
        $this->assertSame('user', $decoded->summarizationMessages[0]->role);
        $this->assertSame('Summarize this.', $decoded->summarizationMessages[0]->content[0]['text']);
        $this->assertSame('2024-06-01T12:00:00+00:00', $decoded->summarizationMessages[0]->timestamp?->format(\DATE_ATOM));
        $this->assertSame(['source' => 'history'], $decoded->summarizationMessages[0]->metadata);
        $this->assertSame('assistant', $decoded->summarizationMessages[1]->role);
        $this->assertSame('Prior answer.', $decoded->summarizationMessages[1]->content[0]['text']);

        $this->assertCount(2, $decoded->retainedTailMessages);
        $this->assertContainsOnlyInstancesOf(AgentMessage::class, $decoded->retainedTailMessages);
        $this->assertSame('Recent question.', $decoded->retainedTailMessages[0]->content[0]['text']);
        $this->assertSame('alice', $decoded->retainedTailMessages[0]->name);
        $this->assertSame('tool', $decoded->retainedTailMessages[1]->role);
        $this->assertSame('call-1', $decoded->retainedTailMessages[1]->toolCallId);
        $this->assertSame('read_file', $decoded->retainedTailMessages[1]->toolName);
        $this->assertSame(['path' => '/tmp/x'], $decoded->retainedTailMessages[1]->details);
        $this->assertTrue($decoded->retainedTailMessages[1]->isError);
    }

    public function testCompactionStepResultNestedMessagesSurviveNativePhpSerializer(): void
    {
        $retainedTailMessages = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Keep me.']],
                timestamp: new \DateTimeImmutable('2024-07-01T08:00:00+00:00'),
            ),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'And me.']],
                metadata: ['k' => 1],
            ),
        ];

        $result = new CompactionStepResult(
            runId: 'run-2',
            turnNo: 7,
            stepId: 'step-result-1',
            attempt: 1,
            idempotencyKey: 'key-result',
            summaryText: 'summary body',
            error: null,
            retainedTailMessages: $retainedTailMessages,
            messagesCompacted: 3,
            messagesRetained: 2,
            firstRetainedIndex: 4,
            tokenEstimateBefore: 9000,
            trigger: 'auto',
            continueAfterCompaction: false,
            model: 'openai/gpt-4.1-mini',
            modelOptions: ['thinking_level' => 'medium'],
            hookMetadata: null,
        );

        // run_control transport uses messenger.transport.native_php_serializer.
        $payload = serialize($result);
        $decoded = unserialize($payload);

        $this->assertInstanceOf(CompactionStepResult::class, $decoded);
        $this->assertSame('run-2', $decoded->runId());
        $this->assertSame('summary body', $decoded->summaryText);
        $this->assertNull($decoded->error);
        $this->assertSame('openai/gpt-4.1-mini', $decoded->model);
        $this->assertSame(['thinking_level' => 'medium'], $decoded->modelOptions);
        $this->assertCount(2, $decoded->retainedTailMessages);
        $this->assertContainsOnlyInstancesOf(AgentMessage::class, $decoded->retainedTailMessages);
        $this->assertSame('Keep me.', $decoded->retainedTailMessages[0]->content[0]['text']);
        $this->assertSame('2024-07-01T08:00:00+00:00', $decoded->retainedTailMessages[0]->timestamp?->format(\DATE_ATOM));
        $this->assertSame('And me.', $decoded->retainedTailMessages[1]->content[0]['text']);
        $this->assertSame(['k' => 1], $decoded->retainedTailMessages[1]->metadata);
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
            modelOptions: [],
            summarizationMessages: [],
            retainedTailMessages: [],
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            tokenEstimateBefore: 0,
            trigger: 'auto',
        );

        $messengerSerializer = new MessengerSerializer($this->createProductionLikeSerializer(), 'json');
        $decoded = $messengerSerializer->decode(
            $messengerSerializer->encode(new Envelope($step)),
        )->getMessage();

        $this->assertInstanceOf(ExecuteCompactionStep::class, $decoded);
        $this->assertSame([], $decoded->summarizationMessages);
        $this->assertSame([], $decoded->retainedTailMessages);
        $this->assertSame('auto', $decoded->trigger);
        $this->assertSame([], $decoded->modelOptions);
    }

    /**
     * Mirrors production FrameworkBundle serializer stack used by the llm
     * Messenger transport: DateTime + attributes + ArrayDenormalizer +
     * PhpDocExtractor for nested list&lt;AgentMessage&gt; denormalization.
     */
    private function createProductionLikeSerializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyInfo = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        return new Serializer(
            normalizers: [
                new DateTimeNormalizer(),
                new BackedEnumNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    nameConverter: new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter()),
                    propertyTypeExtractor: $propertyInfo,
                ),
            ],
            encoders: [new JsonEncoder()],
        );
    }
}
