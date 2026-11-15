<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\DTO;

final readonly class ExtensionUpdatePlanDTO
{
    /**
     * @param list<array{package: string, current: string, target: string}> $changes
     * @param list<string>                                                  $installCommand
     * @param list<string>                                                  $aliases
     */
    public function __construct(
        public string $scope,
        public string $root,
        public string $stateHash,
        public string $lockContents,
        public array $changes,
        public array $installCommand,
        public bool $devMode,
        public array $aliases,
    ) {
    }
}
