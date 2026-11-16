<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\RetrievalResultHook;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RetrievalResultHookTest extends TestCase
{
    public function testLocalAndMcpFailuresPromoteIsErrorAndPreserveAmbiguityCandidates(): void
    {
        $hook = new RetrievalResultHook(new NullLogger());
        foreach ([['ok' => false, 'error' => 'ambiguous_id'], ['ok' => true, 'isError' => true]] as $error) {
            $payload = $error + ['candidates' => [['id' => str_repeat('a', 64), 'content' => 'Actual memory content']], 'candidates_truncated' => false];
            $decision = $hook->onToolResult(new ToolResultContextDTO(
                toolCallId: 'call', toolName: 'recall', arguments: ['id' => str_repeat('a', 12)],
                isError: false, content: [], details: ['raw_result' => Toon::encode($payload)],
            ));
            $this->assertTrue($decision->isError);
            $this->assertSame($payload, $decision->details['structuredContent']);
        }
    }

    public function testSuccessfulEmptyResultRemainsSuccessful(): void
    {
        $decision = (new RetrievalResultHook(new NullLogger()))->onToolResult(new ToolResultContextDTO(
            toolCallId: 'call', toolName: 'memory_search', arguments: ['query' => 'identifier'],
            isError: false, content: [], details: ['raw_result' => Toon::encode(['ok' => true, 'count' => 0, 'results' => []])],
        ));
        $this->assertFalse($decision->isError);
        $this->assertSame(0, $decision->details['structuredContent']['count']);
    }
}
