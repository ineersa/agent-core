<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** Where line counting of one Claude Code transcript stopped: just after its last complete line. */
final class ClaudeTranscriptCursorDTO
{
    #[Assert\PositiveOrZero]
    public int $inode = 0;

    #[Assert\PositiveOrZero]
    public int $offset = 0;

    #[Assert\PositiveOrZero]
    public int $lines = 0;
}
