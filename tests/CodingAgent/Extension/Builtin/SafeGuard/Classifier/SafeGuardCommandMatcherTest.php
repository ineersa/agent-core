<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardCommandMatcher;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardCommandMatcher — faithful port of Pi's classify.ts.
 */
final class SafeGuardCommandMatcherTest extends TestCase
{
    private SafeGuardCommandMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SafeGuardCommandMatcher();
    }

    // ── Hard block: sudo ──

    public function testSudoIsHardBlocked(): void
    {
        $result = $this->matcher->classify('sudo rm -rf /');

        $this->assertSame(SafeGuardDecisionKind::HardBlock, $result->kind);
        $this->assertSame('sudo commands are not allowed', $result->reason);
    }

    public function testSudoInSubcommandIsHardBlocked(): void
    {
        $result = $this->matcher->classify('bash -c "sudo something"');

        $this->assertSame(SafeGuardDecisionKind::HardBlock, $result->kind);
    }

    // ── Destructive commands ──

    public function testRmIsDestructive(): void
    {
        $result = $this->matcher->classify('rm file.txt');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
        $this->assertSame('Destructive command', $result->reason);
    }

    public function testRmdirIsDestructive(): void
    {
        $result = $this->matcher->classify('rmdir some/dir');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testGitCleanIsDestructive(): void
    {
        $result = $this->matcher->classify('git clean -fd');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testGitResetHardIsDestructive(): void
    {
        $result = $this->matcher->classify('git reset --hard HEAD~1');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testGitCheckoutDotIsDestructive(): void
    {
        $result = $this->matcher->classify('git checkout -- .');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testMkfsIsDestructive(): void
    {
        $result = $this->matcher->classify('mkfs.ext4 /dev/sdb');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testDdIsDestructive(): void
    {
        $result = $this->matcher->classify('dd if=/dev/zero of=/dev/sdb');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testChmodIsDestructive(): void
    {
        $result = $this->matcher->classify('chmod 777 file');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testChmodRecursiveNotDetected(): void
    {
        // chmod without mode digits not matched
        $result = $this->matcher->classify('chmod +x file');

        $this->assertSame(SafeGuardDecisionKind::Allow, $result->kind);
    }

    public function testChownRecursiveIsDestructive(): void
    {
        $result = $this->matcher->classify('chown -R user:group /');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    public function testMvToDevNullIsDestructive(): void
    {
        $result = $this->matcher->classify('mv file.txt /dev/null');

        $this->assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
    }

    // ── Dangerous git commands ──

    public function testGitPushForceIsDangerous(): void
    {
        $result = $this->matcher->classify('git push --force origin main');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
        $this->assertSame('Dangerous git operation', $result->reason);
    }

    public function testGitPushShortFlagIsDangerous(): void
    {
        $result = $this->matcher->classify('git push -f');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
    }

    public function testGitBranchDeleteIsDangerous(): void
    {
        $result = $this->matcher->classify('git branch -d old-branch');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
    }

    public function testGitBranchDeleteForceIsDangerous(): void
    {
        $result = $this->matcher->classify('git branch -D old-branch');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
    }

    public function testGitTagDeleteIsDangerous(): void
    {
        $result = $this->matcher->classify('git tag -d v1.0');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
    }

    public function testGitRebaseIsDangerous(): void
    {
        $result = $this->matcher->classify('git rebase main');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
    }

    public function testGitReflogExpireIsDangerous(): void
    {
        $result = $this->matcher->classify('git reflog expire --all');

        $this->assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
    }

    // ── Sensitive info ──

    public function testEnvIsSensitive(): void
    {
        $result = $this->matcher->classify('env');

        $this->assertSame(SafeGuardDecisionKind::SensitiveInfo, $result->kind);
        $this->assertSame('Exposes environment variables', $result->reason);
    }

    public function testPrintenvIsSensitive(): void
    {
        $result = $this->matcher->classify('printenv');

        $this->assertSame(SafeGuardDecisionKind::SensitiveInfo, $result->kind);
    }

    public function testEnvPipeIsSensitive(): void
    {
        $result = $this->matcher->classify('env | grep SECRET');

        $this->assertSame(SafeGuardDecisionKind::SensitiveInfo, $result->kind);
    }

    public function testPrintenvPipeIsSensitive(): void
    {
        $result = $this->matcher->classify('printenv | sort');

        $this->assertSame(SafeGuardDecisionKind::SensitiveInfo, $result->kind);
    }

    // ── Custom dangerous patterns ──

    public function testCustomDangerousMatchesSubstring(): void
    {
        $result = $this->matcher->classify(
            command: 'some-risky-command --option',
            dangerousCommandPatterns: ['risky'],
        );

        $this->assertSame(SafeGuardDecisionKind::CustomDangerous, $result->kind);
        $this->assertSame('Matched custom dangerous pattern', $result->reason);
    }

    public function testCustomDangerousIsCaseInsensitive(): void
    {
        $result = $this->matcher->classify(
            command: 'RISKY-COMMAND',
            dangerousCommandPatterns: ['risky'],
        );

        $this->assertSame(SafeGuardDecisionKind::CustomDangerous, $result->kind);
    }

    public function testCustomDangerousCollapsesWhitespace(): void
    {
        $result = $this->matcher->classify(
            command: 'risky    command',
            dangerousCommandPatterns: ['risky command'],
        );

        $this->assertSame(SafeGuardDecisionKind::CustomDangerous, $result->kind);
    }

    // ── Allowlist (command allow bypass) ──

    public function testAllowlistBypassesDestructive(): void
    {
        $this->assertTrue(
            $this->matcher->isCommandAllowed(['rm -rf'], 'rm -rf /tmp/safe'),
        );
    }

    public function testAllowlistIsSubstringMatch(): void
    {
        $this->assertTrue(
            $this->matcher->isCommandAllowed(['rm'], 'rm -rf /tmp/safe'),
        );
    }

    public function testAllowlistDoesNotMatchUnrelated(): void
    {
        $this->assertFalse(
            $this->matcher->isCommandAllowed(['ls'], 'rm -rf /tmp'),
        );
    }

    public function testAllowlistIsCaseInsensitive(): void
    {
        $this->assertTrue(
            $this->matcher->isCommandAllowed(['RM'], 'rm file'),
        );
    }

    // ── Safe / allowed commands ──

    public function testSafeCommandsAreAllowed(): void
    {
        $safeCommands = [
            'ls',
            'cat README.md',
            'git status',
            'git log',
            'echo hello',
            'mkdir newdir',
            'cp file1 file2',
            'mv file1 file2',
            'grep pattern file',
            'find . -name "*.php"',
            'php -v',
            'composer install',
        ];

        foreach ($safeCommands as $cmd) {
            $result = $this->matcher->classify($cmd);
            $this->assertSame(
                SafeGuardDecisionKind::Allow,
                $result->kind,
                \sprintf('Command "%s" should be allowed', $cmd),
            );
        }
    }

    public function testRegularGitCommandsAreSafe(): void
    {
        $safeGitCommands = [
            'git status',
            'git log',
            'git diff',
            'git add .',
            'git commit -m "msg"',
            'git checkout branch',
            'git pull',
            'git fetch',
            'git merge branch',
            'git stash',
        ];

        foreach ($safeGitCommands as $cmd) {
            $result = $this->matcher->classify($cmd);
            $this->assertSame(
                SafeGuardDecisionKind::Allow,
                $result->kind,
                \sprintf('Git command "%s" should be allowed', $cmd),
            );
        }
    }
}
