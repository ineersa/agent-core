<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCliErrorClassifier;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextCliTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-cli-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function statusMapsAuthenticationRequiredStderrToAuthCodeWithoutEchoingSecret(): void
    {
        $secret = 'Bearer leaked-token-value';
        $exec = new RecordingExec([
            new ExecResultDTO(
                stdout: '',
                stderr: "Authentication required\n".$secret,
                exitCode: 1,
            ),
        ]);
        $cli = new JbcontextCli($exec, $this->projectDir);

        $result = $cli->status();

        $this->assertFalse($result['ok']);
        $this->assertSame(JbcontextCliErrorClassifier::AUTH_REQUIRED, $result['error']);
        $this->assertNull($result['payload']);
        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('Authentication required', $encoded);
    }

    #[Test]
    public function statusKeepsUnknownEmptyStdoutFallback(): void
    {
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'daemon unavailable', exitCode: 1),
        ]);
        $cli = new JbcontextCli($exec, $this->projectDir);

        $result = $cli->status();

        $this->assertFalse($result['ok']);
        $this->assertSame(JbcontextCliErrorClassifier::EMPTY_STDOUT, $result['error']);
    }
}
