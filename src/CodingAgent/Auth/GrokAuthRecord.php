<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

/**
 * Immutable value object holding Grok CLI OAuth credentials.
 *
 * Maps to the per-provider entry in auth.json:
 *   grok-cli => { type, access, refresh, expires }
 *
 * No accountId — xAI tokens are not account-scoped the way Codex is.
 */
final readonly class GrokAuthRecord
{
    public string $type;

    public function __construct(
        public string $access,
        public string $refresh,
        public int $expires,
        string $type = 'oauth',
    ) {
        $this->type = $type;
    }

    /**
     * Whether the access token is expired (or within 60s of expiry).
     *
     * @param int $bufferSeconds Grace period before actual expiry
     */
    public function isExpired(int $bufferSeconds = 60): bool
    {
        return (time() + $bufferSeconds) >= $this->expires;
    }

    /**
     * Create a record from the auth.json entry array.
     *
     * @param array{access?: string, refresh?: string, expires?: int, type?: string} $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['access'], $data['refresh'])) {
            throw new \InvalidArgumentException('Grok auth record missing required fields: access, refresh');
        }

        return new self(
            access: (string) $data['access'],
            refresh: (string) $data['refresh'],
            expires: (int) ($data['expires'] ?? 0),
            type: (string) ($data['type'] ?? 'oauth'),
        );
    }

    /**
     * Serialize to an array suitable for auth.json storage.
     *
     * @return array{type: string, access: string, refresh: string, expires: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'access' => $this->access,
            'refresh' => $this->refresh,
            'expires' => $this->expires,
        ];
    }
}
