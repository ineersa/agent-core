<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

interface FileRewindConversationRewindBindableInterface
{
    public function bindConversationRewind(?ConversationRewindPortInterface $port): void;
}
