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

        self::assertSame(SafeGuardDecisionKind::HardBlock, $decision->kind);
        self::assertSame('bash', $decision->toolName);
    }

    public function testBashDestructive(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', ['command' => 'rm -rf /tmp'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Destructive, $decision->kind);
    }

    public function testBashSafeCommandIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', ['command' => 'ls -la'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testBashAllowlistBypassesDestructive(): void
    {
        $policy = new SafeGuardPolicy(
            allowCommandPatterns: ['rm -rf /tmp'],
        );

        $decision = $this->classifier->classify('bash', ['command' => 'rm -rf /tmp/cached'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testBashCustomDangerous(): void
    {
        $policy = new SafeGuardPolicy(
            dangerousCommandPatterns: ['my-risky-tool'],
        );

        $decision = $this->classifier->classify('bash', ['command' => 'my-risky-tool --force'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::CustomDangerous, $decision->kind);
    }

    public function testBashEmptyCommandIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('bash', [], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Write / Edit tool ──

    public function testWriteInsideCwdIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => 'src/test.php'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testWriteOutsideCwdIsFlagged(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => '/etc/hosts'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::WriteOutsideCwd, $decision->kind);
    }

    public function testWriteOutsideCwdAllowlistedIsAllowed(): void
    {
        $policy = new SafeGuardPolicy(
            allowWriteOutsideCwd: ['/etc'],
        );

        $decision = $this->classifier->classify('write', ['path' => '/etc/hosts'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testEditInsideCwdIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('edit', ['path' => 'README.md'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testEditOutsideCwdIsFlagged(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('edit', ['path' => '/etc/hostname'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::WriteOutsideCwd, $decision->kind);
    }

    public function testWriteWithAtPrefixIsHandled(): void
    {
        // Editor-style path references like @src/foo.php
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => '@src/test.php'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testWriteEmptyPathIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', ['path' => ''], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testWritePathMissingIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('write', [], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Read tool ──

    public function testReadSafeFileIsAllowed(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('read', ['path' => 'src/main.php'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    public function testReadProtectedFileIsFlagged(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $decision = $this->classifier->classify('read', ['path' => '.env.local'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::ProtectedRead, $decision->kind);
    }

    public function testReadSshKeyIsFlagged(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.ssh/id_'],
        );

        $decision = $this->classifier->classify('read', ['path' => '/home/user/.ssh/id_rsa'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::ProtectedRead, $decision->kind);
    }

    public function testReadWithAtPrefixIsHandled(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $decision = $this->classifier->classify('read', ['path' => '@.env.local'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::ProtectedRead, $decision->kind);
    }

    // ── Unknown tools ──

    public function testUnknownToolIsAllowedByDefault(): void
    {
        $policy = new SafeGuardPolicy();

        $decision = $this->classifier->classify('view_image', ['path' => '/secret/file.png'], $this->cwd, $policy);

        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
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
        self::assertSame(SafeGuardDecisionKind::Destructive, $decision->kind);

        // Custom classifier matches 'execute' as the bash tool
        $decision = $classifier->classify('execute', ['command' => 'rm -rf /'], $this->cwd, $policy);
        self::assertSame(SafeGuardDecisionKind::Destructive, $decision->kind);
        self::assertSame('execute', $decision->toolName);

        // Custom classifier does NOT match 'bash' anymore (it uses 'execute')
        $decision = $classifier->classify('bash', ['command' => 'rm -rf /'], $this->cwd, $policy);
        self::assertSame(SafeGuardDecisionKind::Allow, $decision->kind);
    }

    // ── Decision convenience methods ──

    public function testAllowDecisionIsAllowed(): void
    {
        $decision = SafeGuardDecision::allow('bash');

        self::assertTrue($decision->isAllowed());
    }

    public function testBlockDecisionIsNotAllowed(): void
    {
        $decision = SafeGuardDecision::block(SafeGuardDecisionKind::HardBlock, 'no', 'bash');

        self::assertFalse($decision->isAllowed());
    }
}
