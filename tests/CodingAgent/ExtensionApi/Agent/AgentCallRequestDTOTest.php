<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\ExtensionApi\Agent;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: AgentCallRequestDTO validates model identity and optional maxToolCalls bounds.
 */
final class AgentCallRequestDTOTest extends TestCase
{
    public function testAcceptsExactModelAndOptionalMaxToolCalls(): void
    {
        $dto = new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-1',
            instructions: 'sys',
            input: 'user',
            maxToolCalls: 3,
        );

        $this->assertSame(3, $dto->maxToolCalls);
        $this->assertNull((new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-1',
            instructions: 'sys',
            input: 'user',
        ))->maxToolCalls);
    }

    public function testRejectsNonPositiveMaxToolCalls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentCallRequestDTO(
            model: 'llama_cpp_test/test',
            sessionId: 'run-1',
            instructions: 'sys',
            input: 'user',
            maxToolCalls: 0,
        );
    }

    public function testRejectsAliasModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentCallRequestDTO(
            model: '@compaction',
            sessionId: 'run-1',
            instructions: 'sys',
            input: 'user',
        );
    }
}
