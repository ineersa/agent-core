<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

final readonly class McpServerHeaderEntry
{
    public function __construct(
        public string $name,
        public string $icon,
        public ?int $toolCount,
        public string $statusText,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->icon === $other->icon
            && $this->toolCount === $other->toolCount
            && $this->statusText === $other->statusText;
    }
}
