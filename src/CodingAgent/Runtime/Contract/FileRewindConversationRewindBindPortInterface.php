<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * TUI/runtime seam for binding conversation rewind after extension handlers register.
 */
interface FileRewindConversationRewindBindPortInterface
{
    public function bindConversationRewind(?ConversationRewindPortInterface $port): void;
}
