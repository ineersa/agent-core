<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

final class HookDiagnostic
{
    /** @param 'hook_input_failed'|'hook_state_failed' $event */
    public static function write(string $event): void
    {
        fwrite(\STDERR, json_encode(['component' => 'hatfield_360', 'event_type' => $event], \JSON_THROW_ON_ERROR)."\n");
    }
}
