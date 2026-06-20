<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\CodexAccountIdExtractor;
use PHPUnit\Framework\TestCase;

final class CodexAccountIdExtractorTest extends TestCase
{
    public function testExtractsAccountIdFromValidJwt(): void
    {
        $jwt = self::createJwt([
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'chat-abc123def456',
            ],
        ]);

        $result = CodexAccountIdExtractor::extract($jwt);

        self::assertSame('chat-abc123def456', $result);
    }

    public function testReturnsNullWhenClaimPathMissing(): void
    {
        $jwt = self::createJwt([
            'sub' => 'user_123',
        ]);

        $result = CodexAccountIdExtractor::extract($jwt);

        self::assertNull($result);
    }

    public function testReturnsNullWhenAccountIdMissing(): void
    {
        $jwt = self::createJwt([
            'https://api.openai.com/auth' => [
                'sub' => 'user_123',
            ],
        ]);

        $result = CodexAccountIdExtractor::extract($jwt);

        self::assertNull($result);
    }

    public function testReturnsNullOnMalformedToken(): void
    {
        $result = CodexAccountIdExtractor::extract('not-a-jwt');
        self::assertNull($result);
    }

    public function testReturnsNullOnEmptyToken(): void
    {
        $result = CodexAccountIdExtractor::extract('');
        self::assertNull($result);
    }

    public function testReturnsNullOnInvalidBase64Payload(): void
    {
        $jwt = 'header.!!!invalid-base64!!.signature';
        $result = CodexAccountIdExtractor::extract($jwt);
        self::assertNull($result);
    }

    public function testReturnsNullOnNonJsonPayload(): void
    {
        $payloadB64 = self::base64urlEncode('not-json-at-all');
        $jwt = 'header.'.$payloadB64.'.sig';

        $result = CodexAccountIdExtractor::extract($jwt);

        self::assertNull($result);
    }

    /**
     * Build a valid JWT with the given payload.
     */
    private static function createJwt(array $payload): string
    {
        $header = self::base64urlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body = self::base64urlEncode(json_encode($payload));
        $sig = self::base64urlEncode('fake-signature');

        return $header.'.'.$body.'.'.$sig;
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
