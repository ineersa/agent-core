<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmCancelAwareHttpClient;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmEventSourceHttpClient;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\SseStream;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

#[CoversClass(LlmCancelAwareHttpClient::class)]
#[CoversClass(LlmEventSourceHttpClient::class)]
final class LlmCancelAwareHttpClientTest extends TestCase
{
    public function testSilentStreamCancelAbortsViaProgressHookWithoutWaitingIdleTimeout(): void
    {
        [$proc, $url] = $this->startStallingSseServer();

        $token = new class implements CancellationTokenInterface {
            private int $checks = 0;

            public function isCancellationRequested(): bool
            {
                return ++$this->checks >= 2;
            }
        };

        RunLogContext::enter(['llm_cancel_token' => $token]);
        $started = hrtime(true);

        try {
            $client = new LlmEventSourceHttpClient(new LlmCancelAwareHttpClient(
                new CurlHttpClient(['timeout' => 30, 'max_duration' => 60]),
            ));
            $response = $client->request('GET', $url, [
                'headers' => ['Accept' => 'text/event-stream'],
            ]);

            foreach ((new SseStream())->stream($response) as $unused) {
                $this->fail('Silent cancel must not yield SSE data.');
            }

            $this->fail('Expected cancel abort exception.');
        } catch (TransportException $exception) {
            $elapsed = (hrtime(true) - $started) / 1e9;
            $this->assertInstanceOf(LlmStreamCancelledException::class, $exception->getPrevious());
            $this->assertLessThan(5.0, $elapsed, 'Cancel during silence must abort well under idle timeout/max_duration.');
        } finally {
            RunLogContext::leave();
            $this->stopProcess($proc);
        }
    }

    public function testSilentStreamIdleTimeoutSurfacesBeforeMaxDuration(): void
    {
        [$proc, $url] = $this->startStallingSseServer();
        $started = hrtime(true);

        try {
            $client = new LlmEventSourceHttpClient(
                new CurlHttpClient(['timeout' => 1, 'max_duration' => 20]),
            );
            $response = $client->request('GET', $url, [
                'headers' => ['Accept' => 'text/event-stream'],
            ]);

            foreach ((new SseStream())->stream($response) as $unused) {
                $this->fail('Idle stall must not yield SSE data.');
            }

            $this->fail('Expected idle timeout.');
        } catch (TimeoutExceptionInterface $exception) {
            $elapsed = (hrtime(true) - $started) / 1e9;
            $this->assertInstanceOf(TimeoutException::class, $exception);
            $this->assertStringContainsString('Idle timeout reached', $exception->getMessage());
            $this->assertGreaterThanOrEqual(0.9, $elapsed);
            $this->assertLessThan(5.0, $elapsed, 'Idle timeout must fire near timeout, not max_duration.');
        } finally {
            $this->stopProcess($proc);
        }
    }

    /**
     * @return array{0: resource, 1: string}
     */
    private function startStallingSseServer(): array
    {
        $portFile = TestDirectoryIsolation::createOsTempDir('llm-stall-port').'/port';
        $serverCode = <<<'PHP'
$s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $s) {
    fwrite(STDERR, $errstr);
    exit(1);
}
file_put_contents(getenv('PORTFILE'), stream_socket_get_name($s, false));
$c = @stream_socket_accept($s, 5);
if (false === $c) {
    exit(2);
}
fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n");
fflush($c);
sleep(60);
PHP;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open(
            [\PHP_BINARY, '-r', $serverCode],
            $descriptors,
            $pipes,
            null,
            ['PORTFILE' => $portFile],
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        $deadline = microtime(true) + 2.0;
        $addr = '';
        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $addr = trim((string) file_get_contents($portFile));
                if ('' !== $addr) {
                    break;
                }
            }
            usleep(5_000);
        }
        $this->assertMatchesRegularExpression('/:(\d+)$/', $addr);
        preg_match('/:(\d+)$/', $addr, $matches);

        return [$proc, 'http://127.0.0.1:'.(int) $matches[1].'/'];
    }

    /**
     * @param resource $proc
     */
    private function stopProcess($proc): void
    {
        $status = proc_get_status($proc);
        if (($status['running'] ?? false) && isset($status['pid'])) {
            posix_kill((int) $status['pid'], \SIGTERM);
        }
        proc_close($proc);
    }
}
