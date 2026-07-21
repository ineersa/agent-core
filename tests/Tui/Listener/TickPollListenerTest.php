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
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\PromptHistory;
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
        $this->assertSame('default text', $active->default);
        $this->assertTrue($active->allowOther, 'Model-turn HITL free-form remains available');
        $this->assertSame('hitl_'.substr(hash('sha256', 'run-rich|q_rich'), 0, 16), $active->requestId);
        $this->assertSame('q_rich', $active->questionId);
        $this->assertTrue($active->transcript);
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
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(SessionTranscriptProviderInterface::class), new PromptHistory());

        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'hitl_guard_test',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Choice,
                prompt: 'Test prompt',
                schema: ['type' => 'string', 'enum' => ['A', 'B']],
                runId: 'run-guard',
                questionId: 'q_guard',
                allowOther: true,
            ),
        );
        $this->assertTrue($coordinator->actionRequired());

        $ctrlRef = new \ReflectionClass(QuestionController::class);
        $controller = $ctrlRef->newInstanceWithoutConstructor();
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($controller, true);
        $this->assertTrue($controller->isAwaitingFreeForm(), 'Precondition: awaitingFreeForm must be true');

        // Inject dependencies into TickPollListener via reflection
        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('subagentLivePickerController')->setValue($listener, $this->closedSubagentLivePicker());
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $this->createIsolatedSubagentLiveChildPoller());
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, $coordinator);
        $listenerRef->getProperty('questionController')->setValue($listener, $controller);
        $listenerRef->getProperty('runtimeQuestionEventHandler')->setValue($listener, new RuntimeQuestionEventHandler());

        // Build TuiRuntimeContext with a real ChatScreen and Running activity
        $state = new TuiSessionState('run-guard');
        $state->activity = RunActivityStateEnum::Running;

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, 'run-guard', $promptEditor);

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->build();

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
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(SessionTranscriptProviderInterface::class), new PromptHistory());

        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'hitl_orphan_test',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Choice,
                prompt: 'Orphan test',
                schema: ['type' => 'string', 'enum' => ['A', 'B']],
                runId: 'run-orphan',
                questionId: 'q_orphan',
                allowOther: true,
            ),
        );
        $this->assertTrue($coordinator->actionRequired());

        // Block the guard (so open() does not throw on the skeleton) by
        // setting awaitingFreeForm=true. The self-heal is independent of
        // the guard and triggers on !isActive() && actionRequired().
        $ctrlRef = new \ReflectionClass(QuestionController::class);
        $controller = $ctrlRef->newInstanceWithoutConstructor();
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($controller, true);

        // Inject dependencies into TickPollListener via reflection
        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('subagentLivePickerController')->setValue($listener, $this->closedSubagentLivePicker());
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $this->createIsolatedSubagentLiveChildPoller());
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, $coordinator);
        $listenerRef->getProperty('questionController')->setValue($listener, $controller);
        $listenerRef->getProperty('runtimeQuestionEventHandler')->setValue($listener, new RuntimeQuestionEventHandler());

        // Use default Idle activity (isActive()=false) — the self-heal condition
        // !isActive() will be true.
        $state = new TuiSessionState('run-orphan');

        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, 'run-orphan', $promptEditor);

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->build();

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
                schema: ['type' => 'string'],
                runId: $parentRunId,
                questionId: 'q_parent_docs',
            ),
        );

        $state = new TuiSessionState($parentRunId);
        $state->handle = new RunHandle($parentRunId);
        $state->activity = RunActivityStateEnum::WaitingHuman;

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'shouldRejectOrphanedQuestion');
        $reject = $ref->invoke($this->runtimeQuestionHandler(), $state, $coordinator->activeRequest());
        $this->assertFalse($reject, 'Parent WaitingHuman question must not be self-healed as orphaned');
    }

    public function testParentWaitingHumanTickHidesWorkingRowAndClearsQuestionPendingStatus(): void
    {
        $parentRunId = 'parent-hitl-chrome';
        $eventApplier = (new \ReflectionClass(TuiRuntimeEventApplier::class))->newInstanceWithoutConstructor();
        $logger = $this->createStub(LoggerInterface::class);
        $boundary = (new \ReflectionClass(RuntimeExceptionBoundary::class))->newInstanceWithoutConstructor();
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(SessionTranscriptProviderInterface::class), new PromptHistory());

        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new QuestionRequest(
                requestId: 'parent_hitl_chrome',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Text,
                prompt: 'Which docs file would you like me to inspect and summarize?',
                schema: ['type' => 'string'],
                runId: $parentRunId,
                questionId: 'q_parent_docs_chrome',
            ),
        );

        $questionController = new QuestionController($coordinator);

        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('subagentLivePickerController')->setValue($listener, $this->closedSubagentLivePicker());
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $this->createIsolatedSubagentLiveChildPoller());
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, $coordinator);
        $listenerRef->getProperty('questionController')->setValue($listener, $questionController);
        $listenerRef->getProperty('runtimeQuestionEventHandler')->setValue($listener, new RuntimeQuestionEventHandler());

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

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->build();

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
        $listener = $this->createTickPollListener($poller);

        $state = new TuiSessionState($runId);
        $state->activity = $activity;
        if ($withHandle) {
            $state->handle = new RunHandle($runId);
        }

        $handler = $this->registerTickHandler($listener, $state);
        $tickEvent = new \Symfony\Component\Tui\Event\TickEvent();

        $this->assertSame($expected, $handler($tickEvent));
        $dispatch = $this->contextTicks->dispatch($tickEvent);
        $this->assertSame(true === $expected, true === $dispatch);
    }

    public function testLiveViewTickHandlerReturnsBusyHintWhenChildActivityActive(): void
    {
        $runId = 'tick-busy-live-child';
        $poller = $this->createNoOpPoller();
        $listener = $this->createTickPollListener($poller);

        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Idle;
        $state->subagentLiveView->active = true;
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;

        $handler = $this->registerTickHandler($listener, $state);
        $tickEvent = new \Symfony\Component\Tui\Event\TickEvent();

        $this->assertTrue($handler($tickEvent));
    }

    public function testLiveViewTickHandlerReturnsNullWhenParentAndChildIdle(): void
    {
        $runId = 'tick-busy-live-idle';
        $poller = $this->createNoOpPoller();
        $listener = $this->createTickPollListener($poller);

        $state = new TuiSessionState($runId);
        $state->activity = RunActivityStateEnum::Idle;
        $state->subagentLiveView->active = true;
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Idle;

        $handler = $this->registerTickHandler($listener, $state);
        $tickEvent = new \Symfony\Component\Tui\Event\TickEvent();

        $this->assertNull($handler($tickEvent));
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
            new PromptHistory(),
        );
    }

    private function createTickPollListener(RuntimeEventPoller $poller): TickPollListener
    {
        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('subagentLivePickerController')->setValue($listener, $this->closedSubagentLivePicker());
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $this->createIsolatedSubagentLiveChildPoller());
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, new QuestionCoordinator());
        $listenerRef->getProperty('questionController')->setValue($listener, new QuestionController(new QuestionCoordinator()));
        $listenerRef->getProperty('runtimeQuestionEventHandler')->setValue($listener, new RuntimeQuestionEventHandler());

        return $listener;
    }

    /**
     * @return callable(\Symfony\Component\Tui\Event\TickEvent): ?bool
     */
    private function registerTickHandler(TickPollListener $listener, TuiSessionState $state): callable
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
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
        return new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new NullLogger(),
        );
    }

    /** @return array<string, string> */
    private function statusEntries(ChatScreen $screen): array
    {
        $ref = new \ReflectionClass(ChatScreen::class);
        $prop = $ref->getProperty('footerDataProvider');

        return $prop->getValue($screen)->getStatusEntries();
    }

    private function workingMessage(ChatScreen $screen): string
    {
        $ref = new \ReflectionClass(ChatScreen::class);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->getWorkingMessage();
    }

    private function isWorkingVisible(ChatScreen $screen): bool
    {
        $ref = new \ReflectionClass(ChatScreen::class);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->isWorkingVisible();
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
