<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\OpenCodeSessionHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenCodeSessionHttpClientTest extends TestCase
{
    public function testSessionHeaderFollowsRunAcrossRequestsWithoutLeakingIntoNextRun(): void
    {
        $headers = [];
        $client = new OpenCodeSessionHttpClient(new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers[] = $options['normalized_headers']['x-opencode-session'][0];
            self::assertSame('x-opencode-client: hatfield', $options['normalized_headers']['x-opencode-client'][0]);
            self::assertSame('User-Agent: hatfield', $options['normalized_headers']['user-agent'][0]);

            return new MockResponse('{}');
        }));
        $token = $this->createStub(CancellationTokenInterface::class);
        foreach (['run-1', 'run-1', 'run-2'] as $runId) {
            LlmInvocationCancelScope::enter($token, $runId);
            try {
                $client->request('POST', 'https://opencode.ai/zen/go/v1/chat/completions')->getContent();
            } finally {
                LlmInvocationCancelScope::leave();
            }
        }

        $this->assertSame(['x-opencode-session: run-1', 'x-opencode-session: run-1', 'x-opencode-session: run-2'], $headers);
        $this->assertNull(LlmInvocationCancelScope::currentRunId());
    }

    public function testMissingRunIdFailsBeforeNetworkRequest(): void
    {
        $client = new OpenCodeSessionHttpClient(new MockHttpClient(static function (): MockResponse {
            self::fail('OpenCode Go request must not be sent without a run ID.');
        }));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenCode Go requires an active run ID');
        $client->request('POST', 'https://opencode.ai/zen/go/v1/chat/completions');
    }

    public function testUnresolvedKeyReferenceFailsOnRequestBeforeNetwork(): void
    {
        $client = new OpenCodeSessionHttpClient(new MockHttpClient(static function (): MockResponse {
            self::fail('OpenCode Go request must not be sent without the configured API key.');
        }), 'OPENCODE_GO_API_KEY');
        $client = $client->withOptions(['timeout' => 5]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OPENCODE_GO_API_KEY is not set');
        $client->request('POST', 'https://opencode.ai/zen/go/v1/chat/completions');
    }
}
