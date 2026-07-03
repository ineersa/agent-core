<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

final readonly class McpServerHeaderEntry
{
    public function __construct(
        public string $name,
        public ?int $toolCount,
        public bool $isConnected,
        public bool $isGlobal,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->toolCount === $other->toolCount
            && $this->isConnected === $other->isConnected
            && $this->isGlobal === $other->isGlobal;
    }
}
