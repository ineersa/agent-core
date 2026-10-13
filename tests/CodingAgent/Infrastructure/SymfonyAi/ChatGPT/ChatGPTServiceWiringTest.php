<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\ChatGPT;

use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ChatGPT\ChatGPTSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthCommand;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

final class ChatGPTServiceWiringTest extends PerMethodIsolatedKernelTestCase
{
    public function testHostProviderCompressesWithoutChangingHistoryOrRoutingHeaders(): void
    {
        $container = self::getContainer();
        $record = \Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture::record();
        $storage = $this->createStub(AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static fn (callable $update) => $update($record));
        $container->set(AuthStorageInterface::class, $storage);
        $text = implode('', array_map(static fn (int $index): string => hash('sha256', (string) $index), range(1, 256)));
        $text .= $text;
        $requests = 0;
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url, array $options) use ($text, &$requests): \Symfony\Component\HttpClient\Response\MockResponse {
            ++$requests;
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);
            $headers = $options['normalized_headers'];
            self::assertSame(['Originator: Hatfield'], $headers['originator']);
            self::assertSame(['session-id: synthetic-cache-key'], $headers['session-id']);
            self::assertSame(['x-client-request-id: synthetic-cache-key'], $headers['x-client-request-id']);
            self::assertSame(['Content-Encoding: zstd'], $headers['content-encoding']);
            self::assertSame(['Content-Type: application/json'], $headers['content-type']);
            self::assertSame(['Accept: text/event-stream'], $headers['accept']);
            self::assertSame(['Authorization: Bearer synthetic-access'], $headers['authorization']);
            self::assertSame(['X-Host-Proof: unchanged'], $headers['x-host-proof']);
            $json = zstd_uncompress($options['body']);
            self::assertIsString($json);
            self::assertSame(zstd_compress($json, 3), $options['body']);
            self::assertLessThan(\strlen($json), \strlen($options['body']));
            self::assertLessThanOrEqual(100 * \strlen($options['body']), \strlen($json));
            $body = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('synthetic-cache-key', $body['prompt_cache_key']);
            self::assertSame($text, $body['input'][0]['content']);
            self::assertFalse($body['store']);
            self::assertTrue($body['stream']);

            return new \Symfony\Component\HttpClient\Response\MockResponse("data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[]}}\n\n", ['response_headers' => ['content-type: text/event-stream']]);
        });
        $provider = $container->get(ChatGPTSymfonyAiProviderBuilder::class)->build(
            new \Ineersa\CodingAgent\Config\Ai\AiProviderConfig('openai-codex', type: 'chatgpt', models: ['gpt-6.1-sol' => new \Ineersa\CodingAgent\Config\Ai\AiModelDefinition('gpt-6.1-sol', toolCalling: true, reasoning: true)]),
            $http->withOptions(['headers' => ['X-Host-Proof' => 'unchanged']]),
        );
        $result = $provider->invoke('openai-codex/gpt-6.1-sol', new \Symfony\AI\Platform\Message\MessageBag(\Symfony\AI\Platform\Message\Message::ofUser($text)), ['prompt_cache_key' => 'synthetic-cache-key']);
        foreach ($result->getResult()->getContent() as $chunk) {
            // Consume the real package stream so the HTTP boundary assertions execute.
        }
        $this->assertSame(1, $requests);
    }

    public function testOAuthAndPendingIdentityVerifierUseTheSameInjectedClock(): void
    {
        $container = self::getContainer();
        $clock = new \Symfony\Component\Clock\MockClock('2100-01-01T00:00:00Z');
        $container->set(\Symfony\Component\Clock\ClockInterface::class, $clock);
        $record = \Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture::record();
        $storage = $this->createStub(AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static function (callable $update) use (&$record) {
            return $record = $update($record);
        });
        $container->set(AuthStorageInterface::class, $storage);
        $requests = [];
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url) use (&$requests): \Symfony\Component\HttpClient\Response\MockResponse {
            $requests[] = [$method, $url];
            if ('POST' === $method) {
                return new \Symfony\Component\HttpClient\Response\MockResponse(json_encode(['access_token' => 'rotated-synthetic-access', 'refresh_token' => 'rotated-synthetic-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => \Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig::SCOPE], \JSON_THROW_ON_ERROR));
            }

            return new \Symfony\Component\HttpClient\Response\MockResponse('{}', ['http_code' => 503]);
        });
        $container->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $http);
        $updated = $container->get(\Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService::class)->refreshCredentials();
        $this->assertSame($clock->now()->getTimestamp() + 3600, $updated->expires);
        $token = rtrim(strtr(base64_encode('{"alg":"RS256","kid":"synthetic-key"}'), '+/', '-_'), '=').'.e30.invalid';
        try {
            $container->get(\Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier::class)->verifyReceived($token, $record->clientId, $record->nonce, $clock->now()->getTimestamp());
            $this->fail('An unavailable signing key must still prevent identity verification.');
        } catch (\Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException $error) {
            $this->assertSame('ChatGPT ID-token signature verification failed.', $error->getMessage());
        }
        $this->assertSame([['POST', 'https://auth.openai.com/api/accounts/oauth/token'], ['GET', 'https://auth.openai.com/.well-known/jwks.json']], $requests);
    }

    public function testFreshKernelRegistersAuthCommandAndHostStorageBeforeLogin(): void
    {
        $container = self::getContainer();
        $loader = $container->get('console.command_loader');
        $this->assertInstanceOf(CommandLoaderInterface::class, $loader);
        $this->assertTrue($loader->has('auth:chatgpt'));
        $this->assertFalse($loader->has('auth:codex'));
        // Constructing these services must not read a grant or require network
        // access. Their storage operations happen only on explicit auth/inference.
        $this->assertInstanceOf(AuthFileStore::class, $container->get(AuthStorageInterface::class));
        $this->assertInstanceOf(AuthCommand::class, $container->get(AuthCommand::class));
        $this->assertInstanceOf(ChatGPTSymfonyAiProviderBuilder::class, $container->get(ChatGPTSymfonyAiProviderBuilder::class));
    }
}
