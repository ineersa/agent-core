<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Owner-reference JSON must round-trip through the configured kernel serializer
 * at the deferred-tool repository boundary without custom coercion.
 */
#[Group('db')]
final class DeferredToolCompletionOwnerReferenceSerializationTest extends IsolatedKernelTestCase
{
    private DeferredToolCompletionRepository $repository;
    private Connection $connection;
    private SerializerInterface $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DeferredToolCompletionRepository $repository */
        $repository = self::getContainer()->get(DeferredToolCompletionRepository::class);
        $this->repository = $repository;

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $this->serializer = $serializer;
    }

    #[Test]
    public function registerPendingRoundTripsOwnerReferenceThroughConfiguredSerializer(): void
    {
        $owner = new ToolInvocationOwnerReference('bash', 'record-owner-full', 4242, '/tmp/owner.log');
        $registered = $this->repository->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440700',
            runId: 'run-owner-full',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-owner-full',
            toolCallId: 'call-owner-full',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            executionOwnerKind: 'bash',
            executionOwnerReference: $owner,
        ));

        $this->assertNotNull($registered->executionOwnerReference);
        $this->assertSame('bash', $registered->executionOwnerReference->kind);
        $this->assertSame('record-owner-full', $registered->executionOwnerReference->recordId);
        $this->assertSame(4242, $registered->executionOwnerReference->presentationPid);
        $this->assertSame('/tmp/owner.log', $registered->executionOwnerReference->presentationLogPath);

        $row = $this->connection->fetchAssociative(
            'SELECT execution_owner_reference_json FROM deferred_tool_completion WHERE deferred_id = :deferred_id',
            ['deferred_id' => $registered->deferredId],
        );
        $this->assertIsArray($row);
        $this->assertIsString($row['execution_owner_reference_json']);
        $this->assertJsonStringEqualsJsonString(
            $this->serializer->serialize($owner, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]),
            $row['execution_owner_reference_json'],
        );

        $identityOnly = new ToolInvocationOwnerReference('bash', 'record-owner-nulls');
        $registeredNulls = $this->repository->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440701',
            runId: 'run-owner-nulls',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-owner-nulls',
            toolCallId: 'call-owner-nulls',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 1,
            executionOwnerKind: 'bash',
            executionOwnerReference: $identityOnly,
        ));

        $this->assertNotNull($registeredNulls->executionOwnerReference);
        $this->assertSame('bash', $registeredNulls->executionOwnerReference->kind);
        $this->assertSame('record-owner-nulls', $registeredNulls->executionOwnerReference->recordId);
        $this->assertNull($registeredNulls->executionOwnerReference->presentationPid);
        $this->assertNull($registeredNulls->executionOwnerReference->presentationLogPath);

        $nullRow = $this->connection->fetchAssociative(
            'SELECT execution_owner_reference_json FROM deferred_tool_completion WHERE deferred_id = :deferred_id',
            ['deferred_id' => $registeredNulls->deferredId],
        );
        $this->assertIsArray($nullRow);
        $this->assertIsString($nullRow['execution_owner_reference_json']);
        $this->assertJsonStringEqualsJsonString(
            '{"kind":"bash","record_id":"record-owner-nulls"}',
            $nullRow['execution_owner_reference_json'],
        );
        $this->assertStringNotContainsString('presentation_pid', $nullRow['execution_owner_reference_json']);
        $this->assertStringNotContainsString('presentation_log_path', $nullRow['execution_owner_reference_json']);
    }

    #[Test]
    #[DataProvider('corruptOwnerReferencePayloads')]
    public function findByDeferredIdRejectsCorruptOwnerReferencePayloads(string $payloadJson): void
    {
        $registered = $this->repository->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440702',
            runId: 'run-owner-corrupt',
            turnNo: 4,
            stepId: 'turn-4-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-owner-corrupt',
            toolCallId: 'call-owner-corrupt',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-owner-corrupt', 11, '/tmp/ok.log'),
        ));

        $this->connection->executeStatement(
            'UPDATE deferred_tool_completion
             SET execution_owner_reference_json = :payload
             WHERE deferred_id = :deferred_id',
            [
                'payload' => $payloadJson,
                'deferred_id' => $registered->deferredId,
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('Deferred tool owner reference is corrupt for "%s".', $registered->deferredId));

        $this->repository->findByDeferredId($registered->deferredId);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function corruptOwnerReferencePayloads(): iterable
    {
        yield 'fractional pid' => [json_encode([
            'kind' => 'bash',
            'record_id' => 'record-owner-corrupt',
            'presentation_pid' => 1.5,
            'presentation_log_path' => '/tmp/ok.log',
        ], \JSON_THROW_ON_ERROR)];
        yield 'boolean log path' => [json_encode([
            'kind' => 'bash',
            'record_id' => 'record-owner-corrupt',
            'presentation_pid' => 11,
            'presentation_log_path' => true,
        ], \JSON_THROW_ON_ERROR)];
        yield 'non-string kind' => [json_encode([
            'kind' => 7,
            'record_id' => 'record-owner-corrupt',
        ], \JSON_THROW_ON_ERROR)];
        yield 'non-string record id' => [json_encode([
            'kind' => 'bash',
            'record_id' => ['nested'],
        ], \JSON_THROW_ON_ERROR)];
        yield 'empty kind' => [json_encode([
            'kind' => '',
            'record_id' => 'record-owner-corrupt',
        ], \JSON_THROW_ON_ERROR)];
        yield 'non-positive pid' => [json_encode([
            'kind' => 'bash',
            'record_id' => 'record-owner-corrupt',
            'presentation_pid' => 0,
        ], \JSON_THROW_ON_ERROR)];
    }
}
