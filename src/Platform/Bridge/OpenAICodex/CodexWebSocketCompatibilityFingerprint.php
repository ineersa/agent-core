<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Privacy-safe cache entry identity: session correlation plus hashed provider/auth/model context.
 */
final readonly class CodexWebSocketCompatibilityFingerprint
{
    public function __construct(
        public string $sessionKey,
        public string $fingerprint,
    ) {
        if ('' === $this->sessionKey) {
            throw new \InvalidArgumentException('Codex WebSocket cache session key must not be empty.');
        }
        if ('' === $this->fingerprint) {
            throw new \InvalidArgumentException('Codex WebSocket cache fingerprint must not be empty.');
        }
    }

    public static function fromContext(
        string $sessionKey,
        string $providerId,
        string $modelName,
        string $baseUrl,
        string $responsesPath,
        string $accountId,
        #[\SensitiveParameter] string $accessToken,
    ): self {
        $material = json_encode([
            'provider_id' => $providerId,
            'model' => $modelName,
            'base_url' => $baseUrl,
            'responses_path' => $responsesPath,
            'account_id' => $accountId,
            'access_token' => $accessToken,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return new self($sessionKey, hash('sha256', $material));
    }
}
