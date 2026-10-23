<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolPayloadStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\Support\CountingToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SessionToolPayloadStoreTest extends IsolatedKernelTestCase
{
    public function testFirstProcessedTerminalSurvivesColdReadAndDuplicatePublication(): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('processed evidence');
        $store = $c->get(SessionToolPayloadStore::class)->forRun($run);
        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'key', 'call', 'echo', ['value' => 42], 0);
        $invocation = $store->publish($call);
        $first = new ToolCallResult($run, 1, 'tools', 1, 'key', 'call', 0,
            ['tool_name' => 'echo', 'content' => [['type' => 'text', 'text' => str_repeat('first', 262144)]], 'details' => ['raw_result' => ['value' => 42], 'stdout' => 'OUT', 'stderr' => 'ERR']],
            true, ['message' => 'original failure', 'retryable' => false]);
        $replacement = new ToolCallResult($run, 1, 'tools', 1, 'key', 'call', 0, ['tool_name' => 'echo', 'content' => [['type' => 'text', 'text' => 'replacement']]]);
        $processed = $store->retainProcessedResult($invocation, $first);
        $root = \dirname($c->get(ToolBatchRunStoragePathsInterface::class)->resolveToolBatchesDirectory($run)).'/tool-payloads/'.hash('sha256', 'call');
        $this->assertLessThan(1024, filesize($root.'/processed-'.$invocation->sha256.'.json'), 'Discovery reads reference metadata, not the large terminal body.');
        $this->assertEquals($first, $store->read($processed));
        $this->assertEquals($processed, $store->retainProcessedResult($invocation, $replacement));
        $cold = new SessionToolPayloadStore($c->get(ToolBatchRunStoragePathsInterface::class), $c->get('serializer'), $c->get(ValidatorInterface::class), new Filesystem(), $c->get(LockFactory::class));
        $this->assertEquals($processed, $cold->findProcessedResultReference($invocation));
        $this->assertEquals($first, $cold->read($processed));
        $store->deleteCall($run, 'call');
        $this->assertNull($cold->findProcessedResultReference($invocation));
    }

    public function testProcessedAnchorIsBoundToEveryPartOfTheImmutableInvocation(): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('processed invocation fence');
        $store = $c->get(SessionToolPayloadStore::class)->forRun($run);
        $arguments = ['runId' => $run, 'turnNo' => 1, 'stepId' => 'tools', 'attempt' => 1, 'idempotencyKey' => 'key', 'toolCallId' => 'call', 'toolName' => 'echo', 'args' => ['value' => 42], 'orderIndex' => 0, 'parentModel' => 'model'];
        $call = new ExecuteToolCall(...$arguments);
        $invocation = $store->publish($call);
        $result = new ToolCallResult($run, 1, 'tools', 1, 'key', 'call', 0, ['tool_name' => 'echo', 'content' => []]);
        $processed = $store->retainProcessedResult($invocation, $result);
        foreach (['args' => ['value' => 43], 'parentModel' => 'other', 'timeoutSeconds' => 7, 'maxParallelism' => 3, 'toolsRef' => 'different-tools', 'argSchema' => ['type' => 'object'], 'attempt' => 2, 'idempotencyKey' => 'another-key'] as $field => $value) {
            $other = $store->publish(new ExecuteToolCall(...[...$arguments, $field => $value]));
            $this->assertNotSame($invocation->sha256, $other->sha256, $field);
            $this->assertNull($store->findProcessedResultReference($other), $field.' must not reuse another invocation\'s processed result.');
        }
        $this->assertEquals($processed, $store->findProcessedResultReference($invocation));
        $this->expectException(ToolPayloadException::class);
        $store->findProcessedResultReference(new ToolPayloadReferenceDTO(...[...get_object_vars($invocation), 'toolName' => 'different']));
    }

    public function testProcessedPublicationRefusesMismatchedResultBeforeRetainingIt(): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('processed result fence');
        $store = $c->get(SessionToolPayloadStore::class);
        $invocation = $store->publish(new ExecuteToolCall($run, 1, 'tools', 1, 'key', 'call', 'echo', [], 0));
        $result = new ToolCallResult($run, 1, 'tools', 2, 'key', 'call', 0, ['tool_name' => 'echo', 'content' => []]);
        try {
            $store->retainProcessedResult($invocation, $result);
            $this->fail('A different execution attempt must not become processed evidence.');
        } catch (ToolPayloadException $error) {
            $this->assertStringContainsString('identity', $error->getMessage());
        }
        $this->assertNull($store->findProcessedResultReference($invocation));
    }

    public function testScopeReusesRealNumericParentResolutionWithoutCachingAcrossOperations(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('scoped bodies');
        $paths = new CountingToolBatchRunStoragePaths($container->get(ToolBatchRunStoragePathsInterface::class));
        $store = new SessionToolPayloadStore($paths, $container->get('serializer'), $container->get(ValidatorInterface::class), new Filesystem(), locks: self::getContainer()->get(LockFactory::class));
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        $scoped = $store->forRun($run);
        $references = [];
        for ($i = 0; $i < 12; ++$i) {
            $references[] = $scoped->publish(new ExecuteToolCall($run, 1, 'tools', 1, 'key-'.$i, 'call-'.$i, 'echo', ['text' => 'hello'], $i));
        }
        $this->assertSame(1, $paths->calls);
        // One path resolution plus one authoritative owner check per short
        // publication; cached paths must not cache permission to recreate data.
        $this->assertSame(13, $this->parentCounts($sql));
        $sql->records = [];
        foreach ($references as $i => $reference) {
            $this->assertSame('call-'.$i, $scoped->read($reference)->toolCallId);
        }
        $this->assertSame(1, $paths->calls);
        $this->assertSame(0, $this->parentCounts($sql));
        foreach ($references as $reference) {
            $scoped->deleteCall($run, $reference->toolCallId);
        }
        $this->assertSame(1, $paths->calls);
        $this->assertSame(0, $this->parentCounts($sql));
        $store->forRun($run);
        $this->assertSame(2, $paths->calls);
        $this->assertSame(1, $this->parentCounts($sql));
    }

    public function testScopeRetainsDifferentDataKindsForTheSameCallAndRejectsChangedBytes(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('shared call data');
        $store = $container->get(SessionToolPayloadStore::class)->forRun($run);
        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'key', 'same-call', 'echo', ['value' => 42], 0);
        $result = new ToolCallResult($run, 1, 'tools', 1, 'key', 'same-call', 0, ['tool_name' => 'echo', 'content' => [['type' => 'text', 'text' => 'done']]]);
        $correlation = new DeferredToolCompletionCorrelation('deferred', $run, 1, 'tools', 1, 'key', 'same-call', 'echo', ['value' => 42], 0, argSchema: ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']]], toolsRef: 'tools-ref');
        $callRef = $store->publish($call);
        $resultRef = $store->publish($result);
        $correlationRef = $store->publishCorrelation($correlation);
        $this->assertCount(3, array_unique([$callRef->sha256, $resultRef->sha256, $correlationRef->sha256]));
        $this->assertEquals($call, $store->read($callRef));
        $this->assertSame('done', $store->read($resultRef)->result['content'][0]['text']);
        $this->assertEquals($correlation, $store->readCorrelation($correlationRef));
        $root = $container->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads/'.hash('sha256', 'same-call');
        $path = $root.'/'.$callRef->sha256.'.json';
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0600, fileperms($root.'/'.$correlationRef->sha256.'.json') & 0777);
        $this->assertSame(0700, fileperms($root) & 0777);
        $original = file_get_contents($path);
        $this->assertIsString($original);
        file_put_contents($path, str_repeat('x', \strlen($original)));
        $this->expectException(ToolPayloadException::class);
        $this->expectExceptionMessage('integrity check');
        $store->read($callRef);
    }

    public function testIdentityChecksKeepCallResultShellAndCorrelationPoliciesSeparate(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('identity policies');
        $store = $container->get(SessionToolPayloadStore::class);
        $call = $store->publish(new ExecuteToolCall($run, 1, 'tools', 1, 'key', 'call', 'echo', [], 0));
        $result = $store->publish(new ToolCallResult($run, 1, 'tools', 1, 'key', 'call', 0, 'done'));
        $correlation = $store->publishCorrelation(new DeferredToolCompletionCorrelation('deferred', $run, 1, 'tools', 1, 'key', 'call', 'echo', [], 0));
        foreach ([$call, $result, $correlation] as $reference) {
            $bad = new ToolPayloadReferenceDTO(...[...get_object_vars($reference), 'orderIndex' => 7]);
            try {
                $store->read($bad);
                $this->fail('Batch-backed data must retain its order identity.');
            } catch (ToolPayloadException $exception) {
                $this->assertStringContainsString('identity', $exception->getMessage());
            }
        }
        foreach ([$call, $correlation] as $reference) {
            $bad = new ToolPayloadReferenceDTO(...[...get_object_vars($reference), 'toolName' => 'different']);
            try {
                $store->read($bad);
                $this->fail('Invocation and correlation routing identities must match.');
            } catch (ToolPayloadException $exception) {
                $this->assertStringContainsString('identity', $exception->getMessage());
            }
        }
        $this->assertSame('done', $store->read(new ToolPayloadReferenceDTO(...[...get_object_vars($result), 'toolName' => 'different']))->result);
        $shell = new ExecuteShellToolCall($run, 1, 'shell', 1, 'shell-call', 'printf hello', true);
        $reference = $store->publish($shell);
        $this->assertEquals($shell, $store->read(new ToolPayloadReferenceDTO(...[...get_object_vars($reference), 'orderIndex' => 7, 'toolName' => 'different'])));
    }

    public function testReadRefusesWrongLengthKindAndInvocationIdentity(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('refusal');
        $store = $container->get(SessionToolPayloadStore::class);
        $reference = $store->publish(new ExecuteToolCall($run, 1, 'tools', 1, 'key', 'call', 'echo', [], 0));
        foreach ([['call', 1, $reference->bytes + 1, 'integrity check'], ['result', 1, $reference->bytes, 'kind'], ['call', 2, $reference->bytes, 'identity']] as [$kind, $attempt, $bytes, $reason]) {
            $bad = new ToolPayloadReferenceDTO($kind, $run, 1, 'tools', $attempt, 'key', 'call', 0, 'echo', $reference->sha256, $bytes);
            try {
                $store->read($bad);
                $this->fail('Changed reference must be rejected.');
            } catch (ToolPayloadException $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }
        $this->assertSame('call', $store->read($reference)->toolCallId);
    }

    #[DataProvider('normalizationProvider')]
    public function testPublicationReusesTheCheckedNormalizerRepresentation(string $mode, ?string $failure): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('normalization reuse');
        $normalizer = new class($container->get('serializer'), $mode) implements NormalizerInterface, DenormalizerInterface {
            public int $resultCalls = 0;

            public function __construct(private SerializerInterface&NormalizerInterface&DenormalizerInterface $inner, private string $mode)
            {
            }

            public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
            {
                $normalized = $this->inner->normalize($data, $format, $context);
                if ($data instanceof ToolCallResult && 1 === ++$this->resultCalls) {
                    if ('throw' === $this->mode) {
                        throw new \RuntimeException('Injected normalizer failure.');
                    }
                    if ('throw-utf8' === $this->mode) {
                        throw new \JsonException('Injected UTF-8 failure.', \JSON_ERROR_UTF8);
                    }
                    if ('non-array' === $this->mode) {
                        return 'unexpected representation';
                    }
                    if ('utf8' === $this->mode) {
                        $normalized['result']['content'][0]['text'] = "\xff";
                    }
                }

                return $normalized;
            }

            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
            {
                return \is_object($data);
            }

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                return $this->inner->denormalize($data, $type, $format, $context);
            }

            public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function getSupportedTypes(?string $format): array
            {
                return ['*' => false];
            }
        };
        $serializer = new Serializer([$normalizer], [new JsonEncoder()]);
        $store = new SessionToolPayloadStore($container->get(ToolBatchRunStoragePathsInterface::class), $serializer, $container->get(ValidatorInterface::class), new Filesystem(), locks: self::getContainer()->get(LockFactory::class));
        $result = new ToolCallResult($run, 3, 'tools', 2, 'private-key', 'call', 4, ['tool_name' => 'echo', 'content' => [['type' => 'text', 'text' => 'complete']]]);
        $reference = $store->publish($result);
        $this->assertSame(null === $failure ? 1 : 2, $normalizer->resultCalls);
        $this->assertSame([$run, 3, 'tools', 2, 'private-key', 4, 'echo'], [$reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->orderIndex, $reference->toolName]);
        $actual = $store->read($reference);
        $expected = null === $failure ? $result : $result->withRepresentationFailure($failure);
        $this->assertEquals($expected, $actual);
        $path = $container->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads/'.hash('sha256', 'call').'/'.$reference->sha256.'.json';
        $data = json_decode(file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR)['data'];
        $this->assertSame('private-key', $data['idempotency_key']);
        $this->assertSame('call', $data['tool_call_id']);
        $this->assertArrayNotHasKey('toolCallId', $data);
    }

    public static function normalizationProvider(): iterable
    {
        yield 'valid' => ['valid', null];
        yield 'malformed representation' => ['utf8', ToolResultText::FAILURE_MESSAGE];
        yield 'normalizer exception' => ['throw', ToolResultText::INSPECTION_FAILURE_MESSAGE];
        yield 'UTF-8 exception' => ['throw-utf8', ToolResultText::FAILURE_MESSAGE];
        yield 'non-array representation' => ['non-array', ToolResultText::INSPECTION_FAILURE_MESSAGE];
    }

    public function testDocumentSerializationFailuresPropagateWithoutPublishingAFile(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('strict document serialization');
        $store = $container->get(SessionToolPayloadStore::class);
        try {
            $store->publish(new ToolCallResult($run, 1, 'tools', 1, "key-\xff", 'call', 0, 'valid body'));
            $this->fail('Explicit private identities still require strict serialization.');
        } catch (NotEncodableValueException $exception) {
            $this->assertStringContainsString('UTF-8', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($sessions->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads');
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }

    private function parentCounts(TestLogger $sql): int
    {
        return \count(array_filter($sql->records, static fn (array $record): bool => str_contains(strtolower($record['context']['sql'] ?? ''), 'hatfield_session') && str_contains(strtolower($record['context']['sql'] ?? ''), 'count')));
    }
}
