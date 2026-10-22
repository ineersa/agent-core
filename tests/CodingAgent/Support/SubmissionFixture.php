<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionReceiptDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionFactory;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class SubmissionFixture
{
    public string $parent;
    public string $child;
    public string $artifact;
    public string $lifecycle;
    public RunState $parentState;
    public RunState $childState;

    /** @param list<string> $childTools */
    public function __construct(private readonly ContainerInterface $container, array $childTools = ['notify_parent'])
    {
        $this->parent = $container->get(HatfieldSessionStore::class)->createSession();
        $this->child = 'child-'.Uuid::v7();
        $this->artifact = 'artifact-'.Uuid::v7();
        $this->lifecycle = 'batch-'.Uuid::v7();
        $entry = $container->get(AgentArtifactRegistry::class)->create($this->parent, $this->artifact, $this->child, 'scout', AgentArtifactKindEnum::Subagent);
        $container->get(AgentChildRunDirectory::class)->register($entry);
        $container->get(DeferredSubagentBatchRepository::class)->reserveBatch($this->lifecycle, $this->parent, 1, 'spawn', 0, new \DateTimeImmutable('+1 hour'), new DeferredSubagentBatchChildIntentDTO(1, $this->child, $this->artifact, 'scout', 'task', 'model', 'medium'));
        $this->parentState = RunStateBuilder::running($this->parent)->withTurnNo(1)->withLastSeq(3)->withActiveStepId('step')->withPendingToolCalls(['spawn' => false, 'steer' => false])->build();
        $this->childState = RunStateBuilder::running($this->child)->withTurnNo(1)->withLastSeq(3)->withActiveStepId('step')->withPendingToolCalls(['notice' => false])->build()->with(['parentRunId' => $this->parent]);
        foreach ([$this->parentState, $this->childState] as $state) {
            $container->get(RunOperationalProjectionRepository::class)->replace($state);
            $session = $state->runId === $this->parent ? [] : ['kind' => 'agent_child', 'parent_run_id' => $this->parent, 'agent_name' => 'scout', 'artifact_id' => $this->artifact];
            $this->append($state->runId, 1, 0, 'run_started', ['step_id' => 'step', 'payload' => ['metadata' => [
                'model' => 'model', 'reasoning' => 'medium', 'session' => $session,
                'tools_scope' => ['allowed_tools' => $childTools, 'mcp' => ['mode' => 'none', 'tools' => []]],
            ]]]);
            $this->append($state->runId, 2, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step']);
            $this->append($state->runId, 3, 1, 'llm_step_completed', ['assistant_message' => ['tool_calls' => array_map(static fn (string $id): array => ['id' => $id], array_keys($state->pendingToolCalls))]]);
        }
    }

    public function status(string $run, RunStatus $status): void
    {
        $state = $run === $this->parent ? $this->parentState : $this->childState;
        $this->container->get(RunOperationalProjectionRepository::class)->replace($state->with(['status' => $status]));
    }

    /** @param array<string, mixed> $payload */
    public function append(string $run, int $seq, int $turn, string $type, array $payload = []): void
    {
        $source = $this->container->get(RunHistorySourceProviderInterface::class)->historySource($run);
        (new Filesystem())->mkdir(\dirname($source->path));
        $record = $this->container->get(EventPayloadNormalizer::class)->normalize($run, $seq, $turn, $type, $payload);
        file_put_contents($source->path, json_encode($record, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
    }

    public function prepare(bool $notice = false, string $text = 'Important guidance'): SubmissionReceiptDTO
    {
        return $this->container->get(SubmissionFactory::class)->prepare($notice ? $this->child : $this->parent, 1, 'step', $notice ? 'notice' : 'steer', $notice ? 'notify_parent' : 'agent_steer', $notice ? ['text' => $text] : ['text' => $text, 'artifact_id' => $this->artifact]);
    }

    public function register(SubmissionReceiptDTO $receipt): void
    {
        $correlation = new DeferredToolCompletionCorrelation($receipt->identity, $receipt->sourceRunId, 1, 'step', 1, 'source-key', 'notify_parent' === $receipt->toolName ? 'notice' : 'steer', $receipt->toolName, 'notify_parent' === $receipt->toolName ? ['text' => $receipt->text] : ['text' => $receipt->text, 'artifact_id' => $this->artifact], 0);
        TestToolBatchRegistration::registerDeferredInvocation($this->container->get(ToolBatchStoreInterface::class), $correlation);
        $this->container->get(DeferredToolCompletionRepositoryInterface::class)->registerPending($correlation);
    }
}
