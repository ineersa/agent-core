<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ProjectionPipeline;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ModelNotificationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class UserMessageProjectionSubscriberTest extends TestCase
{
    private TranscriptProjector $projector;
    private RuntimeEventTranslator $translator;

    protected function setUp(): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new ModelNotificationProjectionSubscriber($serializer));
        $this->projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $this->translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer), $serializer);
    }

    public function testInitialMessagesRetainHostMetadataButExcludePrivateContext(): void
    {
        $task = ['agent_task' => ['artifact_id' => 'child-artifact']];
        $runtime = $this->translator->translate(new RunEvent('run', 1, 0, 'run_started', ['payload' => ['messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Delegated task']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Delegated task']], 'metadata' => $task],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '!echo human']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '<system-reminder>Host reminder</system-reminder>']], 'metadata' => ['system_reminder' => true]],
            ['role' => 'user-context', 'content' => [['type' => 'text', 'text' => 'Private context']], 'metadata' => ['source' => 'agents_context']],
        ]]]));
        $this->projector->accept($runtime);
        $blocks = $this->projector->blocks();
        $this->assertCount(4, $blocks);
        $this->assertSame('Delegated task', $blocks[0]->text);
        $this->assertSame([], $blocks[0]->meta);
        $this->assertSame($blocks[0]->text, $blocks[1]->text);
        $this->assertSame($task, $blocks[1]->meta['producer_metadata']);
        $this->assertSame('!echo human', $blocks[2]->text);
        $this->assertSame([], $blocks[2]->meta);
        $this->assertTrue($blocks[3]->meta['producer_metadata']['system_reminder']);
    }

    #[TestWith(['bash', '[BG_PROCESS_DONE] PID 42 finished'])]
    #[TestWith(['subagent', 'Subagent scout completed. Artifact: child'])]
    #[TestWith(['code_mode', 'Background code_mode result'])]
    public function testSameQuotedTextIsHumanUnlessItCarriesHostProvenance(string $kind, string $text): void
    {
        $origin = ['kind' => $kind, 'identity' => 'opaque-id', 'run_id' => 'original-source', 'tool_call_id' => 'call'];
        foreach ([[], ['background_completion' => $origin]] as $index => $metadata) {
            $runtime = $this->translator->translate(new RunEvent('run', $index + 1, 1, 'agent_command_applied', [
                'kind' => 'append_message', 'idempotency_key' => 'key-'.$index,
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]], 'metadata' => $metadata],
            ]));
            $this->projector->accept($runtime);
        }
        $blocks = $this->projector->blocks();
        $this->assertSame([TranscriptBlockKindEnum::UserMessage, TranscriptBlockKindEnum::System], array_column($blocks, 'kind'));
        $this->assertSame([$text, $text], array_column($blocks, 'text'));
        $this->assertSame([], $blocks[0]->meta);
        $this->assertSame($origin, $blocks[1]->meta['producer_metadata']['background_completion']);
    }

    public function testOnlyHostTaggedAutomaticMessagesAreOmittedFromTheDisplayQueue(): void
    {
        $resume = new SessionResumeMetadataProjection();
        foreach ([[], ['background_completion' => ['identity' => 'completion']], ['agent_communication' => ['identity' => 'notice']], ['agent_task' => ['artifact_id' => 'artifact']], ['system_reminder' => true]] as $index => $metadata) {
            $canonical = new RunEvent('run', $index + 1, 1, 'agent_command_queued', [
                'kind' => 'append_message', 'idempotency_key' => 'key-'.$index,
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Subagent scout completed.']], 'metadata' => $metadata],
            ]);
            $runtime = $this->translator->translate($canonical);
            if (0 === $index) {
                $this->assertSame('user.message_queued', $runtime->type);
            } else {
                $this->assertNull($runtime);
            }
            $resume->observe($canonical, $runtime, true);
        }
        $this->assertSame(['key-0' => 'Subagent scout completed.'], $resume->toArray()['queued_messages']);
    }

    public function testSubmittedHostReminderKeepsProvenanceWithoutRelabelingAHumanQuote(): void
    {
        foreach ([[], ['system_reminder' => true]] as $index => $metadata) {
            $this->projector->accept($this->translator->translate(new RunEvent('run', $index + 1, 1, 'agent_command_applied', [
                'kind' => 'append_message', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => '<system-reminder>Reminder</system-reminder>']], 'metadata' => $metadata],
            ])));
        }
        $blocks = $this->projector->blocks();
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[0]->kind);
        $this->assertSame('<system-reminder>Reminder</system-reminder>', $blocks[0]->text);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[1]->kind);
        $this->assertTrue($blocks[1]->meta['producer_metadata']['system_reminder']);
    }
}
