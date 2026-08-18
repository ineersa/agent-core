<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\ReloadArgvBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Relaunch argv reconstruction for /reload: one-shot --prompt is dropped,
 * stale --resume is replaced by the current session id, and the persistent
 * launch policy is preserved untouched.
 */
#[CoversClass(ReloadArgvBuilder::class)]
final class ReloadArgvBuilderTest extends TestCase
{
    #[Test]
    public function testKeepsScriptNameAndPersistentPolicy(): void
    {
        $argv = ['/usr/bin/php', 'bin/console', 'agent', '--model=deepseek/deepseek-v4-pro', '--reasoning=high', '--transport=process', '--tools-excluded=bash', '--cwd=/work'];

        $result = ReloadArgvBuilder::build($argv, '7');

        $this->assertSame('/usr/bin/php', $result[0]);
        $this->assertSame([
            '/usr/bin/php',
            'bin/console',
            'agent',
            '--model=deepseek/deepseek-v4-pro',
            '--reasoning=high',
            '--transport=process',
            '--tools-excluded=bash',
            '--cwd=/work',
            '--resume=7',
        ], $result);
    }

    #[Test]
    public function testDropsOneShotPromptInAllForms(): void
    {
        $this->assertSame(
            ['php', 'bin/console', 'agent', '--resume=7'],
            ReloadArgvBuilder::build(['php', 'bin/console', 'agent', '--prompt=hello'], '7'),
        );
        $this->assertSame(
            ['php', 'bin/console', 'agent', '--resume=7'],
            ReloadArgvBuilder::build(['php', 'bin/console', 'agent', '--prompt', 'hello'], '7'),
        );
    }

    #[Test]
    public function testStaleResumeIsReplaced(): void
    {
        $this->assertSame(
            ['php', 'bin/console', 'agent', '--resume=9'],
            ReloadArgvBuilder::build(['php', 'bin/console', 'agent', '--resume=3'], '9'),
        );
        $this->assertSame(
            ['php', 'bin/console', 'agent', '--resume=9'],
            ReloadArgvBuilder::build(['php', 'bin/console', 'agent', '--resume', '3'], '9'),
        );
        $this->assertSame(
            ['php', 'bin/console', 'agent', '--resume=9'],
            ReloadArgvBuilder::build(['php', 'bin/console', 'agent', '--resume=3', '--resume=4'], '9'),
        );
    }

    #[Test]
    public function testEmptySessionIdOmitsResumeFlag(): void
    {
        $this->assertSame(
            ['php', 'bin/console', 'agent'],
            ReloadArgvBuilder::build(['php', 'bin/console', 'agent', '--prompt=hi'], ''),
        );
    }
}
