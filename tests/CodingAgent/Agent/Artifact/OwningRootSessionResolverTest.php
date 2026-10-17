<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\OwningRootSessionResolver;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class OwningRootSessionResolverTest extends IsolatedKernelTestCase
{
    public function testNestedChildEvidenceSealsUnderRootWithoutPhantomChildSession(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        /** @var AgentArtifactRegistry $registry */
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        /** @var SessionRetainedToolCompletionBodyStore $store */
        $store = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);
        /** @var RetainedToolCompletionEnvelopeFactory $factory */
        $factory = self::getContainer()->get(RetainedToolCompletionEnvelopeFactory::class);

        $root = $sessions->createSession('owning-root-nested');
        $childRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_root_'.bin2hex(random_bytes(3));
        $entry = $registry->create($root, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $children = new AgentChildRunDirectory($sessions, $registry, new NullLogger());
        $children->register($entry);
        $resolver = new OwningRootSessionResolver($children, $sessions);
        $this->assertSame($root, $resolver->resolveOwningRootSessionId($childRunId));

        $large = str_repeat('nested-body-', 1000);
        $normalized = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => $large]],
            'is_error' => false,
        ];
        $envelope = $factory->build(
            completionId: 'completion-nested',
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440910',
                runId: $childRunId,
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-nested',
                toolCallId: 'call-nested',
                toolName: 'bash',
                arguments: ['command' => 'true'],
                orderIndex: 0,
                invocationId: 'inv-nested',
                executionOwnerKind: 'bash',
            ),
            result: new ToolCallResult($childRunId, 1, 'step-1', 1, 'key-nested', 'call-nested', 0, $normalized),
            normalizedToolResult: $normalized,
            executionOwnerKind: 'bash',
        );

        $this->assertSame($root, $envelope->origin->controllerRunId);
        $this->assertSame($childRunId, $envelope->origin->sourceRunId);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
        $this->assertTrue($store->isHeld($root, 'completion-nested'));
        $this->assertTrue($store->exists($envelope->body, $root));

        $sessionsBase = $sessions->resolveSessionsBasePath();
        $this->assertDirectoryDoesNotExist($sessionsBase.'/'.$childRunId);
        $this->assertFileExists($sessionsBase.'/'.$root.'/runtime/retained-tool-completions/completion-nested.json');

        $foreign = $sessions->createSession('owning-root-foreign');
        $sessions->deleteSession($root);
        $this->assertFalse($store->isHeld($root, 'completion-nested'));
        $this->assertDirectoryDoesNotExist($sessionsBase.'/'.$root);
        $this->assertDirectoryExists($sessionsBase.'/'.$foreign);
        $sessions->deleteSession($foreign);
    }
}
