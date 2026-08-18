<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use PHPUnit\Framework\TestCase;

final class GrokOAuthConfigTest extends TestCase
{
    public function testConstants(): void
    {
        $this->assertSame('b1a00492-073a-47ea-816f-4c329264a828', GrokOAuthConfig::CLIENT_ID);
        $this->assertSame('https://auth.x.ai/oauth2/authorize', GrokOAuthConfig::AUTHORIZE_URL);
        $this->assertSame('https://auth.x.ai/oauth2/token', GrokOAuthConfig::TOKEN_URL);
        $this->assertSame('grok-cli', GrokOAuthConfig::PROVIDER_KEY);
        $this->assertSame('/callback', GrokOAuthConfig::CALLBACK_PATH);
        $this->assertSame(56122, GrokOAuthConfig::DEFAULT_PORT);
        $this->assertSame('bin/console auth:grok', GrokOAuthConfig::authCommandHint());
    }

    public function testRedirectUriUsesCallbackPath(): void
    {
        $this->assertSame('http://localhost:56122/callback', GrokOAuthConfig::redirectUriForPort());
        $this->assertSame('http://localhost:9999/callback', GrokOAuthConfig::redirectUriForPort(9999));
    }

    public function testProviderOptions(): void
    {
        $opts = GrokOAuthConfig::providerOptions(56122);

        $this->assertSame(GrokOAuthConfig::CLIENT_ID, $opts['clientId']);
        $this->assertSame('', $opts['clientSecret']);
        $this->assertSame('http://localhost:56122/callback', $opts['redirectUri']);
        $this->assertSame(GrokOAuthConfig::AUTHORIZE_URL, $opts['urlAuthorize']);
        $this->assertSame(GrokOAuthConfig::TOKEN_URL, $opts['urlAccessToken']);
        $this->assertSame('S256', $opts['pkceMethod']);
    }
}
