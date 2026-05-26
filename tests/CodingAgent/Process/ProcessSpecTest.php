<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Process;

use Ineersa\CodingAgent\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class ProcessSpecTest extends TestCase
{
    public function testConstruction(): void
    {
        $spec = new ProcessSpec(
            command: ['echo', 'hello'],
            cwd: '/tmp',
            env: ['FOO' => 'bar'],
            timeoutSeconds: 30,
            createProcessGroup: false,
            commandPreview: 'echo hello',
        );

        self::assertSame(['echo', 'hello'], $spec->command);
        self::assertSame('/tmp', $spec->cwd);
        self::assertSame(['FOO' => 'bar'], $spec->env);
        self::assertSame(30, $spec->timeoutSeconds);
        self::assertFalse($spec->createProcessGroup);
        self::assertSame('echo hello', $spec->commandPreview);
    }

    public function testDefaults(): void
    {
        $spec = new ProcessSpec(command: ['ls'], cwd: '/');

        self::assertTrue($spec->createProcessGroup);
        self::assertNull($spec->timeoutSeconds);
        self::assertNull($spec->commandPreview);
        self::assertSame([], $spec->env);
    }

    public function testShellFactory(): void
    {
        $spec = ProcessSpec::shell('echo hello', '/home/project');

        self::assertSame(['bash', '-c', 'echo hello'], $spec->command);
        self::assertSame('/home/project', $spec->cwd);
        self::assertTrue($spec->createProcessGroup);
        self::assertNull($spec->timeoutSeconds);
        self::assertSame('echo hello', $spec->commandPreview);
    }

    public function testShellFactoryWithCustomPreview(): void
    {
        $spec = ProcessSpec::shell('echo hello', '/tmp', timeoutSeconds: 10, commandPreview: 'echo');

        self::assertSame(10, $spec->timeoutSeconds);
        self::assertSame('echo', $spec->commandPreview);
    }
}
