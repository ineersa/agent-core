<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Support;

/** A readable regular-file stat followed by a deterministic open failure, without a filesystem race. */
final class ImageOpenFailureStreamWrapper
{
    /** @var resource|null */
    public $context;

    public static function withPath(\Closure $test): void
    {
        $scheme = 'hatfield-image-open-failure';
        if (!stream_wrapper_register($scheme, self::class)) {
            throw new \RuntimeException('Cannot register image open failure test wrapper.');
        }
        try {
            $test($scheme.'://image');
        } finally {
            stream_wrapper_unregister($scheme);
            clearstatcache();
        }
    }

    /** @return array{mode: int, size: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100444, 'size' => 5];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
