<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LibrarianClientTest extends TestCase
{
    public function testLazyConnectionEnsuresOnceAndRetainsDomainErrorsAndAmbiguity(): void
    {
        $calls = [];
        $client = $this->client($calls, static fn (string $tool): array => match ($tool) {
            'ensure_project' => ['ok' => true, 'created' => false, 'index' => ['searchable' => false]],
            'memory_search' => ['ok' => false, 'error' => 'index_unavailable', 'retryable' => true],
            'recall' => ['ok' => false, 'error' => 'ambiguous_id', 'candidates' => [['id' => str_repeat('a', 64), 'content' => 'Actual memory']], 'candidates_truncated' => false],
        });
        $this->assertSame([], $calls);
        $this->assertSame('index_unavailable', $client->call('memory_search', ['query' => 'identifier'])['error']);
        $recall = $client->call('recall', ['id' => str_repeat('a', 12)]);
        $this->assertTrue($recall['isError']);
        $this->assertSame('Actual memory', $recall['candidates'][0]['content']);
        $this->assertSame(['ensure_project', 'memory_search', 'recall'], $calls);
        $client->disconnect();
        $client->call('memory_search', ['query' => 'identifier']);
        $this->assertSame(['ensure_project', 'memory_search', 'recall', 'ensure_project', 'memory_search'], $calls);
    }

    public function testRegistrationFailureIsReturnedWithoutSearchOrReadinessPolling(): void
    {
        $calls = [];
        $client = $this->client($calls, static fn (): array => ['ok' => false, 'error' => 'project_registration_conflict', 'retryable' => false]);
        $this->assertSame('project_registration_conflict', $client->call('memory_search', ['query' => 'identifier'])['error']);
        $this->assertSame(['ensure_project'], $calls);
    }

    public function testTransportFailureResetsAndNextInvocationInitializesOnce(): void
    {
        $calls = [];
        $fail = true;
        $client = $this->client($calls, static function (string $tool) use (&$fail): array {
            if ('memory_search' === $tool && $fail) {
                $fail = false;
                throw new \RuntimeException('private provider detail');
            }

            return ['ok' => true];
        });
        $result = $client->call('memory_search', ['query' => 'private query']);
        $this->assertSame('librarian_unavailable', $result['error']);
        $this->assertStringNotContainsString('private', json_encode($result));
        $this->assertTrue($client->call('memory_search', ['query' => 'identifier'])['ok']);
        $this->assertSame(['ensure_project', 'memory_search', 'ensure_project', 'memory_search'], $calls);
    }

    public function testCancellationDuringHttpStopsAndResetsWithoutRetry(): void
    {
        $calls = [];
        $token = new class implements ToolCancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $client = $this->client($calls, static function (string $tool) use ($token): array {
            if ('memory_search' === $tool) {
                $token->cancelled = true;
            }

            return ['ok' => true];
        });
        $this->assertTrue($client->call('memory_search', ['query' => 'identifier'], $token)['cancelled']);
        $this->assertSame(['ensure_project', 'memory_search'], $calls);
    }

    public function testExpiredBudgetNeverConnects(): void
    {
        $calls = [];
        $client = $this->client($calls, static fn (): array => ['ok' => true]);
        $this->assertTrue($client->call('recall', ['id' => str_repeat('a', 12)], deadlineNs: 0)['timed_out']);
        $this->assertSame([], $calls);
    }

    public function testHttpTimeoutResetsAndEveryExchangeReceivesTheCurrentBudget(): void
    {
        $calls = [];
        $budgets = [];
        $fail = true;
        $client = $this->client($calls, static function (string $tool, array $options) use (&$budgets, &$fail): array {
            $budgets[] = $options['max_duration'];
            if ('memory_search' === $tool && $fail) {
                $fail = false;
                throw new \Symfony\Component\HttpClient\Exception\TimeoutException('Synthetic HTTP deadline');
            }

            return ['ok' => true];
        });
        $this->assertTrue($client->call('memory_search', ['query' => 'identifier'])['timed_out']);
        $this->assertTrue($client->call('memory_search', ['query' => 'identifier'])['ok']);
        $this->assertSame(['ensure_project', 'memory_search', 'ensure_project', 'memory_search'], $calls);
        $this->assertLessThanOrEqual(30, $budgets[0]);
        $this->assertGreaterThan(0, $budgets[2]);
        $this->assertLessThanOrEqual(30, $budgets[2]);
    }

    public function testOversizedStructuredResponseIsNotReturned(): void
    {
        $calls = [];
        $client = $this->client($calls, static fn (string $tool): array => 'ensure_project' === $tool
            ? ['ok' => true] : ['ok' => true, 'content' => str_repeat('x', 1_048_576)]);
        $this->assertSame('librarian_unavailable', $client->call('recall', ['id' => str_repeat('a', 12)])['error']);
    }

    public function testUnicodeAndSlashPayloadBudgetsMatchLibrarianEncoding(): void
    {
        foreach ([str_repeat('Я', 4000), str_repeat('/', 12000)] as $content) {
            $payload = ['ok' => true, 'count' => 50, 'results' => array_fill(0, 50, ['content' => $content])];
            $this->assertGreaterThan(1_048_576, \strlen(json_encode($payload, \JSON_THROW_ON_ERROR)));
            $this->assertLessThan(1_048_576, \strlen(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
            $calls = [];
            $client = $this->client($calls, static fn (string $tool): array => 'ensure_project' === $tool ? ['ok' => true] : $payload);
            $result = $client->call('memory_search', ['query' => 'identifier']);
            $this->assertTrue($result['ok']);
            $this->assertFalse($result['isError']);
            $this->assertSame($payload['results'], $result['results']);
            $this->assertSame(['ensure_project', 'memory_search'], $calls);
        }
    }

    /** @param list<string> $calls */
    private function client(array &$calls, callable $payload): LibrarianClient
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, $payload): MockResponse {
            self::assertSame('http://librarian.test/_mcp', $url);
            self::assertContains('X-Librarian-Project: /canonical/project', $options['headers']);
            self::assertArrayNotHasKey('x-hatfield-session', $options['normalized_headers']);
            if ('DELETE' === $method) {
                return new MockResponse('', ['http_code' => 204]);
            }
            $body = $options['body'];
            $request = json_decode(\is_resource($body) ? stream_get_contents($body) : $body, true, flags: \JSON_THROW_ON_ERROR);
            if (!isset($request['id'])) {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ('server/discover' === $request['method']) {
                return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']], \JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type: application/json']]);
            }
            if ('initialize' === $request['method']) {
                $result = ['protocolVersion' => $request['params']['protocolVersion'], 'serverInfo' => ['name' => 'test-librarian', 'version' => '1.0'], 'capabilities' => ['tools' => new \stdClass()]];
            } else {
                self::assertSame('tools/call', $request['method']);
                $tool = $request['params']['name'];
                $calls[] = $tool;
                if ('ensure_project' === $tool) {
                    self::assertSame([], $request['params']['arguments']);
                }
                $value = $payload($tool, $options);
                $result = ['content' => [['type' => 'text', 'text' => json_encode($value, \JSON_THROW_ON_ERROR)]], 'structuredContent' => $value, 'isError' => false === ($value['ok'] ?? true)];
            }

            return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result], \JSON_THROW_ON_ERROR), [
                'response_headers' => ['Content-Type: application/json', 'Mcp-Session-Id: test-session'],
            ]);
        });

        return new LibrarianClient(new LibrarianSettingsDTO('http://librarian.test/_mcp'), '/canonical/project', new NullLogger(), $http);
    }
}
