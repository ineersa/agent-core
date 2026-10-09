<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ChatGPTAuthFixture
{
    public static function record(string $access = 'synthetic-access'): AuthRecord
    {
        return new AuthRecord('synthetic-client', $access, 'synthetic-refresh', time() + 3600, 'synthetic-id-token', OAuthConfig::ISSUER, 'synthetic-subject', [OAuthConfig::DIRECT_SCOPE], 'synthetic-nonce');
    }

    public static function service(AuthStorageInterface $storage, HttpClientInterface $client): OAuthService
    {
        return new OAuthService($storage, $client, new IdTokenVerifier($client), new OAuthConfig('Hatfield test'));
    }
}
