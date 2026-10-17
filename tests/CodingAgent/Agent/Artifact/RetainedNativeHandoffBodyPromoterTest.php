<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\OwningRootSessionResolver;
use Ineersa\CodingAgent\Agent\Artifact\RetainedNativeHandoffBodyPromoter;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class RetainedNativeHandoffBodyPromoterTest extends IsolatedKernelTestCase
{
    public function testSealsOwnedAndNestedGenerationsAndRejectsForeignBeforeRead(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        /** @var AgentArtifactRegistry $registry */
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        /** @var SessionAgentArtifactPathResolver $paths */
        $paths = self::getContainer()->get(SessionAgentArtifactPathResolver::class);
        /** @var SessionRetainedToolCompletionBodyStore $store */
        $store = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);
        /** @var DeferredSubagentBatchRepository $batches */
        $batches = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        /** @var DeferredSubagentChildRepository $deferredChildren */
        $deferredChildren = self::getContainer()->get(DeferredSubagentChildRepository::class);

        $root = $sessions->createSession('native-promoter-root');
        $foreign = $sessions->createSession('native-promoter-foreign');
        $identity = new DeferredSubagentBatchIdentityFactory();

        $depth1Tool = 'tool-native-promoter-'.bin2hex(random_bytes(3));
        $depth1Lifecycle = $identity->batchLifecycleId($root, $depth1Tool);
        $depth1 = $identity->childIdentity($root, $depth1Tool, 1);
        $batches->reserveBatch(
            lifecycleId: $depth1Lifecycle,
            parentRunId: $root,
            parentTurnNo: 1,
            parentToolCallId: $depth1Tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $depth1['childRunId'],
                'artifactId' => $depth1['artifactId'],
                'agentName' => 'depth1',
                'task' => 'depth1 task',
                'launchModel' => 'deepseek/deepseek-v4-flash',
                'launchReasoning' => 'medium',
            ]],
        );
        $registry->create($root, $depth1['artifactId'], $depth1['childRunId'], 'depth1', AgentArtifactKindEnum::Subagent);

        $coldChildren = new AgentChildRunDirectory($sessions, $registry, new NullLogger());
        $resolver = new OwningRootSessionResolver($coldChildren, $sessions, $deferredChildren, $batches);
        $promoter = new RetainedNativeHandoffBodyPromoter($paths, $store, $resolver);

        $rootArtifactId = 'agent_root_'.bin2hex(random_bytes(3));
        $registry->create($root, $rootArtifactId, 'child-root-'.bin2hex(random_bytes(3)), 'scout', AgentArtifactKindEnum::Subagent);
        $rootGeneration = $registry->writeHandoff($root, $rootArtifactId, str_repeat('root-handoff-', 40), summary: 'root');
        $rootSealed = $promoter->sealOwnedCopy($root, 'completion-root-native', $rootGeneration);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $rootSealed->kind);
        $this->assertTrue($store->isHeld($root, 'completion-root-native'));

        $nestedArtifactId = 'agent_nested_'.bin2hex(random_bytes(3));
        $registry->create(
            $depth1['childRunId'],
            $nestedArtifactId,
            'grandchild-'.bin2hex(random_bytes(3)),
            'nested',
            AgentArtifactKindEnum::Subagent,
        );
        $nestedGeneration = $registry->writeHandoff(
            $depth1['childRunId'],
            $nestedArtifactId,
            str_repeat('nested-handoff-', 40),
            summary: 'nested',
        );
        $nestedSealed = $promoter->sealOwnedCopy($root, 'completion-nested-native', $nestedGeneration);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $nestedSealed->kind);
        $this->assertTrue($store->isHeld($root, 'completion-nested-native'));
        $this->assertSame($nestedGeneration->sha256, $nestedSealed->sha256);

        $foreignArtifactId = 'agent_foreign_'.bin2hex(random_bytes(3));
        $registry->create($foreign, $foreignArtifactId, 'child-foreign-'.bin2hex(random_bytes(3)), 'scout', AgentArtifactKindEnum::Subagent);
        $foreignGeneration = $registry->writeHandoff($foreign, $foreignArtifactId, 'foreign-body', summary: 'foreign');
        $sessionsBase = $sessions->resolveSessionsBasePath();
        $before = $this->listRetainedBodies($sessionsBase.'/'.$root);
        try {
            $promoter->sealOwnedCopy($root, 'completion-foreign-native', $foreignGeneration);
            $this->fail('Expected foreign-scope rejection before seal.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }
        $after = $this->listRetainedBodies($sessionsBase.'/'.$root);
        $this->assertSame($before, $after);
        $this->assertFalse($store->isHeld($root, 'completion-foreign-native'));
        $this->assertFileExists(
            $sessionsBase.'/'.$foreign.'/artifacts/agents/'.$foreignArtifactId.'/handoffs/'.$foreignGeneration->handoffId.'.md',
        );

        // Fabricated foreign parent must fail closed before any filesystem copy/seal.
        $fabricated = new RetainedNativeHandoffGeneration(
            parentRunId: $foreign,
            artifactId: $foreignArtifactId,
            handoffId: $foreignGeneration->handoffId,
            bytes: $foreignGeneration->bytes,
            sha256: $foreignGeneration->sha256,
            mediaType: $foreignGeneration->mediaType,
        );
        try {
            $promoter->sealOwnedCopy($root, 'completion-fabricated-native', $fabricated);
            $this->fail('Expected fabricated foreign generation rejection.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }
        $this->assertFalse($store->isHeld($root, 'completion-fabricated-native'));

        $sessions->deleteSession($root);
        $sessions->deleteSession($foreign);
    }

    /**
     * @return list<string>
     */
    private function listRetainedBodies(string $sessionDir): array
    {
        $dir = $sessionDir.'/runtime/retained-tool-completions';
        if (!is_dir($dir)) {
            return [];
        }
        $files = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $name): bool => !str_starts_with($name, '.'),
        ));
        sort($files);

        return $files;
    }
}
