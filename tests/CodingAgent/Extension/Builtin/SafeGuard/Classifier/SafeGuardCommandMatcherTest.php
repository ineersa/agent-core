<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardCommandMatcher;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ── Hard block ──

    #[DataProvider('hardBlockProvider')]
    public function testHardBlockedCommands(string $command, ?string $expectedReason = null): void
    {
        $result = $this->matcher->classify($command);
        self::assertSame(SafeGuardDecisionKind::HardBlock, $result->kind);
        if (null !== $expectedReason) {
            self::assertSame($expectedReason, $result->reason);
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function hardBlockProvider(): iterable
    {
        yield 'sudo' => ['sudo rm -rf /', 'sudo commands are not allowed'];
        yield 'sudo in subcommand' => ['bash -c "sudo something"', null];
    }

    // ── Destructive commands ──

    #[DataProvider('destructiveProvider')]
    public function testDestructiveCommands(string $command): void
    {
        $result = $this->matcher->classify($command);
        self::assertSame(SafeGuardDecisionKind::Destructive, $result->kind);
        self::assertSame('Destructive command', $result->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function destructiveProvider(): iterable
    {
        $cases = [
            'rm' => 'rm file.txt',
            'rmdir' => 'rmdir some/dir',
            'git clean' => 'git clean -fd',
            'git reset --hard' => 'git reset --hard HEAD~1',
            'git checkout .' => 'git checkout -- .',
            'mkfs' => 'mkfs.ext4 /dev/sdb',
            'dd' => 'dd if=/dev/zero of=/dev/sdb',
            'chmod 777' => 'chmod 777 file',
            'chown -R' => 'chown -R user:group /',
            'mv to /dev/null' => 'mv file.txt /dev/null',
        ];
        foreach ($cases as $name => $cmd) {
            yield $name => [$cmd];
        }
    }

    public function testChmodWithoutDigitsIsAllowed(): void
    {
        $result = $this->matcher->classify('chmod +x file');
        self::assertSame(SafeGuardDecisionKind::Allow, $result->kind);
    }

    // ── Dangerous git commands ──

    #[DataProvider('dangerousGitProvider')]
    public function testDangerousGitCommands(string $command): void
    {
        $result = $this->matcher->classify($command);
        self::assertSame(SafeGuardDecisionKind::DangerousGit, $result->kind);
        self::assertSame('Dangerous git operation', $result->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousGitProvider(): iterable
    {
        $cases = [
            'push --force' => 'git push --force origin main',
            'push -f' => 'git push -f',
            'branch -d' => 'git branch -d old-branch',
            'branch -D' => 'git branch -D old-branch',
            'tag -d' => 'git tag -d v1.0',
            'rebase' => 'git rebase main',
            'reflog expire' => 'git reflog expire --all',
        ];
        foreach ($cases as $name => $cmd) {
            yield $name => [$cmd];
        }
    }

    // ── Sensitive info ──

    #[DataProvider('sensitiveProvider')]
    public function testSensitiveCommands(string $command): void
    {
        $result = $this->matcher->classify($command);
        self::assertSame(SafeGuardDecisionKind::SensitiveInfo, $result->kind);
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveProvider(): iterable
    {
        yield 'env' => ['env'];
        yield 'printenv' => ['printenv'];
        yield 'env pipe' => ['env | grep SECRET'];
        yield 'printenv pipe' => ['printenv | sort'];
    }

    // ── Custom dangerous ──

    #[DataProvider('customDangerousProvider')]
    public function testCustomDangerousPatterns(string $command, array $patterns): void
    {
        $result = $this->matcher->classify(command: $command, dangerousCommandPatterns: $patterns);
        self::assertSame(SafeGuardDecisionKind::CustomDangerous, $result->kind);
        self::assertSame('Matched custom dangerous pattern', $result->reason);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function customDangerousProvider(): iterable
    {
        yield 'substring match' => ['some-risky-command --option', ['risky']];
        yield 'case insensitive' => ['RISKY-COMMAND', ['risky']];
        yield 'whitespace collapse' => ['risky    command', ['risky command']];
    }

    // ── Allowlist ──

    #[DataProvider('allowlistProvider')]
    public function testAllowlist(array $allowed, string $command, bool $expected): void
    {
        self::assertSame($expected, $this->matcher->isCommandAllowed($allowed, $command));
    }

    /** @return iterable<string, array{list<string>, string, bool}> */
    public static function allowlistProvider(): iterable
    {
        yield 'bypass destructive' => [['rm -rf'], 'rm -rf /tmp/safe', true];
        yield 'substring match' => [['rm'], 'rm -rf /tmp/safe', true];
        yield 'unrelated' => [['ls'], 'rm -rf /tmp', false];
        yield 'case insensitive' => [['RM'], 'rm file', true];
    }

    // ── Safe commands ──

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
            self::assertSame(
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
            self::assertSame(
                SafeGuardDecisionKind::Allow,
                $result->kind,
                \sprintf('Git command "%s" should be allowed', $cmd),
            );
        }
    }
}
