<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardPolicyWriter;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionKindEnum;
use PHPUnit\Framework\TestCase;

/** SafeGuard classification + approval answer mapping (no process-local tracker). */
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
            policyWriter: null,
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

    public function testBashDestructiveRequiresApproval(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c3', 'bash', ['command' => 'rm -rf /tmp/build'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertNotSame('', (string) ($dto->details['question_id'] ?? ''));
        $this->assertSame(['✅ Allow once', '📌 Always allow', '❌ Block'], $dto->details['schema']['enum'] ?? null);
    }

    public function testWriteOutsideCwdRequiresApproval(): void
    {
        putenv('HATFIELD_APPROVAL_CHANNEL=controller');
        $dto = $this->hook->onToolCall(new ToolCallContextDTO('c4', 'write', ['path' => '/tmp/out.txt', 'content' => 'x'], 0));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
    }

    public function testResolveApprovalAnswerAllowOnceReturnsAllow(): void
    {
        $decision = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO('q', '✅ Allow once', 'bash', ['category' => 'destructive']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $decision->kind);
    }

    public function testResolveApprovalAnswerDenyReturnsBlock(): void
    {
        $decision = $this->hook->resolveApprovalAnswer(new ApprovalAnswerContextDTO('q', '❌ Block', 'bash', ['category' => 'destructive']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $decision->kind);
        $this->assertSame('safeguard_denied', $decision->reason);
    }

    public function testAlwaysAllowPersistsPatternWithoutTracker(): void
    {
        $tmpDir = sys_get_temp_dir().'/sg_hook_'.uniqid();
        mkdir($tmpDir, 0o755, true);
        $settingsPath = $tmpDir.'/settings.yaml';
        try {
            putenv('HATFIELD_APPROVAL_CHANNEL=controller');
            $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
            $hook = new SafeGuardToolCallHook(
                classifier: SafeGuardClassifier::fromConfig($config),
                policy: SafeGuardPolicy::fromConfig($config),
                policyWriter: new SafeGuardPolicyWriter($settingsPath),
                cwd: $this->cwd,
                autoDenyInNoninteractive: false,
            );
            $dto = $hook->onToolCall(new ToolCallContextDTO('c5', 'bash', ['command' => 'rm -rf /tmp/build'], 0));
            $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                (string) $dto->details['question_id'],
                '📌 Always allow',
                'bash',
                [
                    'operation_key' => $dto->details['operation_key'],
                    'category' => 'destructive',
                    'command' => 'rm -rf /tmp/build',
                    'tool_name' => 'bash',
                ],
            ));
            $this->assertFileExists($settingsPath);
            $this->assertStringContainsString('rm -rf /tmp/build', (string) file_get_contents($settingsPath));
        } finally {
            @unlink($settingsPath);
            @rmdir($tmpDir);
            putenv('HATFIELD_APPROVAL_CHANNEL');
        }
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
            policyWriter: null,
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
