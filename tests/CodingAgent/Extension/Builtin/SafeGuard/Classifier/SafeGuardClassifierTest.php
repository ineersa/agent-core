<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecision;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use PHPUnit\Framework\TestCase;

/**
 * Integration/end-to-end tests for SafeGuardClassifier.
 *
 * Tests the orchestration of CommandMatcher + PathMatcher through the
 * single classify() entry point. Uses configurable tool names from
 * SafeGuardConfig.
 */
final class SafeGuardClassifierTest extends TestCase
{
    private SafeGuardClassifier $classifier;
    private string $cwd;

    protected function setUp(): void
    {
        $this->classifier = SafeGuardClassifier::fromConfig(new SafeGuardConfig());
        $this->cwd = getcwd() ?: '.';
    }

    // ── Bash tool ──

    public function testBashSudoIsHardBlocked(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', ['command' => 'sudo id'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::HardBlock, $decision->kind);
        $this->assertSame('bash', $decision->toolName);
    }

    public function testBashDestructive(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', ['command' => 'rm -rf /tmp'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Destructive, $decision->kind);
    }

    public function testBashSafeCommandIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', ['command' => 'ls -la'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testBashAllowlistBypassesDestructive(): void
    {
        $policy = new SafeGuardPolicy(
            allowCommandPatterns: ['rm -rf /tmp'],
        );

        $decision = $this->classifier->classify('bash', ['command' => 'rm -rf /tmp/cached'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testBashCustomDangerous(): void
    {
        $policy = new SafeGuardPolicy(
            dangerousCommandPatterns: ['my-risky-tool'],
        );

        $decision = $this->classifier->classify('bash', ['command' => 'my-risky-tool --force'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::CustomDangerous, $decision->kind);
    }

    public function testBashEmptyCommandIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', [], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Write / Edit tool ──

    public function testWriteInsideCwdIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => 'src/test.php'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testWriteOutsideCwdIsFlagged(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => '/etc/hosts'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::WriteOutsideCwd, $decision->kind);
    }

    public function testWriteOutsideCwdAllowlistedIsAllowed(): void
    {
        $policy = new SafeGuardPolicy(
            allowWriteOutsideCwd: ['/etc'],
        );

        $decision = $this->classifier->classify('write', ['path' => '/etc/hosts'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testEditInsideCwdIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('edit', ['path' => 'README.md'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testEditOutsideCwdIsFlagged(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('edit', ['path' => '/etc/hostname'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::WriteOutsideCwd, $decision->kind);
    }

    public function testWriteWithAtPrefixIsHandled(): void
    {
        // Editor-style path references like @src/foo.php
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => '@src/test.php'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testWriteEmptyPathIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => ''], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testWritePathMissingIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', [], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Read tool ──

    public function testReadSafeFileIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('read', ['path' => 'src/main.php'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testReadProtectedFileIsFlagged(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $decision = $this->classifier->classify('read', ['path' => '.env.local'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::ProtectedRead, $decision->kind);
    }

    public function testReadSshKeyIsFlagged(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.ssh/id_'],
        );

        $decision = $this->classifier->classify('read', ['path' => '/home/user/.ssh/id_rsa'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::ProtectedRead, $decision->kind);
    }

    public function testReadWithAtPrefixIsHandled(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $decision = $this->classifier->classify('read', ['path' => '@.env.local'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::ProtectedRead, $decision->kind);
    }

    // ── Settings tool ──

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: SafeGuardDecisionKind}>
     */
    public static function settingsClassificationCases(): iterable
    {
        yield 'read allows' => [['operation' => 'read', 'path' => 'tui.theme'], SafeGuardDecisionKind::Allow];
        yield 'set custom dangerous' => [['operation' => 'set', 'path' => 'tui.theme', 'scope' => 'project', 'value' => 'x'], SafeGuardDecisionKind::CustomDangerous];
        yield 'remove custom dangerous' => [['operation' => 'remove', 'path' => 'tui.theme', 'scope' => 'user'], SafeGuardDecisionKind::CustomDangerous];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('settingsClassificationCases')]
    public function testSettingsOperationClassification(array $arguments, SafeGuardDecisionKind $expected): void
    {
        $decision = $this->classifier->classify('settings', $arguments, $this->cwd, new SafeGuardPolicy());

        $this->assertSame($expected, $decision->kind);
        if (SafeGuardDecisionKind::CustomDangerous === $expected) {
            $this->assertStringContainsString((string) $arguments['operation'], $decision->reason);
            $this->assertStringContainsString((string) $arguments['path'], $decision->reason);
        }
    }

    // ── Unknown tools ──

    public function testUnknownToolIsAllowedByDefault(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('view_image', ['path' => '/secret/file.png'], $this->cwd, $policy);

        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Configurable tool names ──

    public function testCustomBashToolNameIsRespected(): void
    {
        $config = SafeGuardConfig::fromArray([
            'tool_names' => ['bash' => 'execute'],
        ]);
        $classifier = SafeGuardClassifier::fromConfig($config);
        $policy = new SafeGuardPolicy();

        // Default classifier still matches 'bash'
        $decision = $this->classifier->classify('bash', ['command' => 'rm -rf /'], $this->cwd, $policy);
        $this->assertSame(SafeGuardDecisionKind::Destructive, $decision->kind);

        // Custom classifier matches 'execute' as the bash tool
        $decision = $classifier->classify('execute', ['command' => 'rm -rf /'], $this->cwd, $policy);
        $this->assertSame(SafeGuardDecisionKind::Destructive, $decision->kind);
        $this->assertSame('execute', $decision->toolName);

        // Custom classifier does NOT match 'bash' anymore (it uses 'execute')
        $decision = $classifier->classify('bash', ['command' => 'rm -rf /'], $this->cwd, $policy);
        $this->assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Decision convenience methods ──

    public function testAllowDecisionIsAllowed(): void
    {
        $decision = SafeGuardDecision::allow('bash');

        $this->assertTrue($decision->isAllowed());
    }

    public function testBlockDecisionIsNotAllowed(): void
    {
        $decision = SafeGuardDecision::block(SafeGuardDecisionKind::HardBlock, 'no', 'bash');

        $this->assertFalse($decision->isAllowed());
    }
}
