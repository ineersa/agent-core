<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Amp\TimeoutCancellation;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmCancelAwareHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\SseStream;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function Amp\async;
use function Amp\Socket\listen;

#[CoversClass(LlmCancelAwareHttpClient::class)]
final class LlmCancelAwareHttpClientTest extends TestCase
{
    /**
     * @return iterable<string, array{0: callable(): HttpClientInterface}>
     */
    public static function transportFactoryProvider(): iterable
    {
        yield 'curl' => [static fn (): HttpClientInterface => new CurlHttpClient(['timeout' => 30, 'max_duration' => 60])];
        yield 'native' => [static fn (): HttpClientInterface => new NativeHttpClient(['timeout' => 30, 'max_duration' => 60])];
        yield 'amp' => [static fn (): HttpClientInterface => new AmpHttpClient(['timeout' => 30, 'max_duration' => 60])];
    }

    /** @return iterable<string, array{string, string}> */
    public static function proxyEnvironmentProvider(): iterable
    {
        foreach (['https_proxy', 'HTTPS_PROXY', 'http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'] as $name) {
            yield $name => [$name, str_contains(strtolower($name), 'https') ? 'https' : 'http'];
        }
    }

    #[DataProvider('proxyEnvironmentProvider')]
    public function testAmpProxyEnvironmentAllowsRequestWithMatchingNoProxy(string $name, string $scheme): void
    {
        $this->withProxyEnvironment([$name => 'http://127.0.0.1:1', 'NO_PROXY' => 'localhost'], function () use ($scheme): void {
            $client = new LlmCancelAwareHttpClient(new AmpHttpClient());
            // Request preparation checks the tunnel dependency even when NO_PROXY will bypass it.
            $response = $client->request('GET', $scheme.'://localhost:1/');
            $response->cancel();
            $this->assertTrue($response->getInfo('canceled'));
        });
    }

    /** @return iterable<string, array{string, bool}> */
    public static function proxyRoutingProvider(): iterable
    {
        // Uppercase HTTP_PROXY is honored here because the application runs under CLI, not CGI.
        foreach (['http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'] as $name) {
            yield $name.' tunnel' => [$name, false];
            yield $name.' bypass' => [$name, true];
        }
    }

    #[DataProvider('proxyRoutingProvider')]
    public function testAmpProxyRoutingAndDirectNoProxyRequest(string $name, bool $bypass): void
    {
        $server = listen('127.0.0.1:0');
        $cancellation = new TimeoutCancellation(3);
        $request = async(static function () use ($server, $cancellation, $bypass): array {
            $socket = $server->accept($cancellation);
            self::assertNotNull($socket);
            try {
                $readHeaders = static function () use ($socket, $cancellation): string {
                    $headers = '';
                    while (!str_contains($headers, "\r\n\r\n")) {
                        $chunk = $socket->read($cancellation);
                        self::assertNotNull($chunk, 'The client must finish its request headers.');
                        $headers .= $chunk;
                    }

                    return $headers;
                };
                $first = $readHeaders();
                if (!$bypass) {
                    $socket->write("HTTP/1.1 200 Connection Established\r\n\r\n");
                    $headers = $readHeaders();
                } else {
                    $headers = $first;
                }
                $socket->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");

                return [$first, $headers];
            } finally {
                $socket->close();
            }
        });
        $address = (string) $server->getAddress();
        try {
            $this->withProxyEnvironment([$name => 'http://'.$address, 'no_proxy' => $bypass ? '127.0.0.1' : ''], function () use ($bypass, $address, $request, $cancellation): void {
                $client = new LlmCancelAwareHttpClient(new AmpHttpClient(['timeout' => 3, 'max_duration' => 3]));
                $url = $bypass ? 'http://'.$address.'/direct' : 'http://provider.invalid/proxied';
                $this->assertSame('OK', $client->request('GET', $url)->getContent());
                [$first, $headers] = $request->await($cancellation);
                $this->assertStringStartsWith($bypass ? 'GET /direct HTTP/1.1' : 'CONNECT provider.invalid:80 HTTP/1.1', $first);
                $this->assertStringStartsWith($bypass ? 'GET /direct HTTP/1.1' : 'GET /proxied HTTP/1.1', $headers);
            });
        } finally {
            $server->close();
            $request->ignore();
        }
    }

    #[DataProvider('transportFactoryProvider')]
    public function testSilentStreamCancelAfterFirstChunkDuringTransportWait(callable $transportFactory): void
    {
        $server = $this->startControlledSseServer(sendFirstEvent: true);
        $token = $this->mutableToken();
        $progressAfterCancel = 0;

        LlmInvocationCancelScope::enter($token);
        try {
            $transport = new LlmCancelAwareHttpClient($transportFactory());
            $client = new EventSourceHttpClient($transport);
            $response = $client->request('GET', $server['url'], [
                'headers' => ['Accept' => 'text/event-stream'],
                'on_progress' => static function () use (&$progressAfterCancel, $token): void {
                    if ($token->cancelled) {
                        ++$progressAfterCancel;
                    }
                },
            ]);
            $this->assertSame(200, $response->getStatusCode());
            $sawFirst = false;
            $started = hrtime(true);
            try {
                foreach ($client->stream($response) as $streamResponse => $chunk) {
                    $this->assertInstanceOf(ResponseInterface::class, $streamResponse);
                    $this->assertInstanceOf(ChunkInterface::class, $chunk);
                    if ($chunk->isFirst()) {
                        continue;
                    }
                    if (!$sawFirst && $chunk instanceof \Symfony\Component\HttpClient\Chunk\ServerSentEvent) {
                        $sawFirst = true;
                        $token->cancelled = true;
                        continue;
                    }
                    if ($sawFirst && '' !== $chunk->getContent()) {
                        $this->fail('No further body expected after cancel during silence.');
                    }
                }
                $this->fail('Expected cancel abort exception.');
            } catch (LlmStreamCancelledException $exception) {
                $elapsed = (hrtime(true) - $started) / 1e9;
                $this->assertSame(LlmStreamCancelledException::MESSAGE, $exception->getMessage());
                $this->assertTrue($sawFirst, 'Cancel must happen after first SSE event.');
                $this->assertGreaterThan(0, $progressAfterCancel, 'Cancel must be observed on a post-first progress wake.');
                // EventSource returns AsyncResponse while cancel aborts the inner transport response.
                $this->assertTrue(
                    (bool) $response->getInfo('canceled')
                    || \is_string($response->getInfo('error')),
                    'Transport must tear down the in-flight response.',
                );
                $this->assertLessThan(5.0, $elapsed);
            }

            $this->assertSame(1, $server['accepts'](), 'Cancel must not trigger EventSource reconnection.');
        } finally {
            LlmInvocationCancelScope::leave();
            $server['stop']();
        }
    }

    public function testSilentStreamIdleTimeoutSurfacesBeforeMaxDuration(): void
    {
        $server = $this->startControlledSseServer(sendFirstEvent: false);
        $started = hrtime(true);

        try {
            $client = new EventSourceHttpClient(new LlmCancelAwareHttpClient(
                new CurlHttpClient(['timeout' => 1, 'max_duration' => 20]),
            ));
            $response = $client->request('GET', $server['url'], [
                'headers' => ['Accept' => 'text/event-stream'],
            ]);

            try {
                foreach ((new SseStream())->stream($response) as $unused) {
                    $this->fail('Idle stall must not yield SSE data.');
                }
                $this->fail('Expected idle timeout.');
            } catch (TimeoutExceptionInterface $exception) {
                $elapsed = (hrtime(true) - $started) / 1e9;
                $this->assertInstanceOf(TimeoutException::class, $exception);
                $this->assertStringContainsString('Idle timeout reached', $exception->getMessage());
                $this->assertGreaterThanOrEqual(0.9, $elapsed);
                $this->assertLessThan(5.0, $elapsed);
            }

            $this->assertSame(1, $server['accepts'](), 'Idle timeout must not reconnect.');
        } finally {
            $server['stop']();
        }
    }

    public function testRequestCapturesTokenAndIgnoresLaterAmbientSiblingCancel(): void
    {
        $requestToken = $this->mutableToken();
        $siblingToken = $this->mutableToken();
        $progressHits = 0;

        LlmInvocationCancelScope::enter($requestToken);
        try {
            $requests = 0;
            $transport = new LlmCancelAwareHttpClient(new \Symfony\Component\HttpClient\MockHttpClient(
                static function () use (&$requests): \Symfony\Component\HttpClient\Response\MockResponse {
                    ++$requests;

                    return new \Symfony\Component\HttpClient\Response\MockResponse(
                        "data: {\"choices\":[{\"delta\":{\"content\":\"A\"}}]}\n\n",
                        ['http_code' => 200, 'response_headers' => ['content-type' => 'text/event-stream']],
                    );
                },
            ));

            $response = $transport->request('GET', 'https://example.test/v1/chat/completions', [
                'on_progress' => static function () use (&$progressHits): void {
                    ++$progressHits;
                },
            ]);

            LlmInvocationCancelScope::enter($siblingToken);
            try {
                $siblingToken->cancelled = true;

                // Ambient sibling cancel must not abort the captured request token.
                foreach ($transport->stream($response) as $unusedChunk) {
                    // Drain mock body under sibling cancel without abort.
                }
                $this->assertFalse((bool) $response->getInfo('canceled'));
                $this->assertSame(1, $requests);
                $this->assertGreaterThan(0, $progressHits);

                $requestToken->cancelled = true;
                try {
                    $transport->request('GET', 'https://example.test/v1/chat/completions');
                    $this->fail('Captured request-token cancel must abort during request progress.');
                } catch (LlmStreamCancelledException $exception) {
                    $this->assertSame(LlmStreamCancelledException::MESSAGE, $exception->getMessage());
                    $this->assertSame(2, $requests);
                }
            } finally {
                LlmInvocationCancelScope::leave();
            }
        } finally {
            LlmInvocationCancelScope::leave();
        }
    }

    public function testWithOptionsPreservesDefaultOnProgressUnderCancelHook(): void
    {
        $server = $this->startControlledSseServer(sendFirstEvent: false);
        $token = $this->mutableToken();
        $defaultProgressCalls = 0;

        LlmInvocationCancelScope::enter($token);
        try {
            $decorated = (new LlmCancelAwareHttpClient(new CurlHttpClient(['timeout' => 30, 'max_duration' => 60])))
                ->withOptions([
                    'on_progress' => static function () use (&$defaultProgressCalls): void {
                        ++$defaultProgressCalls;
                    },
                ]);
            $client = new EventSourceHttpClient($decorated);
            $response = $client->request('GET', $server['url'], [
                'headers' => ['Accept' => 'text/event-stream'],
            ]);
            $this->assertSame(200, $response->getStatusCode());
            $token->cancelled = true;

            try {
                foreach ($client->stream($response) as $unusedChunk) {
                    $this->fail('No body expected.');
                }
                $this->fail('Expected cancel.');
            } catch (LlmStreamCancelledException) {
                $this->assertGreaterThan(0, $defaultProgressCalls);
                $this->assertTrue(
                    (bool) $response->getInfo('canceled')
                    || \is_string($response->getInfo('error')),
                    'Transport must tear down the in-flight response.',
                );
            }
        } finally {
            LlmInvocationCancelScope::leave();
            $server['stop']();
        }
    }

    /** @param array<string, string> $values */
    private function withProxyEnvironment(array $values, callable $test): void
    {
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];
        $environment = [];
        $server = $_SERVER;
        $env = $_ENV;
        foreach ($names as $name) {
            $environment[$name] = getenv($name);
        }
        try {
            foreach ($names as $name) {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            }
            foreach ($values as $name => $value) {
                putenv($name.'='.$value);
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
            $test();
        } finally {
            foreach ($environment as $name => $value) {
                putenv(false === $value ? $name : $name.'='.$value);
            }
            $_SERVER = $server;
            $_ENV = $env;
        }
    }

    /**
     * @return array{url: string, accepts: \Closure(): int, stop: \Closure(): void}
     */
    private function startControlledSseServer(bool $sendFirstEvent): array
    {
        $controlServer = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($controlServer, $errstr);
        $controlName = stream_socket_get_name($controlServer, false);
        $this->assertIsString($controlName);

        $serverCode = <<<'PHP'
$controlListen = getenv('CONTROL_ADDR');
$sendFirst = getenv('SEND_FIRST') === '1';
$control = @stream_socket_client($controlListen, $errno, $errstr, 2);
if (false === $control) {
    fwrite(STDERR, "control connect failed: $errstr\n");
    exit(1);
}
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, $errstr."\n");
    exit(2);
}
fwrite($control, 'URL:'.stream_socket_get_name($server, false)."\n");
fflush($control);

$accepts = 0;
$clients = [];
$stop = false;
$commands = '';
stream_set_blocking($control, false);
stream_set_blocking($server, false);

while (!$stop) {
    $read = [$control, $server];
    $write = null;
    $except = null;
    if (false === @stream_select($read, $write, $except, 0, 50_000)) {
        continue;
    }

    if (\in_array($control, $read, true)) {
        $cmd = stream_get_contents($control);
        $commands .= (string) $cmd;
        if (str_contains($commands, "STOP\n") || feof($control)) {
            $stop = true;
        }
        if (str_contains($commands, "ACCEPTS?\n")) {
            fwrite($control, 'ACCEPTS:'.$accepts."\n");
            fflush($control);
            $commands = str_replace("ACCEPTS?\n", '', $commands);
        }
    }

    if (\in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if (false !== $client) {
            ++$accepts;
            stream_set_blocking($client, false);
            stream_get_contents($client);
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: keep-alive\r\n\r\n");
            fflush($client);
            if ($sendFirst) {
                // Emit first event immediately so stream can consume it, then stay silent.
                fwrite($client, "data: {\"choices\":[{\"delta\":{\"content\":\"A\"}}]}\n\n");
                fflush($client);
                $clients[] = $client;
            } else {
                $clients[] = $client;
            }
            fwrite($control, 'ACCEPT:'.$accepts."\n");
            fflush($control);
        }
    }
}

foreach ($clients as $client) {
    fclose($client);
}
fclose($server);
fclose($control);
PHP;

        $process = new Process(
            [\PHP_BINARY, '-r', $serverCode],
            null,
            [
                'CONTROL_ADDR' => $controlName,
                'SEND_FIRST' => $sendFirstEvent ? '1' : '0',
            ],
        );
        $process->setTimeout(10);
        $process->start();

        $control = null;
        $url = null;
        $accepts = 0;
        $buffer = '';
        $deadline = microtime(true) + 2.0;

        try {
            while (microtime(true) < $deadline) {
                if (!$process->isRunning()) {
                    throw new \RuntimeException('SSE stall server exited early: '.$process->getErrorOutput().$process->getOutput());
                }
                $control ??= @stream_socket_accept($controlServer, 0) ?: null;
                if (null === $control) {
                    usleep(5_000);
                    continue;
                }
                stream_set_blocking($control, false);
                $buffer .= (string) stream_get_contents($control);
                if (preg_match('/URL:([^\n]+)\n/', $buffer, $matches)) {
                    $url = 'http://'.$matches[1].'/';
                    break;
                }
                usleep(5_000);
            }

            $this->assertNotNull($url, 'SSE stall server did not publish URL while alive.');
            $this->assertIsResource($control);

            $acceptsFn = static function () use (&$control, &$accepts, $process): int {
                fwrite($control, "ACCEPTS?\n");
                fflush($control);
                $deadline = microtime(true) + 1.0;
                $msg = '';
                while (microtime(true) < $deadline) {
                    if (!$process->isRunning()) {
                        throw new \RuntimeException('SSE server exited: '.$process->getErrorOutput().$process->getOutput());
                    }
                    $msg .= (string) stream_get_contents($control);
                    if (\is_string($msg)) {
                        if (preg_match('/ACCEPT:(\d+)\n/', $msg, $matches)) {
                            $accepts = max($accepts, (int) $matches[1]);
                        }
                        if (preg_match('/ACCEPTS:(\d+)\n/', $msg, $matches)) {
                            return $accepts = (int) $matches[1];
                        }
                    }
                    usleep(5_000);
                }

                throw new \RuntimeException('SSE server did not acknowledge ACCEPTS: '.$process->getErrorOutput().$process->getOutput());
            };

            $stop = static function () use ($process, &$control, $controlServer): void {
                if (\is_resource($control)) {
                    @fwrite($control, "STOP\n");
                    @fflush($control);
                    @fclose($control);
                    $control = null;
                }
                @fclose($controlServer);
                if ($process->isRunning()) {
                    $process->stop(1, \SIGTERM);
                }
            };

            return [
                'url' => $url,
                'accepts' => $acceptsFn,
                'stop' => $stop,
            ];
        } catch (\Throwable $exception) {
            if (\is_resource($control)) {
                @fclose($control);
            }
            @fclose($controlServer);
            if ($process->isRunning()) {
                $process->stop(1, \SIGTERM);
            }
            throw $exception;
        }
    }

    /**
     * @return object{cancelled: bool}&CancellationTokenInterface
     */
    private function mutableToken(): CancellationTokenInterface
    {
        return new class implements CancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
    }
}
