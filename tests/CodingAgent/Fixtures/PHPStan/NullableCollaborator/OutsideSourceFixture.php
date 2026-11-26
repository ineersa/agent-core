<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\NullableCollaborator;

use Psr\Log\LoggerInterface;

final class OutsideSourceFixture
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }
}
