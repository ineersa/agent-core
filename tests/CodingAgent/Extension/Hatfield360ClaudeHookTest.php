<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

use function Amp\Socket\listen;

final class Hatfield360ClaudeHookTest extends TestCase
{
    private static string $pluginDirectory;

    public static function setUpBeforeClass(): void
    {
        $directory = self::$pluginDirectory = TestDirectoryIsolation::createProjectTempDir();
        self::assertSame($directory, realpath($directory));
        $plugin = ProjectDir::get().'/.claude-plugin/hatfield-360';
        $filesystem = new Filesystem();
        $filesystem->mirror($plugin.'/src', $directory.'/src');
        // Signal only after the real retry library schedules a retry for a held lock.
        file_put_contents($directory.'/retry-barrier.cjs', <<<'JS'
            const assert = require('node:assert/strict');
            const retry = require('retry');
            const operation = retry.operation;
            retry.operation = function (options) {
                const result = operation(options);
                const attempt = result.retry;
                result.retry = function (error) {
                    const scheduled = attempt.call(this, error);
                    if (scheduled && error.code === 'ELOCKED') {
                        assert.deepEqual(options, { retries: 50, factor: 1, minTimeout: 100, maxTimeout: 100 });
                        process.stderr.write('contention-retry\n');
                    }
                    return scheduled;
                };
                return result;
            };
            JS);
        foreach (['package.json', 'package-lock.json'] as $file) {
            $filesystem->copy($plugin.'/'.$file, $directory.'/'.$file);
        }
        self::assertSame(getenv('HATFIELD_QA_TEST_HOME'), getenv('HOME'));
        self::assertNotEmpty(getenv('HATFIELD_QA_TEST_HOME'));
        $setup = new Process(['npm', 'ci', '--omit=optional', '--ignore-scripts', '--no-audit', '--no-fund'], $directory, ['HATFIELD_SESSION_ID' => false, 'npm_config_cache' => $directory.'/npm-cache'], timeout: 10);
        self::assertSame(0, $setup->run(), $setup->getErrorOutput());
    }

    public static function tearDownAfterClass(): void
    {
        TestDirectoryIsolation::removeDirectory(self::$pluginDirectory);
    }

    public function testClaudeDisplayedSessionNameReachesTheSocket(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir();
        $socketDirectory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        try {
            $filesystem = new Filesystem();
            $config = $directory.'/config';
            $project = $config.'/projects/'.preg_replace('/[^a-zA-Z0-9]/', '-', $directory);
            $filesystem->mkdir($project, 0o700);
            $this->assertSame($project, realpath($project));
            $session = '22222222-2222-4222-8222-222222222222';
            $transcript = $project.'/'.$session.'.jsonl';
            $this->assertFalse(is_link($transcript));
            $write = static function (array $row) use ($transcript, $session): void {
                file_put_contents($transcript, json_encode($row + ['sessionId' => $session], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
            };
            $write(['type' => 'user', 'cwd' => $directory, 'message' => ['role' => 'user', 'content' => "Fix the   failing\n build"]]);
            $endpoint = $socketDirectory.'/s';
            $server = listen('unix://'.$endpoint);
            $environment = [
                'CLAUDE_CONFIG_DIR' => $config,
                'H360_STATUS_ENDPOINT' => $endpoint,
                'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
                'H360_STATUS_TOKEN' => str_repeat('a', 64),
            ];
            $base = ['session_id' => $session, 'cwd' => $socketDirectory];
            $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $base + $hook));
            // Untitled, Claude's /resume shows the latest prompt, on one line.
            $this->assertSame('Fix the failing build', $send(['hook_event_name' => 'SessionStart', 'source' => 'startup'])['title']);
            $write(['type' => 'ai-title', 'aiTitle' => 'Generated title']);
            $prompt = ['hook_event_name' => 'UserPromptSubmit'];
            $this->assertSame('Generated title', $send($prompt + ['prompt_id' => '55555555-5555-4555-8555-555555555555'])['title']);
            $write(['type' => 'custom-title', 'customTitle' => 'Renamed title']);
            $this->assertSame('Renamed title', $send($prompt + ['prompt_id' => '55555555-5555-4555-8555-666666666666'])['title']);
            $this->assertSame('Hook title', $send($prompt + ['prompt_id' => '55555555-5555-4555-8555-777777777777', 'session_title' => 'Hook title'])['title']);
        } finally {
            if (isset($server)) {
                $server->close();
            }
            TestDirectoryIsolation::removeDirectory($socketDirectory);
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testSeparateCliInvocationsShareOnlyLaunchCorrelation(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $endpoint = $directory.'/s';
        $server = listen('unix://'.$endpoint);
        $environment = [
            'H360_STATUS_ENDPOINT' => $endpoint,
            'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
            'H360_STATUS_TOKEN' => str_repeat('a', 64),
        ];
        try {
            // This runs the standalone entry point in a fresh process for each
            // event, with the same autoload path a Composer-installed binary gets.
            $startup = $this->launch($environment, ['session_id' => 'claude-1', 'hook_event_name' => 'SessionStart', 'source' => 'startup', 'session_title' => 'Initial title']);
            $first = $this->exchange($server, $startup);
            $switch = '44444444-4444-4444-8444-111111111111';
            $nextSwitch = '44444444-4444-4444-8444-222222222222';
            $outgoing = ['session_id' => 'claude-1', 'hook_event_name' => 'SessionEnd', 'reason' => 'resume', 'prompt_id' => $switch];
            $incoming = ['session_id' => 'claude-2', 'hook_event_name' => 'SessionStart', 'source' => 'resume', 'prompt_id' => $switch];
            $this->assertQuietHook($environment, $incoming);
            $this->assertQuietHook($environment, array_replace($outgoing, ['session_id' => 'other']));
            $ended = $this->exchange($server, $this->launch($environment, $outgoing));
            $this->assertSame('session.ended', $ended['kind']);
            $this->assertSame($first['sessionEpoch'], $ended['sessionEpoch']);
            $this->assertQuietHook($environment, array_replace($incoming, ['prompt_id' => $nextSwitch]));
            $this->assertQuietHook($environment, array_replace($incoming, ['source' => 'clear']));
            $second = $this->exchange($server, $this->launch($environment, $incoming));
            $this->assertSame('session.snapshot', $first['kind']);
            $this->assertSame('claude', $first['provider']);
            $this->assertSame('Initial title', $first['title']);
            $this->assertNull($second['title']);
            $this->assertSame('claude-2', $second['sessionId']);
            $this->assertGreaterThan($first['sequence'], $second['sequence']);
            $this->assertSame($second['sequence'], $second['sessionEpoch']);

            $prompt = '44444444-4444-4444-8444-555555555555';
            $start = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'UserPromptSubmit', 'prompt_id' => $prompt, 'prompt' => 'private prompt', 'session_title' => 'Renamed title']);
            $turn = $this->exchange($server, $start);
            $this->assertSame('session.snapshot', $turn['kind']);
            $this->assertArrayNotHasKey('prompt', $turn);
            $this->assertSame('Renamed title', $turn['title']);
            $this->assertSame($second['sessionEpoch'], $turn['sessionEpoch']);
            $failure = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'StopFailure', 'prompt_id' => $prompt, 'error' => 'server_error']);
            $end = $this->exchange($server, $failure);
            $this->assertSame(['id' => $prompt, 'status' => 'failed'], $end['turn']);
            $this->assertSame('Renamed title', $end['title']);

            $this->exchange($server, $this->launch($environment, array_replace($outgoing, ['session_id' => 'claude-2', 'prompt_id' => $nextSwitch])));
            $returned = $this->exchange($server, $this->launch($environment, array_replace($incoming, ['session_id' => 'claude-1', 'prompt_id' => $nextSwitch])));
            $this->assertSame('claude-1', $returned['sessionId']);
            $this->assertGreaterThan($second['sessionEpoch'], $returned['sessionEpoch']);
            $this->assertQuietHook($environment, $outgoing);
            $this->assertQuietHook($environment, $incoming);
            $clear = array_replace($outgoing, ['reason' => 'clear', 'prompt_id' => '44444444-4444-4444-8444-333333333333']);
            $this->exchange($server, $this->launch($environment, $clear));
            $cleared = $this->exchange($server, $this->launch($environment, ['session_id' => 'claude-3', 'hook_event_name' => 'SessionStart', 'source' => 'clear']));
            $this->assertGreaterThan($returned['sessionEpoch'], $cleared['sessionEpoch']);
            $this->assertQuietHook($environment, ['session_id' => 'late', 'hook_event_name' => 'SessionStart', 'source' => 'clear']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testClaudeRegistryDoesNotInventSettledChildren(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $environment = ['H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $hook + ['session_id' => 'registry-session']));
        $child = ['agent_id' => 'observed-child'];
        $unknownChild = ['agent_id' => 'unseen-child'];
        $registry = ['background_tasks' => [['id' => 'unrelated-task-id', 'type' => 'subagent', 'status' => 'running']]];
        $empty = ['background_tasks' => []];
        $start = ['hook_event_name' => 'UserPromptSubmit'];
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false];
        $childStop = ['hook_event_name' => 'SubagentStop'];
        try {
            $send(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $first = ['prompt_id' => '55555555-5555-4555-8555-555555555551'];
            $send($first + $start);
            $completed = $send($first + $stop + $registry);
            $this->assertNull($completed['activeSubagents']);
            // An old empty sample may arrive after this positive main Stop.
            // The same prompt ID does not establish registry sampling order.
            $this->assertQuietHook($environment, $first + $childStop + $unknownChild + $empty + ['session_id' => 'registry-session']);

            $boundary = ['prompt_id' => '55555555-5555-4555-8555-555555555552'];
            $send($boundary + ['hook_event_name' => 'SessionEnd', 'reason' => 'resume']);
            $resumed = $send($boundary + ['hook_event_name' => 'SessionStart', 'source' => 'resume']);
            $this->assertNull($resumed['activeSubagents']);
            $second = ['prompt_id' => '55555555-5555-4555-8555-555555555553'];
            $send($second + $start);
            $this->assertNull($send($second + $stop)['activeSubagents']);
            $teammateTurn = ['prompt_id' => '55555555-5555-4555-8555-555555555557'];
            $send($teammateTurn + $start);
            $teammateStop = $send($teammateTurn + $stop + ['background_tasks' => [['id' => 'teammate-task', 'type' => 'teammate', 'status' => 'running']]]);
            $this->assertSame('completed', $teammateStop['turn']['status']);
            $this->assertNull($teammateStop['activeSubagents'], 'A resumed registry containing only a teammate cannot establish zero.');
            $third = ['prompt_id' => '55555555-5555-4555-8555-555555555554'];
            $send($third + $start);
            $this->assertNull($send($third + $stop + ['background_tasks' => [['type' => 'subagent']]])['activeSubagents']);
            $fourth = ['prompt_id' => '55555555-5555-4555-8555-555555555555'];
            $send($fourth + $start);
            $this->assertSame(0, $send($fourth + $stop + $empty)['activeSubagents']);

            $fifth = ['prompt_id' => '55555555-5555-4555-8555-555555555556'];
            $working = $send($fifth + $start);
            $send($child + ['hook_event_name' => 'SubagentStart']);
            $this->assertQuietHook($environment, $childStop + $unknownChild + $registry + ['session_id' => 'registry-session']);
            $this->assertQuietHook($environment, $childStop + $unknownChild + $empty + ['session_id' => 'registry-session']);
            $settling = $send($childStop + $child + $registry);
            $this->assertNull($settling['activeSubagents']);
            $this->assertSame($working['turn'], $settling['turn']);
            $final = $send($fifth + $stop + $empty);
            $this->assertSame(0, $final['activeSubagents']);
            $this->assertSame($working['turn']['id'], $final['turn']['id']);
            $this->assertSame('completed', $final['turn']['status']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testBackgroundChildFinishingAfterTheMainStopKeepsWorkingUntilClaudeHandlesIt(): void
    {
        // Hook order recorded from Claude Code 2.1.331 with a background subagent: the main
        // response stops first, then the child stops, then Claude answers its result.
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $environment = ['H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $hook + ['session_id' => 'background-session']));
        $child = ['agent_id' => 'background-child', 'agent_type' => 'general-purpose'];
        $running = ['background_tasks' => [['id' => 'background-task', 'type' => 'subagent', 'status' => 'running']]];
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false];
        try {
            $send(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $launch = ['prompt_id' => '55555555-5555-4555-8555-555555555561'];
            $send($launch + ['hook_event_name' => 'UserPromptSubmit']);
            $send($launch + $child + ['hook_event_name' => 'SubagentStart']);
            $mainStop = $send($launch + $stop + $running);
            $this->assertSame('completed', $mainStop['turn']['status']);
            $this->assertSame(1, $mainStop['activeSubagents']);

            $childStop = $send($launch + $child + ['hook_event_name' => 'SubagentStop', 'stop_hook_active' => false] + $running);
            $this->assertSame(1, $childStop['activeSubagents'], 'Claude has yet to handle the result, so the work is not settled or unknown.');

            $handle = ['prompt_id' => '55555555-5555-4555-8555-555555555562'];
            $handling = $send($handle + ['hook_event_name' => 'UserPromptSubmit']);
            $this->assertSame('working', $handling['turn']['status']);
            $this->assertNull($handling['activeSubagents'], 'The earlier registry listed a running child; only the next Stop sample can establish zero.');
            $final = $send($handle + $stop + ['background_tasks' => []]);
            $this->assertSame('completed', $final['turn']['status']);
            $this->assertSame(0, $final['activeSubagents']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testCompletedTurnReportsTheTruncatedFinalMessageUntilTheNextPrompt(): void
    {
        // Stop payload shape recorded from Claude Code 2.1.331.
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $environment = ['H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $hook + ['session_id' => 'response-session']));
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []];
        $prompt = static fn (int $i): array => ['prompt_id' => \sprintf('55555555-5555-4555-8555-%012d', $i)];
        try {
            $send(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $send($prompt(1) + ['hook_event_name' => 'UserPromptSubmit']);
            $done = $send($prompt(1) + $stop + ['last_assistant_message' => "Reviewed 3 files.\nAll checks pass."]);
            $this->assertSame("Reviewed 3 files.\nAll checks pass.", $done['turn']['response']);
            $settled = $send(['agent_id' => 'late-child', 'hook_event_name' => 'SubagentStart']);
            $this->assertSame($done['turn'], $settled['turn'], 'Settling snapshots resend the response.');

            $working = $send($prompt(2) + ['hook_event_name' => 'UserPromptSubmit']);
            $this->assertArrayNotHasKey('response', $working['turn']);
            $long = $send($prompt(2) + $stop + ['last_assistant_message' => 'x'.str_repeat('🐕', 999).'end']);
            $this->assertSame('…'.str_repeat('🐕', 996).'end', $long['turn']['response']);

            foreach ([3 => ['last_assistant_message' => " \n"], 4 => []] as $i => $message) {
                $send($prompt($i) + ['hook_event_name' => 'UserPromptSubmit']);
                $this->assertArrayNotHasKey('response', $send($prompt($i) + $stop + $message)['turn']);
            }
            $send($prompt(5) + ['hook_event_name' => 'UserPromptSubmit']);
            $failed = $send($prompt(5) + ['hook_event_name' => 'StopFailure', 'error' => 'rate_limit', 'last_assistant_message' => 'Partial.']);
            $this->assertSame(['id' => $prompt(5)['prompt_id'], 'status' => 'failed'], $failed['turn']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testCronJobsAreReportedAndTheirRunsAreScheduledTurns(): void
    {
        // Payload shapes recorded from Claude Code 2.1.331 `/loop` hooks.
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $environment = ['H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $frames = [];
        $send = function (array $hook) use ($server, $environment, &$frames): array {
            return $frames[] = $this->exchange($server, $this->launch($environment, $hook + ['session_id' => 'cron-session']));
        };
        $now = (int) (microtime(true) * 1000);
        $turn = static fn (int $n): array => ['prompt_id' => \sprintf('66666666-6666-4666-8666-%012d', $n)];
        $prompt = static fn (int $n, string $text): array => $turn($n) + ['hook_event_name' => 'UserPromptSubmit', 'prompt' => $text];
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []];
        $tool = static fn (int $n, string $name, array $input, array $response): array => $turn($n) + ['hook_event_name' => 'PostToolUse', 'tool_name' => $name, 'tool_use_id' => 'toolu_'.$name, 'tool_input' => $input, 'tool_response' => $response];
        try {
            $send(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $typed = $send($prompt(1, '/loop 7m say ping'));
            $this->assertArrayNotHasKey('trigger', $typed['turn']);
            $this->assertArrayNotHasKey('schedules', $typed, 'Sessions without schedules keep the original snapshot shape.');
            $created = $send($tool(1, 'CronCreate', ['cron' => '*/7 * * * *', 'prompt' => 'say ping', 'recurring' => true], ['id' => '26923628', 'humanSchedule' => 'Every 7 minutes', 'recurring' => true, 'durable' => false, 'nextFireAt' => $now + 30_000.3875, 'maxAgeMs' => 2_592_000_000]));
            $this->assertSame([['id' => '26923628', 'label' => 'Every 7 minutes', 'recurring' => true, 'nextRunAt' => $now + 30_000]], $created['schedules']);
            $send($tool(1, 'CronCreate', ['cron' => '13 4 1 1 *', 'prompt' => 'say once', 'recurring' => false], ['id' => 'a61706d4', 'humanSchedule' => '13 4 1 1 *', 'recurring' => false, 'durable' => false, 'nextFireAt' => $now + 3_600_000]));
            $idle = $send($turn(1) + $stop);
            $this->assertSame(['26923628', 'a61706d4'], array_column($idle['schedules'], 'id'));

            $run = $send($prompt(2, 'say ping'));
            $this->assertSame('schedule', $run['turn']['trigger']);
            $this->assertGreaterThan($now + 30_000, $run['schedules'][0]['nextRunAt'], 'A recurring job moves on to its next cron match.');
            $this->assertSame('schedule', $send($turn(2) + $stop)['turn']['trigger']);

            $early = $send($prompt(3, 'say once'));
            $this->assertArrayNotHasKey('trigger', $early['turn'], 'A matching prompt typed long before the run is the user\'s own.');
            $this->assertCount(2, $early['schedules']);
            $send($turn(3) + $stop);

            $send($prompt(4, 'list them'));
            $listed = $send($tool(4, 'CronList', [], ['jobs' => [['id' => 'a61706d4', 'cron' => '13 4 1 1 *', 'humanSchedule' => '13 4 1 1 *', 'prompt' => 'say once', 'durable' => false, 'nextFireAt' => $now + 10_000]]]));
            $this->assertSame([['id' => 'a61706d4', 'label' => '13 4 1 1 *', 'recurring' => false, 'nextRunAt' => $now + 10_000]], $listed['schedules'], 'The listing replaces the whole inventory.');
            $send($turn(4) + $stop);

            $once = $send($prompt(5, 'say once'));
            $this->assertSame('schedule', $once['turn']['trigger']);
            $this->assertArrayNotHasKey('schedules', $once, 'A one-shot job is gone once it runs.');
            $send($turn(5) + $stop);

            $send($prompt(6, '/loop 5m check'));
            $send($tool(6, 'CronCreate', ['cron' => '*/5 * * * *', 'prompt' => 'check', 'recurring' => true], ['id' => 'b0000001', 'humanSchedule' => 'Every 5 minutes', 'recurring' => true, 'durable' => false, 'nextFireAt' => $now + 300_000, 'maxAgeMs' => 2_592_000_000]));
            $deleted = $send($tool(6, 'CronDelete', ['id' => 'b0000001'], ['id' => 'b0000001']));
            $this->assertArrayNotHasKey('schedules', $deleted);

            foreach ($frames as $frame) {
                $json = json_encode($frame, \JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('say ping', $json);
                $this->assertStringNotContainsString('*/7', $json);
            }
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testSelfPacedLoopWakeupsAreScheduledTurnsUntilTheLoopStops(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $environment = ['H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $hook + ['session_id' => 'wakeup-session']));
        $now = (int) (microtime(true) * 1000);
        $loop = '/loop reply with only the word ping, then wait about a minute before the next check';
        $turn = static fn (int $n): array => ['prompt_id' => \sprintf('77777777-7777-4777-8777-%012d', $n)];
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []];
        $wakeup = static fn (int $n, int $at): array => $turn($n) + ['hook_event_name' => 'PostToolUse', 'tool_name' => 'ScheduleWakeup', 'tool_use_id' => 'toolu_wake', 'tool_input' => ['delaySeconds' => 60, 'reason' => 'User asked for about a minute between checks.', 'prompt' => $loop, 'noop' => false], 'tool_response' => ['scheduledFor' => $at, 'clampedDelaySeconds' => 60]];
        $expected = static fn (?int $at): array => [['id' => 'wakeup', 'label' => 'Self-paced loop', 'recurring' => true, 'nextRunAt' => $at]];
        try {
            $send(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $this->assertArrayNotHasKey('trigger', $send($turn(1) + ['hook_event_name' => 'UserPromptSubmit', 'prompt' => $loop])['turn']);
            $this->assertSame($expected($now + 20_000), $send($wakeup(1, $now + 20_000))['schedules']);
            $this->assertSame($expected($now + 20_000), $send($turn(1) + $stop)['schedules']);

            $woken = $send($turn(2) + ['hook_event_name' => 'UserPromptSubmit', 'prompt' => $loop]);
            $this->assertSame('schedule', $woken['turn']['trigger']);
            $this->assertSame($expected(null), $woken['schedules'], 'The loop stays visible while its run decides on the next wakeup.');
            $send($wakeup(2, $now + 80_000));
            $this->assertSame($expected($now + 80_000), $send($turn(2) + $stop)['schedules']);

            $last = $send($turn(3) + ['hook_event_name' => 'UserPromptSubmit', 'prompt' => $loop]);
            $this->assertSame('schedule', $last['turn']['trigger']);
            $ended = $send($turn(3) + $stop);
            $this->assertSame('completed', $ended['turn']['status']);
            $this->assertArrayNotHasKey('schedules', $ended, 'A run that schedules no further wakeup ends the loop.');
            $this->assertArrayNotHasKey('trigger', $send($turn(4) + ['hook_event_name' => 'UserPromptSubmit', 'prompt' => $loop])['turn']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testDelayedChildRegistryCannotClearNewerUncertaintyOrPublishStaleScripts(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $environment = ['H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $session = ['session_id' => 'ordered-registry'];
        $first = ['prompt_id' => '55555555-5555-4555-8555-555555555551'];
        $second = ['prompt_id' => '55555555-5555-4555-8555-555555555552'];
        $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $hook + $session));
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false];
        $shell = ['id' => 'shell', 'type' => 'shell', 'status' => 'running'];
        $unseen = ['id' => 'not-an-agent-id', 'type' => 'subagent', 'status' => 'running'];
        // This empty sample was captured before either newer authoritative Stop.
        $oldEmpty = $first + ['hook_event_name' => 'SubagentStop', 'agent_id' => 'untracked', 'background_tasks' => []];
        try {
            $send(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $send($first + ['hook_event_name' => 'UserPromptSubmit']);
            $send(['hook_event_name' => 'SubagentStart', 'agent_id' => 'tracked']);
            $completed = $send($first + $stop + ['background_tasks' => [$unseen, $shell]]);
            $this->assertSame(1, $completed['activeSubagents']);
            $this->assertSame(1, $completed['backgroundScripts']);
            $this->assertQuietHook($environment, $oldEmpty + $session);
            $settled = $send($first + ['hook_event_name' => 'SubagentStop', 'agent_id' => 'tracked', 'background_tasks' => []]);
            $this->assertSame(1, $settled['activeSubagents'], 'Claude has yet to handle the stopped child\'s result.');
            $this->assertNull($settled['backgroundScripts'], 'Unordered child samples cannot replace a script count with zero.');
            $this->assertSame($completed['turn'], $settled['turn']);

            $working = $send($second + ['hook_event_name' => 'UserPromptSubmit']);
            $this->assertNull($working['backgroundScripts']);
            $newer = $send($second + $stop + ['background_tasks' => [$unseen, $shell]]);
            $this->assertNull($newer['activeSubagents']);
            $this->assertQuietHook($environment, $oldEmpty + $session);
            $state = json_decode(file_get_contents($directory.'/node-claude-status-'.$environment['H360_STATUS_LAUNCH_ID'].'.json'), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertTrue($state['agentsUncertain']);
            $this->assertSame($newer['sequence'], $state['sequence']);
            $this->assertSame(1, $state['scripts'], 'Ignored hooks do not mutate newer accepted registry fields.');

            $third = ['prompt_id' => '55555555-5555-4555-8555-555555555553'];
            $send($third + ['hook_event_name' => 'UserPromptSubmit']);
            $send(['hook_event_name' => 'SubagentStart', 'agent_id' => 'tracked-next']);
            $final = $send($third + $stop + ['background_tasks' => [$shell]]);
            $this->assertSame(1, $final['activeSubagents'], 'A valid main registry cannot erase known child membership.');
            $this->assertSame(1, $final['backgroundScripts']);
            $settled = $send(['hook_event_name' => 'SubagentStop', 'agent_id' => 'tracked-next', 'background_tasks' => [$shell, ['id' => 'other-shell', 'type' => 'shell', 'status' => 'running']]]);
            $this->assertSame(1, $settled['activeSubagents'], 'Claude has yet to handle the stopped child\'s result.');
            $this->assertNull($settled['backgroundScripts'], 'A correlated child stop still lacks freshness for exact script counts.');
            $this->assertSame($final['turn'], $settled['turn'], 'Script invalidation neither gates nor creates a response completion.');
            $this->assertQuietHook($environment, $third + $stop + ['background_tasks' => []] + $session);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testLaterSnapshotReplacesLostObservations(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $endpoint = $directory.'/s';
        $environment = [
            'H360_STATUS_ENDPOINT' => $endpoint,
            'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
            'H360_STATUS_TOKEN' => str_repeat('a', 64),
        ];
        $selection = ['session_id' => 'claude-1', 'hook_event_name' => 'SessionStart', 'source' => 'startup'];
        $prompt = ['session_id' => 'claude-1', 'hook_event_name' => 'UserPromptSubmit', 'prompt_id' => '44444444-4444-4444-8444-555555555555'];
        try {
            // The first observations fail open while no socket is listening.
            $selectionProcess = $this->launch($environment, $selection);
            $this->assertSame(0, $selectionProcess->wait(), $selectionProcess->getErrorOutput());
            $promptProcess = $this->launch($environment, $prompt);
            $this->assertSame(0, $promptProcess->wait(), $promptProcess->getErrorOutput());
            $server = listen('unix://'.$endpoint);
            $agent = ['session_id' => 'claude-1', 'agent_id' => 'child-1'];
            $first = $this->exchange($server, $this->launch($environment, $agent + ['hook_event_name' => 'SubagentStart']), reply: null);
            $this->assertSame('session.snapshot', $first['kind']);
            $this->assertSame(1, $first['sessionEpoch']);
            $this->assertSame(3, $first['sequence']);
            $this->assertSame('working', $first['turn']['status']);
            $this->assertSame(1, $first['activeSubagents']);
            $latest = $this->exchange($server, $this->launch($environment, $agent + ['hook_event_name' => 'SubagentStop']));
            $this->assertNotSame($first['eventId'], $latest['eventId']);
            $this->assertSame($first['turn'], $latest['turn']);
            $this->assertSame(0, $latest['activeSubagents']);
        } finally {
            if (isset($server)) {
                $server->close();
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testCorrelatesForegroundActivityWaitAndStopWithoutLeakingContent(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $endpoint = $directory.'/s';
        $server = listen('unix://'.$endpoint);
        $environment = [
            'H360_STATUS_ENDPOINT' => $endpoint,
            'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
            'H360_STATUS_TOKEN' => str_repeat('a', 64),
        ];
        $session = ['session_id' => 'claude-1'];
        $prompt = $session + ['prompt_id' => '44444444-4444-4444-8444-555555555555'];
        $tool = ['hook_event_name' => 'PreToolUse', 'tool_name' => 'mcp__private__tool', 'tool_use_id' => 'tool-1', 'tool_input' => ['private' => true]];
        $wait = ['mcp_server_name' => 'mcp-private', 'elicitation_id' => 'request-1'];
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'last_assistant_message' => 'Done.', 'background_tasks' => []];
        try {
            $start = $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']));
            $this->assertNull($start['backgroundScripts']);
            $turn = $this->exchange($server, $this->launch($environment, $prompt + ['hook_event_name' => 'UserPromptSubmit']));
            $this->assertSame('working', $turn['turn']['status']);
            $this->assertQuietHook($environment, $prompt + $tool + ['agent_id' => 'child']);
            $this->assertQuietHook($environment, $prompt + ['hook_event_name' => 'Elicitation', 'mcp_server_name' => 'mcp-private']);
            $this->assertQuietHook($environment, $prompt + $wait + ['hook_event_name' => 'ElicitationResult', 'action' => 'accept']);
            $builtin = $prompt + ['tool_name' => 'Bash', 'tool_use_id' => 'tool-2'];
            $working = $this->exchange($server, $this->launch($environment, $builtin + ['hook_event_name' => 'PreToolUse', 'tool_input' => ['private' => true]]));
            $this->assertSame('Bash', $working['tool']);
            $this->assertArrayNotHasKey('tool_input', $working);
            $recovery = $this->exchange($server, $this->launch($environment, $builtin + ['hook_event_name' => 'PostToolUseFailure', 'error' => 'private']));
            $this->assertNull($recovery['tool']);
            $this->assertSame($turn['turn'], $recovery['turn']);
            $request = $this->exchange($server, $this->launch($environment, $prompt + $wait + ['hook_event_name' => 'Elicitation', 'message' => 'private']));
            $this->assertSame('external-input', $request['attention']['reason']);
            $this->assertSame($recovery['sequence'] + 1, $request['sequence']);
            $this->assertArrayNotHasKey('message', $request);
            $this->assertQuietHook($environment, $prompt + ['mcp_server_name' => 'other'] + $wait + ['hook_event_name' => 'ElicitationResult', 'action' => 'accept']);
            $answer = $this->exchange($server, $this->launch($environment, $prompt + $wait + ['hook_event_name' => 'ElicitationResult', 'action' => 'accept', 'content' => ['secret' => true]]));
            $this->assertNull($answer['attention']);
            $this->assertSame($turn['turn'], $answer['turn']);
            $this->assertArrayNotHasKey('content', $answer);
            $this->assertQuietHook($environment, $prompt + $wait + ['hook_event_name' => 'Elicitation', 'message' => 'private']);
            $agent = $session + ['agent_id' => 'child-1'];
            $child = $this->exchange($server, $this->launch($environment, $agent + ['hook_event_name' => 'SubagentStart']));
            $this->assertSame(1, $child['activeSubagents']);
            $this->assertQuietHook($environment, $agent + ['hook_event_name' => 'SubagentStart']);
            $end = $this->exchange($server, $this->launch($environment, $prompt + $stop));
            $this->assertSame('completed', $end['turn']['status']);
            $this->assertSame('Done.', $end['turn']['response'], 'Only the final message is reported, and only in the turn.');
            $this->assertSame($child['sequence'] + 1, $end['sequence']);
            $this->assertArrayNotHasKey('last_assistant_message', $end);
            $this->assertSame(1, $end['activeSubagents']);
            // After the main Stop, a stopped child stays counted until Claude handles its result; a later start is still observed.
            $attempt = $this->exchange($server, $this->launch($environment, $agent + ['hook_event_name' => 'SubagentStop', 'stop_hook_active' => false, 'background_tasks' => []]));
            $this->assertSame(1, $attempt['activeSubagents']);
            $this->assertSame($end['turn'], $attempt['turn']);
            $continued = $this->exchange($server, $this->launch($environment, $agent + ['hook_event_name' => 'SubagentStart']));
            $this->assertSame(1, $continued['activeSubagents']);
            $this->assertSame($end['turn'], $continued['turn']);
            $this->assertQuietHook($environment, $prompt + $stop);
            $this->assertQuietHook($environment, $prompt + $tool);

            $this->assertQuietHook($environment, '{broken-private', 'hook_reporting_unavailable');
            $state = $directory.'/node-claude-status-'.$environment['H360_STATUS_LAUNCH_ID'].'.json';
            $this->assertSame($directory, realpath(\dirname($state)));
            $this->assertFalse(is_link($state));
            file_put_contents($state, '{broken-private');
            $this->assertQuietHook($environment, $prompt + $stop, 'hook_reporting_unavailable');
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testClaudeCorrelatesToolWaitsAndCountsOnlyObservedShells(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $endpoint = $directory.'/s';
        $server = listen('unix://'.$endpoint);
        $environment = ['H360_STATUS_ENDPOINT' => $endpoint, 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $session = ['session_id' => 'claude-1'];
        $prompt = $session + ['prompt_id' => '44444444-4444-4444-8444-555555555555'];
        $tool = $prompt + ['tool_name' => 'Bash', 'tool_input' => ['command' => 'private command']];
        $pre = $tool + ['hook_event_name' => 'PreToolUse'];
        $permission = $tool + ['hook_event_name' => 'PermissionRequest'];
        $post = $tool + ['hook_event_name' => 'PostToolUseFailure'];
        try {
            $send = fn (array $hook): array => $this->exchange($server, $this->launch($environment, $hook));
            $send($session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $send($prompt + ['hook_event_name' => 'UserPromptSubmit']);
            $this->assertQuietHook($environment, $permission);
            $this->assertNull($send($pre + ['tool_use_id' => 'one'])['attention']);
            $send($pre + ['tool_use_id' => 'two']);
            $this->assertQuietHook($environment, $permission);
            $send($post + ['tool_use_id' => 'two']);
            $this->assertQuietHook($environment, array_replace($permission, ['prompt_id' => '44444444-4444-4444-8444-666666666666']));
            $this->assertQuietHook($environment, array_replace($permission, ['tool_input' => ['command' => 'different']]));
            $waiting = $send($permission);
            $this->assertSame('permission', $waiting['attention']['reason']);
            $this->assertArrayNotHasKey('tool_input', $waiting);
            $this->assertQuietHook($environment, $permission);
            $question = $prompt + ['tool_name' => 'AskUserQuestion', 'tool_use_id' => 'question'];
            $this->assertSame($waiting['attention'], $send($question + ['hook_event_name' => 'PreToolUse'])['attention']);
            $this->assertSame('question', $send($post + ['tool_use_id' => 'one'])['attention']['reason']);
            $this->assertNull($send($question + ['hook_event_name' => 'PostToolUse'])['attention']);
            $send($question + ['hook_event_name' => 'PreToolUse']);
            $stop = $prompt + ['hook_event_name' => 'Stop', 'stop_hook_active' => false];
            $done = $send($stop + ['background_tasks' => [
                ['type' => 'shell', 'status' => 'running', 'id' => 'shell-id', 'command' => 'private command'],
                ['type' => 'subagent', 'status' => 'running', 'id' => 'agent-id'],
            ]]);
            $this->assertSame(1, $done['backgroundScripts']);
            $this->assertNull($done['attention']);
            $this->assertArrayNotHasKey('background_tasks', $done);
            $this->assertQuietHook($environment, $stop + ['background_tasks' => []]);
            $next = array_replace($prompt, ['prompt_id' => '44444444-4444-4444-8444-666666666666']);
            $send($next + ['hook_event_name' => 'UserPromptSubmit']);
            $this->assertQuietHook($environment, $stop + ['background_tasks' => []]);
            $this->assertSame(0, $send($next + ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []])['backgroundScripts']);
            $last = array_replace($prompt, ['prompt_id' => '44444444-4444-4444-8444-777777777777']);
            $send($last + ['hook_event_name' => 'UserPromptSubmit']);
            $this->assertNull($send($last + ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => [['type' => 'shell']]])['backgroundScripts']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testManagedClaudeRequiresRealOwnerAndReusesRegistrationIdentity(): void
    {
        $finder = new ExecutableFinder();
        $script = $finder->find('script');
        $nativeShell = $finder->find('dash');
        if ('Linux' !== \PHP_OS_FAMILY || null === $script || null === $nativeShell || !is_executable('/bin/bash') || !is_dir('/dev/pts')) {
            $this->markTestSkipped('Requires a native Linux shell and a PTY.');
        }
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $this->assertSame($directory, realpath($directory));
        $this->assertTrue(copy($nativeShell, $directory.'/claude'));
        $this->assertTrue(chmod($directory.'/claude', 0o700));
        $server = listen('unix://'.$directory.'/s');
        $env = [
            'H360_STATUS_ENDPOINT' => $directory.'/s', 'H360_STATUS_BOOTSTRAP_TOKEN' => str_repeat('b', 64),
            'H360_STATUS_TOKEN' => false, 'H360_STATUS_LAUNCH_ID' => false, 'HATFIELD_SESSION_ID' => false,
            'PATH' => $directory.':'.getenv('PATH'), 'SHELL' => '/bin/bash',
        ];
        $hook = ['session_id' => 'native-session', 'hook_event_name' => 'SessionStart', 'source' => 'startup'];
        $turn = ['session_id' => 'native-session', 'hook_event_name' => 'UserPromptSubmit', 'prompt_id' => '44444444-4444-4444-8444-555555555555'];
        $reporter = ['node', $this->entry()];
        $commands = implode(' ', array_map(escapeshellarg(...), $reporter));
        $call = static fn (array $payload): string => 'printf %s '.escapeshellarg(json_encode($payload, \JSON_THROW_ON_ERROR)).' | '.$commands;
        $owner = escapeshellarg($directory.'/claude');
        $shell = static fn (string $body): array => [$script, '-qec', $owner.' -c '.escapeshellarg($body.'; :'), '/dev/null'];
        try {
            // The same real native ancestor survives both hook subprocesses.
            $process = new Process($shell($call($hook).'; '.$call($turn)), env: $env, timeout: 5);
            $process->start();
            $first = $this->exchange($server, $process, reply: '{"ok":false,"error":"unauthorized"}', finish: false);
            $this->assertSame('launch.register', $first['kind']);
            $this->assertSame('claude', $first['provider']);
            $this->assertGreaterThan(0, $first['owner']['pid']);
            $reply = json_encode(['ok' => true, 'launchId' => '44444444-4444-4444-8444-444444444444', 'token' => str_repeat('a', 64)], \JSON_THROW_ON_ERROR);
            $this->assertSame($first, $this->exchange($server, $process, reply: $reply, finish: false));
            $snapshot = $this->exchange($server, $process);
            $this->assertSame('session.snapshot', $snapshot['kind']);
            $this->assertSame('working', $snapshot['turn']['status']);
            $this->assertSame(2, $snapshot['sequence']);
            $this->assertSame(1, $snapshot['sessionEpoch']);

            // A first report from B must not wait for A’s socket acknowledgement.
            $barrier = listen('unix://'.$directory.'/b');
            $pause = escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg('$s = stream_socket_client("unix://".$argv[1]); exit(fread($s, 1) === "!" ? 0 : 1);').' '.escapeshellarg($directory.'/b');
            $stop = array_replace($turn, ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []]);
            $agent = array_replace($turn, ['hook_event_name' => 'SubagentStart', 'agent_id' => 'agent-1']);
            $ownerA = new Process($shell(implode('; ', [$call($hook), $call($turn), $pause, $call($agent), $pause, $call($stop)])), env: $env, timeout: 5);
            $ownerB = new Process($shell(implode('; ', [$call($hook), $call($turn), $pause, $call($stop)])), env: $env, timeout: 5);
            try {
                $ready = function (Process $owner) use ($server, $reply, $barrier): array {
                    $owner->start();
                    $registration = $this->exchange($server, $owner, reply: $reply, finish: false);
                    $this->exchange($server, $owner, finish: false);
                    $this->assertSame($registration, $this->exchange($server, $owner, reply: $reply, finish: false));
                    $this->assertSame(0, $this->exchange($server, $owner, finish: false)['activeSubagents']);
                    $signal = $barrier->accept(new TimeoutCancellation(2));
                    $this->assertNotNull($signal);

                    return [$registration, $signal];
                };
                [$registrationA, $signalA] = $ready($ownerA);
                // One prior owner and A are already admitted; leave one slot for B.
                for ($i = 0; $i < 61; ++$i) {
                    $fixture = \sprintf('%s/node-claude-managed-00000000-0000-4000-8000-%012x.json', $directory, $i);
                    $this->assertFalse(is_link($fixture));
                    $this->assertNotFalse(file_put_contents($fixture, '{}'));
                }
                $signalA->write('!');
                $signalA->close();
                $this->assertSame($registrationA, $this->exchange($server, $ownerA, reply: $reply, finish: false));
                $agentReport = $this->exchange($server, $ownerA, finish: false, beforeReply: function () use ($ownerB, $server, $reply, &$registrationB): void {
                    $ownerB->start();
                    $registrationB = $this->exchange($server, $ownerB, reply: $reply, finish: false);
                });
                $this->assertNotSame($registrationA['owner']['pid'], $registrationB['owner']['pid']);
                $this->assertSame(1, $agentReport['activeSubagents']);
                $signalA = $barrier->accept(new TimeoutCancellation(2));
                $this->assertNotNull($signalA);
                $this->assertSame(1, $this->exchange($server, $ownerB, finish: false)['sequence']);
                $this->assertSame($registrationB, $this->exchange($server, $ownerB, reply: $reply, finish: false));
                $this->assertSame(0, $this->exchange($server, $ownerB, finish: false)['activeSubagents']);
                $signalB = $barrier->accept(new TimeoutCancellation(2));
                $this->assertNotNull($signalB);
                $this->assertCount(64, glob($directory.'/node-claude-managed-*.json'));
                $overflow = new Process($shell($call($hook)), env: $env, timeout: 5);
                $this->assertSame(0, $overflow->run());
                $this->assertCount(64, glob($directory.'/node-claude-managed-*.json'));
                foreach ([[$ownerA, $signalA, $registrationA, 1], [$ownerB, $signalB, $registrationB, 0]] as [$native, $signal, $registration, $count]) {
                    $signal->write('!');
                    $signal->close();
                    $this->assertSame($registration, $this->exchange($server, $native, reply: $reply, finish: false));
                    $completed = $this->exchange($server, $native, finish: false);
                    $this->assertSame('completed', $completed['turn']['status']);
                    $this->assertSame($count, $completed['activeSubagents']);
                    $this->assertSame(0, $native->wait());
                }
                $this->assertStringNotContainsString('hook_lock_unavailable', $ownerB->getOutput());
            } finally {
                if (isset($signalA)) {
                    $signalA->close();
                }
                if (isset($signalB)) {
                    $signalB->close();
                }
                $barrier->close();
                foreach ([$ownerA, $ownerB] as $native) {
                    if ($native->isRunning()) {
                        $native->wait();
                    }
                }
            }

            foreach ([$env, array_replace($env, ['H360_STATUS_TOKEN' => 'malformed'])] as $environment) {
                // In the second case the owner is valid, but explicit auth forbids
                // bootstrap. The first case has no native owner in its ancestry.
                $command = $environment === $env ? $reporter : $shell($call($hook));
                $denied = new Process($command, env: $environment, input: $environment === $env ? json_encode($hook, \JSON_THROW_ON_ERROR) : null, timeout: 5);
                $this->assertSame(0, $denied->run());
            }
            // A queued connection would be visible even though the CLI fails open.
            try {
                $this->assertNull($server->accept(new TimeoutCancellation(0.05)));
            } catch (CancelledException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->wait();
            }
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testClaudeNotificationUsesTheLiveHookSelectionAndSequence(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $endpoint = $directory.'/s';
        $server = listen('unix://'.$endpoint);
        $environment = ['H360_STATUS_ENDPOINT' => $endpoint, 'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444', 'H360_STATUS_TOKEN' => str_repeat('a', 64)];
        $session = ['session_id' => 'claude-1'];
        $input = ['title' => 'Review', 'body' => 'Available'];
        try {
            $this->assertSame(1, $this->launch($environment, $input, notify: true)->wait());
            $first = $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']));
            $this->assertSame(2, $this->launch($environment, ['title' => str_repeat('é', 201), 'body' => 'Available'], notify: true)->wait());
            $notice = $this->exchange($server, $this->launch($environment, $input, notify: true));
            $this->assertSame('notification', $notice['kind']);
            $this->assertSame($first['sessionId'], $notice['sessionId']);
            $this->assertSame($first['sessionEpoch'], $notice['sessionEpoch']);
            $this->assertSame($first['sequence'] + 1, $notice['sequence']);
            $this->assertSame($input, array_intersect_key($notice, $input));
            $end = $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionEnd', 'reason' => 'logout']));
            $this->assertSame($notice['sequence'] + 1, $end['sequence']);
            $this->assertSame(1, $this->launch($environment, $input, notify: true)->wait());
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testStopCannotSettleWhileAnEnteredStartHoldsItsPendingLock(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $launch = '44444444-4444-4444-8444-444444444444';
        $environment = [
            'H360_STATUS_ENDPOINT' => $directory.'/s',
            'H360_STATUS_LAUNCH_ID' => $launch,
            'H360_STATUS_TOKEN' => str_repeat('a', 64),
        ];
        $session = ['session_id' => 'claude-1'];
        $prompt = $session + ['prompt_id' => '44444444-4444-4444-8444-111111111111'];
        try {
            $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']));
            $this->exchange($server, $this->launch($environment, $prompt + ['hook_event_name' => 'UserPromptSubmit']));
            $release = $this->holdLock($directory.'/node-claude-status-'.$launch.'.json.pending');
            $start = $prompt + ['hook_event_name' => 'SubagentStart', 'agent_id' => 'agent-1'];
            $stop = $this->exchange(
                $server,
                $this->launch($environment, $prompt + ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []]),
                beforeReply: function () use (&$waiting, $environment, $start): void {
                    $waiting = $this->launch(['NODE_OPTIONS' => $this->retryProbe()] + $environment, $start);
                    $this->awaitLockRetry($waiting);
                },
            );
            $this->assertSame('completed', $stop['turn']['status']);
            $this->assertNull($stop['activeSubagents']);
            $release();
            unset($release);
            $agent = $this->exchange($server, $waiting);
            $this->assertSame($stop['sequence'] + 1, $agent['sequence']);
            $this->assertSame(1, $agent['activeSubagents']);
        } finally {
            if (isset($release)) {
                $release();
            }
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function retryProbe(): string
    {
        return '--require='.self::$pluginDirectory.'/retry-barrier.cjs';
    }

    private function awaitLockRetry(Process $process): void
    {
        $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'contention-retry')));
        $this->assertTrue($process->isRunning());
    }

    private function holdLock(string $path): \Closure
    {
        $code = <<<'JS'
            const { lockSync } = require('proper-lockfile');
            const release = lockSync(process.argv[1], {realpath: false});
            process.stdin.on('end', release).resume();
            process.stdout.write('locked\n');
            JS;
        $input = new InputStream();
        $process = new Process(['node', '-e', $code, $path], self::$pluginDirectory, ['HATFIELD_SESSION_ID' => false, 'NODE_OPTIONS' => false], $input, 5);
        $process->start();
        try {
            $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => Process::OUT === $type && str_contains($output, "locked\n")));
        } catch (\Throwable $exception) {
            $input->close();
            $process->wait();

            throw $exception;
        }

        return function () use ($input, $process): void {
            $input->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        };
    }

    /** @param array<string, string> $environment @param array<string, mixed>|string $input */
    private function assertQuietHook(array $environment, array|string $input, ?string $diagnostic = null): void
    {
        $process = $this->launch($environment, $input);
        $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertSame(null === $diagnostic ? '' : json_encode(['component' => 'hatfield-360', 'event_type' => $diagnostic], \JSON_THROW_ON_ERROR)."\n", $process->getErrorOutput());
    }

    /** @param array<string, string> $environment @param array<string, mixed>|string $input */
    private function launch(array $environment, array|string $input, bool $notify = false): Process
    {
        $command = ['node', $this->entry()];
        if ($notify) {
            $command[] = '--notify';
        }
        $process = new Process($command, env: $environment + ['HATFIELD_SESSION_ID' => false, 'NODE_OPTIONS' => false], input: \is_string($input) ? $input : json_encode($input, \JSON_THROW_ON_ERROR), timeout: 9);
        $process->start();

        return $process;
    }

    private function entry(): string
    {
        return self::$pluginDirectory.'/src/hook.mjs';
    }

    /** @return array<array-key, mixed> */
    private function exchange(\Amp\Socket\ServerSocket $server, Process $process, ?string $reply = '{"ok":true,"status":"accepted"}', bool $finish = true, ?\Closure $beforeReply = null): array
    {
        try {
            $deadline = new TimeoutCancellation(2);
            $socket = $server->accept($deadline);
            $this->assertNotNull($socket);
            try {
                $line = '';
                while (!str_contains($line, "\n")) {
                    $chunk = $socket->read($deadline);
                    $this->assertNotNull($chunk);
                    $line .= $chunk;
                }
                $frame = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                $this->assertIsArray($frame);
                $beforeReply?->__invoke();
                if (null !== $reply) {
                    $socket->write($reply."\n");
                }
            } finally {
                $socket->close();
            }

            if ($finish) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $this->assertSame('', $process->getOutput());
            }

            return $frame;
        } catch (CancelledException $exception) {
            $this->fail('Reporter did not send a frame: '.$process->getErrorOutput().' '.$process->getOutput().' ('.$exception::class.')');
        } finally {
            if ($finish && $process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
