<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\OwningRootSessionResolver;
use Ineersa\CodingAgent\Agent\Artifact\RetainedNativeArtifactHandoffReader;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class RetainedNativeArtifactHandoffReaderTest extends IsolatedKernelTestCase
{
    public function testNestedScopeIntegrityTruncationAndForeignRejection(): void
    {
        /** @var HatfieldSessionStore $sessions */
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        /** @var AgentArtifactRegistry $registry */
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $root = $sessions->createSession('retained-native-root');
        $childRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_native_'.bin2hex(random_bytes(3));
        $entry = $registry->create($root, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $children = new AgentChildRunDirectory($sessions, $registry, new NullLogger());
        $children->register($entry);
        /** @var DeferredSubagentChildRepository $deferredChildren */
        $deferredChildren = self::getContainer()->get(DeferredSubagentChildRepository::class);
        /** @var DeferredSubagentBatchRepository $deferredBatches */
        $deferredBatches = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $resolver = new OwningRootSessionResolver($children, $sessions, $deferredChildren, $deferredBatches);
        $reader = new RetainedNativeArtifactHandoffReader($registry, $resolver);

        $this->assertSame($root, $resolver->resolveOwningRootSessionId($childRunId));
        $this->assertSame($root, $resolver->resolveOwningRootSessionId($root));

        $body = str_repeat('handoff-body-', 400);
        $generation = $registry->writeHandoff($root, $artifactId, $body, summary: 'first');

        $full = $reader->inspectHandoff(
            authorizedRootSessionId: $root,
            parentRunId: $root,
            artifactId: $artifactId,
            handoffId: $generation->handoffId,
            expectedBytes: $generation->bytes,
            expectedSha256: $generation->sha256,
            expectedMediaType: $generation->mediaType,
        );
        $this->assertFalse($full['truncated']);
        $this->assertSame($body, $full['text']);

        $truncated = $reader->inspectHandoff(
            authorizedRootSessionId: $root,
            parentRunId: $root,
            artifactId: $artifactId,
            handoffId: $generation->handoffId,
            expectedBytes: $generation->bytes,
            expectedSha256: $generation->sha256,
            expectedMediaType: $generation->mediaType,
            maxDecodeBytes: 32,
        );
        $this->assertTrue($truncated['truncated']);
        $this->assertArrayNotHasKey('text', $truncated);
        $this->assertSame($generation->sha256, $truncated['sha256']);

        try {
            $reader->inspectHandoff(
                authorizedRootSessionId: $root,
                parentRunId: $root,
                artifactId: $artifactId,
                handoffId: '0199aaaa-bbbb-7ccc-8ddd-ffffffffffff',
                expectedBytes: $generation->bytes,
                expectedSha256: $generation->sha256,
                expectedMediaType: $generation->mediaType,
            );
            $this->fail('Expected missing handoff.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::BodyMissing, $failure->code());
        }

        try {
            $reader->inspectHandoff(
                authorizedRootSessionId: $root,
                parentRunId: $root,
                artifactId: $artifactId,
                handoffId: $generation->handoffId,
                expectedBytes: $generation->bytes,
                expectedSha256: str_repeat('0', 64),
                expectedMediaType: $generation->mediaType,
            );
            $this->fail('Expected corrupt handoff.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, $failure->code());
        }

        $foreign = $sessions->createSession('retained-native-foreign');
        try {
            $reader->inspectHandoff(
                authorizedRootSessionId: $foreign,
                parentRunId: $root,
                artifactId: $artifactId,
                handoffId: $generation->handoffId,
                expectedBytes: $generation->bytes,
                expectedSha256: $generation->sha256,
                expectedMediaType: $generation->mediaType,
            );
            $this->fail('Expected foreign scope.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }

        $oversized = str_repeat('X', max(1_000_000, $generation->bytes * 50));
        $handoffPath = $sessions->resolveSessionsBasePath().'/'.$root.'/artifacts/agents/'.$artifactId.'/handoffs/'.$generation->handoffId.'.md';
        file_put_contents($handoffPath, $oversized);
        try {
            $reader->inspectHandoff(
                authorizedRootSessionId: $root,
                parentRunId: $root,
                artifactId: $artifactId,
                handoffId: $generation->handoffId,
                expectedBytes: $generation->bytes,
                expectedSha256: $generation->sha256,
                expectedMediaType: $generation->mediaType,
                maxDecodeBytes: $generation->bytes,
            );
            $this->fail('Expected oversized replacement to fail closed without full capture.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, $failure->code());
        }
        file_put_contents($handoffPath, $body);

        $second = $registry->writeHandoff($root, $artifactId, $body.'-v2', summary: 'second');
        $firstAgain = $reader->inspectHandoff(
            authorizedRootSessionId: $root,
            parentRunId: $root,
            artifactId: $artifactId,
            handoffId: $generation->handoffId,
            expectedBytes: $generation->bytes,
            expectedSha256: $generation->sha256,
            expectedMediaType: $generation->mediaType,
        );
        $this->assertSame($body, $firstAgain['text']);
        $this->assertNotSame($second->handoffId, $generation->handoffId);
    }
}
