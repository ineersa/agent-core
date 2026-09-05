<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tool;

use HelgeSverre\Toon\Toon;

/**
 * Top-level TOON builder for code_search results.
 */
final class JbcontextToolResult
{
    /**
     * @param array<string, mixed> $details
     */
    public static function structured(array $details): string
    {
        return Toon::encode($details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function unavailable(string $message, array $details = []): string
    {
        return Toon::encode(array_merge([
            'available' => false,
            'message' => $message,
        ], $details));
    }
}
