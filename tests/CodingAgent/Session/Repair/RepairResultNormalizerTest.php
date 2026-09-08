<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\Repair\RepairResultNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepairResultNormalizer::class)]
final class RepairResultNormalizerTest extends TestCase
{
    public function testRoundTripsScalarsIncludingRefusal(): void
    {
        $original = new RepairResult(
            repairableStaleCancellationDetected: true,
            staleCancellationRepaired: false,
            message: 'internal',
            refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
            activeOperationsRedriven: 0,
        );

        $decoded = RepairResultNormalizer::fromArray(RepairResultNormalizer::toArray($original));

        $this->assertSame($original->repairableStaleCancellationDetected, $decoded->repairableStaleCancellationDetected);
        $this->assertSame($original->staleCancellationRepaired, $decoded->staleCancellationRepaired);
        $this->assertSame($original->message, $decoded->message);
        $this->assertSame($original->refusalReason, $decoded->refusalReason);
        $this->assertSame($original->activeOperationsRedriven, $decoded->activeOperationsRedriven);
    }

    public function testRejectsMissingResultInsteadOfReportingSuccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RepairResultNormalizer::fromArray([]);
    }

    public function testRejectsUnknownRefusalReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RepairResultNormalizer::fromArray([
            'repairable_stale_cancellation_detected' => false,
            'stale_cancellation_repaired' => false,
            'message' => 'x',
            'refusal_reason' => 'not_a_real_reason',
            'active_operations_redriven' => 0,
        ]);
    }
}
