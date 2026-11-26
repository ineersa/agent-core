<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\NullableCollaborator\Source;

use Psr\Log\LoggerInterface;

final readonly class NullableCollaboratorSuffixDTO
{
    public function __construct(
        public ?LoggerInterface $logger = null,
    ) {
    }
}
