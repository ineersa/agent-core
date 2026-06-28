<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

/**
 * A single normalized choice option for the ask_human tool.
 *
 * Every entry includes a label and description (empty string when absent
 * in the original input). An optional value field is preserved when the
 * caller provides a distinct value separate from the display label.
 */
final readonly class AskHumanChoiceDTO
{
    public function __construct(
        public string $label,
        public string $description = '',
        public ?string $value = null,
    ) {
    }
}
