<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\ApprovalSessionTracker;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardPolicyWriter;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardToolCallHook;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardToolCallHook — end-to-end classification through
 * the real SafeGuardClassifier, plus approval lifecycle tests.
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

    /** @param array<string, mixed> $arguments */
    private function ctx(
        string $id,
        string $toolName,
        array $arguments,
        ?string $runId = 'run-1',
    ): ToolCallContextDTO {
        return new ToolCallContextDTO(
            toolCallId: $id,
            toolName: $toolName,
            arguments: $arguments,
            orderIndex: 0,
            runId: $runId,
        );
    }

    // ── Bash tool — basic classification ──

    public function testBashSafeCommandIsAllowed(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c1', 'bash', ['command' => 'ls -la']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testBashSudoIsBlocked(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c2', 'bash', ['command' => 'sudo apt update']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
        $this->assertTrue($dto->details['intercepted']);
        $this->assertTrue($dto->details['denied']);
    }

    // ── Auto-deny mode (autoDenyInNoninteractive=true, default) ──

    public function testDestructiveIsBlockedWhenAutoDeny(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c3', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    public function testDangerousGitIsBlockedWhenAutoDeny(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c4', 'bash', ['command' => 'git push --force origin main']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('dangerous_git', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    public function testEnvExposureIsBlockedWhenAutoDeny(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c5', 'bash', ['command' => 'env']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('sensitive_info', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    public function testWriteOutsideCwdIsBlockedWhenAutoDeny(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c6', 'write', ['path' => '/etc/hosts', 'content' => 'x']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('write_outside_cwd', $dto->details['category']);
        $this->assertTrue($dto->details['auto_denied']);
    }

    // ── Approval mode (autoDenyInNoninteractive=false) ──

    public function testDestructiveRequiresApprovalWhenNotAutoDeny(): void
    {
        $dto = $this->createHook(autoDeny: false)->onToolCall($this->ctx('c7', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
        $this->assertArrayHasKey('question_id', $dto->details);
        $this->assertArrayHasKey('prompt', $dto->details);
        $this->assertArrayHasKey('schema', $dto->details);
    }

    public function testReadProtectedDotEnvRequiresApprovalWhenNotAutoDeny(): void
    {
        $config = new SafeGuardConfig(protectedReadPatterns: ['.env.local']);
        $hook = $this->createHook(autoDeny: false, config: $config);
        $dto = $hook->onToolCall($this->ctx('c18', 'read', ['path' => '.env.local']));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    public function testHardBlockIsAlwaysBlockedEvenWithoutAutoDeny(): void
    {
        $dto = $this->createHook(autoDeny: false)->onToolCall($this->ctx('c8', 'bash', ['command' => 'sudo rm -rf /']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('hard_block', $dto->details['category']);
        $this->assertArrayNotHasKey('auto_denied', $dto->details);
    }

    // ── Null runId guard ──

    public function testEmptyRunIdBlocksInsteadOfRequireApproval(): void
    {
        $dto = $this->createHook(autoDeny: false)->onToolCall($this->ctx('c_no_run', 'bash', ['command' => 'rm -rf /tmp'], runId: null));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue($dto->details['no_run_id']);
    }

    // ── Approval lifecycle: Allow once ──

    public function testAllowOnceLifecycle(): void
    {
        $tracker = new ApprovalSessionTracker();
        $hook = $this->createHook(autoDeny: false, tracker: $tracker);

        // 1. First call: RequireApproval
        $dto1 = $hook->onToolCall($this->ctx('c9', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto1->kind);

        // Simulate the answer being resolved from events.jsonl
        $key = 'bash:rm -rf /tmp/build';
        $tracker->forceAnswer($key, 'Allow once');

        // 2. Retry: resolveAnswer → approve(key) → Allow
        $dto2 = $hook->onToolCall($this->ctx('c10', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto2->kind);

        // 3. Third call: isApproved → consumeApproval → Allow (one retry)
        $dto3 = $hook->onToolCall($this->ctx('c11', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto3->kind);

        // 4. Fourth call: consumed, re-classify → RequireApproval
        $dto4 = $hook->onToolCall($this->ctx('c12', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::RequireApproval, $dto4->kind);
    }

    // ── Approval lifecycle: Deny ──

    public function testDenyAnswerBlocksOnRetry(): void
    {
        $tracker = new ApprovalSessionTracker();
        $hook = $this->createHook(autoDeny: false, tracker: $tracker);

        // First call: RequireApproval
        $hook->onToolCall($this->ctx('c13', 'bash', ['command' => 'rm -rf /tmp/build']));

        // Force answer "Deny"
        $tracker->forceAnswer('bash:rm -rf /tmp/build', 'Deny');

        // Retry: should block with denied_by_user flag
        $dto = $hook->onToolCall($this->ctx('c14', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertTrue($dto->details['denied_by_user']);
    }

    // ── Approval lifecycle: Always allow ──

    public function testAlwaysAllowPersistsCommandPattern(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tmpFile = tempnam(sys_get_temp_dir(), 'sg_test_');
        unlink($tmpFile);
        $writer = new SafeGuardPolicyWriter($tmpFile);

        $config = new SafeGuardConfig();
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            tracker: $tracker,
            policyWriter: $writer,
            autoDenyInNoninteractive: false,
            cwd: $this->cwd,
        );

        // First call: RequireApproval
        $hook->onToolCall($this->ctx('c_al_1', 'bash', ['command' => 'rm -rf /tmp/build']));

        // Force answer "Always allow"
        $tracker->forceAnswer('bash:rm -rf /tmp/build', 'Always allow');

        // Retry: should persist and allow
        $dto = $hook->onToolCall($this->ctx('c_al_2', 'bash', ['command' => 'rm -rf /tmp/build']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);

        // Policy file should contain the pattern
        $content = file_get_contents($tmpFile);
        $this->assertStringContainsString('rm -rf /tmp/build', $content);

        unlink($tmpFile);
    }

    public function testAlwaysAllowUsesPathForWriteOutsideCwd(): void
    {
        $tracker = new ApprovalSessionTracker();
        $tmpFile = tempnam(sys_get_temp_dir(), 'sg_test_');
        unlink($tmpFile);
        $writer = new SafeGuardPolicyWriter($tmpFile);

        $config = new SafeGuardConfig();
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook(
            classifier: $classifier,
            policy: $policy,
            tracker: $tracker,
            policyWriter: $writer,
            autoDenyInNoninteractive: false,
            cwd: $this->cwd,
        );

        // First call: RequireApproval (write outside cwd)
        $hook->onToolCall($this->ctx('c_al_3', 'write', ['path' => '/etc/hosts', 'content' => 'x']));

        // Force answer "Always allow"
        $tracker->forceAnswer('write:/etc/hosts', 'Always allow');

        // Retry: should persist path, not empty command
        $dto = $hook->onToolCall($this->ctx('c_al_4', 'write', ['path' => '/etc/hosts', 'content' => 'x']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);

        $content = file_get_contents($tmpFile);
        $this->assertStringContainsString('/etc/hosts', $content);
        $this->assertStringContainsString('allow_write_outside_cwd', $content);

        unlink($tmpFile);
    }

    // ── Write/Edit/Read tools ──

    public function testWriteInsideCwdIsAllowed(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c15', 'write', ['path' => 'src/test.php', 'content' => '<?php']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testEditInsideCwdIsAllowed(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c16', 'edit', ['path' => 'README.md', 'oldText' => 'a', 'newText' => 'b']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testReadSafeFileIsAllowed(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c17', 'read', ['path' => 'src/main.php']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    public function testReadProtectedDotEnvIsBlockedWhenAutoDeny(): void
    {
        $config = new SafeGuardConfig(protectedReadPatterns: ['.env.local']);
        $hook = $this->createHook(autoDeny: true, config: $config);
        $dto = $hook->onToolCall($this->ctx('c18', 'read', ['path' => '.env.local']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    // ── Allowlist ──

    public function testAllowlistBypassesDestructiveBlock(): void
    {
        $config = new SafeGuardConfig(allowCommandPatterns: ['rm -rf /tmp/build']);
        $hook = $this->createHook(config: $config);
        $dto = $hook->onToolCall($this->ctx('c19', 'bash', ['command' => 'rm -rf /tmp/build/cache']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Unknown tools ──

    public function testUnknownToolIsAllowed(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c20', 'view_image', ['path' => '/img.png']));
        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Decision metadata ──

    public function testBlockedDecisionIncludesAllMetadata(): void
    {
        $dto = $this->createHook()->onToolCall($this->ctx('c21', 'bash', ['command' => 'rm -rf /']));
        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertNotNull($dto->reason);
        $this->assertArrayHasKey('category', $dto->details);
        $this->assertArrayHasKey('intercepted', $dto->details);
        $this->assertArrayHasKey('denied', $dto->details);
        $this->assertTrue($dto->details['intercepted']);
        $this->assertTrue($dto->details['denied']);
    }
}
