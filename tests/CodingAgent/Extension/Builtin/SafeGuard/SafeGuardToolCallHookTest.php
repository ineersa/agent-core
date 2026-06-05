<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardToolCallHook — end-to-end classification through
 * the real SafeGuardClassifier.
 */
final class SafeGuardToolCallHookTest extends TestCase
{
    private SafeGuardToolCallHook $hook;
    private string $cwd;
    private ApprovalSessionTracker $tracker;

    protected function setUp(): void
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: false);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = new SafeGuardPolicy();
        $this->cwd = getcwd() ?: '.';
        $this->tracker = new ApprovalSessionTracker();
        $this->hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: $this->tracker,
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );
    }

    // ── Bash tool ──

    public function testBashSafeCommandIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_1',
            toolName: 'bash',
            arguments: ['command' => 'ls -la'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testBashSudoIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_2',
            toolName: 'bash',
            arguments: ['command' => 'sudo apt update'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
        $this->assertTrue($dto->details['intercepted']);
        $this->assertTrue($dto->details['denied']);
    }

    public function testBashDestructiveRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_3',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
        $this->assertArrayHasKey('question_id', $dto->details);
        $this->assertArrayHasKey('schema', $dto->details);
        $this->assertArrayHasKey('prompt', $dto->details);
    }

    public function testBashDangerousGitRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_4',
            toolName: 'bash',
            arguments: ['command' => 'git push --force origin main'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('dangerous_git', $dto->details['category']);
    }

    public function testBashEnvExposureRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_5',
            toolName: 'bash',
            arguments: ['command' => 'env'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('sensitive_info', $dto->details['category']);
    }

    // ── Write tool ──

    public function testWriteInsideCwdIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_6',
            toolName: 'write',
            arguments: ['path' => 'src/test.php', 'content' => '<?php'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testWriteOutsideCwdRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_7',
            toolName: 'write',
            arguments: ['path' => '/etc/hosts', 'content' => '127.0.0.1 localhost'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('write_outside_cwd', $dto->details['category']);
    }

    // ── Edit tool ──

    public function testEditInsideCwdIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_8',
            toolName: 'edit',
            arguments: ['path' => 'README.md', 'oldText' => 'foo', 'newText' => 'bar'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testEditOutsideCwdRequiresApproval(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_9',
            toolName: 'edit',
            arguments: ['path' => '/etc/hostname', 'oldText' => 'old', 'newText' => 'new'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('write_outside_cwd', $dto->details['category']);
    }

    // ── Read tool ──

    public function testReadSafeFileIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_10',
            toolName: 'read',
            arguments: ['path' => 'src/main.php'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testReadProtectedDotEnvRequiresApproval(): void
    {
        $config = new SafeGuardConfig(
            protectedReadPatterns: ['.env.local'],
            autoDenyInNoninteractive: false,
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_11',
            toolName: 'read',
            arguments: ['path' => '.env.local'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    public function testReadSshKeyRequiresApproval(): void
    {
        $config = new SafeGuardConfig(
            protectedReadPatterns: ['.ssh/id_'],
            autoDenyInNoninteractive: false,
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_12',
            toolName: 'read',
            arguments: ['path' => '/home/user/.ssh/id_rsa'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    // ── Unknown tools ──

    public function testUnknownToolIsAllowed(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_13',
            toolName: 'view_image',
            arguments: ['path' => '/secret/file.png'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Allowlist support ──

    public function testAllowlistBypassesDestructiveBlock(): void
    {
        $config = new SafeGuardConfig(
            allowCommandPatterns: ['rm -rf /tmp/build'],
            autoDenyInNoninteractive: false,
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: false,
        );

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_14',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build/cache'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Decision details ──

    public function testRequireApprovalDecisionIncludesAllMetadata(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_15',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertArrayHasKey('category', $dto->details);
        $this->assertArrayHasKey('prompt', $dto->details);
        $this->assertArrayHasKey('schema', $dto->details);
        $this->assertArrayHasKey('question_id', $dto->details);
    }

    // ── Approval session tracking ──

    public function testApprovedOperationIsAllowedOnRetry(): void
    {
        $command = 'rm -rf /tmp/build';

        // First call: should return RequireApproval (not approved yet)
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_16',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);

        // Simulate approval by the human
        $questionId = $dto->details['question_id'];
        $operationKey = $dto->details['operation_key'];
        $this->assertNotNull($operationKey);

        $this->tracker->approveByQuestionId($questionId);
        $this->assertTrue($this->tracker->isApproved($operationKey));

        // Second call (retry): should be allowed
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_17',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto2->kind);
        $this->assertFalse($this->tracker->isApproved($operationKey), 'Approval should be consumed on use');
    }

    public function testDeniedOperationIsNotApproved(): void
    {
        $command = 'rm -rf /tmp/test';

        // First call: RequireApproval
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_18',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $operationKey = $dto->details['operation_key'];
        $this->assertNotNull($operationKey);

        // Simulate denial by the human
        $this->tracker->remove($operationKey);
        $this->assertFalse($this->tracker->isApproved($operationKey));

        // Second call (retry after denial): should still require approval
        $dto2 = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_19',
            toolName: 'bash',
            arguments: ['command' => $command],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto2->kind);
    }

    public function testHooksHandleNonInteractiveAutoDeny(): void
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: true);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: true,
        );

        // Destructive command should be blocked (not require approval) in noninteractive mode
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_20',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue($dto->details['auto_denied'] ?? false);
        $this->assertSame('destructive', $dto->details['category']);
    }

    public function testHardBlockStaysBlockedInNoninteractive(): void
    {
        $config = new SafeGuardConfig(autoDenyInNoninteractive: true);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            approvalTracker: new ApprovalSessionTracker(),
            policyWriter: null,
            cwd: $this->cwd,
            autoDenyInNoninteractive: true,
        );

        // sudo is HardBlock — never approvable, always Block
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_21',
            toolName: 'bash',
            arguments: ['command' => 'sudo rm -rf /'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
    }
}
