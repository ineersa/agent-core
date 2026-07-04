<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

final class FileRewindPickerRegistry
{
    private static ?FileRewindPickerController $picker = null;

    public static function set(FileRewindPickerController $picker): void
    {
        self::$picker = $picker;
    }

    public static function get(): ?FileRewindPickerController
    {
        return self::$picker;
    }
}
