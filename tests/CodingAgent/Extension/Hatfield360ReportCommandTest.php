<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function Amp\Socket\listen;

final class Hatfield360ReportCommandTest extends TestCase
{
    public function testSdkTitleBridgeNeverFallsBackToThePrompt(): void
    {
        $packaged = getenv('H360_TEST_PACKAGED_PLUGIN');
        $plugin = $packaged ?: ProjectDir::get().'/.hatfield/extensions/hatfield-360';
        $node = (new ExecutableFinder())->find('node');
        if (null === $node || !is_file($plugin.'/node_modules/@anthropic-ai/claude-agent-sdk/core.mjs')) {
            $this->assertFalse($packaged, 'Required packaged SDK dependencies are missing.');
            $this->markTestSkipped('Install the standalone Claude plugin Node dependencies to exercise the real SDK.');
        }

        $directory = TestDirectoryIsolation::createProjectTempDir();
        try {
            $cwd = realpath($directory);
            $this->assertSame($directory, $cwd);
            $projects = $cwd.'/projects/'.preg_replace('/[^a-zA-Z0-9]/', '-', $cwd);
            $this->assertTrue(mkdir($projects, 0o700, true));
            $id = '22222222-2222-4222-8222-222222222222';
            $transcript = $projects.'/'.$id.'.jsonl';
            $this->assertFalse(is_link($transcript));
            $this->assertSame($projects, realpath(\dirname($transcript)));
            $prompt = json_encode(['type' => 'user', 'sessionId' => $id, 'cwd' => $cwd, 'message' => ['role' => 'user', 'content' => 'Never export this prompt']], \JSON_THROW_ON_ERROR)."\n";
            file_put_contents($transcript, $prompt);
            $command = [$node, $plugin.'/bin/session-title.mjs', $id, $cwd];
            $environment = ['CLAUDE_CONFIG_DIR' => $cwd, 'NODE_OPTIONS' => false, 'HATFIELD_SESSION_ID' => false];
            $process = new Process($command, $cwd, $environment, timeout: 2);
            $run = static function (string $label) use ($process, $packaged): int {
                $start = hrtime(true);
                $exit = $process->run();
                if ($packaged) {
                    fwrite(\STDERR, \sprintf("SDK lookup %s: %.1f ms\n", $label, (hrtime(true) - $start) / 1e6));
                }

                return $exit;
            };
            $this->assertSame(1, $run('no title'));
            $this->assertSame('', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());

            file_put_contents($transcript, json_encode(['type' => 'ai-title', 'sessionId' => $id, 'aiTitle' => 'Generated title'], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
            $this->assertSame(0, $run('generated'));
            $this->assertSame('"Generated title"', $process->getOutput());
            file_put_contents($transcript, json_encode(['type' => 'custom-title', 'sessionId' => $id, 'customTitle' => 'Renamed title'], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
            $this->assertSame(0, $run('renamed'));
            $this->assertSame('"Renamed title"', $process->getOutput());

            $socketDirectory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
            try {
                $endpoint = $socketDirectory.'/s';
                $server = listen('unix://'.$endpoint);
                $hookEnvironment = $environment + [
                    'H360_STATUS_ENDPOINT' => $endpoint,
                    'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
                    'H360_STATUS_TOKEN' => str_repeat('a', 64),
                ];
                $startup = ['session_id' => $id, 'cwd' => $cwd, 'hook_event_name' => 'SessionStart', 'source' => 'startup'];
                $selected = $this->exchange($server, $this->launch($hookEnvironment, $startup + ['session_title' => 'Hook title'], disableProcOpen: true));
                $this->assertSame('Hook title', $selected['title']);
                $hook = $this->launch($hookEnvironment, [
                    'session_id' => $id, 'cwd' => $cwd, 'hook_event_name' => 'UserPromptSubmit',
                    'prompt_id' => '55555555-5555-4555-8555-555555555555',
                ], disableProcOpen: true);
                $working = $this->exchange($server, $hook);
                $this->assertNull($working['title']);
                $this->assertSame($selected['sessionEpoch'], $working['sessionEpoch']);
                $this->assertSame('working', $working['turn']['status']);
                $this->assertSame('', $hook->getOutput());
                $this->assertSame(['component' => 'hatfield_360', 'event_type' => 'hook_title_unavailable'], json_decode($hook->getErrorOutput(), true, flags: \JSON_THROW_ON_ERROR));
            } finally {
                if (isset($server)) {
                    $server->close();
                }
                TestDirectoryIsolation::removeDirectory($socketDirectory);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testMissingAutoloaderReportsOnlyAnActionableHookDiagnostic(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        try {
            $autoload = $directory.'/absent-autoload.php';
            $hook = $this->launch([], 'private hook input', autoload: $autoload);
            $this->assertSame(0, $hook->wait());
            $this->assertSame('', $hook->getOutput());
            $this->assertSame([
                'component' => 'hatfield_360',
                'event_type' => 'hook_dependencies_missing',
                'action' => 'Ensure the active Claude plugin has its Composer runtime dependencies, then reload the plugin or restart Claude Code.',
            ], json_decode($hook->getErrorOutput(), true, flags: \JSON_THROW_ON_ERROR));

            $explicit = $this->launch([], '', hook: false, autoload: $autoload);
            $this->assertSame(1, $explicit->wait());
        } finally {
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

            // Explicit mode is a separate producer with its own launch and epoch.
            $explicitEnvironment = array_replace($environment, [
                'H360_STATUS_LAUNCH_ID' => '55555555-5555-4555-8555-555555555555',
                'H360_STATUS_TOKEN' => str_repeat('b', 64),
            ]);
            $registration = $this->launch($explicitEnvironment, [
                'kind' => 'session.snapshot', 'sessionId' => 'explicit-test', 'sessionEpoch' => 1, 'sequence' => 1,
                'provider' => 'hatfield', 'turn' => null, 'attention' => null, 'activeSubagents' => null, 'backgroundScripts' => null, 'tool' => null,
            ], false);
            $selected = $this->exchange($server, $registration);
            $this->assertSame('session.snapshot', $selected['kind']);
            $this->assertNotSame($first['launchId'], $selected['launchId']);

            $notification = $this->launch($explicitEnvironment, [
                'kind' => 'notification', 'sessionId' => 'explicit-test', 'sessionEpoch' => 1, 'sequence' => 2,
                'title' => 'Ready', 'body' => 'Extra message',
            ], false);
            $frame = $this->exchange($server, $notification);
            $this->assertSame('notification', $frame['kind']);
            $this->assertSame($selected['launchId'], $frame['launchId']);
            $this->assertSame($selected['sessionId'], $frame['sessionId']);
            $this->assertSame($selected['sessionEpoch'], $frame['sessionEpoch']);
            $this->assertGreaterThan($selected['sequence'], $frame['sequence']);
            $this->assertSame('Extra message', $frame['body']);
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
            $settled = $send($childStop + $unknownChild + $empty);
            $this->assertSame(0, $settled['activeSubagents']);
            $this->assertSame($completed['turn'], $settled['turn']);

            $boundary = ['prompt_id' => '55555555-5555-4555-8555-555555555552'];
            $send($boundary + ['hook_event_name' => 'SessionEnd', 'reason' => 'resume']);
            $resumed = $send($boundary + ['hook_event_name' => 'SessionStart', 'source' => 'resume']);
            $this->assertNull($resumed['activeSubagents']);
            $second = ['prompt_id' => '55555555-5555-4555-8555-555555555553'];
            $send($second + $start);
            $this->assertNull($send($second + $stop)['activeSubagents']);
            $third = ['prompt_id' => '55555555-5555-4555-8555-555555555554'];
            $send($third + $start);
            $this->assertNull($send($third + $stop + ['background_tasks' => [['type' => 'subagent']]])['activeSubagents']);
            $fourth = ['prompt_id' => '55555555-5555-4555-8555-555555555555'];
            $send($fourth + $start);
            $this->assertSame(0, $send($fourth + $stop + $empty)['activeSubagents']);

            $fifth = ['prompt_id' => '55555555-5555-4555-8555-555555555556'];
            $working = $send($fifth + $start);
            $send($child + ['hook_event_name' => 'SubagentStart']);
            $settling = $send($childStop + $unknownChild + $registry);
            $this->assertSame(1, $settling['activeSubagents']);
            $settling = $send($childStop + $unknownChild + $empty);
            $this->assertSame(1, $settling['activeSubagents']);
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
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'last_assistant_message' => 'private', 'background_tasks' => []];
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
            $this->assertSame($child['sequence'] + 1, $end['sequence']);
            $this->assertArrayNotHasKey('last_assistant_message', $end);
            $this->assertSame(1, $end['activeSubagents']);
            $settled = $this->exchange($server, $this->launch($environment, $agent + ['hook_event_name' => 'SubagentStop']));
            $this->assertSame(0, $settled['activeSubagents']);
            $this->assertSame($end['turn'], $settled['turn']);
            $this->assertQuietHook($environment, $prompt + $stop);
            $this->assertQuietHook($environment, $prompt + $tool);

            $this->assertQuietHook($environment, '{broken-private', 'hook_input_failed');
            $state = $directory.'/claude-status-'.$environment['H360_STATUS_LAUNCH_ID'].'.json';
            $this->assertSame($directory, realpath(\dirname($state)));
            $this->assertFalse(is_link($state));
            file_put_contents($state, '{broken-private');
            $this->assertQuietHook($environment, $prompt + $stop, 'hook_state_failed');
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
        $root = ProjectDir::get();
        $reporter = [\PHP_BINARY, '-r', '$_composer_autoload_path = $argv[1]; array_splice($argv, 0, 2); $_SERVER["argv"] = $argv; require $argv[0];', '--',
            $root.'/vendor/autoload.php', $root.'/.hatfield/extensions/hatfield-360/bin/h360-report', '--claude-hook'];
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

            // Two distinct native PTY owners share the state admission lock.
            $barrier = listen('unix://'.$directory.'/b');
            $pause = escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg('$s = stream_socket_client("unix://".$argv[1]); exit(fread($s, 1) === "!" ? 0 : 1);').' '.escapeshellarg($directory.'/b');
            $stop = array_replace($turn, ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []]);
            $agent = array_replace($turn, ['hook_event_name' => 'SubagentStart', 'agent_id' => 'agent-1']);
            $ownerA = new Process($shell(implode('; ', [$call($hook), $call($turn), $pause, $call($agent), $pause, $call($stop)])), env: $env, timeout: 5);
            $ownerB = new Process($shell(implode('; ', [$call($hook), $call($turn), $pause, $call($stop)])), env: $env, timeout: 5);
            $lock = (new LockFactory(new FlockStore($directory)))->createLock('claude-managed');
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
                [$registrationB, $signalB] = $ready($ownerB);
                $this->assertNotSame($registrationA['owner']['pid'], $registrationB['owner']['pid']);
                $this->assertTrue($lock->acquire());
                $signalA->write('!');
                $signalA->close();
                $signalA = $barrier->accept(new TimeoutCancellation(2));
                $this->assertNotNull($signalA);
                $this->assertStringContainsString('hook_lock_timeout', $ownerA->getOutput());
                $lock->release();
                foreach ([[$ownerA, $signalA, $registrationA, null], [$ownerB, $signalB, $registrationB, 0]] as [$native, $signal, $registration, $count]) {
                    $signal->write('!');
                    $signal->close();
                    $this->assertSame($registration, $this->exchange($server, $native, reply: $reply, finish: false));
                    $completed = $this->exchange($server, $native, finish: false);
                    $this->assertSame('completed', $completed['turn']['status']);
                    $this->assertSame($count, $completed['activeSubagents']);
                    $this->assertSame(0, $native->wait());
                }
                $this->assertStringNotContainsString('hook_lock_timeout', $ownerB->getOutput());
            } finally {
                $lock->release();
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
            $this->assertSame(1, $this->launch($environment, $input, hook: false, notify: true)->wait());
            $first = $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']));
            $this->assertSame(2, $this->launch($environment, ['title' => str_repeat('é', 201), 'body' => 'Available'], hook: false, notify: true)->wait());
            $notice = $this->exchange($server, $this->launch($environment, $input, hook: false, notify: true));
            $this->assertSame('notification', $notice['kind']);
            $this->assertSame($first['sessionId'], $notice['sessionId']);
            $this->assertSame($first['sessionEpoch'], $notice['sessionEpoch']);
            $this->assertSame($first['sequence'] + 1, $notice['sequence']);
            $this->assertSame($input, array_intersect_key($notice, $input));
            $end = $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionEnd', 'reason' => 'logout']));
            $this->assertSame($notice['sequence'] + 1, $end['sequence']);
            $this->assertSame(1, $this->launch($environment, $input, hook: false, notify: true)->wait());
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testConcurrentClaudeHookWaitsForTheInFlightSnapshot(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $barrier = listen('unix://'.$directory.'/b');
        $environment = [
            'H360_STATUS_ENDPOINT' => $directory.'/s',
            'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
            'H360_STATUS_TOKEN' => str_repeat('a', 64),
            'H360_TEST_BARRIER' => $directory.'/b',
        ];
        $session = ['session_id' => 'claude-1'];
        $prompt = $session + ['prompt_id' => '44444444-4444-4444-8444-111111111111'];
        try {
            $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']));
            $first = $this->launch($environment, $prompt + ['hook_event_name' => 'UserPromptSubmit']);
            $started = $this->exchange($server, $first, beforeReply: function () use ($barrier, $environment, $prompt, &$second): void {
                // Signal only after the real flock has refused the competing process.
                $second = $this->launch($environment, $prompt + ['hook_event_name' => 'SubagentStart', 'agent_id' => 'agent-1'], observeContention: true);
                $signal = $barrier->accept(new TimeoutCancellation(2));
                $this->assertNotNull($signal);
                $signal->close();
            });
            $this->assertInstanceOf(Process::class, $second);
            $agent = $this->exchange($server, $second);
            $completed = $this->exchange($server, $this->launch($environment, $prompt + ['hook_event_name' => 'Stop', 'stop_hook_active' => false]));
            $this->assertSame($started['sequence'] + 1, $agent['sequence']);
            $this->assertSame(1, $agent['activeSubagents']);
            $this->assertSame('completed', $completed['turn']['status']);
            $this->assertSame(1, $completed['activeSubagents']);
        } finally {
            if (isset($second) && $second->isRunning()) {
                $second->stop(0);
            }
            $barrier->close();
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testStopCannotSettleWhileAnEnteredStartIsPending(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360', 0o700);
        $server = listen('unix://'.$directory.'/s');
        $barrier = listen('unix://'.$directory.'/b');
        $environment = [
            'H360_STATUS_ENDPOINT' => $directory.'/s',
            'H360_STATUS_LAUNCH_ID' => '44444444-4444-4444-8444-444444444444',
            'H360_STATUS_TOKEN' => str_repeat('a', 64),
            'H360_TEST_BARRIER' => $directory.'/b',
            'H360_TEST_HOLD_START' => '1',
        ];
        $session = ['session_id' => 'claude-1'];
        $prompt = $session + ['prompt_id' => '44444444-4444-4444-8444-111111111111'];
        try {
            $this->exchange($server, $this->launch($environment, $session + ['hook_event_name' => 'SessionStart', 'source' => 'startup']));
            $this->exchange($server, $this->launch($environment, $prompt + ['hook_event_name' => 'UserPromptSubmit']));
            $start = $this->launch($environment, $prompt + ['hook_event_name' => 'SubagentStart', 'agent_id' => 'agent-1'], observeContention: true);
            $signal = $barrier->accept(new TimeoutCancellation(2));
            $this->assertNotNull($signal);
            $stop = $this->exchange($server, $this->launch($environment, $prompt + ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'background_tasks' => []]));
            $signal->write('!');
            $signal->close();
            $agent = $this->exchange($server, $start);
            $this->assertSame('completed', $stop['turn']['status']);
            $this->assertNull($stop['activeSubagents']);
            $this->assertSame($stop['sequence'] + 1, $agent['sequence']);
            $this->assertSame(1, $agent['activeSubagents']);
        } finally {
            if (isset($signal)) {
                $signal->close();
            }
            if (isset($start) && $start->isRunning()) {
                $start->stop(0);
            }
            $barrier->close();
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @param array<string, string> $environment @param array<string, mixed>|string $input */
    private function assertQuietHook(array $environment, array|string $input, ?string $diagnostic = null): void
    {
        $process = $this->launch($environment, $input);
        $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertSame(null === $diagnostic ? '' : json_encode(['component' => 'hatfield_360', 'event_type' => $diagnostic], \JSON_THROW_ON_ERROR)."\n", $process->getErrorOutput());
    }

    /** @param array<string, string> $environment @param array<string, mixed>|string $input */
    private function launch(array $environment, array|string $input, bool $hook = true, bool $notify = false, bool $observeContention = false, ?string $autoload = null, bool $disableProcOpen = false): Process
    {
        $root = ProjectDir::get();
        $packaged = getenv('H360_TEST_PACKAGED_PLUGIN');
        $plugin = $packaged ?: $root.'/.hatfield/extensions/hatfield-360';
        $command = [\PHP_BINARY, '-r', '$_composer_autoload_path = $argv[1]; array_splice($argv, 0, 2); $_SERVER["argv"] = $argv; require $argv[0];', '--',
            $autoload ?? ($packaged ? $plugin.'/vendor/autoload.php' : $root.'/vendor/autoload.php'), $plugin.'/bin/h360-report'];
        if ($observeContention) {
            $command[2] = <<<'PHP'
                namespace Symfony\Component\Lock\Store;
                function flock($handle, $operation): bool {
                    static $signaled = false;
                    $acquired = \flock($handle, $operation);
                    $hold = $acquired && ($operation & \LOCK_SH) && getenv('H360_TEST_HOLD_START');
                    if ((!$acquired || $hold) && !$signaled) {
                        $signaled = true;
                        $signal = stream_socket_client('unix://'.getenv('H360_TEST_BARRIER'));
                        if ($hold) {
                            fread($signal, 1);
                        }
                        fclose($signal);
                    }
                    return $acquired;
                }
                PHP
                .$command[2];
        }
        if ($hook) {
            $command[] = '--claude-hook';
        } elseif ($notify) {
            $command[] = '--claude-notify';
        }
        if ($disableProcOpen) {
            array_splice($command, 1, 0, ['-d', 'disable_functions=proc_open']);
        }
        $process = new Process($command, env: $environment + ['HATFIELD_SESSION_ID' => false], input: \is_string($input) ? $input : json_encode($input, \JSON_THROW_ON_ERROR), timeout: 2);
        $process->start();

        return $process;
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
