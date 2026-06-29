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
use PHPUnit\Framework\TestCase;

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

    public function testHandleHumanInputRequestedPassesHeaderDefaultAllowOtherSecret(): void
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
                'allow_other' => true,
                'secret' => true,
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
        self::assertTrue($active->allowOther);
        self::assertTrue($active->secret);
        self::assertSame('hitl_q_rich', $active->requestId);
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

}
