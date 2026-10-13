<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/**
 * Regression tests for TickPollListener / local tool-question cancellation.
 *
 * Local tool questions are boolean/confirm only (bash background prompts).
 * Extension approvals use canonical human_input.requested, not tool_question.
 */
final class TickPollListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private ?TuiTickDispatcher $contextTicks = null;

    public function testTrustedParentRecoveryExitsChildAndRefreshesTickOwnership(): void
    {
        $state = new TuiSessionState('parent');
        $state->handle = new RunHandle('parent', 'attached', 'old-request');
        $state->subagentLiveView->enter(new \Ineersa\Tui\Runtime\SubagentLiveChildDTO('child', 'artifact', 'Child',
            \Ineersa\Tui\Runtime\SubagentLiveStatusEnum::Running, 'Child work', 1, 'provider/model', 'low'));
        $state->queuedFollowUps = ['Deferred parent input'];
        $state->pendingEditorRestoreText = 'Pending parent restoration';
        $harness = new VirtualTuiHarness(sessionId: 'parent');
        $harness->screen()->promptEditor()->replaceText('Fresh draft');
        $order = [];
        $client = $this->createMock(AgentSessionClient::class);
        $client->method('events')->willReturnCallback(static function (string $run) use (&$order): array {
            $order[] = $run;

            return 'parent' === $run ? [new RuntimeEvent('session.restoring', 'parent', 0,
                ['previous_command_id' => 'old-request', 'command_id' => 'new-request'])] : [];
        });
        $client->expects($this->once())->method('endObservingChildRun')->with('child');
        $client->expects($this->never())->method('send');
        $childProjector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $childProjector->accept(new RuntimeEvent('user.message_submitted', 'child', 1, ['text' => 'Child-only projection']));
        $childPoller = new SubagentLiveChildViewPoller($childProjector, new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
        $coordinator = new QuestionCoordinator();
        $question = new QuestionRequest('child-question', QuestionSource::AgentCore, QuestionKind::Confirm, 'Child question', runId: 'child');
        $coordinator->enqueue($question);
        $controller = new QuestionController($coordinator, $harness->screen());
        $controller->open($question);
        $poller = new RuntimeEventPoller(new TuiRuntimeEventApplier(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), SubagentProgressSerializerTestSupport::denormalizer()),
            new NullLogger(), new RuntimeExceptionBoundary(new EventDispatcher()), $this->createStub(SessionTranscriptProviderInterface::class));
        $services = $this->createSessionServices(tui: $harness->tui(), screen: $harness->screen(), state: $state,
            client: $client, parentPoller: $poller, childPoller: $childPoller,
            questionCoordinator: $coordinator, questionController: $controller, subagentLivePicker: $this->closedSubagentLivePicker());
        $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState($state)
            ->withClient($client)->withSessionServices($services)->build();
        $this->createTickPollListener()->register($context);
        $context->ticks->dispatch(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame(['child', 'parent'], $order);
        $this->assertFalse($state->subagentLiveView->active);
        $this->assertSame('parent', $state->visibleQuestionOwnerRunId());
        $this->assertSame([], $childProjector->blocks());
        $this->assertFalse($coordinator->actionRequired());
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['Deferred parent input'], $state->queuedFollowUps);
        $this->assertSame("Pending parent restoration\n\nFresh draft", $harness->screen()->editorText());
        $this->assertNull($state->pendingEditorRestoreText);
        $this->assertFalse($state->sessionReady);
        $this->assertSame('new-request', $state->handle->bootstrapRequestId);
    }

    /**
     * Confirm cancel must send a boolean false answer so the bash background
     * poller receives a resolved decision instead of hanging on null.
     */
    public function testConfirmOnCancelSendsBooleanFalse(): void
    {
        $sentCommand = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-1'),
                $this->callback(static function (UserCommand $cmd) use (&$sentCommand): bool {
                    $sentCommand = $cmd;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleConfirmToolQuestion');
        $ref->invoke(
            $this->runtimeQuestionHandler(),
            [
                'prompt' => 'Move it to the background?',
                'request_id' => 'rq_test',
                'tool_call_id' => 'tc_test',
                'tool_name' => 'bash',
            ],
            'tool_rq_test',
            'run-1',
            'rq_test',
            $client,
            $coordinator,
        );

        $this->assertTrue($coordinator->actionRequired(), 'Coordinator must have active request after enqueue');

        $coordinator->cancel();

        $this->assertNotNull($sentCommand, 'Expected UserCommand to be sent on cancel');
        $this->assertSame('answer_tool_question', $sentCommand->type);
        $this->assertSame('rq_test', $sentCommand->payload['request_id'] ?? null);
        $this->assertFalse($sentCommand->payload['answer'] ?? true);
        $this->assertSame('confirm', $sentCommand->payload['kind'] ?? null);
    }

    public function testConfirmToolQuestionWithNullSchemaEnqueuesConfirmWithoutWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (\E_USER_WARNING === $severity) {
                $warnings[] = $message;
            }

            return true;
        });

        try {
            $client = $this->createStub(AgentSessionClient::class);
            $coordinator = new QuestionCoordinator();

            $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleToolQuestionRequested');
            $event = new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
                runId: 'run-bg',
                seq: 0,
                payload: [
                    'request_id' => 'bash_bg_run1_tc1_99',
                    'kind' => 'confirm',
                    'schema' => null,
                    'prompt' => 'Move it to the background?',
                ],
            );

            $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

            $this->assertSame([], $warnings, 'Confirm with null schema must not emit E_USER_WARNING');
            $this->assertTrue($coordinator->actionRequired());

            $active = $coordinator->activeRequest();
            $this->assertNotNull($active);
            $this->assertSame(QuestionKind::Confirm, $active->kind);
        } finally {
            restore_error_handler();
        }
    }

    public function testConfirmToolQuestionWithBooleanSchemaEnqueuesConfirm(): void
    {
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleToolQuestionRequested');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
            runId: 'run-bg',
            seq: 0,
            payload: [
                'request_id' => 'bash_bg_run1_tc1_100',
                'kind' => 'confirm',
                'schema' => '{"type":"boolean"}',
                'prompt' => 'Move it to the background?',
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame(QuestionKind::Confirm, $active->kind);
    }

    // ── QH-06: human_input.requested kind routing and answer normalization ──

    public function testResolveQuestionKindMapsUiKind(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        // text
        $this->assertSame(QuestionKind::Text, $ref->invoke($this->runtimeQuestionHandler(), ['ui_kind' => 'text']));

        // confirm
        $this->assertSame(QuestionKind::Confirm, $ref->invoke($this->runtimeQuestionHandler(), ['ui_kind' => 'confirm']));

        // approval maps to Confirm
        $this->assertSame(QuestionKind::Confirm, $ref->invoke($this->runtimeQuestionHandler(), ['ui_kind' => 'approval']));

        // choice
        $this->assertSame(QuestionKind::Choice, $ref->invoke($this->runtimeQuestionHandler(), ['ui_kind' => 'choice']));

        // legacy kind fallback (no ui_kind)
        $this->assertSame(QuestionKind::Confirm, $ref->invoke($this->runtimeQuestionHandler(), ['kind' => 'approval']));
        $this->assertSame(QuestionKind::Text, $ref->invoke($this->runtimeQuestionHandler(), ['kind' => 'text']));
    }

    public function testResolveQuestionKindFallsBackToSchemaBoolean(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        $result = $ref->invoke($this->runtimeQuestionHandler(), [
            'schema' => ['type' => 'boolean'],
        ]);

        $this->assertSame(QuestionKind::Confirm, $result);
    }

    public function testResolveQuestionKindFallsBackToSchemaEnum(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        $result = $ref->invoke($this->runtimeQuestionHandler(), [
            'schema' => ['type' => 'string', 'enum' => ['Yes', 'No']],
        ]);

        $this->assertSame(QuestionKind::Choice, $result);
    }

    public function testResolveQuestionKindFallsBackToTextWhenNoHints(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        $this->assertSame(QuestionKind::Text, $ref->invoke($this->runtimeQuestionHandler(), []));
        $this->assertSame(QuestionKind::Text, $ref->invoke($this->runtimeQuestionHandler(), ['schema' => ['type' => 'string']]));
        $this->assertSame(QuestionKind::Text, $ref->invoke($this->runtimeQuestionHandler(), ['schema' => ['type' => 'integer']]));
    }

    public function testBuildChoicesFromPayloadChoicesField(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'buildChoices');

        $choices = $ref->invoke($this->runtimeQuestionHandler(), [
            'choices' => [
                ['label' => 'Yes', 'description' => 'Approve the action'],
                ['label' => 'No'],
            ],
        ], ['type' => 'string']);

        $this->assertCount(2, $choices);
        $this->assertContainsOnlyInstancesOf(QuestionOption::class, $choices);
        $this->assertSame('Yes', $choices[0]->label);
        $this->assertSame('Approve the action', $choices[0]->description);
        $this->assertSame('No', $choices[1]->label);
        $this->assertSame('', $choices[1]->description, 'Missing description must default to empty string');
    }

    public function testBuildChoicesFallsBackToSchemaEnum(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'buildChoices');

        $choices = $ref->invoke($this->runtimeQuestionHandler(), [], [
            'type' => 'string',
            'enum' => ['Option A', 'Option B'],
        ]);

        $this->assertCount(2, $choices);
        $this->assertContainsOnlyInstancesOf(QuestionOption::class, $choices);
        $this->assertSame('Option A', $choices[0]->label);
        $this->assertSame('Option B', $choices[1]->label);
    }

    public function testBuildChoicesReturnsEmptyWhenNoSources(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'buildChoices');

        $this->assertSame([], $ref->invoke($this->runtimeQuestionHandler(), [], []));
        $this->assertSame([], $ref->invoke($this->runtimeQuestionHandler(), ['choices' => []], ['type' => 'string']));
        $this->assertSame([], $ref->invoke($this->runtimeQuestionHandler(), [], ['type' => 'boolean']));
    }

    public function testHandleHumanInputRequestedPassesHeaderDefaultAllowOther(): void
    {
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-rich',
            seq: 0,
            payload: [
                'question_id' => 'q_rich',
                'ui_kind' => 'text',
                'header' => 'Custom Rich Header',
                'default' => 'default text',
                'prompt' => 'Enter your input:',
                'schema' => ['type' => 'string'],
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        $this->assertTrue($coordinator->actionRequired());
        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame(QuestionKind::Text, $active->kind);
        $this->assertSame('Custom Rich Header', $active->header);
        $this->assertTrue($active->allowOther, 'Model-turn HITL free-form remains available');
        $this->assertSame('hitl_'.substr(hash('sha256', 'run-rich|q_rich'), 0, 16), $active->requestId);
    }

    public function testHandleHumanInputRequestedConfirmAnswerYesNormalizesToBoolean(): void
    {
        $capturedAnswer = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-confirm'),
                $this->callback(static function (UserCommand $cmd) use (&$capturedAnswer): bool {
                    $capturedAnswer = $cmd->payload['answer'] ?? null;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-confirm',
            seq: 0,
            payload: [
                'question_id' => 'q_confirm',
                'ui_kind' => 'confirm',
                'prompt' => 'Approve deployment?',
                'schema' => ['type' => 'boolean'],
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        // Simulate user selecting 'Yes' (select list returns 'yes' string)
        $coordinator->answer('yes');

        $this->assertTrue($capturedAnswer, 'Confirm answer for yes must be boolean true');
    }

    public function testHandleHumanInputRequestedConfirmAnswerNoNormalizesToBoolean(): void
    {
        $capturedAnswer = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-confirm-no'),
                $this->callback(static function (UserCommand $cmd) use (&$capturedAnswer): bool {
                    $capturedAnswer = $cmd->payload['answer'] ?? null;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-confirm-no',
            seq: 0,
            payload: [
                'question_id' => 'q_confirm_no',
                'ui_kind' => 'confirm',
                'prompt' => 'Approve deployment?',
                'schema' => ['type' => 'boolean'],
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        // Simulate user selecting 'No' (select list returns 'no' string)
        $coordinator->answer('no');

        $this->assertFalse($capturedAnswer, 'Confirm answer for no must be boolean false');
    }

    public function testHandleHumanInputRequestedChoiceAnswerPassesThroughAsString(): void
    {
        $capturedAnswer = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-choice'),
                $this->callback(static function (UserCommand $cmd) use (&$capturedAnswer): bool {
                    $capturedAnswer = $cmd->payload['answer'] ?? null;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-choice',
            seq: 0,
            payload: [
                'question_id' => 'q_choice',
                'ui_kind' => 'choice',
                'prompt' => 'Pick an option:',
                'schema' => ['type' => 'string', 'enum' => ['Alpha', 'Beta']],
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        // Simulate user selecting 'Beta'
        $coordinator->answer('Beta');

        $this->assertSame('Beta', $capturedAnswer, 'Choice answer must pass through as-is (string)');
        $this->assertIsString($capturedAnswer);
    }

    public function testHandleHumanInputRequestedCancelSendsCancelledByUser(): void
    {
        $capturedPayload = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-cancel'),
                $this->callback(static function (UserCommand $cmd) use (&$capturedPayload): bool {
                    $capturedPayload = $cmd->payload;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-cancel',
            seq: 0,
            payload: [
                'question_id' => 'q_cancel',
                'ui_kind' => 'confirm',
                'prompt' => 'Cancel test?',
                'schema' => ['type' => 'boolean'],
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        // Cancel the question — this fires the onCancel closure
        $coordinator->cancel();

        $this->assertNotNull($capturedPayload, 'Must send UserCommand on cancel');
        $this->assertSame('q_cancel', $capturedPayload['question_id'] ?? null);
        $this->assertSame('Cancelled by user', $capturedPayload['answer'] ?? null);
    }

    public function testHandleHumanInputRequestedAllowOtherDefaultsTrueForModelTurn(): void
    {
        // Model-turn HITL (no continuation_kind=tool_call) keeps free-form.
        // Actual __other__ rendering is gated on QuestionKind::Choice in
        // QuestionController::buildItems().
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-aot',
            seq: 0,
            payload: [
                'question_id' => 'q_aot',
                'ui_kind' => 'choice',
                'prompt' => 'Pick one:',
                'schema' => ['type' => 'string', 'enum' => ['A', 'B']],
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertTrue($active->allowOther, 'Model-turn HITL keeps allowOther=true');
    }

    public function testHandleHumanInputRequestedToolCallDisablesAllowOther(): void
    {
        // Exact tool-call approvals (SafeGuard, etc.) must not offer free-form.
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: 'run-tc',
            seq: 0,
            payload: [
                'question_id' => 'q_tc',
                'ui_kind' => 'choice',
                'prompt' => 'Allow write outside working directory?',
                'schema' => ['type' => 'string', 'enum' => ['✅ Allow', '❌ Deny']],
                'continuation_kind' => 'tool_call',
                'tool_call_id' => 'call_1',
            ],
        );

        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator);

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertFalse($active->allowOther, 'tool_call continuation must set allowOther=false');
        $this->assertSame(QuestionKind::Choice, $active->kind);
    }

    // ── QH-06 follow-up: interrupt transport marker and bare-string choices ──

    public function testResolveQuestionKindIgnoresInterruptTransportMarkerWithStringSchema(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        // kind='interrupt' with no ui_kind and a string schema should
        // fall through to schema-driven derivation (QuestionKind::Text),
        // NOT match default => Choice which would render an empty overlay.
        $result = $ref->invoke($this->runtimeQuestionHandler(), [
            'kind' => 'interrupt',
            'schema' => ['type' => 'string'],
        ]);

        $this->assertSame(QuestionKind::Text, $result);
    }

    public function testResolveQuestionKindIgnoresInterruptTransportMarkerWithBooleanSchema(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        // kind='interrupt' with no ui_kind and a boolean schema should
        // derive Confirm, not fall to default => Choice.
        $result = $ref->invoke($this->runtimeQuestionHandler(), [
            'kind' => 'interrupt',
            'schema' => ['type' => 'boolean'],
        ]);

        $this->assertSame(QuestionKind::Confirm, $result);
    }

    public function testResolveQuestionKindStillMatchesUiKindWhenInterruptKindPresent(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'resolveQuestionKind');

        // When ui_kind IS present alongside kind='interrupt', ui_kind wins.
        $result = $ref->invoke($this->runtimeQuestionHandler(), [
            'kind' => 'interrupt',
            'ui_kind' => 'choice',
        ]);

        $this->assertSame(QuestionKind::Choice, $result);
    }

    public function testBuildChoicesHandlesBareStringEntries(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'buildChoices');

        $choices = $ref->invoke($this->runtimeQuestionHandler(), [
            'choices' => ['Yes', 'No'],
        ], ['type' => 'string']);

        $this->assertCount(2, $choices);
        $this->assertContainsOnlyInstancesOf(QuestionOption::class, $choices);
        $this->assertSame('Yes', $choices[0]->label);
        $this->assertSame('', $choices[0]->description, 'Bare string choice must default to empty description');
        $this->assertSame('No', $choices[1]->label);
        $this->assertSame('', $choices[1]->description);
    }

    public function testBuildChoicesHandlesMixedArrayAndStringEntries(): void
    {
        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'buildChoices');

        $choices = $ref->invoke($this->runtimeQuestionHandler(), [
            'choices' => [
                ['label' => 'Structured', 'description' => 'Has description'],
                'BareString',
            ],
        ], ['type' => 'string']);

        $this->assertCount(2, $choices);
        $this->assertSame('Structured', $choices[0]->label);
        $this->assertSame('Has description', $choices[0]->description);
        $this->assertSame('BareString', $choices[1]->label);
        $this->assertSame('', $choices[1]->description, 'Bare string in mixed list must default to empty description');
    }

    // ── QH-06 per-tick re-open guard + orphan self-heal ──

    public function testAwaitingFreeFormGuardPreventsReOpen(): void
    {
        // Thesis: when awaitingFreeForm=true, the per-tick guard
        // (!isAwaitingFreeForm()) prevents open() from being called
        // despite actionRequired() and !isOpen(). Without the third
        // condition in 5f2cef13e, this test would fail (open() would
        // be invoked, rebuilding the select overlay).

        $eventApplier = (new \ReflectionClass(TuiRuntimeEventApplier::class))->newInstanceWithoutConstructor();
        $logger = $this->createStub(LoggerInterface::class);
        $boundary = (new \ReflectionClass(RuntimeExceptionBoundary::class))->newInstanceWithoutConstructor();
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(SessionTranscriptProviderInterface::class));

        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'hitl_guard_test',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Choice,
                prompt: 'Test prompt',
                runId: 'run-guard',
                allowOther: true),
        );
        $this->assertTrue($coordinator->actionRequired());

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $state = new TuiSessionState('run-guard');
        $state->activity = RunActivityStateEnum::Running;
        $screen = new ChatScreen($theme, 'run-guard', $promptEditor);

        $ctrlRef = new \ReflectionClass(QuestionController::class);
        $controller = new QuestionController($coordinator, $screen);
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($controller, true);
        $this->assertTrue($controller->isAwaitingFreeForm(), 'Precondition: awaitingFreeForm must be true');

        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            questionCoordinator: $coordinator,
            questionController: $controller,
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();

        $listener = new TickPollListener(new RuntimeQuestionEventHandler());
        $listener->register($context);

        // Retrieve the tick handler from TuiTickDispatcher
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handlers = $handlerRef->getValue($context->ticks);
        $this->assertCount(1, $handlers);

        // Drive one tick
        ($handlers[0])();

        // Assertions: guard blocked open() — overlay is still closed,
        // awaitingFreeForm is still true, and coordinator still has the request.
        $this->assertFalse($controller->isOpen(), 'Guard must prevent open() when awaitingFreeForm=true');
        $this->assertTrue($controller->isAwaitingFreeForm(), 'awaitingFreeForm must remain true after guard block');
        $this->assertTrue($coordinator->actionRequired(), 'Coordinator must still have the active request');
    }

    public function testOrphanedQuestionHealedWhenRunTerminal(): void
    {
        // Thesis: when the run is terminal (isActive()=false) and a
        // HITL question is still pending, the tick self-heal calls
        // coordinator->reject() and controller->close() to prevent
        // awaitingFreeForm from getting stuck, silently suppressing
        // the next HITL question.

        $eventApplier = (new \ReflectionClass(TuiRuntimeEventApplier::class))->newInstanceWithoutConstructor();
        $logger = $this->createStub(LoggerInterface::class);
        $boundary = (new \ReflectionClass(RuntimeExceptionBoundary::class))->newInstanceWithoutConstructor();
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(SessionTranscriptProviderInterface::class));

        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'hitl_orphan_test',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Choice,
                prompt: 'Orphan test',
                runId: 'run-orphan',
                allowOther: true),
        );
        $this->assertTrue($coordinator->actionRequired());

        // Block the guard (so open() does not rebuild the overlay) by
        // setting awaitingFreeForm=true. The self-heal is independent of
        // the guard and triggers on !isActive() && actionRequired().
        // Use default Idle activity (isActive()=false) — the self-heal condition
        // !isActive() will be true.
        $state = new TuiSessionState('run-orphan');

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, 'run-orphan', $promptEditor);

        $ctrlRef = new \ReflectionClass(QuestionController::class);
        $controller = new QuestionController($coordinator, $screen);
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($controller, true);

        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            questionCoordinator: $coordinator,
            questionController: $controller,
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();

        $listener = new TickPollListener(new RuntimeQuestionEventHandler());
        $listener->register($context);

        // Retrieve the tick handler from TuiTickDispatcher
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handlers = $handlerRef->getValue($context->ticks);
        $this->assertCount(1, $handlers);

        // Drive one tick — the self-heal must reject the orphaned question
        ($handlers[0])();

        // Assertions: reject() advanced the queue (actionRequired=false)
        // and close() reset isOpen/awaitingFreeForm.
        $this->assertFalse($coordinator->actionRequired(), 'Orphaned question must be rejected');
        $this->assertFalse($controller->isOpen(), 'close() must be called after self-heal');
        $this->assertFalse($controller->isAwaitingFreeForm(), 'close() must reset awaitingFreeForm after self-heal');
    }

    public function testParentWaitingHumanQuestionNotRejectedWhenActivityWaitingHuman(): void
    {
        $parentRunId = 'parent-hitl-post-subagent';
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'parent_hitl_active',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Text,
                prompt: 'Which docs file would you like me to inspect and summarize?',
                runId: $parentRunId),
        );

        $state = new TuiSessionState($parentRunId);
        $state->handle = new RunHandle($parentRunId);
        $state->activity = RunActivityStateEnum::WaitingHuman;

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'shouldRejectOrphanedQuestion');
        $reject = $ref->invoke($this->runtimeQuestionHandler(), $state, $coordinator->activeRequest());
        $this->assertFalse($reject, 'Parent WaitingHuman question must not be self-healed as orphaned');
    }

    public function testTickRendersLlmRetryWorkingMessageAndClearsOnStreamStart(): void
    {
        $runId = 'retry-working-line';
        $applier = new TuiRuntimeEventApplier(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            SubagentProgressSerializerTestSupport::denormalizer(),
        );
        $listener = $this->createTickPollListener();

        $state = new TuiSessionState($runId);
        $state->handle = new RunHandle($runId);
        $state->activity = RunActivityStateEnum::Running;

        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $runId, $promptEditor);
        $screen->setWorkingVisible(true);
        $screen->setWorkingMessage('Working...');

        $handler = $this->registerTickHandler($listener, $state, screen: $screen);

        $applier->apply($state, new RuntimeEvent(
            type: RuntimeEventTypeEnum::LlmRequestRetrying->value,
            runId: $runId,
            seq: 0,
            payload: [
                'attempt' => 1,
                'max_attempts' => 5,
                'delay_ms' => 1000,
                'reason' => 'LLM provider request timed out.',
            ],
        ));

        ($handler)();
        $this->assertSame(
            'Retrying LLM 1/5 in 1.0s — LLM provider request timed out.',
            $this->workingMessage($screen),
        );

        $applier->apply($state, new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageStarted->value,
            runId: $runId,
            seq: 0,
            payload: [],
        ));

        ($handler)();
        $this->assertNull($state->llmRetryWorkingMessage);
        $this->assertSame('Working...', $this->workingMessage($screen));
    }

    public function testParentWaitingHumanTickHidesWorkingRowAndClearsQuestionPendingStatus(): void
    {
        $parentRunId = 'parent-hitl-chrome';
        $eventApplier = (new \ReflectionClass(TuiRuntimeEventApplier::class))->newInstanceWithoutConstructor();
        $logger = $this->createStub(LoggerInterface::class);
        $boundary = (new \ReflectionClass(RuntimeExceptionBoundary::class))->newInstanceWithoutConstructor();
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(SessionTranscriptProviderInterface::class));

        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'parent_hitl_chrome',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Text,
                prompt: 'Which docs file would you like me to inspect and summarize?',
                runId: $parentRunId),
        );

        $state = new TuiSessionState($parentRunId);
        $state->handle = new RunHandle($parentRunId);
        $state->activity = RunActivityStateEnum::WaitingHuman;

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $parentRunId, $promptEditor);
        $screen->mount($tui);
        $screen->setWorkingMessage('Working...');
        $screen->setStatus('action', '\u{26A0} Question pending');

        $questionController = new QuestionController($coordinator, $screen);

        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            questionCoordinator: $coordinator,
            questionController: $questionController,
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();

        $listener = new TickPollListener(new RuntimeQuestionEventHandler());
        $listener->register($context);

        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handlers = $handlerRef->getValue($context->ticks);
        ($handlers[0])();

        $this->assertTrue($questionController->isOpen(), 'Tick must open the text question overlay');
        $this->assertArrayNotHasKey('action', $this->statusEntries($screen));
        $this->assertFalse($this->isWorkingVisible($screen));
        $this->assertNotSame('Working...', $this->workingMessage($screen));
    }

    /**
     * @return array<string, array{0: RunActivityStateEnum, 1: bool, 2: ?bool}>
     */
    public static function activeRuntimeTickHintProvider(): array
    {
        return [
            'starting' => [RunActivityStateEnum::Starting, true, true],
            'running' => [RunActivityStateEnum::Running, true, true],
            'waiting_human' => [RunActivityStateEnum::WaitingHuman, true, true],
            'cancelling' => [RunActivityStateEnum::Cancelling, true, true],
            'idle' => [RunActivityStateEnum::Idle, true, null],
            'completed' => [RunActivityStateEnum::Completed, true, null],
            'failed' => [RunActivityStateEnum::Failed, true, null],
            'starting_without_handle' => [RunActivityStateEnum::Starting, false, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activeRuntimeTickHintProvider')]
    public function testTickHandlerReturnsBusyHintForActiveRuntimeStates(
        RunActivityStateEnum $activity,
        bool $withHandle,
        ?bool $expected,
    ): void {
        $runId = 'tick-busy-hint';
        $poller = $this->createNoOpPoller();
        $listener = $this->createTickPollListener();

        $state = new TuiSessionState($runId);
        $state->activity = $activity;
        if ($withHandle) {
            $state->handle = new RunHandle($runId);
        }

        $handler = $this->registerTickHandler($listener, $state, poller: $poller);
        $tickEvent = new \Symfony\Component\Tui\Event\TickEvent();

        $this->assertSame($expected, $handler($tickEvent));
        $dispatch = $this->contextTicks->dispatch($tickEvent);
        $this->assertSame(true === $expected, true === $dispatch);
    }

    public function testLiveViewTickHandlerReturnsBusyHintWhenChildActivityActive(): void
    {
        $runId = 'tick-busy-live-child';
        $poller = $this->createNoOpPoller();
        $listener = $this->createTickPollListener();

        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Idle;
        $state->subagentLiveView->active = true;
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;

        $handler = $this->registerTickHandler($listener, $state, poller: $poller);
        $tickEvent = new \Symfony\Component\Tui\Event\TickEvent();

        $this->assertTrue($handler($tickEvent));
    }

    public function testLiveViewTickHandlerReturnsNullWhenParentAndChildIdle(): void
    {
        $runId = 'tick-busy-live-idle';
        $poller = $this->createNoOpPoller();
        $listener = $this->createTickPollListener();

        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Idle;
        $state->subagentLiveView->active = true;
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Idle;

        $handler = $this->registerTickHandler($listener, $state, poller: $poller);
        $tickEvent = new \Symfony\Component\Tui\Event\TickEvent();

        $this->assertNull($handler($tickEvent));
    }

    public function testRunFailureRestoresDeferredPromptWithoutSendingOrOverwritingEditorDraft(): void
    {
        $runId = 'tick-failed-compaction-request';
        $state = new TuiSessionState($runId);
        $state->handle = new RunHandle($runId);
        $state->activity = RunActivityStateEnum::Running;
        $state->isCompacting = true;
        $state->queuedFollowUps = ['Run the checks after compaction', 'Also update the docs'];
        $harness = new VirtualTuiHarness(sessionId: $runId);
        $harness->screen()->promptEditor()->setText('Unsubmitted draft');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $client->expects($this->exactly(2))->method('events')->willReturnOnConsecutiveCalls(
            [new RuntimeEvent('run.failed', $runId, 10, ['reason' => 'provider_failure'])],
            [new RuntimeEvent('compaction.started', $runId, 11, ['trigger' => 'manual']), new RuntimeEvent('compaction.completed', $runId, 12)],
        );
        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer()),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );
        $poller->poll($state, $client);
        $this->assertSame(RunActivityStateEnum::Failed, $state->activity);
        $this->assertFalse($state->isCompacting);
        $state->subagentLiveView->enter(new \Ineersa\Tui\Runtime\SubagentLiveChildDTO(
            'child-run', 'child-artifact', 'child', \Ineersa\Tui\Runtime\SubagentLiveStatusEnum::Completed,
            'Child task', 1, 'llama_cpp_test/test', 'off',
        ));
        $handler = $this->registerTickHandler($this->createTickPollListener(), $state, screen: $harness->screen());
        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame('Unsubmitted draft', $harness->screen()->promptEditor()->getText(), 'Parent input must not enter the child editor.');
        $state->subagentLiveView->exit();
        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $expected = "Run the checks after compaction\n\nAlso update the docs\n\nUnsubmitted draft";
        $this->assertSame($expected, $harness->screen()->promptEditor()->getText());
        $this->assertStringContainsString('Run the checks after compaction', $harness->plainScreenText());
        $this->assertSame([], $state->queuedFollowUps);

        $state->lastPoll = 0.0;
        $poller->poll($state, $client);
        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame($expected, $harness->screen()->promptEditor()->getText());
    }

    public function testTickShowsAndClearsFollowUpQueuedDuringCompaction(): void
    {
        $runId = 'tick-compaction-queue';
        $harness = new VirtualTuiHarness(sessionId: $runId);
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Compacting;
        $state->queuedFollowUps = ['Run the checks after compaction'];
        $state->queuedUserMessages = ['steer-1' => 'Existing queued steer'];

        $handler = $this->registerTickHandler(
            $this->createTickPollListener(),
            $state,
            poller: $this->createNoOpPoller(),
            screen: $harness->screen(),
        );

        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertStringContainsString('⏳ Existing queued steer', $harness->plainScreenText());
        $this->assertStringContainsString('⏳ Run the checks after compaction', $harness->plainScreenText());

        $state->activity = RunActivityStateEnum::Starting;
        $state->queuedFollowUps = [];
        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertStringContainsString('⏳ Existing queued steer', $harness->plainScreenText());
        $this->assertStringNotContainsString('⏳ Run the checks after compaction', $harness->plainScreenText());
    }

    public function testIdleMountSchedulesOneShotIdleMemoryCheckpointWithoutBoundary(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-resume-idle';
        $state = new TuiSessionState($runId, resuming: true);
        $state->activity = RunActivityStateEnum::Completed;
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;
        $state->replaceTranscript([
            new \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock(
                id: 'u1',
                kind: \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum::UserMessage,
                runId: $runId,
                seq: 1,
                text: 'hello',
            ),
        ]);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))
            ->method('events')
            ->willReturn([]);

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];
        $tick = new \Symfony\Component\Tui\Event\TickEvent();

        $handler($tick);
        $this->assertCount(1, $logger->records);
        $this->assertSame('tui.memory.idle', $logger->records[0]['context']['event_type']);
        $this->assertSame('next_tick_after_mount', $logger->records[0]['context']['checkpoint_phase']);
        $this->assertSame('parent', $logger->records[0]['context']['transcript_scope']);

        $state->lastPoll = 0.0;
        $handler($tick);
        $this->assertCount(1, $logger->records, 'Idle resume must emit only one idle checkpoint');
    }

    public function testTerminalBoundarySchedulesOneShotIdleMemoryCheckpoint(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-idle';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Running;
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;
        $state->replaceTranscript([
            new \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock(
                id: 'u1',
                kind: \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum::UserMessage,
                runId: $runId,
                seq: 1,
                text: 'hello',
            ),
        ]);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(3))
            ->method('events')
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::RunCompleted->value,
                        runId: $runId,
                        seq: 2,
                        payload: [],
                    ),
                ],
                [],
                [],
            );

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $eventApplier = new TuiRuntimeEventApplier(
            $projector,
            SubagentProgressSerializerTestSupport::denormalizer(),
        );
        $poller = new RuntimeEventPoller(
            $eventApplier,
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];
        $tick = new \Symfony\Component\Tui\Event\TickEvent();

        $handler($tick);
        $this->assertSame(RunActivityStateEnum::Completed, $state->activity);
        $this->assertCount(1, $logger->records);
        $this->assertSame('tui.activity.terminal', $logger->records[0]['context']['event_type']);
        $this->assertSame('pre_next_frame', $logger->records[0]['context']['checkpoint_phase']);
        $this->assertSame('parent', $logger->records[0]['context']['transcript_scope']);
        $this->assertSame($runId, $logger->records[0]['context']['visible_run_id']);
        $this->assertFalse($logger->records[0]['context']['live_child_view']);
        $this->assertSame(1, $logger->records[0]['context']['transcript_block_count']);

        $state->lastPoll = 0.0;
        $handler($tick);
        $this->assertCount(2, $logger->records);
        $this->assertSame('tui.memory.idle', $logger->records[1]['context']['event_type']);
        $this->assertSame('next_tick_after_boundary', $logger->records[1]['context']['checkpoint_phase']);

        $state->lastPoll = 0.0;
        $handler($tick);
        $this->assertCount(2, $logger->records);
    }

    public function testCompactionStartedAndCompletedInOnePollEmitsSettledThenOneIdle(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-compact-batch';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Completed;
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))
            ->method('events')
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::CompactionStarted->value,
                        runId: $runId,
                        seq: 2,
                        payload: [],
                    ),
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::CompactionCompleted->value,
                        runId: $runId,
                        seq: 3,
                        payload: [],
                    ),
                ],
                [],
            );

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];
        $tick = new \Symfony\Component\Tui\Event\TickEvent();

        $handler($tick);
        $this->assertSame(RunActivityStateEnum::Completed, $state->activity);
        $this->assertFalse($state->isCompacting);
        $this->assertCount(1, $logger->records);
        $this->assertSame('tui.compaction.settled', $logger->records[0]['context']['event_type']);

        $state->lastPoll = 0.0;
        $handler($tick);
        $this->assertCount(2, $logger->records);
        $this->assertSame('tui.memory.idle', $logger->records[1]['context']['event_type']);
        $this->assertSame('next_tick_after_boundary', $logger->records[1]['context']['checkpoint_phase']);
    }

    public function testCompactionSettlementThenQueuedFollowUpInSamePollEmitsSettledBoundary(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-compaction-followup';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Compacting;
        $state->isCompacting = true;
        $state->queuedFollowUps = ['Continue after compact'];
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::CompactionCompleted->value,
                    runId: $runId,
                    seq: 5,
                    payload: [],
                ),
            ]);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $runId,
                $this->callback(static fn ($cmd): bool => $cmd instanceof UserCommand
                    && 'follow_up' === $cmd->type
                    && 'Continue after compact' === $cmd->text
                ),
            );

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];

        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame(RunActivityStateEnum::Starting, $state->activity);
        $this->assertSame([], $state->queuedFollowUps);
        $this->assertCount(1, $logger->records);
        $this->assertSame('tui.compaction.settled', $logger->records[0]['context']['event_type']);
        $this->assertSame('starting', $logger->records[0]['context']['activity']);
    }

    public function testTerminalThenQueuedFollowUpInSamePollEmitsTerminalBoundaryAndSkipsIdle(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-terminal-followup';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Cancelling;
        $state->queuedFollowUps = ['Continue after cancel'];
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))
            ->method('events')
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::RunCancelled->value,
                        runId: $runId,
                        seq: 7,
                        payload: [],
                    ),
                ],
                [],
            );
        $client->expects($this->once())
            ->method('send')
            ->with(
                $runId,
                $this->callback(static fn ($cmd): bool => $cmd instanceof UserCommand
                    && 'follow_up' === $cmd->type
                    && 'Continue after cancel' === $cmd->text
                ),
            );

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];
        $tick = new \Symfony\Component\Tui\Event\TickEvent();

        $handler($tick);
        $this->assertSame(RunActivityStateEnum::Starting, $state->activity);
        $this->assertSame([], $state->queuedFollowUps);
        $this->assertCount(1, $logger->records);
        $this->assertSame('tui.activity.terminal', $logger->records[0]['context']['event_type']);
        $this->assertSame('cancelled', $logger->records[0]['context']['boundary_activity']);
        $this->assertSame('starting', $logger->records[0]['context']['activity']);

        $state->lastPoll = 0.0;
        $handler($tick);
        $this->assertCount(1, $logger->records, 'Immediate continuation must skip idle until a later stable settle');
    }

    public function testThrowingCheckpointLoggerDoesNotBreakTickPoll(): void
    {
        $throwing = new class extends \Psr\Log\AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                throw new \RuntimeException('checkpoint sink unavailable');
            }
        };
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($throwing);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);
        $previous = \ini_get('error_log');
        $errorDir = TestDirectoryIsolation::createProjectTempDir('tick-mem-err-');
        $errorPath = $errorDir.'/error.log';
        ini_set('error_log', $errorPath);

        $runId = 'tick-memory-throw';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Running;
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunCompleted->value,
                    runId: $runId,
                    seq: 2,
                    payload: [],
                ),
            ]);

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];

        try {
            $handler(new \Symfony\Component\Tui\Event\TickEvent());
            $this->assertSame(RunActivityStateEnum::Completed, $state->activity);
            $this->assertSame(2, $state->lastSeq);
        } finally {
            ini_set('error_log', false === $previous ? '' : $previous);
            TestDirectoryIsolation::removeDirectory($errorDir);
        }
    }

    public function testAlreadyAppliedRetryDoesNotDuplicateBoundary(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-already-applied';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Running;
        $state->handle = new RunHandle($runId);
        $state->lastSeq = 2;
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunCompleted->value,
                    runId: $runId,
                    seq: 2,
                    payload: [],
                ),
            ]);

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];

        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame(RunActivityStateEnum::Running, $state->activity);
        $this->assertSame(2, $state->lastSeq);
        $this->assertSame([], $logger->records);
    }

    public function testNonTransitioningApplyDoesNotEmitBoundary(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-non-transition';
        $state = new TuiSessionState($runId);
        // Running avoids the mount idle one-shot. AssistantTextDelta keeps
        // activity Running, so before/after observation must not invent a boundary.
        $state->activity = RunActivityStateEnum::Running;
        $state->handle = new RunHandle($runId);
        $state->lastSeq = 2;
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->willReturn([
                new RuntimeEvent(
                    type: RuntimeEventTypeEnum::AssistantTextDelta->value,
                    runId: $runId,
                    seq: 0,
                    payload: ['delta' => 'stale'],
                ),
            ]);

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];

        $handler(new \Symfony\Component\Tui\Event\TickEvent());
        $this->assertSame(RunActivityStateEnum::Running, $state->activity);
        $this->assertSame([], $logger->records);
    }

    public function testTerminalAndCompactionSettlementInSamePollEmitBothKindsOnce(): void
    {
        $logger = new \Ineersa\AgentCore\Tests\Support\TestLogger();
        $snapshot = new \Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger($logger);
        $listener = new TickPollListener(new RuntimeQuestionEventHandler(), $snapshot);

        $runId = 'tick-memory-both-kinds';
        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Compacting;
        $state->isCompacting = true;
        $state->handle = new RunHandle($runId);
        $state->lastPoll = 0.0;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->exactly(2))
            ->method('events')
            ->willReturnOnConsecutiveCalls(
                [
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::RunCompleted->value,
                        runId: $runId,
                        seq: 8,
                        payload: [],
                    ),
                    new RuntimeEvent(
                        type: RuntimeEventTypeEnum::CompactionCompleted->value,
                        runId: $runId,
                        seq: 9,
                        payload: [],
                    ),
                ],
                [],
            );

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('accept');
        $projector->method('reset');
        $projector->method('blocks')->willReturn([]);
        $projector->method('drainChanges')->willReturn(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::incremental([]));
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(
                $projector,
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
            new NullLogger(),
            new RuntimeExceptionBoundary($this->createStub(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);
        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            client: $client,
            parentPoller: $poller,
            childPoller: $this->createIsolatedSubagentLiveChildPoller(),
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();
        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handler = $handlerRef->getValue($context->ticks)[0];
        $tick = new \Symfony\Component\Tui\Event\TickEvent();

        $handler($tick);
        $this->assertSame(RunActivityStateEnum::Completed, $state->activity);
        $this->assertCount(2, $logger->records);
        $types = array_map(
            static fn (array $record): string => (string) $record['context']['event_type'],
            $logger->records,
        );
        $this->assertSame(['tui.activity.terminal', 'tui.compaction.settled'], $types);

        $state->lastPoll = 0.0;
        $handler($tick);
        $this->assertCount(3, $logger->records);
        $this->assertSame('tui.memory.idle', $logger->records[2]['context']['event_type']);
    }

    private function createNoOpPoller(): RuntimeEventPoller
    {
        $eventApplier = (new \ReflectionClass(TuiRuntimeEventApplier::class))->newInstanceWithoutConstructor();
        $boundary = (new \ReflectionClass(RuntimeExceptionBoundary::class))->newInstanceWithoutConstructor();

        return new RuntimeEventPoller(
            $eventApplier,
            new NullLogger(),
            $boundary,
            $this->createStub(SessionTranscriptProviderInterface::class),
        );
    }

    private function createTickPollListener(): TickPollListener
    {
        return new TickPollListener(new RuntimeQuestionEventHandler());
    }

    /**
     * @return callable(\Symfony\Component\Tui\Event\TickEvent): ?bool
     */
    private function registerTickHandler(
        TickPollListener $listener,
        TuiSessionState $state,
        ?RuntimeEventPoller $poller = null,
        ?SubagentLiveChildViewPoller $childPoller = null,
        ?QuestionCoordinator $coordinator = null,
        ?QuestionController $questionController = null,
        ?ChatScreen $screen = null,
    ): callable {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen ??= new ChatScreen($theme, $state->sessionId, $promptEditor);

        $services = $this->createSessionServices(
            tui: $tui,
            state: $state,
            screen: $screen,
            parentPoller: $poller ?? $this->createNoOpPoller(),
            childPoller: $childPoller ?? $this->createIsolatedSubagentLiveChildPoller(),
            questionCoordinator: $coordinator,
            questionController: $questionController,
            subagentLivePicker: $this->closedSubagentLivePicker(),
        );
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($services)
            ->build();

        $this->contextTicks = $context->ticks;
        $listener->register($context);

        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        $handlers = $handlerRef->getValue($context->ticks);
        $this->assertCount(1, $handlers);

        return $handlers[0];
    }

    private function runtimeQuestionHandler(): RuntimeQuestionEventHandler
    {
        return new RuntimeQuestionEventHandler();
    }

    private function createIsolatedSubagentLiveChildPoller(): SubagentLiveChildViewPoller
    {
        return new SubagentLiveChildViewPoller(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()), new NullLogger(), SubagentProgressSerializerTestSupport::denormalizer());
    }

    /** @return array<string, string> */
    private function statusEntries(ChatScreen $screen): array
    {
        $ref = new \ReflectionProperty(ChatScreen::class, 'statusEntries');

        return $ref->getValue($screen);
    }

    private function workingMessage(ChatScreen $screen): string
    {
        return $screen->workingMessage();
    }

    private function isWorkingVisible(ChatScreen $screen): bool
    {
        $ref = new \ReflectionClass(ChatScreen::class);

        return $ref->getProperty('workingVisible')->getValue($screen);
    }

    private function closedSubagentLivePicker(): \Ineersa\Tui\Picker\SubagentLivePickerController
    {
        $picker = (new \ReflectionClass(\Ineersa\Tui\Picker\SubagentLivePickerController::class))->newInstanceWithoutConstructor();
        $overlay = new \Ineersa\Tui\Picker\PickerOverlay();
        $overlayRef = new \ReflectionProperty(\Ineersa\Tui\Picker\SubagentLivePickerController::class, 'overlay');
        $overlayRef->setValue($picker, $overlay);
        $openRef = new \ReflectionProperty(\Ineersa\Tui\Picker\PickerOverlay::class, 'isOpen');
        $openRef->setValue($overlay, false);

        return $picker;
    }
}
