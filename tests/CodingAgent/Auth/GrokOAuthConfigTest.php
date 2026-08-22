<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use PHPUnit\Framework\TestCase;

final class GrokOAuthConfigTest extends TestCase
{
    public function testRedirectUriUsesCallbackPath(): void
    {
        $this->assertSame('http://127.0.0.1:56122/callback', GrokOAuthConfig::redirectUriForPort());
        $this->assertSame('http://127.0.0.1:9999/callback', GrokOAuthConfig::redirectUriForPort(9999));
    }

    public function testProviderOptions(): void
    {
        $opts = GrokOAuthConfig::providerOptions(56122);

        $this->assertSame(GrokOAuthConfig::CLIENT_ID, $opts['clientId']);
        $this->assertSame('', $opts['clientSecret']);
        $this->assertSame('http://127.0.0.1:56122/callback', $opts['redirectUri']);
        $this->assertSame(GrokOAuthConfig::AUTHORIZE_URL, $opts['urlAuthorize']);
        $this->assertSame(GrokOAuthConfig::TOKEN_URL, $opts['urlAccessToken']);
        $this->assertSame('S256', $opts['pkceMethod']);
    }
}
