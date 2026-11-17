<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

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

    public static function tokens(): iterable
    {
        yield 'anonymous' => [null];
        yield 'authenticated' => ['fixture-token'];
    }
}
