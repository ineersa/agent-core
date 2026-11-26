<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

use Ineersa\Hatfield\ExtensionApi\Session\DTO\ScheduleDTO;

final readonly class BackgroundWorkSnapshotDTO
{
    /**
     * @param list<BackgroundChildStatusDTO>    $children
     * @param list<BackgroundWorkDTO>           $work
     * @param list<ChildBackgroundCandidateDTO> $codeMode
     * @param list<ScheduleDTO>|null            $schedules
     */
    public function __construct(public array $children, public array $work, public array $codeMode = [], public ?array $schedules = null)
    {
    }
}
