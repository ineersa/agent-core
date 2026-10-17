<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool\Completion;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolCompletionRecipientDTOTest extends TestCase
{
    #[DataProvider('invalidDispositions')]
    public function testRejectsInvalidReceiptOrAppliedLocation(int $turn, ?int $received, ToolCompletionDeliveryDispositionEnum $disposition, ?int $applied): void
    {
        $reference = new ToolCompletionDeliveryReferenceDTO('run', 'message', new RetainedToolCompletionSourceEventReference('run', 1, 2), str_repeat('a', 64));
        $this->expectException(\InvalidArgumentException::class);
        new ToolCompletionRecipientDTO($reference, $turn, $received, $disposition, appliedSequence: $applied);
    }

    public static function invalidDispositions(): iterable
    {
        yield 'negative turn' => [-1, 1, ToolCompletionDeliveryDispositionEnum::Pending, null];
        yield 'unallocated receipt' => [1, 0, ToolCompletionDeliveryDispositionEnum::Pending, null];
        yield 'applied without canonical location' => [1, 1, ToolCompletionDeliveryDispositionEnum::Applied, null];
        yield 'pending with application marker' => [1, 1, ToolCompletionDeliveryDispositionEnum::Pending, 2];
        yield 'unallocated application' => [1, 1, ToolCompletionDeliveryDispositionEnum::Applied, 0];
        yield 'application before receipt' => [1, 10, ToolCompletionDeliveryDispositionEnum::Applied, 9];
        yield 'application without receipt' => [1, null, ToolCompletionDeliveryDispositionEnum::Applied, 2];
    }
}
