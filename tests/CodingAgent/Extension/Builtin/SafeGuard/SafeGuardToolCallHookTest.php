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
use PHPUnit\Framework\TestCase;

/** SafeGuard classification + approval answer mapping (no interactive Always-allow). */
final class SafeGuardToolCallHookTest extends TestCase
{
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
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c2', 'bash', ['command' => 'rm -rf /tmp/x'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
    }

    public function testBashDestructiveRequiresApprovalWithAllowDenyOnly(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c3', 'bash', ['command' => 'rm -rf /tmp/build'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertNotSame('', (string) ($dto->details['question_id'] ?? ''));
        $this->assertSame(['✅ Allow', '❌ Deny'], $dto->details['schema']['enum'] ?? null);
    }

    public function testWriteOutsideCwdRequiresApproval(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c4', 'write', ['path' => '/tmp/out.txt', 'content' => 'x'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
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

    public function testSettingsMutationWithoutChannelFailsClosed(): void
    {
        $hook = $this->createHook(false);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            'c7',
            'settings',
            ['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'project', 'value' => 'nord'],
            0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
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
    }

    private function restoreApprovalChannelEnv(): void
    {
        if (false === $this->approvalChannelEnvBackup) {
            putenv('HATFIELD_APPROVAL_CHANNEL');

            return;
        }
        putenv('HATFIELD_APPROVAL_CHANNEL='.$this->approvalChannelEnvBackup);
    }
}
