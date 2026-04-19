<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Api\Http;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunAccessStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Kernel\TestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversNothing]
final class RunApiControllerTest extends TestCase
{
    private static ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->commandTransport()->reset();
    }

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new TestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    public function testStartRunEndpointReturnsQueuedHandleAndStreamTopic(): void
    {
        $response = $this->jsonRequest('POST', '/agent/runs', [
            'prompt' => 'Inspect this codebase.',
            'model' => 'gpt-4o-mini',
            'session' => ['locale' => 'en'],
            'tools_scope' => ['allow' => ['web_search']],
        ]);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());

        $payload = $this->decodeResponse($response);
        self::assertIsString($payload['run_id'] ?? null);
        self::assertSame('queued', $payload['status'] ?? null);
        self::assertSame('agent/runs/'.$payload['run_id'], $payload['stream_topic'] ?? null);

        $scope = $this->serviceContainer()->get(RunAccessStoreInterface::class)->get($payload['run_id']);
        self::assertNotNull($scope);
        self::assertSame('tenant-test', $scope->tenantId);
        self::assertSame('user-test', $scope->userId);

        $sent = $this->commandTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(StartRun::class, $sent[0]->getMessage());
        self::assertSame($payload['run_id'], $sent[0]->getMessage()->runId());
    }

    public function testCommandEndpointRejectsUnknownOptions(): void
    {
        $runId = $this->startRun();
        $this->commandTransport()->reset();

        $response = $this->jsonRequest('POST', sprintf('/agent/runs/%s/commands', $runId), [
            'kind' => 'ext:demo:noop',
            'payload' => [],
            'idempotency_key' => 'api-command-1',
            'options' => ['unknown' => true],
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = $this->decodeResponse($response);
        self::assertStringContainsString('Unknown command options', (string) ($payload['error'] ?? ''));
        self::assertCount(0, $this->commandTransport()->getSent());
    }

    public function testRunSummaryRequiresMatchingActor(): void
    {
        $runId = $this->startRun();

        $forbidden = $this->jsonRequest(
            'GET',
            sprintf('/agent/runs/%s', $runId),
            null,
            ['X-Agent-Tenant-Id' => 'tenant-test', 'X-Agent-User-Id' => 'other-user'],
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

        $authorized = $this->jsonRequest('GET', sprintf('/agent/runs/%s', $runId));
        self::assertSame(Response::HTTP_OK, $authorized->getStatusCode());

        $payload = $this->decodeResponse($authorized);
        self::assertSame($runId, $payload['run_id'] ?? null);
        self::assertSame('queued', $payload['status'] ?? null);
        self::assertArrayHasKey('waiting_flags', $payload);
        self::assertSame('agent/runs/'.$runId, $payload['stream_topic'] ?? null);
    }

    public function testTranscriptPaginationReturnsPagedMessages(): void
    {
        $runId = $this->startRun();

        $runStore = $this->serviceContainer()->get(RunStoreInterface::class);
        self::assertTrue($runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 2,
            lastSeq: 4,
            messages: [
                new AgentMessage('user', [['type' => 'text', 'text' => 'hello']]),
                new AgentMessage('assistant', [['type' => 'text', 'text' => 'world']]),
            ],
        ), 0));

        $response = $this->jsonRequest('GET', sprintf('/agent/runs/%s/messages?cursor=1&limit=1', $runId));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = $this->decodeResponse($response);
        self::assertSame($runId, $payload['run_id'] ?? null);
        self::assertSame('1', $payload['cursor'] ?? null);
        self::assertSame(null, $payload['next_cursor'] ?? null);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('assistant', $payload['items'][0]['role'] ?? null);
    }

    public function testReplayEndpointReturnsResyncRequiredEventWhenGapDetected(): void
    {
        $runId = $this->startRun();

        $eventStore = $this->serviceContainer()->get(EventStoreInterface::class);
        $eventStore->appendMany([
            new RunEvent(
                runId: $runId,
                seq: 1,
                turnNo: 1,
                type: 'message_update',
                payload: ['delta' => 'foo'],
                createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
            ),
            new RunEvent(
                runId: $runId,
                seq: 3,
                turnNo: 1,
                type: 'turn_end',
                payload: [],
                createdAt: new \DateTimeImmutable('2026-04-12T12:00:01+00:00'),
            ),
        ]);

        $response = $this->jsonRequest(
            'GET',
            sprintf('/agent/runs/%s/events', $runId),
            null,
            ['Last-Event-ID' => '1'],
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = $this->decodeResponse($response);
        self::assertTrue((bool) ($payload['resync_required'] ?? false));
        self::assertSame(sprintf('/agent/runs/%s/messages', $runId), $payload['reload_endpoint'] ?? null);
        self::assertCount(1, $payload['events'] ?? []);
        self::assertSame('resync_required', $payload['events'][0]['type'] ?? null);
    }

    private function startRun(): string
    {
        $response = $this->jsonRequest('POST', '/agent/runs', [
            'prompt' => 'start run for api test',
            'session' => ['locale' => 'en'],
        ]);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());

        $payload = $this->decodeResponse($response);
        self::assertIsString($payload['run_id'] ?? null);

        return $payload['run_id'];
    }

    /**
     * @param array<string, mixed>|null  $payload
     * @param array<string, string>|null $headers
     */
    private function jsonRequest(string $method, string $uri, ?array $payload = null, ?array $headers = null): Response
    {
        $headers ??= [];

        $server = [];
        foreach (array_merge([
            'X-Agent-Tenant-Id' => 'tenant-test',
            'X-Agent-User-Id' => 'user-test',
        ], $headers) as $header => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($header))] = $value;
        }

        $content = null;
        if (null !== $payload) {
            $server['CONTENT_TYPE'] = 'application/json';
            $encoded = json_encode($payload);
            self::assertNotFalse($encoded);
            $content = $encoded;
        }

        $request = Request::create(
            uri: $uri,
            method: $method,
            server: $server,
            content: $content,
        );

        self::assertNotNull(self::$kernel);

        return self::$kernel->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $decoded = json_decode($response->getContent() ?: '{}', true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function commandTransport(): InMemoryTransport
    {
        $transport = $this->serviceContainer()->get('messenger.transport.agent_loop.command');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function serviceContainer(): ContainerInterface
    {
        self::assertNotNull(self::$kernel);

        $container = self::$kernel->getContainer();

        if ($container->has('test.service_container')) {
            $testContainer = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);

            return $testContainer;
        }

        return $container;
    }
}
