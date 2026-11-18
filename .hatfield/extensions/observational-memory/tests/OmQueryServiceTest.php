<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OmQueryServiceTest extends OmTestCase
{
    #[DataProvider('viewResponses')]
    public function testViewDistinguishesEmptyUnavailableAndExactContent(string $body, int $status, string $expected): void
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
        $query = $this->query($this->api(), $http);
        $this->assertSame($expected, $query->formatView('session'));
    }

    public static function viewResponses(): iterable
    {
        yield 'empty' => ['{"ok":true,"contract_version":1,"text":""}', 200, 'No observational memory for this session.'];
        yield 'whitespace' => ['{"ok":true,"contract_version":1,"text":"  \\n"}', 200, 'No observational memory for this session.'];
        yield 'error' => ['{}', 503, 'OM view unavailable.'];
        yield 'nonempty exact' => ['{"ok":true,"contract_version":1,"text":"  memory\\n"}', 200, "  memory\n"];
    }

    #[DataProvider('invalidSearches')]
    public function testSearchValidationWithoutNetwork(string $query, ?string $after, ?string $before, ?int $limit, string $error): void
    {
        $http = new MockHttpClient();
        $queryService = $this->query($this->api(), $http);
        $this->assertSame($error, $queryService->search($query, $after, $before, $limit)['error']);
        $this->assertSame(0, $http->getRequestsCount());
    }

    public static function invalidSearches(): iterable
    {
        yield 'blank' => [' ', null, null, null, 'invalid_query'];
        yield 'invalid date' => ['q', '2026-02-30', null, null, 'invalid_after'];
        yield 'invalid format' => ['q', null, 'yesterday', null, 'invalid_before'];
        yield 'reverse range' => ['q', '2026-02-02', '2026-02-01', null, 'invalid_date_range'];
        yield 'limit' => ['q', null, null, 0, 'invalid_limit'];
    }

    public function testChildrenAndWorktreesNeverRetrieveOrProject(): void
    {
        $child = $this->query($this->api(child: true));
        $this->assertSame('main_only', $child->search('query', runId: 'child')['error']);
        $this->assertSame('main_only', $child->recall('child', str_repeat('a', 12))['error']);
        $this->assertNull($child->projection('child'));
        $http = new MockHttpClient();
        $worktree = $this->query($this->api(worktree: true), $http);
        $this->assertSame('om_unavailable', $worktree->search('query')['error']);
        $this->assertSame('om_unavailable', $worktree->recall('main', str_repeat('a', 12))['error']);
        $this->assertNull($worktree->projection('main'));
        $this->assertSame(0, $http->getRequestsCount());
    }
}
