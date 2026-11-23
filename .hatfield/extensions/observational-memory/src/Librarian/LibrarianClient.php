<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Librarian;

use GuzzleHttp\Psr7\HttpFactory;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Mcp\Client\CancellationTokenInterface;
use Mcp\Client\Client;
use Mcp\Client\Exception\RequestCancelledException;
use Mcp\Client\Exception\TimeoutException;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** One lazy SDK connection owned by the tool worker, never by an observer job. */
final class LibrarianClient
{
    // Librarian duplicates a <=1 MiB payload in text and structuredContent; allow JSON escaping overhead.
    private const int MAX_WIRE_BYTES = 8_388_608;
    private const int MAX_PAYLOAD_BYTES = 1_048_576;

    private ?Client $client = null;
    private ?ToolCancellationTokenInterface $cancellation = null;
    private ?int $deadlineNs = null;
    private ?string $runId = null;

    public function __construct(
        private readonly LibrarianSettingsDTO $settings,
        private readonly string $projectRoot,
        private readonly LoggerInterface $logger,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments, ?ToolCancellationTokenInterface $cancellation = null, ?int $deadlineNs = null, ?string $runId = null): array
    {
        $this->runId = $runId;
        $this->cancellation = $cancellation;
        $this->deadlineNs = min($deadlineNs ?? \PHP_INT_MAX, hrtime(true) + $this->settings->timeoutSeconds * 1_000_000_000);
        $token = new class($cancellation) implements CancellationTokenInterface {
            public function __construct(private readonly ?ToolCancellationTokenInterface $token)
            {
            }

            public function isCancellationRequested(): bool
            {
                return $this->token?->isCancellationRequested() ?? false;
            }
        };
        try {
            $this->checkpoint();
            if (null === $this->client) {
                // HTTP callbacks must not retain their owner: otherwise the SDK/HTTP callback
                // cycle prevents immediate disconnect when a worker releases its tool registry.
                $owner = \WeakReference::create($this);
                // Match the host's explicit Symfony PSR-18 transport: do not auto-discover Amp I/O.
                // on_progress bounds both JSON and SSE bodies before the SDK parses them, and
                // cooperatively checks cancellation/deadlines even during synchronous HTTP I/O.
                $http = ($this->httpClient ?? new CurlHttpClient())->withOptions([
                    'timeout' => $this->settings->timeoutSeconds,
                    'max_duration' => $this->settings->timeoutSeconds,
                    'max_redirects' => 0,
                    'on_progress' => static function (int $downloaded, int $size) use ($owner): void {
                        $owner->get()?->checkpoint();
                        if (max($downloaded, $size) > self::MAX_WIRE_BYTES) {
                            throw new \LengthException('Librarian response exceeded the wire limit.');
                        }
                    },
                ]);
                $http = new class($http, static fn (): float => $owner->get()?->remainingSeconds() ?? 1.0) implements HttpClientInterface {
                    use DecoratorTrait;

                    public function __construct(HttpClientInterface $client, private readonly \Closure $remaining)
                    {
                        $this->client = $client;
                    }

                    /** @param array<array-key, mixed> $options */
                    public function request(string $method, string $url, array $options = []): ResponseInterface
                    {
                        // Recompute for every HTTP exchange and every reused tool invocation.
                        // A short first invocation must not permanently shrink this connection's budget.
                        $remaining = ($this->remaining)();
                        $options['timeout'] = $remaining;
                        $options['max_duration'] = $remaining;

                        return $this->client->request($method, $url, $options);
                    }
                };
                $this->client = Client::builder()->setClientInfo('hatfield-observational-memory', '1.0')
                    ->setInitTimeout($this->settings->timeoutSeconds)->setRequestTimeout($this->settings->timeoutSeconds)
                    ->setMaxRetries(0)->build();
                // Use the declared PSR-17 implementation, rather than relying on another
                // host package to happen to satisfy SDK/PSR-18 factory auto-discovery.
                $factory = new HttpFactory();
                $psrClient = new Psr18Client($http, $factory, $factory);
                $this->client->connect(new HttpTransport(
                    endpoint: $this->settings->url,
                    headers: array_filter(['X-Librarian-Project' => $this->projectRoot, 'Authorization' => null === $this->settings->token ? null : 'Bearer '.$this->settings->token], static fn (?string $value): bool => null !== $value),
                    httpClient: $psrClient,
                    requestFactory: $factory,
                    streamFactory: $factory,
                    maxSseBufferBytes: self::MAX_WIRE_BYTES,
                ));
                $registration = $this->payload($this->client->callTool('ensure_project', [], cancellation: $token, timeoutSeconds: $this->remainingSeconds()));
                if (true === $registration['isError'] || true !== ($registration['ok'] ?? false)) {
                    $this->disconnect();

                    return $registration;
                }
                // Registration is not readiness: never poll, index, or reconnect for index_unavailable.
            }
            $this->checkpoint();

            return $this->payload($this->client->callTool($tool, $arguments, cancellation: $token, timeoutSeconds: $this->remainingSeconds()));
        } catch (\Throwable $error) {
            $this->logger->warning('om.librarian.request_failed', [
                'run_id' => $this->runId, 'session_id' => $this->runId,
                'component' => 'observational_memory', 'event_type' => 'om.librarian.request_failed',
                'operation' => $tool, 'exception_class' => $error::class,
            ]);
            $this->disconnect();
            if ($cancellation?->isCancellationRequested() || $error instanceof RequestCancelledException) {
                return ['cancelled' => true, 'message' => 'Librarian request cancelled.'];
            }
            $cause = $error;
            do {
                $timedOut = $cause instanceof TimeoutException || $cause instanceof TimeoutExceptionInterface;
            } while (!$timedOut && null !== ($cause = $cause->getPrevious()));
            if (hrtime(true) >= $this->deadlineNs || $timedOut) {
                return ['timed_out' => true, 'timeout_seconds' => $this->settings->timeoutSeconds, 'message' => 'Librarian request timed out.'];
            }

            return ['ok' => false, 'isError' => true, 'error' => 'librarian_unavailable', 'message' => 'Librarian request failed; no local fallback was used.'];
        } finally {
            $this->cancellation = null;
            $this->deadlineNs = null;
        }
    }

    public function disconnect(): void
    {
        $client = $this->client;
        $this->client = null;
        if (null === $client) {
            return;
        }
        try {
            $client->disconnect();
        } catch (\Throwable $error) {
            // Cleanup must not hide the original retrieval failure or break worker shutdown.
            $this->logger->warning('om.librarian.disconnect_failed', [
                'run_id' => $this->runId, 'session_id' => $this->runId,
                'component' => 'observational_memory', 'event_type' => 'om.librarian.disconnect_failed',
                'exception_class' => $error::class,
            ]);
        }
    }

    private function checkpoint(): void
    {
        if ($this->cancellation?->isCancellationRequested()) {
            throw new RequestCancelledException('Librarian request cancelled.');
        }
        if (null !== $this->deadlineNs && hrtime(true) >= $this->deadlineNs) {
            throw new TimeoutException('Librarian request timed out.');
        }
    }

    private function remainingSeconds(): float
    {
        $this->checkpoint();

        return max(0.001, (($this->deadlineNs ?? hrtime(true)) - hrtime(true)) / 1_000_000_000);
    }

    /** @return array<string, mixed> */
    private function payload(CallToolResult $result): array
    {
        $payload = $result->structuredContent;
        if (!\is_array($payload)) {
            throw new \UnexpectedValueException('Librarian did not return structured content.');
        }
        // Match Librarian's byte budget, not JSON's expanded Unicode/slash escaping.
        if (\strlen(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)) > self::MAX_PAYLOAD_BYTES) {
            throw new \LengthException('Librarian response exceeded the payload limit.');
        }
        $payload['isError'] = $result->isError || false === ($payload['ok'] ?? null);

        return $payload;
    }
}
