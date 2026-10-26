<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

final readonly class BackgroundWorkSnapshotDTO
{
    /**
     * @param list<BackgroundChildStatusDTO>    $children
     * @param list<BackgroundWorkDTO>           $work
     * @param list<ChildBackgroundCandidateDTO> $codeMode
     */
    public function __construct(public array $children, public array $work, public array $codeMode = [])
    {
    }
}
