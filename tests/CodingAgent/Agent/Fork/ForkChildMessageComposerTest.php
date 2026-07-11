<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkTaskPromptBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ForkChildMessageComposer::class)]
final class ForkChildMessageComposerTest extends IsolatedKernelTestCase
{
    public function testComposeOrdersCanonicalContextBeforeInheritedHistoryAndTaskLast(): void
    {
        $task = 'Implement FORK-MVP slice';
        $promptBuilder = new ForkTaskPromptBuilder();
        $expectedUser = $promptBuilder->buildTaskUserMessage($task);
        $compactSummary = 'COMPACT_SUMMARY_BLOCK';

        $snapshot = new ForkSessionSnapshotDTO(
            messages: [
                new AgentMessage(role: 'system', content: [['type' => 'text', 'text' => 'stale parent system']]),
                new AgentMessage(role: 'user-context', content: [['type' => 'text', 'text' => 'stale agents']], metadata: ['source' => 'agents_context']),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => $compactSummary]], metadata: ['source' => 'compact_summary']),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prior user']]),
            ],
            forkSystemPromptAppend: $promptBuilder->forkChildSystemPromptAppend(),
            forkTaskUserMessage: $expectedUser,
            resolvedModel: null,
        );

        $composer = self::getContainer()->get(ForkChildMessageComposer::class);
        $composed = $composer->compose(
            snapshot: $snapshot,
            artifactId: 'agent_test123',
            allowedToolNames: ['read', 'subagent'],
            agentsMd: '<project_context>AGENTS block</project_context>',
            skillsContext: '<available_skills>skills block</available_skills>',
            agentsContext: '<available_agents>agents catalog block</available_agents>',
        );

        $this->assertSame('', $composed['systemPrompt']);

        $rolesAndSources = [];
        foreach ($composed['messages'] as $message) {
            $rolesAndSources[] = [
                'role' => $message->role,
                'source' => $message->metadata['source'] ?? null,
            ];
        }

        $this->assertSame([
            ['role' => 'system', 'source' => null],
            ['role' => 'user-context', 'source' => 'agents_context'],
            ['role' => 'user-context', 'source' => 'skills_context'],
            ['role' => 'user-context', 'source' => 'agents_definitions_context'],
            ['role' => 'user', 'source' => 'compact_summary'],
            ['role' => 'user', 'source' => null],
            ['role' => 'user-context', 'source' => 'agent_child_contract'],
            ['role' => 'user', 'source' => null],
        ], $rolesAndSources);

        $systemText = (string) ($composed['messages'][0]->content[0]['text'] ?? '');
        $this->assertStringContainsString('delegated child agent', $systemText);
        $this->assertStringContainsString('read', strtolower($systemText));
        $this->assertStringNotContainsString('AGENTS block', $systemText);
        $this->assertStringNotContainsString('agents catalog block', $systemText);
        $this->assertStringNotContainsString('skills block', $systemText);
        $this->assertStringNotContainsString('fork task=', $systemText);
        $this->assertStringNotContainsString('MCP_TOOL_LONG_DESCRIPTION_SHOULD_NOT_APPEAR', $systemText);

        $lastUserText = '';
        foreach (array_reverse($composed['messages']) as $message) {
            if ('user' !== $message->role) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '')) {
                    $lastUserText = (string) $block['text'];
                    break 2;
                }
            }
        }

        $this->assertSame($expectedUser, $lastUserText);
        $this->assertStringContainsString($compactSummary, $this->flattenMessageText($composed['messages']));
        $this->assertStringNotContainsString('stale agents', $this->flattenMessageText($composed['messages']));
    }

    public function testComposeReplacesStaleParentSystemWithChildSafeSystemInMessages(): void
    {
        $staleParentSystem = <<<'EOT'
fork task="leak" — launch fork child with inherited history
Use fork when you need a delegated child run.
EOT;
        $promptBuilder = new ForkTaskPromptBuilder();
        $snapshot = new ForkSessionSnapshotDTO(
            messages: [
                new AgentMessage(role: 'system', content: [['type' => 'text', 'text' => $staleParentSystem]]),
                new AgentMessage(role: 'user-context', content: [['type' => 'text', 'text' => 'stale parent skills']], metadata: ['source' => 'skills_context']),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prior user']]),
            ],
            forkSystemPromptAppend: $promptBuilder->forkChildSystemPromptAppend(),
            forkTaskUserMessage: $promptBuilder->buildTaskUserMessage('Implement FORK-MVP slice'),
            resolvedModel: null,
        );

        $composer = self::getContainer()->get(ForkChildMessageComposer::class);
        $composed = $composer->compose(
            snapshot: $snapshot,
            artifactId: 'agent_test123',
            allowedToolNames: ['read', 'subagent'],
            agentsMd: '',
            skillsContext: 'fresh skills block',
            agentsContext: '',
        );

        $this->assertSame('', $composed['systemPrompt']);
        $this->assertSame('system', $composed['messages'][0]->role);
        $firstSystemText = (string) ($composed['messages'][0]->content[0]['text'] ?? '');
        $this->assertStringContainsString('delegated child agent', $firstSystemText);
        $this->assertStringNotContainsString('fork task=', $firstSystemText);

        $allMessageText = $this->flattenMessageText($composed['messages']);
        $this->assertStringNotContainsString('fork task=', $allMessageText);
        $this->assertStringNotContainsString('stale parent skills', $allMessageText);
        $this->assertStringContainsString('fresh skills block', $allMessageText);
        $this->assertStringContainsString('prior user', $allMessageText);
        $this->assertStringContainsString('read', $firstSystemText);
        $this->assertStringNotContainsString('use fork', strtolower($firstSystemText));
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function flattenMessageText(array $messages): string
    {
        $text = '';
        foreach ($messages as $message) {
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '')) {
                    $text .= (string) $block['text']."
";
                }
            }
        }

        return $text;
    }
}
