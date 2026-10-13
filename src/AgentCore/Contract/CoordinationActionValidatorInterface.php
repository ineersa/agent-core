<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/** Validates supported journal actions before any recovery effect is dispatched. */
interface CoordinationActionValidatorInterface
{
    public function supports(object $action): bool;

    public function validate(object $action): void;
}
