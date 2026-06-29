<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotSerializer;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests for ForkSessionSnapshotSerializer.
 *
 * Test thesis:
 *   - ForkSessionSnapshotDTO round-trips through JSON with all fields
 *     and AgentMessage metadata/tool_calls preserved.
 *   - toFile/fromFile work atomically.
 *   - Missing/corrupt file throws RuntimeError.
 */
#[CoversClass(ForkSessionSnapshotSerializer::class)]
#[CoversClass(ForkSessionSnapshotDTO::class)]
final class ForkSessionSnapshotSerializerTest extends TestCase
{
    private ForkSessionSnapshotSerializer $serializer;
    private string $tmpDir;

    protected function setUp(): void
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        $symfonySerializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: $nameConverter,
            )],
            [new JsonEncoder()],
        );

        $this->serializer = new ForkSessionSnapshotSerializer($symfonySerializer);
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('fork-snapshot-serializer-test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testRoundTrip(): void
    {
        $messages = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Hello world']],
                metadata: ['source' => 'test'],
            ),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'Hi there']],
                metadata: [
                    'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'read', 'arguments' => '{"path":"./file.txt"}', 'order_index' => 0],
                    ],
                ],
            ),
            new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => 'file contents']],
                toolCallId: 'call_1',
                toolName: 'read',
            ),
        ];

        $original = new ForkSessionSnapshotDTO(
            messages: $messages,
            forkSystemPromptAppend: 'FORK MODE IS ENABLED.',
            forkTaskUserMessage: 'Task: do the thing',
            level: ForkLevelEnum::Senior,
            resolvedModel: 'openai/gpt-4',
        );

        $json = $this->serializer->serialize($original);

        // Verify JSON structure has expected keys.
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('messages', $decoded);
        self::assertArrayHasKey('forkSystemPromptAppend', $decoded);
        self::assertArrayHasKey('forkTaskUserMessage', $decoded);
        self::assertArrayHasKey('level', $decoded);
        self::assertArrayHasKey('resolvedModel', $decoded);

        // Verify messages array is present with correct count.
        self::assertCount(3, $decoded['messages']);
        self::assertSame('user', $decoded['messages'][0]['role']);
        self::assertSame('Hello world', $decoded['messages'][0]['content'][0]['text']);
        self::assertSame('test', $decoded['messages'][0]['metadata']['source']);
        self::assertArrayHasKey('tool_calls', $decoded['messages'][1]['metadata']);
        self::assertSame('call_1', $decoded['messages'][1]['metadata']['tool_calls'][0]['id']);
        self::assertSame('read', $decoded['messages'][1]['metadata']['tool_calls'][0]['name']);
        self::assertSame('tool', $decoded['messages'][2]['role']);
        self::assertSame('call_1', $decoded['messages'][2]['tool_call_id']);

        $restored = $this->serializer->deserialize($json);

        self::assertSame($original->forkSystemPromptAppend, $restored->forkSystemPromptAppend);
        self::assertSame($original->forkTaskUserMessage, $restored->forkTaskUserMessage);
        self::assertSame($original->level, $restored->level);
        self::assertSame($original->resolvedModel, $restored->resolvedModel);
        self::assertCount(\count($original->messages), $restored->messages);
    }

    public function testFileRoundTrip(): void
    {
        $messages = [
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => 'Test']],
            ),
        ];

        $original = new ForkSessionSnapshotDTO(
            messages: $messages,
            forkSystemPromptAppend: 'Test append',
            forkTaskUserMessage: 'Test task',
            level: ForkLevelEnum::Junior,
            resolvedModel: null,
        );

        $path = $this->tmpDir.'/snapshot.json';
        $this->serializer->toFile($original, $path);

        self::assertFileExists($path);

        $restored = $this->serializer->fromFile($path);

        self::assertSame($original->forkSystemPromptAppend, $restored->forkSystemPromptAppend);
        self::assertSame($original->forkTaskUserMessage, $restored->forkTaskUserMessage);
        self::assertSame($original->level, $restored->level);
        self::assertNull($restored->resolvedModel);
        self::assertCount(1, $restored->messages);
    }

    public function testFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fork snapshot file not found');

        $this->serializer->fromFile($this->tmpDir.'/nonexistent.json');
    }

    public function testFromFileThrowsOnEmptyFile(): void
    {
        $path = $this->tmpDir.'/empty.json';
        file_put_contents($path, '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is empty');

        $this->serializer->fromFile($path);
    }
}
