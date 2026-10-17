<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use PHPUnit\Framework\Assert;

/** Real OS pipes with a scripted controller, not a broker or application runtime. */
final class ControlledJsonlControllerFixture
{
    public readonly string $script;
    /** @var resource */
    private $server;
    /** @var resource|null */
    private $control;

    public function __construct(private readonly string $directory)
    {
        $this->script = $directory.'/controlled-controller.php';
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        Assert::assertIsResource($this->server, $error);
        file_put_contents($directory.'/control-address', 'tcp://'.stream_socket_get_name($this->server, false));
        file_put_contents($this->script, <<<'PHP'
<?php
$control = stream_socket_client(file_get_contents(__DIR__.'/control-address'));
file_put_contents(__DIR__.'/commands.jsonl', json_encode(['type' => 'spawn', 'pid' => getmypid()])."\n", FILE_APPEND);
fwrite(STDOUT, json_encode(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => []])."\n");
fflush(STDOUT);
$command = json_decode(fgets(STDIN), true);
file_put_contents(__DIR__.'/commands.jsonl', json_encode(['type' => $command['type'], 'runId' => $command['runId']])."\n", FILE_APPEND);
if ('start_run' === $command['type']) {
    $tail = is_file(__DIR__.'/start-tail.jsonl') ? file_get_contents(__DIR__.'/start-tail.jsonl') : '';
    fwrite(STDOUT, json_encode(['type' => 'run.started', 'runId' => $command['runId'], 'seq' => 1, 'payload' => []])."\n".$tail);
    fflush(STDOUT);
}
fwrite($control, "ready\n");
fflush($control);
while (false !== ($line = fgets($control))) {
    $instruction = json_decode($line, true);
    fwrite(STDOUT, base64_decode($instruction['stdout']));
    fflush(STDOUT);
    fwrite($control, "written\n");
    fflush($control);
    if ($instruction['exit']) {
        break;
    }
}
fclose($control);
PHP);
    }

    public function connect(): void
    {
        if (\is_resource($this->control)) {
            fclose($this->control);
        }
        $this->control = stream_socket_accept($this->server, 5);
        Assert::assertIsResource($this->control, 'Owned controller must connect its barrier socket.');
        stream_set_timeout($this->control, 5);
        Assert::assertSame("ready\n", fgets($this->control));
    }

    public function emit(string $stdout, bool $exit = false): void
    {
        Assert::assertIsResource($this->control);
        fwrite($this->control, json_encode(['stdout' => base64_encode($stdout), 'exit' => $exit], \JSON_THROW_ON_ERROR)."\n");
        fflush($this->control);
        // The acknowledgement follows the stdout flush, not a timing delay.
        Assert::assertSame("written\n", fgets($this->control));
    }

    public function awaitExit(JsonlProcessAgentSessionClient $client): void
    {
        $process = new \ReflectionProperty($client, 'process')->getValue($client);
        Assert::assertIsResource($process);
        $deadline = microtime(true) + 5;
        do {
            $running = proc_get_status($process)['running'];
            if (!$running) {
                return;
            }
            usleep(1_000);
        } while (microtime(true) < $deadline);
        Assert::fail('Owned controller must exit after the exit instruction.');
    }

    /** @return list<string> */
    public function commandTypes(): array
    {
        $lines = file($this->directory.'/commands.jsonl', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        Assert::assertIsArray($lines);

        return array_map(static fn (string $line): string => json_decode($line, true, 512, \JSON_THROW_ON_ERROR)['type'], $lines);
    }

    public function close(): void
    {
        if (\is_resource($this->control)) {
            fclose($this->control);
            $this->control = null;
        }
        fclose($this->server);
    }
}
