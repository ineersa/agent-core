<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Websocket\Client\WebsocketConnection;

/**
 * Result of acquiring a Codex WebSocket for one request.
 */
final readonly class CodexWebSocketCacheLease
{
    public function __construct(
        public WebsocketConnection $connection,
        public bool $reused,
        public bool $cached,
        public bool $oneShot,
        public ?CodexWebSocketCacheEntry $entry,
    ) {
    }
}
