<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Psr\Log\LoggerInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/**
 * Regression tests for TickPollListener cancellation behavior.
 *
 * The cancel wedge bug (Issue 1 from PR #162 smoke test):
 * handleChoiceToolQuestion's onCancel sent 'answer' => '' (empty string),
 * which AnswerToolQuestionHandler rejected with ProtocolError without writing
 * to the store → poll loop wedged forever (null === pollAnswerText).
 *
 * Fix: 'answer' => 'cancel' (non-empty generic cancel sentinel).
 */
final class TickPollListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;
    /**
     * Test thesis: when the Choice overlay onCancel fires, the TUI sends
     * answer_tool_question with 'answer' => 'cancel' (non-empty), so
     * AnswerToolQuestionHandler accepts it, writes to the shared store,
     * and the blocking poll in the tool consumer breaks — no hang.
     *
     * If the answer were '' (empty string), this test would fail because
     * the handler rejects empty answers and the poll wedges forever.
     */
    public function testChoiceOnCancelSendsNonEmptyCancelAnswer(): void
    {
        $sentCommand = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-1'),
                $this->callback(function (UserCommand $cmd) use (&$sentCommand): bool {
                    $sentCommand = $cmd;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();

        // Use reflection to invoke the private static handleChoiceToolQuestion
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleChoiceToolQuestion');

        $ref->invoke(null, [
            'prompt' => 'Approve write outside CWD?',
            'request_id' => 'rq_test',
            'tool_call_id' => 'tc_test',
            'tool_name' => 'write',
        ], [
            'type' => 'string',
            'enum' => ['Proceed', 'Abort'],
        ], 'tool_rq_test', 'run-1', 'rq_test', $client, $coordinator);

        // The QuestionCoordinator should now have an active request
        $this->assertTrue($coordinator->actionRequired(), 'Coordinator must have active request after enqueue');

        // Cancel it — this fires the onCancel closure set up by handleChoiceToolQuestion
        $coordinator->cancel();

        // Assert the sent UserCommand has the non-empty cancel answer
        $this->assertNotNull($sentCommand, 'Expected UserCommand to be sent on cancel');
        $this->assertSame('answer_tool_question', $sentCommand->type);
        $this->assertSame('rq_test', $sentCommand->payload['request_id'] ?? null);
        $this->assertSame('cancel', $sentCommand->payload['answer'] ?? null);
        $this->assertNotEmpty($sentCommand->payload['answer'] ?? '', 'Cancel answer must be non-empty to prevent poll wedge');
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

            $ref = new \ReflectionMethod(TickPollListener::class, 'handleToolQuestionRequested');
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

            $ref->invoke(null, $event, $client, $coordinator);

            self::assertSame([], $warnings, 'Confirm with null schema must not emit E_USER_WARNING');
            self::assertTrue($coordinator->actionRequired());

            $active = $coordinator->activeRequest();
            self::assertNotNull($active);
            self::assertSame(QuestionKind::Confirm, $active->kind);
        } finally {
            restore_error_handler();
        }
    }

    public function testConfirmToolQuestionWithBooleanSchemaEnqueuesConfirm(): void
    {
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();

        $ref = new \ReflectionMethod(TickPollListener::class, 'handleToolQuestionRequested');
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

        $ref->invoke(null, $event, $client, $coordinator);

        $active = $coordinator->activeRequest();
        self::assertNotNull($active);
        self::assertSame(QuestionKind::Confirm, $active->kind);
    }

    // ── QH-06: human_input.requested kind routing and answer normalization ──

    public function testResolveQuestionKindMapsUiKind(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        // text
        self::assertSame(QuestionKind::Text, $ref->invoke(null, ['ui_kind' => 'text']));

        // confirm
        self::assertSame(QuestionKind::Confirm, $ref->invoke(null, ['ui_kind' => 'confirm']));

        // approval maps to Confirm
        self::assertSame(QuestionKind::Confirm, $ref->invoke(null, ['ui_kind' => 'approval']));

        // choice
        self::assertSame(QuestionKind::Choice, $ref->invoke(null, ['ui_kind' => 'choice']));

        // legacy kind fallback (no ui_kind)
        self::assertSame(QuestionKind::Confirm, $ref->invoke(null, ['kind' => 'approval']));
        self::assertSame(QuestionKind::Text, $ref->invoke(null, ['kind' => 'text']));
    }

    public function testResolveQuestionKindFallsBackToSchemaBoolean(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        $result = $ref->invoke(null, [
            'schema' => ['type' => 'boolean'],
        ]);

        self::assertSame(QuestionKind::Confirm, $result);
    }

    public function testResolveQuestionKindFallsBackToSchemaEnum(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        $result = $ref->invoke(null, [
            'schema' => ['type' => 'string', 'enum' => ['Yes', 'No']],
        ]);

        self::assertSame(QuestionKind::Choice, $result);
    }

    public function testResolveQuestionKindFallsBackToTextWhenNoHints(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        self::assertSame(QuestionKind::Text, $ref->invoke(null, []));
        self::assertSame(QuestionKind::Text, $ref->invoke(null, ['schema' => ['type' => 'string']]));
        self::assertSame(QuestionKind::Text, $ref->invoke(null, ['schema' => ['type' => 'integer']]));
    }

    public function testBuildChoicesFromPayloadChoicesField(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'buildChoices');

        $choices = $ref->invoke(null, [
            'choices' => [
                ['label' => 'Yes', 'description' => 'Approve the action'],
                ['label' => 'No'],
            ],
        ], ['type' => 'string']);

        self::assertCount(2, $choices);
        self::assertContainsOnlyInstancesOf(QuestionOption::class, $choices);
        self::assertSame('Yes', $choices[0]->label);
        self::assertSame('Approve the action', $choices[0]->description);
        self::assertSame('No', $choices[1]->label);
        self::assertSame('', $choices[1]->description, 'Missing description must default to empty string');
    }

    public function testBuildChoicesFallsBackToSchemaEnum(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'buildChoices');

        $choices = $ref->invoke(null, [], [
            'type' => 'string',
            'enum' => ['Option A', 'Option B'],
        ]);

        self::assertCount(2, $choices);
        self::assertContainsOnlyInstancesOf(QuestionOption::class, $choices);
        self::assertSame('Option A', $choices[0]->label);
        self::assertSame('Option B', $choices[1]->label);
    }

    public function testBuildChoicesReturnsEmptyWhenNoSources(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'buildChoices');

        self::assertSame([], $ref->invoke(null, [], []));
        self::assertSame([], $ref->invoke(null, ['choices' => []], ['type' => 'string']));
        self::assertSame([], $ref->invoke(null, [], ['type' => 'boolean']));
    }

    public function testHandleHumanInputRequestedPassesHeaderDefaultAllowOther(): void
    {
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');

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

        $ref->invoke(null, $event, $client, $coordinator);

        self::assertTrue($coordinator->actionRequired());
        $active = $coordinator->activeRequest();
        self::assertNotNull($active);
        self::assertSame(QuestionKind::Text, $active->kind);
        self::assertSame('Custom Rich Header', $active->header);
        self::assertSame('default text', $active->default);
        self::assertTrue($active->allowOther, 'HITL questions must always allow free-form input');
        self::assertSame('hitl_'.substr(hash('sha256', 'run-rich|q_rich'), 0, 16), $active->requestId);
        self::assertSame('q_rich', $active->questionId);
        self::assertTrue($active->transcript);
    }

    public function testHandleHumanInputRequestedConfirmAnswerYesNormalizesToBoolean(): void
    {
        $capturedAnswer = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-confirm'),
                $this->callback(function (UserCommand $cmd) use (&$capturedAnswer): bool {
                    $capturedAnswer = $cmd->payload['answer'] ?? null;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');

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

        $ref->invoke(null, $event, $client, $coordinator);

        // Simulate user selecting 'Yes' (select list returns 'yes' string)
        $coordinator->answer('yes');

        self::assertTrue($capturedAnswer, 'Confirm answer for yes must be boolean true');
    }

    public function testHandleHumanInputRequestedConfirmAnswerNoNormalizesToBoolean(): void
    {
        $capturedAnswer = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-confirm-no'),
                $this->callback(function (UserCommand $cmd) use (&$capturedAnswer): bool {
                    $capturedAnswer = $cmd->payload['answer'] ?? null;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');

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

        $ref->invoke(null, $event, $client, $coordinator);

        // Simulate user selecting 'No' (select list returns 'no' string)
        $coordinator->answer('no');

        self::assertFalse($capturedAnswer, 'Confirm answer for no must be boolean false');
    }

    public function testHandleHumanInputRequestedChoiceAnswerPassesThroughAsString(): void
    {
        $capturedAnswer = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-choice'),
                $this->callback(function (UserCommand $cmd) use (&$capturedAnswer): bool {
                    $capturedAnswer = $cmd->payload['answer'] ?? null;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');

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

        $ref->invoke(null, $event, $client, $coordinator);

        // Simulate user selecting 'Beta'
        $coordinator->answer('Beta');

        self::assertSame('Beta', $capturedAnswer, 'Choice answer must pass through as-is (string)');
        self::assertIsString($capturedAnswer);
    }

    public function testHandleHumanInputRequestedCancelSendsCancelledByUser(): void
    {
        $capturedPayload = null;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with(
                $this->identicalTo('run-cancel'),
                $this->callback(function (UserCommand $cmd) use (&$capturedPayload): bool {
                    $capturedPayload = $cmd->payload;

                    return true;
                }),
            );

        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');

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

        $ref->invoke(null, $event, $client, $coordinator);

        // Cancel the question — this fires the onCancel closure
        $coordinator->cancel();

        self::assertNotNull($capturedPayload, 'Must send UserCommand on cancel');
        self::assertSame('q_cancel', $capturedPayload['question_id'] ?? null);
        self::assertSame('Cancelled by user', $capturedPayload['answer'] ?? null);
    }

    public function testHandleHumanInputRequestedAllowOtherDefaultsTrue(): void
    {
        // When no allow_other field is present in the payload, the
        // QuestionRequest must still have allowOther=true (the allowOther
        // capability flag — actual __other__ escape hatch rendering is gated
        // on QuestionKind::Choice in QuestionController::buildItems()).
        $client = $this->createStub(AgentSessionClient::class);
        $coordinator = new QuestionCoordinator();
        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');

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

        $ref->invoke(null, $event, $client, $coordinator);

        $active = $coordinator->activeRequest();
        self::assertNotNull($active);
        self::assertTrue($active->allowOther, 'HITL must always allow free-form input (allowOther=true)');
    }

    // ── QH-06 follow-up: interrupt transport marker and bare-string choices ──

    public function testResolveQuestionKindIgnoresInterruptTransportMarkerWithStringSchema(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        // kind='interrupt' with no ui_kind and a string schema should
        // fall through to schema-driven derivation (QuestionKind::Text),
        // NOT match default => Choice which would render an empty overlay.
        $result = $ref->invoke(null, [
            'kind' => 'interrupt',
            'schema' => ['type' => 'string'],
        ]);

        self::assertSame(QuestionKind::Text, $result);
    }

    public function testResolveQuestionKindIgnoresInterruptTransportMarkerWithBooleanSchema(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        // kind='interrupt' with no ui_kind and a boolean schema should
        // derive Confirm, not fall to default => Choice.
        $result = $ref->invoke(null, [
            'kind' => 'interrupt',
            'schema' => ['type' => 'boolean'],
        ]);

        self::assertSame(QuestionKind::Confirm, $result);
    }

    public function testResolveQuestionKindStillMatchesUiKindWhenInterruptKindPresent(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'resolveQuestionKind');

        // When ui_kind IS present alongside kind='interrupt', ui_kind wins.
        $result = $ref->invoke(null, [
            'kind' => 'interrupt',
            'ui_kind' => 'choice',
        ]);

        self::assertSame(QuestionKind::Choice, $result);
    }

    public function testBuildChoicesHandlesBareStringEntries(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'buildChoices');

        $choices = $ref->invoke(null, [
            'choices' => ['Yes', 'No'],
        ], ['type' => 'string']);

        self::assertCount(2, $choices);
        self::assertContainsOnlyInstancesOf(QuestionOption::class, $choices);
        self::assertSame('Yes', $choices[0]->label);
        self::assertSame('', $choices[0]->description, 'Bare string choice must default to empty description');
        self::assertSame('No', $choices[1]->label);
        self::assertSame('', $choices[1]->description);
    }

    public function testBuildChoicesHandlesMixedArrayAndStringEntries(): void
    {
        $ref = new \ReflectionMethod(TickPollListener::class, 'buildChoices');

        $choices = $ref->invoke(null, [
            'choices' => [
                ['label' => 'Structured', 'description' => 'Has description'],
                'BareString',
            ],
        ], ['type' => 'string']);

        self::assertCount(2, $choices);
        self::assertSame('Structured', $choices[0]->label);
        self::assertSame('Has description', $choices[0]->description);
        self::assertSame('BareString', $choices[1]->label);
        self::assertSame('', $choices[1]->description, 'Bare string in mixed list must default to empty description');
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
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(TurnTreeProviderInterface::class));

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
        self::assertTrue($coordinator->actionRequired());

        $ctrlRef = new \ReflectionClass(QuestionController::class);
        $controller = $ctrlRef->newInstanceWithoutConstructor();
        $awaitProp = $ctrlRef->getProperty('awaitingFreeForm');
        $awaitProp->setValue($controller, true);
        self::assertTrue($controller->isAwaitingFreeForm(), 'Precondition: awaitingFreeForm must be true');

        // Inject dependencies into TickPollListener via reflection
        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $this->createIsolatedSubagentLiveChildPoller());
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, $coordinator);
        $listenerRef->getProperty('questionController')->setValue($listener, $controller);

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
        self::assertCount(1, $handlers);

        // Drive one tick
        ($handlers[0])();

        // Assertions: guard blocked open() — overlay is still closed,
        // awaitingFreeForm is still true, and coordinator still has the request.
        self::assertFalse($controller->isOpen(), 'Guard must prevent open() when awaitingFreeForm=true');
        self::assertTrue($controller->isAwaitingFreeForm(), 'awaitingFreeForm must remain true after guard block');
        self::assertTrue($coordinator->actionRequired(), 'Coordinator must still have the active request');
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
        $poller = new RuntimeEventPoller($eventApplier, $logger, $boundary, $this->createStub(TurnTreeProviderInterface::class));

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
        self::assertTrue($coordinator->actionRequired());

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
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, $this->createIsolatedSubagentLiveChildPoller());
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, $coordinator);
        $listenerRef->getProperty('questionController')->setValue($listener, $controller);

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
        self::assertCount(1, $handlers);

        // Drive one tick — the self-heal must reject the orphaned question
        ($handlers[0])();

        // Assertions: reject() advanced the queue (actionRequired=false)
        // and close() reset isOpen/awaitingFreeForm.
        self::assertFalse($coordinator->actionRequired(), 'Orphaned question must be rejected');
        self::assertFalse($controller->isOpen(), 'close() must be called after self-heal');
        self::assertFalse($controller->isAwaitingFreeForm(), 'close() must reset awaitingFreeForm after self-heal');
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
        $state->handle = new \Ineersa\CodingAgent\Runtime\Contract\RunHandle($parentRunId);
        $state->activity = RunActivityStateEnum::WaitingHuman;

        $ref = new \ReflectionMethod(TickPollListener::class, 'shouldRejectOrphanedQuestion');
        $reject = $ref->invoke(null, $state, $coordinator->activeRequest());
        self::assertFalse($reject, 'Parent WaitingHuman question must not be self-healed as orphaned');
    }

    private function createIsolatedSubagentLiveChildPoller(): SubagentLiveChildViewPoller
    {
        return new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new \Psr\Log\NullLogger(),
        );
    }

}
