<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextRetrySchedule;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextEligibilityJobHandlerTest extends TestCase
{
    private string $projectDir;
    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-elig-');
        $this->packageRoot = \dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function disablesWithoutIdeaAndNeverIndexes(): void
    {
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '{"type":"status_result","indices":[]}', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(new TestLogger(), $this->packageRoot, static function (): void {});

        $handler->handle($api, ['attempt' => 1], 'job', 'corr');

        $state = (new JbcontextStatusStore(JbcontextPaths::fromProjectRoot($this->projectDir)->statusPath))->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function disablesWhenNoIndexSnapshotWithoutCallingIndex(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $exec = new RecordingExec([
            new ExecResultDTO(
                stdout: json_encode([
                    'type' => 'status_result',
                    'indices' => [],
                    'message' => 'No indices found',
                ], \JSON_THROW_ON_ERROR),
                stderr: '',
                exitCode: 0,
            ),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(new TestLogger(), $this->packageRoot, static function (): void {});

        $handler->handle($api, ['attempt' => 1], 'job', 'corr');

        $state = (new JbcontextStatusStore(JbcontextPaths::fromProjectRoot($this->projectDir)->statusPath))->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertCount(1, $exec->calls());
        $this->assertSame('status', $exec->calls()[0]['args'][0]);
    }

    #[Test]
    public function eligibleRunsSilentIndexAndInstallsAssets(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $status = json_encode([
            'type' => 'status_result',
            'indices' => [
                [
                    'indexAlias' => ['name' => 'CodeBlocks'],
                    'snapshots' => [['revision' => 'abc', 'branches' => ['main']]],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: $status, stderr: '', exitCode: 0),
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(new TestLogger(), $this->packageRoot, static function (): void {});

        $handler->handle($api, ['attempt' => 1], 'job', 'corr');

        $state = (new JbcontextStatusStore(JbcontextPaths::fromProjectRoot($this->projectDir)->statusPath))->read();
        $this->assertSame(JbcontextSessionModeEnum::Eligible, $state->mode);
        $this->assertSame(['status', 'index'], array_map(static fn (array $c): string => $c['args'][0], $exec->calls()));
        $this->assertContains('--silent', $exec->calls()[1]['args']);
        $this->assertFileExists($this->projectDir.'/.hatfield/skills/jbcontext-semantic-search/SKILL.md');
        $this->assertFileExists($this->projectDir.'/.hatfield/agents/scout.md');
    }

    #[Test]
    public function transientFailureRetriesThenDisablesWithoutIndex(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $sleeps = [];
        $sleeper = static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'boom', exitCode: 1),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(new TestLogger(), $this->packageRoot, $sleeper);

        $handler->handle($api, ['attempt' => 1], 'job', 'corr');
        $this->assertCount(1, $api->jobs);
        $this->assertSame(2, $api->jobs[0]->payload['attempt']);
        $this->assertSame([2], $sleeps);

        // Exhaust remaining attempts without sleeping real time.
        for ($attempt = 2; $attempt <= JbcontextRetrySchedule::maxAttempts(); ++$attempt) {
            $exec->push(new ExecResultDTO(stdout: '', stderr: 'boom', exitCode: 1));
            $api->jobs = [];
            $handler->handle($api, ['attempt' => $attempt], 'job', 'corr');
        }

        $state = (new JbcontextStatusStore(JbcontextPaths::fromProjectRoot($this->projectDir)->statusPath))->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        foreach ($exec->calls() as $call) {
            $this->assertSame('status', $call['args'][0]);
        }
    }
}
