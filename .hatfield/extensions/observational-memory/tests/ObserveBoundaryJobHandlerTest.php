<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObserveBoundaryJobHandlerTest extends OmTestCase
{
    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidCorrelations')]
    public function testRejectedPayloadLogsOnlySafeCorrelation(array $payload, ?string $correlationId, ?string $expectedRun, ?int $expectedSeq): void
    {
        $logger = new TestLogger();
        $http = new MockHttpClient();
        $api = $this->api();
        $api->expects($this->never())->method('isChildRun');
        $api->expects($this->never())->method('getCwd');
        (new ObserveBoundaryJobHandler($logger, $http))->handle($api, $payload, 'queued-job', $correlationId);
        $this->assertSame(0, $http->getRequestsCount());
        $this->assertSame([[
            'level' => 'warning',
            'message' => 'om.observe.invalid_job',
            'context' => [
                'component' => 'observational_memory', 'event_type' => 'om.observe.invalid_job',
                'run_id' => $expectedRun, 'session_id' => $expectedRun, 'job_id' => 'queued-job',
                'end_seq' => $expectedSeq,
            ],
        ]], $logger->records);
    }

    public static function invalidCorrelations(): iterable
    {
        yield 'extra field prefers envelope' => [['run_id' => 'payload-run', 'terminal_end_seq' => 148, 'terminal_status' => 'completed', 'old_field' => 'Bearer private-secret'], 'owner-run', 'owner-run', 148];
        yield 'status with payload correlation' => [['run_id' => 'run-1', 'terminal_end_seq' => 9, 'terminal_status' => 'Bearer private-secret'], null, 'run-1', 9];
        yield 'missing run without correlation' => [['terminal_end_seq' => 9, 'terminal_status' => 'completed'], null, null, 9];
        yield 'invalid run type' => [['run_id' => ['secret'], 'terminal_end_seq' => '9', 'terminal_status' => 'completed'], null, null, null];
        yield 'unsafe strings' => [['run_id' => 'Bearer private-secret', 'terminal_end_seq' => -1, 'terminal_status' => 'completed'], "unsafe\ncorrelation", null, null];
        yield 'old payload with safe envelope' => [['boundary_key' => 'private-secret'], 'owner-run', 'owner-run', null];
    }

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
