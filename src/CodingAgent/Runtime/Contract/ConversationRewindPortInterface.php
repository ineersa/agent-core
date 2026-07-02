<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

interface ConversationRewindPortInterface
{
    public function rewindToTurn(int $turnNo): void;
}
