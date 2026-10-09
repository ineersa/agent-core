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
