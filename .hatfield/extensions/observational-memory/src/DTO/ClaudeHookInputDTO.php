<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** The fields of Claude Code's Stop/SessionEnd hook input that the observe signal uses. */
final class ClaudeHookInputDTO
{
    #[Assert\Regex('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/')]
    public string $session_id = '';

    #[Assert\NotBlank]
    #[Assert\Regex('#\A/#')]
    public string $transcript_path = '';
}
