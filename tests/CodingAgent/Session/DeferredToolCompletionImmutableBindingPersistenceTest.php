<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('db')]
final class DeferredToolCompletionImmutableBindingPersistenceTest extends IsolatedKernelTestCase
{
    #[DataProvider('mutatedBindingProvider')]
    public function testMutatedAcceptedImmutableBindingsRejectLifecycleProjection(array $updates, string $expectedMessageFragment): void
    {
        $container = self::getContainer();
        $label = implode('-', array_keys($updates));
        $run = $container->get(HatfieldSessionStore::class)->createSession('immutable binding '.$label);
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get('doctrine.dbal.default_connection');

        $correlation = $this->registerBound($repo, $run, 'call-'.$label);
        $connection->update(
            'deferred_tool_completion',
            $updates,
            ['deferred_id' => $correlation->deferredId],
        );

        try {
            $repo->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
                $correlation,
                ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                ToolInvocationExecutionObservationEnum::Active,
            ));
            $this->fail('Mutated immutable binding must reject lifecycle projection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessageFragment, $exception->getMessage());
        }

        $current = $repo->findByRunAndToolCall($run, $correlation->toolCallId);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $current?->callerDisposition);
        $this->assertSame('pending', $repo->status($correlation->deferredId));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function mutatedBindingProvider(): iterable
    {
        yield 'owner kind and record' => [[
            'execution_owner_kind' => 'code_mode',
            'execution_owner_reference_json' => '{"kind":"code_mode","record_id":"record-call-owner-kind-and-record","presentation_pid":4242,"presentation_log_path":"/tmp/binding.log"}',
        ], 'owner kind mismatch'];
        yield 'owner record id' => [[
            'execution_owner_reference_json' => '{"kind":"bash","record_id":"other-record","presentation_pid":4242,"presentation_log_path":"/tmp/binding.log"}',
        ], 'owner record mismatch'];
        yield 'controller run' => [['controller_run_id' => 'other-controller'], 'controller run mismatch'];
        yield 'invocation alias' => [['invocation_id' => 'other-alias'], 'invocation alias mismatch'];
        yield 'original execution mode' => [['original_execution_mode' => 'parallel'], 'original execution mode mismatch'];
        yield 'deadline metadata' => [['deadline_metadata_json' => '{"deadline_at":999}'], 'deadline metadata mismatch'];
    }

    public function testPresentationPidAndLogPathDoNotAuthorizeOrRejectLifecycle(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('presentation fields ignored');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get('doctrine.dbal.default_connection');

        $correlation = $this->registerBound($repo, $run, 'call-presentation');
        $connection->update(
            'deferred_tool_completion',
            [
                'execution_owner_reference_json' => '{"kind":"bash","record_id":"record-call-presentation","presentation_pid":9999,"presentation_log_path":"/tmp/other.log"}',
            ],
            ['deferred_id' => $correlation->deferredId],
        );

        $repo->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));

        $updated = $repo->findByRunAndToolCall($run, 'call-presentation');
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updated?->callerDisposition);
        $this->assertSame('record-call-presentation', $updated?->executionOwnerReference?->recordId);
        $this->assertSame(9999, $updated?->executionOwnerReference?->presentationPid);
    }

    public function testExactTargetNoOpStillRequiresImmutableBindingAndAcceptsWhenMatched(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('exact target binding');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);

        $correlation = $this->registerBound($repo, $run, 'call-noop');
        $action = new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $repo->applyLifecycleProjection($action);

        // Exact-target no-op still validates immutable binding first.
        $repo->applyLifecycleProjection($action);
        $again = $repo->findByRunAndToolCall($run, 'call-noop');
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $again?->callerDisposition);

        $mutatedExpected = new DeferredToolCompletionCorrelation(
            deferredId: $correlation->deferredId,
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            attempt: $correlation->attempt,
            idempotencyKey: $correlation->idempotencyKey,
            toolCallId: $correlation->toolCallId,
            toolName: $correlation->toolName,
            arguments: $correlation->arguments,
            orderIndex: $correlation->orderIndex,
            invocationId: 'mutated-alias',
            controllerRunId: $correlation->controllerRunId,
            executionOwnerKind: $correlation->executionOwnerKind,
            executionOwnerReference: $correlation->executionOwnerReference,
            originalExecutionMode: $correlation->originalExecutionMode,
            deadlineMetadata: $correlation->deadlineMetadata,
            envelopeFingerprint: $correlation->envelopeFingerprint,
            callerDisposition: ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            executionObservation: ToolInvocationExecutionObservationEnum::Active,
        );

        try {
            $repo->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
                $mutatedExpected,
                ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                ToolInvocationExecutionObservationEnum::Active,
            ));
            $this->fail('Exact-target no-op must still reject immutable binding mismatch.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invocation alias mismatch', $exception->getMessage());
        }
    }

    public function testAlreadyTargetedRowWithMutatedImmutableBindingIsRejectedOnLoad(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('targeted mutated binding');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get('doctrine.dbal.default_connection');

        $correlation = $this->registerBound($repo, $run, 'call-race');
        // Another writer reached the requested target while mutating an immutable binding.
        $connection->update(
            'deferred_tool_completion',
            [
                'caller_disposition' => ToolInvocationCallerDispositionEnum::BackgroundAcknowledged->value,
                'execution_observation' => ToolInvocationExecutionObservationEnum::Active->value,
                'invocation_id' => 'raced-alias',
            ],
            ['deferred_id' => $correlation->deferredId],
        );

        try {
            $repo->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
                $correlation,
                ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                ToolInvocationExecutionObservationEnum::Active,
            ));
            $this->fail('Lifecycle load must reject immutable binding mismatch before no-op or race acceptance.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invocation alias mismatch', $exception->getMessage());
        }
    }

    private function registerBound(
        DeferredToolCompletionRepositoryInterface $repo,
        string $runId,
        string $toolCallId,
    ): DeferredToolCompletionCorrelation {
        $owner = new ToolInvocationOwnerReference('bash', 'record-'.$toolCallId, 4242, '/tmp/binding.log');
        $base = new DeferredToolCompletionCorrelation(
            deferredId: Uuid::v7()->toRfc4122(),
            runId: $runId,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: $toolCallId.'-key',
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
            invocationId: 'inv-'.$toolCallId,
            controllerRunId: $runId,
            executionOwnerKind: 'bash',
            executionOwnerReference: $owner,
            originalExecutionMode: 'sequential',
            deadlineMetadata: ['deadline_at' => 123],
        );

        return $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            deadlineMetadata: $base->deadlineMetadata,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));
    }
}
