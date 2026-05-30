<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

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
    private SafeGuardToolCallHook $hook;
    private string $cwd;

    protected function setUp(): void
    {
        $config = new SafeGuardConfig();
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = new SafeGuardPolicy();
        $this->cwd = getcwd() ?: '.';
        $this->hook = new SafeGuardToolCallHook($classifier, $policy, $this->cwd);
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

    public function testBashDestructiveIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_3',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('destructive', $dto->details['category']);
    }

    public function testBashDangerousGitIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_4',
            toolName: 'bash',
            arguments: ['command' => 'git push --force origin main'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('dangerous_git', $dto->details['category']);
    }

    public function testBashEnvExposureIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_5',
            toolName: 'bash',
            arguments: ['command' => 'env'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
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

    public function testWriteOutsideCwdIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_7',
            toolName: 'write',
            arguments: ['path' => '/etc/hosts', 'content' => '127.0.0.1 localhost'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
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

    public function testEditOutsideCwdIsBlocked(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_9',
            toolName: 'edit',
            arguments: ['path' => '/etc/hostname', 'oldText' => 'old', 'newText' => 'new'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
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

    public function testReadProtectedDotEnvIsBlocked(): void
    {
        $config = new SafeGuardConfig(
            protectedReadPatterns: ['.env.local'],
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook($classifier, $policy, $this->cwd);

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_11',
            toolName: 'read',
            arguments: ['path' => '.env.local'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
        $this->assertSame('protected_read', $dto->details['category']);
    }

    public function testReadSshKeyIsBlocked(): void
    {
        $config = new SafeGuardConfig(
            protectedReadPatterns: ['.ssh/id_'],
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook($classifier, $policy, $this->cwd);

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_12',
            toolName: 'read',
            arguments: ['path' => '/home/user/.ssh/id_rsa'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Block, $dto->kind);
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
        );
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = SafeGuardPolicy::fromConfig($config);
        $hook = new SafeGuardToolCallHook($classifier, $policy, $this->cwd);

        $dto = $hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_14',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /tmp/build/cache'],
            orderIndex: 0,
        ));

        $this->assertSame(ToolCallDecisionKindEnum::Allow, $dto->kind);
    }

    // ── Decision details ──

    public function testBlockedDecisionIncludesAllMetadata(): void
    {
        $dto = $this->hook->onToolCall(new ToolCallContextDTO(
            toolCallId: 'call_15',
            toolName: 'bash',
            arguments: ['command' => 'rm -rf /'],
            orderIndex: 0,
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
