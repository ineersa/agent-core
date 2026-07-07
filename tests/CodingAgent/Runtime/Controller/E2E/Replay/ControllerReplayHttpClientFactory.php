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
 * Activation:
 *   HATFIELD_LLM_REPLAY_FIXTURE_PATH=/path/to/fixture1.json;/path/to/fixture2.json
 *
 * Optional cursor persistence (controller replay / consumer recycle):
 *   HATFIELD_LLM_REPLAY_CURSOR_DIR=/path/to/isolated/temp/dir
 * When set, the fixture index is stored in that directory only (never
 * next to committed fixture files).  Uses flock(LOCK_EX) for atomic
 * read/increment/write.  Filename: .replay-fixture-cursor-<sha256(fixture-path-env)>.
 *
 * When HATFIELD_LLM_REPLAY_CURSOR_DIR is unset (e.g. TUI replay using
 * committed fixtures under tests/Tui/E2E/fixtures/), the factory uses a
 * process-local closure cursor ($index) that advances on each HTTP request
 * within the same MockHttpClient instance — no files are written beside
 * fixture paths.
 */
final class ControllerReplayHttpClientFactory
{
    public static function create(): HttpClientInterface
    {
        $fixturePathEnv = self::readEnv('HATFIELD_LLM_REPLAY_FIXTURE_PATH');

        if (false !== $fixturePathEnv && '' !== $fixturePathEnv) {
            return self::createReplayClient($fixturePathEnv);
        }

        return HttpClient::create(['timeout' => self::liveHttpTimeout()]);
    }

    private static function liveHttpTimeout(): float
    {
        $timeout = 5.0;
        $envTimeout = self::readEnv('HATFIELD_TEST_LLM_HTTP_TIMEOUT');
        if (false !== $envTimeout && '' !== $envTimeout) {
            $timeout = (float) $envTimeout;
        }

        return $timeout;
    }

    /**
     * @return false|non-empty-string
     */
    private static function readEnv(string $name): false|string
    {
        $value = $_ENV[$name] ?? ($_SERVER[$name] ?? getenv($name));

        return false === $value ? false : (string) $value;
    }

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
            return HttpClient::create(['timeout' => self::liveHttpTimeout()]);
        }

        $cursorPath = self::resolveCursorFilePath($fixturePathEnv);

        if (null !== $cursorPath) {
            return new MockHttpClient(
                static function (string $method, string $url, array $options) use ($fixtures, $cursorPath): MockResponse {
                    return self::serveFixtureResponse($fixtures, self::resolveFileBackedFixtureIndex($fixtures, $cursorPath));
                },
                'http://replay.internal',
            );
        }

        $index = 0;

        return new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$index, $fixtures): MockResponse {
                if ($index >= \count($fixtures)) {
                    return self::buildExhaustedFallbackResponse();
                }

                $fixture = $fixtures[$index];
                ++$index;

                return self::buildFixtureMockResponse($fixture);
            },
            'http://replay.internal',
        );
    }

    /**
     * @return non-empty-string|null
     */
    private static function resolveCursorFilePath(string $fixturePathEnv): ?string
    {
        $cursorDir = self::readEnv('HATFIELD_LLM_REPLAY_CURSOR_DIR');
        if (false === $cursorDir || '' === $cursorDir) {
            return null;
        }

        $cursorDir = rtrim($cursorDir, \DIRECTORY_SEPARATOR);
        if (!is_dir($cursorDir) && !@mkdir($cursorDir, 0777, true) && !is_dir($cursorDir)) {
            throw new \RuntimeException(\sprintf('Cannot create replay fixture cursor directory: %s', $cursorDir));
        }

        $hash = hash('sha256', $fixturePathEnv);

        return $cursorDir.\DIRECTORY_SEPARATOR.'.replay-fixture-cursor-'.$hash;
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     */
    private static function resolveFileBackedFixtureIndex(array $fixtures, string $cursorPath): ?int
    {
        $cursorFile = @fopen($cursorPath, 'c+b');
        if (false === $cursorFile) {
            throw new \RuntimeException(\sprintf('Cannot open/create replay fixture cursor file: %s', $cursorPath));
        }

        if (!flock($cursorFile, \LOCK_EX)) {
            fclose($cursorFile);
            throw new \RuntimeException(\sprintf('Cannot acquire exclusive lock on replay fixture cursor: %s', $cursorPath));
        }

        $content = stream_get_contents($cursorFile);
        $currentIndex = ('' !== $content && false !== $content) ? (int) trim($content) : 0;

        if ($currentIndex >= \count($fixtures)) {
            flock($cursorFile, \LOCK_UN);
            fclose($cursorFile);

            return null;
        }

        ftruncate($cursorFile, 0);
        rewind($cursorFile);
        fwrite($cursorFile, (string) ($currentIndex + 1));
        fflush($cursorFile);
        flock($cursorFile, \LOCK_UN);
        fclose($cursorFile);

        return $currentIndex;
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     */
    private static function serveFixtureResponse(array $fixtures, ?int $fixtureIndex): MockResponse
    {
        if (null === $fixtureIndex) {
            return self::buildExhaustedFallbackResponse();
        }

        return self::buildFixtureMockResponse($fixtures[$fixtureIndex]);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function buildFixtureMockResponse(array $fixture): MockResponse
    {
        $delayMs = $fixture['response_delay_ms'] ?? 0;
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

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
    }

    private static function buildExhaustedFallbackResponse(): MockResponse
    {
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

    /**
     * @param array<string, mixed> $fixture
     */
    private static function isHttpErrorFixture(array $fixture): bool
    {
        return isset($fixture['http_status']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function buildErrorResponse(array $fixture): MockResponse
    {
        $statusCode = (int) $fixture['http_status'];
        $headers = $fixture['response_headers'] ?? [];
        $body = $fixture['response_body'] ?? '{}';

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        return new MockResponse($body, [
            'http_code' => $statusCode,
            'response_headers' => $headers,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $deltas
     * @param array<string, mixed>|null  $usage
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
