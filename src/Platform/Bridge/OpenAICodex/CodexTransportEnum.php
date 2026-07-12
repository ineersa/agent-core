<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

enum CodexTransportEnum: string
{
    case Websocket = 'websocket';
    case Sse = 'sse';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            self::Websocket->value => self::Websocket,
            self::Sse->value => self::Sse,
            default => throw new \InvalidArgumentException(\sprintf('Invalid Codex transport "%s". Allowed values: websocket, sse.', $value)),
        };
    }

    public static function default(): self
    {
        return self::Websocket;
    }
}
