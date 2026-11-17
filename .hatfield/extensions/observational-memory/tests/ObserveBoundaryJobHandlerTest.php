<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObserveBoundaryJobHandlerTest extends OmTestCase
{
    public function testOutageThenNextSignalUsesLatestBoundaryWithoutRetryOrContent(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('http://librarian.test:8093/api/om/v1/observe', $url);
            $this->assertContains('X-Librarian-Project: '.$this->directory, $options['headers']);
            $calls[] = json_decode($options['body'], true);

            return new MockResponse(json_encode(['ok' => true, 'contract_version' => 1, 'status' => 'queued']), ['http_code' => 1 === \count($calls) ? 503 : 202]);
        });
        $handler = new ObserveBoundaryJobHandler(new NullLogger(), $http);
        $api = $this->api();
        $api->expects($this->never())->method('dispatchExtensionAgentJob');
        $handler->handle($api, ['run_id' => 'session', 'terminal_end_seq' => 9, 'terminal_status' => 'completed'], null, null);
        $handler->handle($api, ['run_id' => 'session', 'terminal_end_seq' => 148, 'terminal_status' => 'failed'], null, null);
        $this->assertSame([
            ['contract_version' => 1, 'source_kind' => 'hatfield', 'session_id' => 'session', 'parent_session_id' => null, 'end_seq' => 9, 'terminal_status' => 'completed'],
            ['contract_version' => 1, 'source_kind' => 'hatfield', 'session_id' => 'session', 'parent_session_id' => null, 'end_seq' => 148, 'terminal_status' => 'failed'],
        ], $calls);
        $this->assertSame([], glob($this->directory.'/*'));
    }

    #[DataProvider('excluded')]
    public function testExcludedOrInvalidJobsNeverConnect(bool $child, bool $worktree, array $payload): void
    {
        $http = new MockHttpClient(function (): never { $this->fail('Excluded job contacted Librarian.'); });
        (new ObserveBoundaryJobHandler(new NullLogger(), $http))->handle($this->api($child, $worktree), $payload, null, null);
        $this->assertSame(0, $http->getRequestsCount());
    }

    public static function excluded(): iterable
    {
        $payload = ['run_id' => 'session', 'terminal_end_seq' => 148, 'terminal_status' => 'completed'];
        yield 'child/fork' => [true, false, $payload];
        yield 'worktree' => [false, true, $payload];
        yield 'invalid' => [false, false, ['terminal_end_seq' => -1]];
    }
}
