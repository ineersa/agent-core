<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
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
            $this->assertSame('session.started', $first['kind']);
            $this->assertSame('claude', $first['provider']);
            $this->assertSame('claude-2', $second['sessionId']);
            $this->assertGreaterThan($first['sequence'], $second['sequence']);
            $this->assertSame($second['sequence'], $second['sessionEpoch']);

            $prompt = '44444444-4444-4444-8444-555555555555';
            $start = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'UserPromptSubmit', 'prompt_id' => $prompt, 'prompt' => 'private prompt']);
            $turn = $this->exchange($server, $start);
            $this->assertSame('turn.started', $turn['kind']);
            $this->assertArrayNotHasKey('prompt', $turn);
            $this->assertSame($second['sessionEpoch'], $turn['actorStart']);
            $failure = $this->launch($environment, ['session_id' => 'claude-2', 'hook_event_name' => 'StopFailure', 'prompt_id' => $prompt, 'error' => 'server_error']);
            $end = $this->exchange($server, $failure);
            $this->assertSame('turn.ended', $end['kind']);
            $this->assertSame('failed', $end['outcome']);
            $this->assertSame($turn['sequence'], $end['turnStart']);

            $notification = $this->launch($environment, [
                'kind' => 'notification', 'sessionId' => 'explicit-test', 'sessionEpoch' => 1, 'sequence' => 2,
                'title' => 'Ready', 'body' => 'Extra message',
            ], false);
            $frame = $this->exchange($server, $notification);
            $this->assertSame('notification', $frame['kind']);
            $this->assertSame('Extra message', $frame['body']);
        } finally {
            $server->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testRecoversExactRegistrationAfterLostAcknowledgement(): void
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
            // The first frame received by the peer still loses its acknowledgement.
            $first = $this->exchange($server, $this->launch($environment, $prompt), reply: null);
            $this->assertSame('session.started', $first['kind']);
            $this->assertSame(1, $first['sequence']);
            $stale = $this->exchange($server, $this->launch($environment, $prompt), reply: '{"ok":true,"status":"stale"}');
            $this->assertSame($first, $stale);
            $process = $this->launch($environment, $prompt);
            $duplicate = $this->exchange($server, $process, reply: '{"ok":true,"status":"duplicate"}', finish: false);
            $this->assertSame($first, $duplicate);
            $turn = $this->exchange($server, $process);
            $this->assertSame('turn.started', $turn['kind']);
            $this->assertSame($first['sessionEpoch'], $turn['actorStart']);
            $failure = ['session_id' => 'claude-1', 'hook_event_name' => 'StopFailure', 'prompt_id' => $prompt['prompt_id'], 'error' => 'server_error'];
            $end = $this->exchange($server, $this->launch($environment, $failure), reply: '{"ok":false,"error":"state"}');
            $this->assertSame('turn.ended', $end['kind']);
            $next = $this->exchange($server, $this->launch($environment, array_replace($prompt, ['prompt_id' => '44444444-4444-4444-8444-666666666666'])));
            $this->assertSame('turn.started', $next['kind']);
        } finally {
            if (isset($server)) {
                $server->close();
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @param array<string, string> $environment @param array<string, mixed> $input */
    private function launch(array $environment, array $input, bool $hook = true): Process
    {
        $root = ProjectDir::get();
        $command = [\PHP_BINARY, '-r', '$_composer_autoload_path = $argv[1]; array_splice($argv, 0, 2); $_SERVER["argv"] = $argv; require $argv[0];', '--',
            $root.'/vendor/autoload.php', $root.'/.hatfield/extensions/hatfield-360/bin/h360-report'];
        if ($hook) {
            $command[] = '--claude-hook';
        }
        $process = new Process($command, env: $environment, input: json_encode($input, \JSON_THROW_ON_ERROR), timeout: 2);
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
