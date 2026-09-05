<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Assets;

final class JbcontextManagedMarker
{
    public const string MARKER = 'managed-by: hatfield-ext-jbcontext';

    public static function isManaged(string $contents): bool
    {
        return str_contains($contents, self::MARKER);
    }

    public static function markerLine(): string
    {
        return '# '.self::MARKER;
    }
}
