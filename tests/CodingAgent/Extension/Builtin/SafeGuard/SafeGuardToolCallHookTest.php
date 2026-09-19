<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionKindEnum;
use Ineersa\Tui\Listener\CtrlCInputInterceptor;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Style;

/** SafeGuard classification + approval answer mapping (no interactive Always-allow). */
final class SafeGuardToolCallHookTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private SafeGuardToolCallHook $hook;
    private string $cwd;
    private string|false $approvalChannelEnvBackup = false;

    protected function setUp(): void
    {
        $this->backupAndClearApprovalChannelEnv();
        $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
        $this->cwd = getcwd() ?: '.';
        $this->hook = new SafeGuardToolCallHook(
            classifier: SafeGuardClassifier::fromConfig($config),
            policy: new SafeGuardPolicy(),
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );
    }

    protected function tearDown(): void
    {
        $this->restoreApprovalChannelEnv();
        parent::tearDown();
    }

    public function testBashSafeCommandIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c1', 'bash', ['command' => 'ls -la'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testBashDestructiveStillRequiresApprovalWhenChannelSet(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $_ENV['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $_SERVER['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c2', 'bash', ['command' => 'rm -rf /tmp/x'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
    }

    public function testApprovalPromptContainsEscapedInputWithClassifierMatchesStyledAsMarkdown(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $_ENV['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $_SERVER['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $command = 'rm first && rmdir second && rm third';

        $dto = $this->hook->onToolCall(new ToolCallContextDTO('evidence', 'bash', ['command' => $command], 0));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame(
            "Allow destructive command?\n\n**Command:**\n\n`rm`&#32;first&#32;\\&\\&&#32;`rmdir`&#32;second&#32;\\&\\&&#32;`rm`&#32;third",
            $dto->details['prompt'] ?? null,
        );
        $this->assertArrayNotHasKey('trigger_input', $dto->details);
        $this->assertArrayNotHasKey('match_spans', $dto->details);
    }

    public function testApprovalMarkdownRendersExactUntrustedMultilineInputWithStyledMatches(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $_ENV['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $_SERVER['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $command = " env |grep <fg=red>*literal*</fg> \x1B[31m ΔΟΚΙΜΉ\nprintenv | sort";
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('render', 'bash', ['command' => $command], 0));
        $prompt = (string) ($dto->details['prompt'] ?? '');

        $harness = new VirtualTuiHarness(
            columns: 42,
            rows: 24,
            palette: new ThemePalette('safeguard-prompt', [
                ThemeColorEnum::Accent->value => 'cyan',
                ThemeColorEnum::Prompt->value => 'magenta',
                ThemeColorEnum::MarkdownCode->value => 'yellow',
                ThemeColorEnum::Text->value => 'white',
            ]),
        );
        $controller = new QuestionController(new QuestionCoordinator(), $harness->screen());
        $controller->open(new QuestionRequest(
            requestId: 'safeguard-markdown',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: $prompt,
            choices: [new QuestionOption('✅ Allow'), new QuestionOption('❌ Deny')],
            allowOther: false,
        ));

        $text = $harness->plainScreenText();
        $this->assertStringContainsString('Allow sensitive information access?', $text);
        $this->assertStringContainsString('Command:', $text);
        $this->assertStringContainsString('env |grep <fg=red>*literal*</fg> [31m', $text);
        $this->assertStringContainsString('ΔΟΚΙΜΉ', $text);
        $this->assertStringContainsString('printenv | sort', $text);
        $this->assertStringContainsString('→ ✅ Allow', $text);
        $this->assertStringContainsString('❌ Deny', $text);
        $this->assertStringContainsString('session virtual-startup-session', $text);
        $this->assertStringNotContainsString('…', $text);
        $this->assertStringContainsString(new Style(color: 'yellow')->apply('env |'), $harness->ansiOutput());
    }

    public function testLongApprovalPromptCanBeReadBeforeAnswering(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $command = implode("\n", array_map(static fn (int $i): string => 'printenv # OPERATION_'.$i, range(1, 20)))."\nrm FINAL_OPERATION";
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('long-render', 'bash', ['command' => $command], 0));
        $harness = new VirtualTuiHarness(columns: 42, rows: 24);
        (new CtrlCInputInterceptor())->register($this->buildTuiContext()
            ->withTui($harness->tui())
            ->withScreen($harness->screen())
            ->withState(new TuiSessionState('long-approval'))
            ->build());
        $coordinator = new QuestionCoordinator();
        $controller = new QuestionController($coordinator, $harness->screen());
        $request = new QuestionRequest(
            requestId: 'long-approval',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: (string) $dto->details['prompt'],
            choices: [new QuestionOption('✅ Allow'), new QuestionOption('❌ Deny')],
            allowOther: false,
        );
        $answers = [];
        $coordinator->enqueue($request, onAnswer: static function (mixed $answer) use (&$answers): void {
            $answers[] = $answer;
        });
        $harness->startInputLoop();
        try {
            $controller->open($request);
            $first = $harness->plainScreenText();
            $this->assertStringContainsString('Partial prompt', $first);
            $this->assertStringContainsString('Ctrl+↑/↓', $first);
            $this->assertStringNotContainsString('FINAL_OPERATION', $first);
            $pages = $first;
            for ($i = 0; $i < 20; ++$i) {
                $harness->sendInput("\x1b[1;5B"); // Ctrl+Down pages the prompt, not the answer list.
                $this->assertTrue($harness->tui()->isRunning(), 'Prompt paging must not exit the TUI');
                $page = $harness->plainScreenText();
                $this->assertStringContainsString('→ ✅ Allow', $page);
                $this->assertStringContainsString('❌ Deny', $page);
                $pages .= "\n".$page;
            }
            foreach (range(1, 20) as $i) {
                $this->assertStringContainsString('OPERATION_'.$i, $pages);
            }
            $this->assertStringContainsString('FINAL_OPERATION', $harness->plainScreenText());
            $this->assertSame([], $answers, 'Inspecting the command must not submit an approval');
            for ($i = 0; $i < 20; ++$i) {
                $harness->sendInput("\x1b[1;5A"); // Ctrl+Up returns to the prompt start.
            }
            $this->assertSame($first, $harness->plainScreenText());
            $harness->sendInput("\x1b[B\r");
            $this->assertSame(['❌ Deny'], $answers);
        } finally {
            $harness->stopInputLoop();
        }
    }

    public function testBashDestructiveRequiresApprovalWithAllowDenyOnly(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $_ENV['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $_SERVER['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c3', 'bash', ['command' => 'rm -rf /tmp/build'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertNotSame('', (string) ($dto->details['question_id'] ?? ''));
        $this->assertSame(['✅ Allow', '❌ Deny'], $dto->details['schema']['enum'] ?? null);
    }

    public function testWriteOutsideCwdRequiresApproval(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $_ENV['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $_SERVER['HATFIELD_APPROVAL_CHANNEL'] = 'controller';
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c4', 'write', ['path' => '/tmp/out.txt', 'content' => 'x'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertStringContainsString("**Path:**\n\n\\/tmp\\/out\\.txt", (string) ($dto->details['prompt'] ?? ''));
    }

    public function testResolveApprovalAnswerAllowReturnsAllow(): void
    {
        $decision = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO('q', '✅ Allow', 'bash', ['category' => 'destructive']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $decision->kind);
    }

    public function testResolveApprovalAnswerDenyReturnsBlock(): void
    {
        $decision = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO('q', '❌ Deny', 'bash', ['category' => 'destructive']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $decision->kind);
        $this->assertSame('safeguard_denied', $decision->reason);
    }

    public function testResolveApprovalAnswerCancelledByUserIsFailClosedCancel(): void
    {
        $decision = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO('q', 'Cancelled by user', 'bash', ['category' => 'destructive']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $decision->kind);
        $this->assertSame('safeguard_cancelled', $decision->reason);
    }

    public function testOnApprovalAnsweredIsNoOp(): void
    {
        // Must not throw and must not mutate settings (writer removed).
        $this->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
            'q',
            '✅ Allow',
            'bash',
            ['category' => 'destructive', 'command' => 'rm -rf /tmp/build'],
        ));
        $this->addToAssertionCount(1);
    }

    public function testAutoDenyBlocksWhenNoApprovalChannel(): void
    {
        $hook = $this->createHook(true);
        $dto = $hook->onToolCall(new ToolCallContextDTO('c6', 'bash', ['command' => 'rm -rf /tmp/x'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue((bool) ($dto->details['auto_denied'] ?? false));
    }

    private function createHook(bool $autoDeny): SafeGuardToolCallHook
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: $autoDeny);

        return new SafeGuardToolCallHook(
            classifier: SafeGuardClassifier::fromConfig($config),
            policy: new SafeGuardPolicy(),
            cwd: $this->cwd,
            autoDenyInNoninteractive: $autoDeny,
        );
    }

    private function backupAndClearApprovalChannelEnv(): void
    {
        $value = getenv('HATFIELD_APPROVAL_CHANNEL');
        $this->approvalChannelEnvBackup = false === $value ? false : $value;
        putenv('HATFIELD_APPROVAL_CHANNEL');
        unset($_ENV['HATFIELD_APPROVAL_CHANNEL'], $_SERVER['HATFIELD_APPROVAL_CHANNEL']);
    }

    private function restoreApprovalChannelEnv(): void
    {
        if (false === $this->approvalChannelEnvBackup) {
            putenv('HATFIELD_APPROVAL_CHANNEL');
            unset($_ENV['HATFIELD_APPROVAL_CHANNEL'], $_SERVER['HATFIELD_APPROVAL_CHANNEL']);

            return;
        }
        putenv('HATFIELD_APPROVAL_CHANNEL='.$this->approvalChannelEnvBackup);
        $_ENV['HATFIELD_APPROVAL_CHANNEL'] = $this->approvalChannelEnvBackup;
        $_SERVER['HATFIELD_APPROVAL_CHANNEL'] = $this->approvalChannelEnvBackup;
    }
}
