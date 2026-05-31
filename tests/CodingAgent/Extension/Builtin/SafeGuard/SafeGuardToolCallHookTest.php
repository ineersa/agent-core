<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardToolCallHook — end-to-end classification through
 * the real SafeGuardClassifier.
 */
final class SafeGuardToolCallHookTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
    }

    private function createHook(
        bool $autoDeny = true,
        ?SafeGuardConfig $config = null,
        ?ApprovalSessionTracker $tracker = null,
    ): SafeGuardToolCallHook {
        $config ??= new SafeGuardConfig();
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $tracker ??= new ApprovalSessionTracker();

        return new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            tracker: $tracker,
            policyWriter: null,
            autoDenyInNoninteractive: $autoDeny,
            cwd: $this->cwd,
        );
    }

    // ── Bash tool — basic classification ──

    public function testBashSafeCommandIsAllowed(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_1', toolName: 'bash', arguments: ['command' => 'ls -la'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testBashSudoIsBlocked(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_2', toolName: 'bash', arguments: ['command' => 'sudo apt update'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
        $this->assertTrue($dto->details['intercepted']);
        $this->assertTrue($dto->details['denied']);
    }

    // ── Auto-deny mode (autoDenyInNoninteractive=true, default) ──

    public function testDestructiveIsBlockedWhenAutoDeny(): void
    {
        $hook = $this->createHook(autoDeny: true);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_3', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    public function testDangerousGitIsBlockedWhenAutoDeny(): void
    {
        $hook = $this->createHook(autoDeny: true);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_4', toolName: 'bash', arguments: ['command' => 'git push --force origin main'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('dangerous_git', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    public function testEnvExposureIsBlockedWhenAutoDeny(): void
    {
        $hook = $this->createHook(autoDeny: true);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_5', toolName: 'bash', arguments: ['command' => 'env'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('sensitive_info', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    public function testWriteOutsideCwdIsBlockedWhenAutoDeny(): void
    {
        $hook = $this->createHook(autoDeny: true);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_6', toolName: 'write', arguments: ['path' => '/etc/hosts', 'content' => 'x'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('write_outside_cwd', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    // ── Approval mode (autoDenyInNoninteractive=false) ──

    public function testDestructiveRequiresApprovalWhenNotAutoDeny(): void
    {
        $hook = $this->createHook(autoDeny: false);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_7', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
        $this->assertArrayHasKey('question_id', $dto->details);
        $this->assertArrayHasKey('prompt', $dto->details);
        $this->assertArrayHasKey('schema', $dto->details);
    }

    public function testHardBlockIsAlwaysBlockedEvenWithoutAutoDeny(): void
    {
        $hook = $this->createHook(autoDeny: false);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_8', toolName: 'bash', arguments: ['command' => 'sudo rm -rf /'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
        $this->assertArrayNotHasKey('auto_denied', $dto->details);
    }

    // ── Approval lifecycle: Allow once ──

    public function testAllowOnceApprovesViaTracker(): void
    {
        $tracker = new ApprovalSessionTracker();
        $hook = $this->createHook(autoDeny: false, tracker: $tracker);

        // First call: returns RequireApproval
        $dto1 = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_9', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto1->kind);

        // Simulate the answer being resolved from events.jsonl
        $key = 'bash:rm -rf /tmp/build';
        $tracker->forceAnswer($key, 'Allow once');

        // Retry: should resolve the answer and allow (one-time)
        $dto2 = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_10', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto2->kind);

        // Third call: approval was consumed, should RequireApproval again
        $dto3 = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_11', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto3->kind);
    }

    // ── Approval lifecycle: Deny ──

    public function testDenyAnswerBlocksOnRetry(): void
    {
        $tracker = new ApprovalSessionTracker();
        $hook = $this->createHook(autoDeny: false, tracker: $tracker);

        // First call: RequireApproval
        $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_12', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));

        // Force answer "Deny"
        $key = 'bash:rm -rf /tmp/build';
        $tracker->forceAnswer($key, 'Deny');

        // Retry: should block with denied_by_user flag
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_13', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue($dto->details['denied_by_user']);
    }

    // ── Write/Edit/Read tools ──

    public function testWriteInsideCwdIsAllowed(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_14', toolName: 'write', arguments: ['path' => 'src/test.php', 'content' => '<?php'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testEditInsideCwdIsAllowed(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_15', toolName: 'edit', arguments: ['path' => 'README.md', 'oldText' => 'a', 'newText' => 'b'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testReadSafeFileIsAllowed(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_16', toolName: 'read', arguments: ['path' => 'src/main.php'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testReadProtectedDotEnvIsBlockedWhenAutoDeny(): void
    {
        $config = new SafeGuardConfig(protectedReadPatterns: ['.env.local']);
        $hook = $this->createHook(autoDeny: true, config: $config);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_17', toolName: 'read', arguments: ['path' => '.env.local'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    public function testReadProtectedDotEnvRequiresApprovalWhenNotAutoDeny(): void
    {
        $config = new SafeGuardConfig(protectedReadPatterns: ['.env.local']);
        $hook = $this->createHook(autoDeny: false, config: $config);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_18', toolName: 'read', arguments: ['path' => '.env.local'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    // ── Allowlist ──

    public function testAllowlistBypassesDestructiveBlock(): void
    {
        $config = new SafeGuardConfig(allowCommandPatterns: ['rm -rf /tmp/build']);
        $hook = $this->createHook(config: $config);
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_19', toolName: 'bash', arguments: ['command' => 'rm -rf /tmp/build/cache'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Unknown tools ──

    public function testUnknownToolIsAllowed(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_20', toolName: 'view_image', arguments: ['path' => '/img.png'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Decision details ──

    public function testBlockedDecisionIncludesAllMetadata(): void
    {
        $hook = $this->createHook();
        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_21', toolName: 'bash', arguments: ['command' => 'rm -rf /'], orderIndex: 0,
        ));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertNotNull($dto->reason);
        $this->assertArrayHasKey('category', $dto->details);
        $this->assertArrayHasKey('intercepted', $dto->details);
        $this->assertArrayHasKey('denied', $dto->details);
        $this->assertTrue($dto->details['intercepted']);
        $this->assertTrue($dto->details['denied']);
    }
}
