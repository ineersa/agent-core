<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Support;

final class JournalWorkDecodeCounter
{
    public static int $decodes = 0;

    public function __unserialize(array $data): void
    {
        ++self::$decodes;
    }
}
