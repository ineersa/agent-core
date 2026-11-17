<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianOmClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LibrarianOmClientTest extends TestCase
{
    #[DataProvider('tokens')]
    public function testSharedAuthenticationAndBoundedOriginRequests(?string $token): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($token, &$calls): MockResponse {
            $calls[] = $url;
            self::assertContains('X-Librarian-Project: /canonical/project', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(7.0, $options['max_duration']);
            if (null !== $token) {
                self::assertContains('Authorization: Bearer '.$token, $options['headers']);
            } else {
                self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            }

            return new MockResponse('POST' === $method ? '{"ok":true,"contract_version":1,"status":"queued"}' : '{"ok":true,"contract_version":1,"text":"memory"}', ['http_code' => 'POST' === $method ? 202 : 200]);
        });
        $client = new LibrarianOmClient(new LibrarianSettingsDTO('https://librarian.test:8093/prefix/_mcp', 7, $token), '/canonical/project', new NullLogger(), $http);
        $client->observe('session', 77, 'completed');
        $this->assertSame('memory', $client->projection('session'));
        $this->assertSame(['https://librarian.test:8093/api/om/v1/observe', 'https://librarian.test:8093/api/om/v1/sessions/hatfield/session/projection'], $calls);
    }

    #[DataProvider('deletionResponses')]
    public function testDeletionContract(string $json, int $status, bool $fails): void
    {
        $logger = new TestLogger();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($json, $status): MockResponse {
            self::assertSame('DELETE', $method);
            self::assertSame('https://librarian.test/api/om/v1/sessions/hatfield/a%2Fb', $url);
            self::assertArrayNotHasKey('body', $options);
            self::assertContains('X-Librarian-Project: /target', $options['headers']);
            self::assertContains('Authorization: Bearer fixture-token', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(7.0, $options['max_duration']);

            return new MockResponse($json, ['http_code' => $status]);
        });
        (new LibrarianOmClient(new LibrarianSettingsDTO('https://librarian.test/_mcp', 7, 'fixture-token'), '/target', $logger, $http))->deleteSession('a/b');
        $this->assertCount($fails ? 1 : 0, $logger->records);
        if ($fails) {
            $this->assertSame('om.librarian.delete_failed', $logger->records[0]['message']);
            $this->assertSame('a/b', $logger->records[0]['context']['run_id']);
        }
    }

    public function testDeletionTransportFailureIsLoggedWithoutCredentials(): void
    {
        $logger = new TestLogger();
        $http = new MockHttpClient(new MockResponse('', ['error' => 'private transport details']));
        (new LibrarianOmClient(new LibrarianSettingsDTO('https://librarian.test/_mcp', 1), '/target', $logger, $http))->deleteSession('42');
        $this->assertCount(1, $logger->records);
        $this->assertNull($logger->records[0]['context']['http_status']);
        $this->assertStringNotContainsString('private transport details', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public static function deletionResponses(): iterable
    {
        yield 'deleted' => ['{"ok":true,"contract_version":1,"deleted":true}', 200, false];
        yield 'absent' => ['{"ok":true,"contract_version":1,"deleted":false}', 200, false];
        yield 'missing' => ['{"ok":true,"contract_version":1}', 200, true];
        yield 'null' => ['{"ok":true,"contract_version":1,"deleted":null}', 200, true];
        yield 'string' => ['{"ok":true,"contract_version":1,"deleted":"false"}', 200, true];
        yield 'malformed' => ['{', 200, true];
        yield 'http' => ['{}', 503, true];
    }

    public static function tokens(): iterable
    {
        yield 'anonymous' => [null];
        yield 'authenticated' => ['fixture-token'];
    }
}
