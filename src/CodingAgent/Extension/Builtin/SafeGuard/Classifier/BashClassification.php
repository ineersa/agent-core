<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;

/**
 * Result of bash command classification.
 *
 * Mirrors Pi's BashClassification type.
 */
final readonly class BashClassification
{
    public function __construct(
        public SafeGuardDecisionKind $kind,
        public string $reason,
    ) {
    }
}
