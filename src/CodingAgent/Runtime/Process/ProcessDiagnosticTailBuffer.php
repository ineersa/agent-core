<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

final class ProcessDiagnosticTailBuffer
{
    private const int MAX_BYTES = 16_384;

    public static function append(string $tail, string $chunk): string
    {
        if (\strlen($tail) + \strlen($chunk) <= self::MAX_BYTES) {
            return $tail.$chunk;
        }

        // A large chunk already contains the entire retained suffix. Do not copy its discarded prefix.
        $source = \strlen($chunk) >= self::MAX_BYTES ? $chunk : $tail.$chunk;
        $start = \strlen($source) - self::MAX_BYTES;
        // Skip leading UTF-8 continuation bytes, but retain the raw end:
        // the next process chunk may complete its final code point.
        while (isset($source[$start]) && (\ord($source[$start]) & 0xC0) === 0x80) {
            ++$start;
        }

        return substr($source, $start);
    }
}
