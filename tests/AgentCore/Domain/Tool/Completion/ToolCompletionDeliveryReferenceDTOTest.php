<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool\Completion;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolCompletionDeliveryReferenceDTOTest extends TestCase
{
    #[DataProvider('invalidReferences')]
    public function testRejectsInvalidReference(string $destination, string $messageId, string $fingerprint, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolCompletionDeliveryReferenceDTO($destination, $messageId, new RetainedToolCompletionSourceEventReference('run-1', 1, 1, $type), $fingerprint);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidReferences(): iterable
    {
        yield 'empty destination' => ['', 'message', str_repeat('a', 64), 'tool_execution_end'];
        yield 'empty message' => ['run-1', ' ', str_repeat('a', 64), 'tool_execution_end'];
        yield 'invalid fingerprint' => ['run-1', 'message', 'not-a-hash', 'tool_execution_end'];
        yield 'nonterminal event' => ['run-1', 'message', str_repeat('a', 64), 'tool_execution_start'];
    }
}
