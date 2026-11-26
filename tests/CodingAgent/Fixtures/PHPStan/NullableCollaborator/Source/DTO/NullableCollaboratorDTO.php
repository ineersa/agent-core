<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\NullableCollaborator\Source\DTO;

final readonly class NullableCollaboratorDTO
{
    public function __construct(
        public ?\DateTimeImmutable $at = null,
    ) {
    }
}
