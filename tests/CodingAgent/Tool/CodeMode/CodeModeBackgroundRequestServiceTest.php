<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeBackgroundRequestService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\StopCodeModeDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

final class CodeModeBackgroundRequestServiceTest extends IsolatedKernelTestCase
{
    public function testBothClientsDiscoverOnlyReadyCurrentTopLevelInvocations(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        foreach ([InProcessAgentSessionClient::class, JsonlProcessAgentSessionClient::class] as $client) {
            $this->assertSame([], $c->get($client)->foregroundCodeMode($f->state->runId));
        }
        $id = $f->registerReadyHost();
        foreach ([InProcessAgentSessionClient::class, JsonlProcessAgentSessionClient::class] as $client) {
            $candidate = $c->get($client)->foregroundCodeMode($f->state->runId);
            $this->assertCount(1, $candidate);
            $this->assertSame($id, $candidate[0]->identity);
            $this->assertSame('cm', $candidate[0]->toolCallId);
            $this->assertSame([], $c->get($client)->foregroundCodeMode('foreign'));
        }
        $c->get(CodeModeExecutionService::class)->preserve($id, 42, null);
        $this->assertSame([], $c->get(CodeModeBackgroundRequestService::class)->candidates($f->state->runId));
    }

    public function testProducerAndOwnerFenceTheExactInvocationAndStopNeverCancelsParent(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $f->registerReadyHost();
        $service = $c->get(CodeModeBackgroundRequestService::class);
        $payload = ['identity' => $id, 'tool_call_id' => 'cm'];
        $this->assertFalse($service->request($f->state->runId, ['identity' => 'invalid']));
        $this->assertFalse($service->request('foreign', $payload));
        $this->assertFalse($service->request($f->state->runId, ['identity' => $id, 'tool_call_id' => 'other']));
        $this->assertFalse($service->request($f->state->runId, $payload, true));
        $this->assertTrue($service->request($f->state->runId, $payload));
        $this->receiveLast(CodeModeOwnerDTO::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $this->assertNotNull($deferred->acknowledgedAt($id));
        $this->assertFalse($service->request($f->state->runId, $payload));
        $this->assertSame([], $service->candidates($f->state->runId));
        $other = Uuid::v7()->toRfc4122();
        $native = $c->get(CodeModeExecutionRepository::class);
        $native->register($other, $f->state->runId, 'other-host');
        $this->assertTrue($service->request($f->state->runId, $payload, true));
        $this->receiveLast(StopCodeModeDTO::class);
        $this->assertSame('cancelled', $native->invocation($f->state->runId, 'cm')['interruption_reason']);
        $this->assertTrue($native->isActive($other));
        $this->assertSame(RunStatus::Running, $c->get(ActiveRunContextInterface::class)->requireLoaded($f->state->runId)->status);
        $this->assertFalse($service->request($f->state->runId, $payload, true));
        $snapshot = $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($f->state->runId);
        $this->assertCount(1, $snapshot->work);
        $this->assertSame('stopping', $snapshot->work[0]->status);
        $this->assertFalse($snapshot->work[0]->terminal, 'An invocation fence is not physical host completion.');
        $c->get(CodeModeExecutionService::class)->preserve($id, null, new \RuntimeException('cooperative cancellation'));
        $this->assertSame([], $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($f->state->runId)->work);
        $observed = $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($f->state->runId, observedCodeModeIdentity: $id);
        $this->assertTrue($observed->work[0]->terminal);
        $this->assertSame('interrupted', $observed->work[0]->status);
        $this->assertSame([], $c->get(BackgroundWorkSnapshotProvider::class)->snapshot('foreign', observedCodeModeIdentity: $id)->work);
    }

    public function testInventoryIncludesRootAndOwnedChildHostsWithoutForeignOrForegroundRows(): void
    {
        $c = self::getContainer();
        $root = new CodeModeBackendFixture($c, 'return 1;');
        $rootId = $root->registerReadyHost();
        $service = $c->get(CodeModeBackgroundRequestService::class);
        $this->assertTrue($service->request($root->state->runId, ['identity' => $rootId, 'tool_call_id' => 'cm']));
        $this->receiveLast(CodeModeOwnerDTO::class);
        $identities = new DeferredSubagentBatchIdentityFactory();
        $child = $identities->childIdentity($root->state->runId, 'child-call', 1);
        $c->get(ChildRunArtifactLifecycleService::class)->reservePending(new ChildRunIdentityDTO($root->state->runId, $child['childRunId'], $child['artifactId'], 'scout', 'inventory', AgentArtifactKindEnum::Subagent));
        $c->get(DeferredSubagentBatchRepository::class)->reserveBatch(
            $identities->batchLifecycleId($root->state->runId, 'child-call'), $root->state->runId, 1, 'child-call', 0, new \DateTimeImmutable('+8 seconds'),
            new DeferredSubagentBatchChildIntentDTO(1, $child['childRunId'], $child['artifactId'], 'scout', 'inventory', 'model', 'medium'),
        );
        $childFixture = new CodeModeBackendFixture($c, 'return 2;', runId: $child['childRunId']);
        $childId = $childFixture->registerReadyHost();
        $this->assertTrue($service->request($child['childRunId'], ['identity' => $childId, 'tool_call_id' => 'cm']));
        $this->receiveLast(CodeModeOwnerDTO::class);
        $foreign = new CodeModeBackendFixture($c, 'return 3;');
        $foreign->registerReadyHost();
        $snapshot = $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($root->state->runId);
        $this->assertCount(2, $snapshot->work);
        $this->assertSame([$rootId, $childId], array_column($snapshot->work, 'id'));
        $this->assertStringContainsString('main run:'.$root->state->runId, $snapshot->work[0]->label);
        $this->assertStringContainsString('scout agent_id:'.$child['artifactId'].' run:'.$child['childRunId'], $snapshot->work[1]->label);
        $this->assertSame([], $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($foreign->state->runId)->work);
    }

    public function testQueuedSnapshotCannotAcknowledgeAfterNativeCompletionWins(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $f->registerReadyHost();
        $this->assertTrue($c->get(CodeModeBackgroundRequestService::class)->request($f->state->runId, ['identity' => $id, 'tool_call_id' => 'cm']));
        $c->get(CodeModeExecutionService::class)->preserve($id, 42, null);
        $this->receiveLast(CodeModeOwnerDTO::class);
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->acknowledgedAt($id));
    }

    /** @param class-string $messageClass */
    private function receiveLast(string $messageClass): void
    {
        $c = self::getContainer();
        $sent = array_values(array_filter($c->get('messenger.transport.run_control')->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof $messageClass));
        $this->assertNotEmpty($sent);
        $c->get('agent.command.bus')->dispatch($sent[array_key_last($sent)]->with(new ReceivedStamp('run_control')));
    }
}
