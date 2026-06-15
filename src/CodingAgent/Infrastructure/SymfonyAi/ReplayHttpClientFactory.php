<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates an HttpClient for LLM replay when HATFIELD_LLM_REPLAY_FIXTURE_PATH
 * is set.
 *
 * This is a production-neutral factory: it uses only vendor Symfony
 * components (MockHttpClient, MockResponse) and does not depend on
 * test helpers.  It is safe for use in the controller subprocess
 * (PHP source or PHAR), where test-namespace replay helpers from
 * MAINT-05C may not be autoloaded.
 *
 * Activation:
 *   HATFIELD_LLM_REPLAY_FIXTURE_PATH=/path/to/fixture1.json;/path/to/fixture2.json
 *
 * The factory loads each fixture file, converts its deltas to
 * OpenAI-compatible SSE chunks, and returns a MockHttpClient that
 * serves up to one response per LLM invocation (cycling through
 * the fixture queue).  After the queue is exhausted, subsequent
 * requests use the fallback real client.
 *
 * MAINT-05D: This is the replay seam for controller E2E tests.
 * MAINT-05E will reuse it for TUI E2E replay.
 */
final class ReplayHttpClientFactory
{
    /**
     * Create a replay-enabled HttpClient or return null when replay is
     * not activated (caller should fall back to real HttpClient).
     *
     * @return HttpClientInterface|null null when replay not active
     */
    public static function createIfActive(): ?HttpClientInterface
    {
        $fixturePathEnv = $_ENV['HATFIELD_LLM_REPLAY_FIXTURE_PATH']
            ?? ($_SERVER['HATFIELD_LLM_REPLAY_FIXTURE_PATH'] ?? getenv('HATFIELD_LLM_REPLAY_FIXTURE_PATH'));
        if (false === $fixturePathEnv || '' === $fixturePathEnv) {
            return null;
        }

        $fixturePaths = explode(';', (string) $fixturePathEnv);
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
            return null;
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

                $deltas = $fixture['deltas'] ?? [];
                $stopReason = $fixture['stop_reason'] ?? 'stop';
                $model = $fixture['model'] ?? 'llama_cpp/test';

                $body = self::buildSSEFromDeltas($model, $deltas, $stopReason);

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
     * Convert fixture deltas to an OpenAI-compatible SSE stream.
     *
     * @param list<array<string, mixed>> $deltas
     */
    private static function buildSSEFromDeltas(string $model, array $deltas, string $stopReason): string
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

        // Terminal chunk with finish_reason
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

        $chunks[] = '[DONE]';

        return implode("\n\ndata: ", array_map(
            static fn (string $c): string => "data: {$c}",
            $chunks,
        ))."\n\n";
    }
}
