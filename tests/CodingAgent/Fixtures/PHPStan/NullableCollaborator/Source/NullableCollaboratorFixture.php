<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\NullableCollaborator\Source;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class NullableCollaboratorFixture
{
    public function __construct(
        private ?LoggerInterface $nullableWithoutDefault,
        private ?LoggerInterface $promotedNullable = null,
        ?LoggerInterface $plainNullable = null,
        private LoggerInterface|\Stringable|null $unionWithNull = null,
        private (NormalizerInterface&DenormalizerInterface)|null $intersectionWithNull = null,
        private LoggerInterface $required = new NullLogger(),
        private ?LoggerInterface $nullableWithNonNullDefault = new NullLogger(),
        private ?string $scalar = null,
        private ?array $list = null,
        ?callable $callback = null,
        private ?\Closure $closure = null,
        private string|int|null $scalarUnion = null,
        private ?\Throwable $previous = null,
        private ?\RuntimeException $runtimeException = null,
        private ?FixtureModeEnum $mode = null,
        private ?\DateTimeImmutable $at = null,
        private ?\DateTimeInterface $since = null,
    ) {
    }

    public function notAConstructor(?LoggerInterface $logger = null): void
    {
    }
}
