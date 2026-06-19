<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates an HttpClient for controller replay E2E tests.
 *
 * This factory lives in tests/, NOT in production src/.  It is wired
 * through config/services_test.yaml so that when the controller
 * subprocess boots with APP_ENV=test, the Symfony DI container
 * resolves Symfony\Contracts\HttpClient\HttpClientInterface via this
 * factory.  The existing production code path
 * (SymfonyAiProviderFactory::getHttpClient()) receives the injected
 * HttpClient through its constructor — no production env-var branching
 * needed.
 *
 * Activation:
 *   HATFIELD_LLM_REPLAY_FIXTURE_PATH=/path/to/fixture1.json;/path/to/fixture2.json
 *
 * When the env var is set, the factory loads each fixture file,
 * converts its deltas to OpenAI-compatible SSE chunks, and returns
 * a MockHttpClient that serves one response per LLM invocation
 * (cycling through the fixture queue).  After the queue is exhausted,
 * a minimal "done" text response is returned so the run can complete
 * cleanly.
 *
 * When the env var is NOT set, the factory returns the normal test
 * HttpClient with a 5s timeout — preserving existing behavior for
 * non-replay test runs and live LLM smoke tests.
 *
 * MAINT-05D: This is the replay seam for controller E2E tests.
 * MAINT-05E will reuse it for TUI E2E replay.
 */
final class ControllerReplayHttpClientFactory
{
    /**
     * Create an HttpClient for the test environment.
     *
     * This method is called by the Symfony DI container factory
     * wiring in config/services_test.yaml.
     */
    public static function create(): HttpClientInterface
    {
        $fixturePathEnv = $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH']
            ?? ($_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] ?? getenv('HATFIELD_LLM_REPLAY_FIXTURE_PATH'));

        if (false !== $fixturePathEnv && '' !== $fixturePathEnv) {
            return self::createReplayClient((string) $fixturePathEnv);
        }

        // Default: short timeout for test environment (live LLM smoke
        // or non-replay controller tests).
        return HttpClient::create(['timeout' => 5]);
    }

    // ── Replay client construction ────────────────────────────────

    /**
     * Build a MockHttpClient that serves fixture-driven SSE responses.
     */
    private static function createReplayClient(string $fixturePathEnv): HttpClientInterface
    {
        $fixturePaths = explode(';', $fixturePathEnv);
        $fixtures = [];
        foreach ($fixturePaths as $path) {
            $path = trim($path);
            if ('' === $path || !is_file($path)) {
                continue;
            }
            $fixture = json_decode((string) file_get_contents($path), true);
            if (\is_array($fixture)) {
                $fixtures[] = $fixture;
            }
        }

        if ([] === $fixtures) {
            // No valid fixtures found — fall back to the normal 5s
            // timeout client so the process doesn't fail on a missing
            // HttpClient.  The test will still fail because there are
            // no fixture responses, but the error is easier to debug.
            return HttpClient::create(['timeout' => 5]);
        }

        $index = 0;

        return new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$index, $fixtures): MockResponse {
                if ($index >= \count($fixtures)) {
                    // Queue exhausted — the next LLM call in this run
                    // would normally be the post-tool assistant turn.
                    // Return a minimal text-only stop response so the
                    // run can complete cleanly.
                    return new MockResponse(
                        self::buildSSEFromDeltas(
                            model: 'llama_cpp/test',
                            deltas: [
                                ['type' => 'text', 'content' => 'done'],
                            ],
                            stopReason: 'stop',
                            usage: null,
                        ),
                        [
                            'http_code' => 200,
                            'response_headers' => [
                                'Content-Type' => 'text/event-stream',
                                'X-Replay-Fallback' => '1',
                            ],
                        ],
                    );
                }

                $fixture = $fixtures[$index];
                ++$index;

                // HTTP error fixtures return a non-200 MockResponse directly.
                if (self::isHttpErrorFixture($fixture)) {
                    return self::buildErrorResponse($fixture);
                }

                $deltas = $fixture['deltas'] ?? [];
                $stopReason = $fixture['stop_reason'] ?? 'stop';
                $model = $fixture['model'] ?? 'llama_cpp/test';

                $usage = $fixture['usage'] ?? null;
                $body = self::buildSSEFromDeltas($model, $deltas, $stopReason, $usage);

                return new MockResponse($body, [
                    'http_code' => 200,
                    'response_headers' => [
                        'Content-Type' => 'text/event-stream',
                        'Cache-Control' => 'no-cache',
                        'X-Replay' => '1',
                    ],
                ]);
            },
            'http://replay.internal',
        );
    }

    /**
     * Check whether a fixture represents an HTTP error response.
     *
     * HTTP error fixtures have an "http_status" key and are returned as
     * non-SSE MockResponses so the Symfony AI provider's error-handling
     * path is exercised (EventSourceHttpClient passthru → SseStream →
     * convertStream error detection).
     */
    private static function isHttpErrorFixture(array $fixture): bool
    {
        return isset($fixture['http_status']);
    }

    /**
     * Build a MockResponse from an HTTP error fixture.
     *
     * @param array<string, mixed> $fixture
     */
    private static function buildErrorResponse(array $fixture): MockResponse
    {
        $statusCode = (int) $fixture['http_status'];
        $headers = $fixture['response_headers'] ?? [];
        $body = $fixture['response_body'] ?? '{}';

        // Ensure JSON content-type so EventSourceHttpClient does not
        // interpret the error response as SSE (which would fail parsing).
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        return new MockResponse($body, [
            'http_code' => $statusCode,
            'response_headers' => $headers,
        ]);
    }

    /**
     * Convert fixture deltas to an OpenAI-compatible SSE stream.
     *
     * @param list<array<string, mixed>> $deltas
     */
    /**
     * @param array<string, mixed>|null $usage Fixture usage payload (null if no usage)
     */
    private static function buildSSEFromDeltas(string $model, array $deltas, string $stopReason, ?array $usage): string
    {
        $chunks = [];
        $chunkId = 'chatcmpl-replay-'.bin2hex(random_bytes(4));
        $created = time();
        $toolCallIndex = 0;

        foreach ($deltas as $delta) {
            $type = $delta['type'] ?? '';

            $chunk = match ($type) {
                'text' => json_encode([
                    'id' => $chunkId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'delta' => ['content' => $delta['content'] ?? ''],
                        'finish_reason' => null,
                    ]],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'thinking' => json_encode([
                    'id' => $chunkId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'delta' => ['reasoning_content' => $delta['content'] ?? ''],
                        'finish_reason' => null,
                    ]],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'thinking_signature' => json_encode([
                    'id' => $chunkId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'delta' => ['reasoning_signature' => $delta['content'] ?? ''],
                        'finish_reason' => null,
                    ]],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'tool_call_start' => json_encode([
                    'id' => $chunkId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'delta' => [
                            'tool_calls' => [[
                                'index' => $toolCallIndex,
                                'id' => $delta['id'] ?? 'call_unknown',
                                'type' => 'function',
                                'function' => [
                                    'name' => $delta['name'] ?? 'unknown',
                                    'arguments' => '',
                                ],
                            ]],
                        ],
                        'finish_reason' => null,
                    ]],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'tool_input_delta' => json_encode([
                    'id' => $chunkId,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'delta' => [
                            'tool_calls' => [[
                                'index' => $toolCallIndex,
                                'function' => [
                                    'arguments' => $delta['partial_json'] ?? '',
                                ],
                            ]],
                        ],
                        'finish_reason' => null,
                    ]],
                ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                default => null,
            };

            if (null !== $chunk) {
                $chunks[] = $chunk;
            }
        }

        // Terminal chunk with finish_reason.
        $mappedReason = match ($stopReason) {
            'stop' => 'stop',
            'tool_call' => 'tool_calls',
            'length' => 'length',
            'content_filter' => 'content_filter',
            default => 'stop',
        };

        $chunks[] = json_encode([
            'id' => $chunkId,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => new \stdClass(),
                'finish_reason' => $mappedReason,
            ]],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        // Usage chunk: send token usage as a separate SSE frame so the
        // DurableResultConverter yields a TokenUsage delta.  Usage is
        // read from the fixture, which must include a top-level "usage"
        // key.  When absent, no usage chunk is emitted.
        if (null !== $usage && [] !== $usage) {
            $chunks[] = json_encode([
                'id' => $chunkId,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $model,
                'choices' => [],
                'usage' => $usage,
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        $chunks[] = '[DONE]';

        return implode("\n\n", array_map(
            static fn (string $c): string => "data: {$c}",
            $chunks,
        ))."\n\n";
    }
}
