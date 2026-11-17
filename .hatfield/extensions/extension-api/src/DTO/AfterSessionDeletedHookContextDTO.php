<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\DTO;

final readonly class AfterSessionDeletedHookContextDTO
{
    public function __construct(public string $runId, public string $cwd, public ?string $parentRunId)
    {
    }
}
