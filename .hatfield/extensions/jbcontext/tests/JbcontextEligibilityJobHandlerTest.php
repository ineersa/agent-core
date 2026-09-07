<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCliDiagnostic;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextRetrySchedule;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextSessionStartHook;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\StatusFixtures;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextEligibilityJobHandlerTest extends TestCase
{
    private string $projectDir;
    private string $packageRoot;
    private ?string $previousHome = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-elig-');
        $this->packageRoot = \dirname(__DIR__);
        $home = getenv('HOME');
        $this->previousHome = false === $home ? null : $home;
    }

    protected function tearDown(): void
    {
        if (null === $this->previousHome) {
            putenv('HOME');
            unset($_ENV['HOME'], $_SERVER['HOME']);
        } else {
            putenv('HOME='.$this->previousHome);
            $_ENV['HOME'] = $this->previousHome;
            $_SERVER['HOME'] = $this->previousHome;
        }
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
        $this->claimPending('sess-1');

        $handler->handle($api, ['session_id' => 'sess-1', 'attempt' => 1, 'check_generation' => 1], 'job', 'sess-1');

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-1')->read();
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
        $this->claimPending('sess-1');

        $handler->handle($api, ['session_id' => 'sess-1', 'attempt' => 1, 'check_generation' => 1], 'job', 'sess-1');

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-1')->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertCount(1, $exec->calls());
        $this->assertSame('status', $exec->calls()[0]['args'][0]);
    }

    #[Test]
    public function eligibleRunsSilentIndexAndInstallsAssets(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $home = $this->projectDir.'/home';
        mkdir($home.'/.hatfield/agents', 0o777, true);
        file_put_contents(
            $home.'/.hatfield/agents/scout.md',
            "---\nname: scout\ndescription: recon\nthinking: medium\ntools:\n  - read\n---\n\nYou are a scout.\n",
        );
        putenv('HOME='.$home);
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
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
        $this->claimPending('sess-1');

        $handler->handle($api, ['session_id' => 'sess-1', 'attempt' => 1, 'check_generation' => 1], 'job', 'sess-1');

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-1')->read();
        $this->assertSame(JbcontextSessionModeEnum::Eligible, $state->mode);
        $this->assertSame(['status', 'index'], array_map(static fn (array $c): string => $c['args'][0], $exec->calls()));
        $this->assertContains('--silent', $exec->calls()[1]['args']);
        $this->assertFileExists($this->projectDir.'/.hatfield/skills/jbcontext-semantic-search/SKILL.md');
        $this->assertFileExists($this->projectDir.'/.hatfield/agents/scout.md');
        $this->assertStringContainsString('code_search', (string) file_get_contents($this->projectDir.'/.hatfield/agents/scout.md'));
        $this->assertStringNotContainsString('code_search', (string) file_get_contents($home.'/.hatfield/agents/scout.md'));
    }

    #[Test]
    public function transientFailureRetriesThenDisablesWithoutIndex(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $sleeps = [];
        $sleeper = static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };
        $now = 1_000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'boom', exitCode: 1),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(new TestLogger(), $this->packageRoot, $sleeper, $clock);
        $this->claimPending('sess-1', 1, 1000.0);

        $handler->handle($api, ['session_id' => 'sess-1', 'attempt' => 1, 'check_generation' => 1], 'job', 'sess-1');
        $this->assertCount(1, $api->jobs);
        $this->assertSame(2, $api->jobs[0]->payload['attempt']);
        $this->assertSame([2], $sleeps);
        $now += 2.0;

        for ($attempt = 2; $attempt <= \count(JbcontextRetrySchedule::DELAYS_SECONDS) + 1; ++$attempt) {
            $exec->push(new ExecResultDTO(stdout: '', stderr: 'boom', exitCode: 1));
            $api->jobs = [];
            $handler->handle($api, ['session_id' => 'sess-1', 'attempt' => $attempt, 'check_generation' => 1], 'job', 'sess-1');
            $delay = JbcontextRetrySchedule::sleepBeforeNextAttempt($attempt, $now - 1_000.0);
            if (null !== $delay) {
                $now += $delay;
            }
        }

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-1')->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertSame(
            'jbcontext disabled: '.JbcontextCliDiagnostic::format('boom').' Resolve the CLI error and restart Hatfield.',
            $state->reason,
        );
        foreach ($exec->calls() as $call) {
            $this->assertSame('status', $call['args'][0]);
        }
    }

    #[Test]
    public function exhaustedStatusFailureSurfacesBoundedCliStderr(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $secret = 'token=super-secret-value';
        $stderr = "Authentication required\n".$secret;
        $sleeps = [];
        $responses = [];
        for ($i = 0; $i < 5; ++$i) {
            $responses[] = new ExecResultDTO(
                stdout: '',
                stderr: $stderr,
                exitCode: 1,
            );
        }
        $exec = new RecordingExec($responses);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $now = 1_000.0;
        $handler = new JbcontextEligibilityJobHandler(
            new TestLogger(),
            $this->packageRoot,
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            static function () use (&$now): float {
                return $now;
            },
        );

        $this->claimPending('sess-auth', 1, 1000.0);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $api->jobs = [];
            $handler->handle($api, ['session_id' => 'sess-auth', 'attempt' => $attempt, 'check_generation' => 1], 'job', 'sess-auth');
            $delay = JbcontextRetrySchedule::sleepBeforeNextAttempt($attempt, $now - 1_000.0);
            if (null !== $delay) {
                $now += $delay;
            }
        }

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-auth')->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertNotSame([], $sleeps);
        $this->assertCount(5, $exec->calls());
        $this->assertNotNull($state->reason);
        $this->assertStringContainsString('JB Context error:', (string) $state->reason);
        $this->assertStringContainsString('Authentication required', (string) $state->reason);
        $this->assertStringContainsString($secret, (string) $state->reason);
        $this->assertSame(
            'jbcontext disabled: '.JbcontextCliDiagnostic::format($stderr).' Resolve the CLI error and restart Hatfield.',
            $state->reason,
        );
    }

    #[Test]
    public function budgetExhaustionDisablesWithoutSleepingPastDeadline(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'sess-budget');
        StatusFixtures::replace($store, JbcontextSessionState::pending('sess-budget', 100.0)->with(
            attempt: 1,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 100.0,
        ));

        $sleeps = [];
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'boom', exitCode: 1),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(
            new TestLogger(),
            $this->packageRoot,
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            static fn (): float => 129.0,
        );

        $handler->handle($api, ['session_id' => 'sess-budget', 'attempt' => 2, 'check_generation' => 1], 'job', 'sess-budget');

        $state = $store->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertSame([], $sleeps);
        $this->assertSame([], $api->jobs);
        $this->assertSame([], $exec->calls());
    }

    #[Test]
    public function sessionStatesRemainIsolated(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        StatusFixtures::replace(JbcontextStatusStore::forSession($paths, 'sess-a'), new JbcontextSessionState(
            sessionId: 'sess-a',
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'disabled a',
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

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
        $this->claimPending('sess-b');
        $handler->handle($api, ['session_id' => 'sess-b', 'attempt' => 1, 'check_generation' => 1], 'job', 'sess-b');

        $this->assertSame(JbcontextSessionModeEnum::Disabled, JbcontextStatusStore::forSession($paths, 'sess-a')->read()->mode);
        $this->assertSame(JbcontextSessionModeEnum::Eligible, JbcontextStatusStore::forSession($paths, 'sess-b')->read()->mode);
    }

    #[Test]
    public function staleGenerationJobDoesNotOverwriteNewerPendingClaim(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'sess-stale');
        StatusFixtures::replace($store, JbcontextSessionState::pending('sess-stale', 100.0)->with(
            attempt: 1,
            eligibilityStarted: true,
            checkGeneration: 2,
            updatedAt: 100.0,
        ));

        $exec = new RecordingExec([
            new ExecResultDTO(
                stdout: '',
                stderr: 'Authentication required',
                exitCode: 1,
            ),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler(new TestLogger(), $this->packageRoot, static function (): void {});

        $handler->handle(
            $api,
            ['session_id' => 'sess-stale', 'attempt' => 1, 'check_generation' => 1],
            'job-old',
            'sess-stale',
        );

        $state = $store->read();
        $this->assertSame(JbcontextSessionModeEnum::Pending, $state->mode);
        $this->assertSame(2, $state->checkGeneration);
        $this->assertSame([], $exec->calls());
        $this->assertSame([], $api->jobs);
    }

    #[Test]
    public function resumeAfterDisabledCanBecomeEligibleOnNewGeneration(): void
    {
        mkdir($this->projectDir.'/.idea', 0o777, true);
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $store = JbcontextStatusStore::forSession($paths, 'sess-recover');
        StatusFixtures::replace($store, new JbcontextSessionState(
            sessionId: 'sess-recover',
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'jbcontext disabled: status check failed after retries. Fix CLI auth/daemon access and restart Hatfield.',
            attempt: 5,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $hookApi = new TestExtensionApi($this->projectDir, new RecordingExec());
        $hook = new JbcontextSessionStartHook($hookApi, $paths, new TestLogger());
        $hook->onAfterSessionStart(new AfterSessionStartHookContextDTO('sess-recover'));

        $this->assertSame(JbcontextSessionModeEnum::Pending, $store->read()->mode);
        $this->assertSame(2, $store->read()->checkGeneration);

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
        $handler->handle(
            $api,
            ['session_id' => 'sess-recover', 'attempt' => 1, 'check_generation' => 2],
            'job',
            'sess-recover',
        );

        $state = $store->read();
        $this->assertSame(JbcontextSessionModeEnum::Eligible, $state->mode);
        $this->assertNull($state->reason);
        $this->assertSame(2, $state->checkGeneration);
        $this->assertSame(['status', 'index'], array_map(static fn (array $c): string => $c['args'][0], $exec->calls()));
    }

    #[Test]
    public function missingGenerationFailsClosedWithoutMutatingState(): void
    {
        $this->claimPending('sess-missing');
        $logger = new TestLogger();
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '{"type":"status_result","indices":[]}', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler($logger, $this->packageRoot, static function (): void {});

        $handler->handle($api, ['session_id' => 'sess-missing', 'attempt' => 1], 'job', 'sess-missing');

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-missing')->read();
        $this->assertSame(JbcontextSessionModeEnum::Pending, $state->mode);
        $this->assertSame(1, $state->checkGeneration);
        $this->assertSame([], $exec->calls());
        $this->assertTrue($this->loggerHas($logger, 'jbcontext.eligibility.missing_generation'));
    }

    #[Test]
    public function invalidGenerationFailsClosedWithoutMutatingState(): void
    {
        $this->claimPending('sess-invalid');
        $logger = new TestLogger();
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '{"type":"status_result","indices":[]}', stderr: '', exitCode: 0),
        ]);
        $api = new TestExtensionApi($this->projectDir, $exec);
        $handler = new JbcontextEligibilityJobHandler($logger, $this->packageRoot, static function (): void {});

        $handler->handle(
            $api,
            ['session_id' => 'sess-invalid', 'attempt' => 1, 'check_generation' => 0],
            'job',
            'sess-invalid',
        );

        $state = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), 'sess-invalid')->read();
        $this->assertSame(JbcontextSessionModeEnum::Pending, $state->mode);
        $this->assertSame(1, $state->checkGeneration);
        $this->assertSame([], $exec->calls());
        $this->assertTrue($this->loggerHas($logger, 'jbcontext.eligibility.invalid_generation'));
    }

    private function claimPending(string $sessionId, int $generation = 1, ?float $startedAt = null): void
    {
        $startedAt ??= microtime(true);
        $store = JbcontextStatusStore::forSession(JbcontextPaths::fromProjectRoot($this->projectDir), $sessionId);
        StatusFixtures::replace($store, JbcontextSessionState::pending($sessionId, $startedAt)->with(
            attempt: 1,
            eligibilityStarted: true,
            checkGeneration: $generation,
            updatedAt: $startedAt,
        ));
    }

    private function loggerHas(TestLogger $logger, string $message): bool
    {
        foreach ($logger->records as $record) {
            if ($message === $record['message']) {
                return true;
            }
        }

        return false;
    }
}
