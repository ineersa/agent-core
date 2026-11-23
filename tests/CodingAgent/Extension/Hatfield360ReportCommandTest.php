<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function Amp\Socket\listen;

final class Hatfield360ReportCommandTest extends TestCase
{
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
            $startup = $this->launch($environment, ['session_id' => 'claude-1', 'hook_event_name' => 'SessionStart', 'source' => 'startup']);
            $first = $this->exchange($server, $startup);
            $resume = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'SessionStart', 'source' => 'resume']);
            $second = $this->exchange($server, $resume);
            $this->assertSame('session.snapshot', $first['kind']);
            $this->assertSame('claude', $first['provider']);
            $this->assertSame('claude-2', $second['sessionId']);
            $this->assertGreaterThan($first['sequence'], $second['sequence']);
            $this->assertSame($second['sequence'], $second['sessionEpoch']);

            $prompt = '44444444-4444-4444-8444-555555555555';
            $start = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'UserPromptSubmit', 'prompt_id' => $prompt, 'prompt' => 'private prompt']);
            $turn = $this->exchange($server, $start);
            $this->assertSame('session.snapshot', $turn['kind']);
            $this->assertArrayNotHasKey('prompt', $turn);
            $this->assertSame($second['sessionEpoch'], $turn['sessionEpoch']);
            $failure = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'StopFailure', 'prompt_id' => $prompt, 'error' => 'server_error']);
            $end = $this->exchange($server, $failure);
            $this->assertSame(['id' => $prompt, 'status' => 'failed'], $end['turn']);

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
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'last_assistant_message' => 'private'];
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

    /** @param array<string, string> $environment @param array<string, mixed>|string $input */
    private function assertQuietHook(array $environment, array|string $input, ?string $diagnostic = null): void
    {
        $process = $this->launch($environment, $input);
        $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertSame(null === $diagnostic ? '' : json_encode(['component' => 'hatfield_360', 'event_type' => $diagnostic], \JSON_THROW_ON_ERROR)."\n", $process->getErrorOutput());
    }

    /** @param array<string, string> $environment @param array<string, mixed>|string $input */
    private function launch(array $environment, array|string $input, bool $hook = true, bool $notify = false): Process
    {
        $root = ProjectDir::get();
        $command = [\PHP_BINARY, '-r', '$_composer_autoload_path = $argv[1]; array_splice($argv, 0, 2); $_SERVER["argv"] = $argv; require $argv[0];', '--',
            $root.'/vendor/autoload.php', $root.'/.hatfield/extensions/hatfield-360/bin/h360-report'];
        if ($hook) {
            $command[] = '--claude-hook';
        } elseif ($notify) {
            $command[] = '--claude-notify';
        }
        $process = new Process($command, env: $environment + ['HATFIELD_SESSION_ID' => false], input: \is_string($input) ? $input : json_encode($input, \JSON_THROW_ON_ERROR), timeout: 2);
        $process->start();

        return $process;
    }

    /** @return array<array-key, mixed> */
    private function exchange(\Amp\Socket\ServerSocket $server, Process $process, ?string $reply = '{"ok":true,"status":"accepted"}', bool $finish = true): array
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
