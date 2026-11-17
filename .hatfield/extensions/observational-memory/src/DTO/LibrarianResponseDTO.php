<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class LibrarianResponseDTO
{
    #[Assert\IsTrue]
    public bool $ok = false;

    #[Assert\EqualTo(1)]
    public int $contract_version = 0;

    #[Assert\NotNull(groups: ['delete'])]
    public ?bool $deleted = null;

    #[Assert\NotNull(groups: ['projection'])]
    public ?string $text = null;

    #[Assert\EqualTo('queued', groups: ['observe'])]
    #[Assert\NotNull(groups: ['observe'])]
    public ?string $status = null;
}
