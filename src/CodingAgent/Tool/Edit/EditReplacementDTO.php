<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

final readonly class EditReplacementDTO
{
    /**
     * @param list<string> $newLines
     */
    public function __construct(
        public int $startIndex,
        public int $oldLength,
        public array $newLines,
    ) {
    }
}
