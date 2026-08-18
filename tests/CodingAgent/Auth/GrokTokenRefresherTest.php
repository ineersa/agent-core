<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokTokenRefresher;
use PHPUnit\Framework\TestCase;

final class GrokTokenRefresherTest extends TestCase
{
    public function testRefreshPersistsNewAccessAndRefreshTokens(): void
    {
        $expiresAt = time() + 3600;
        $refresher = $this->refresherWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $record = $refresher->refresh('old-refresh');

        $this->assertSame('new-access', $record->access);
        $this->assertSame('new-refresh', $record->refresh);
        $this->assertGreaterThanOrEqual($expiresAt - 5, $record->expires);
        $this->assertLessThanOrEqual($expiresAt + 5, $record->expires);
    }

    public function testRefreshKeepsOldRefreshTokenWhenResponseOmitsIt(): void
    {
        // xAI-specific: refresh responses may omit refresh_token; keep the old one.
        $refresher = $this->refresherWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'rotated-access',
                'expires_in' => 1800,
                'token_type' => 'Bearer',
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $record = $refresher->refresh('keep-me-refresh');

        $this->assertSame('rotated-access', $record->access);
        $this->assertSame('keep-me-refresh', $record->refresh);
    }

    public function testRefreshKeepsOldRefreshTokenWhenResponseReturnsEmptyString(): void
    {
        $refresher = $this->refresherWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'rotated-access-2',
                'refresh_token' => '',
                'expires_in' => 1800,
                'token_type' => 'Bearer',
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $record = $refresher->refresh('keep-empty-refresh');

        $this->assertSame('rotated-access-2', $record->access);
        $this->assertSame('keep-empty-refresh', $record->refresh);
    }

    public function testRefreshFailureThrowsWithAuthHint(): void
    {
        $refresher = $this->refresherWithResponses([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'refresh token expired',
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GrokOAuthConfig::authCommandHint());

        $refresher->refresh('bad-refresh');
    }

    /**
     * @param list<Response> $responses
     */
    private function refresherWithResponses(array $responses): GrokTokenRefresher
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $guzzle = new Client(['handler' => $handler]);

        return new GrokTokenRefresher(GrokOAuthConfig::DEFAULT_PORT, $guzzle);
    }
}
