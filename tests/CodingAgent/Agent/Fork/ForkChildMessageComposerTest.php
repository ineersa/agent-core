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
    public function testComposeIncludesForkAppendHistoryAndExactHandoffUserMessage(): void
    {
        $task = 'Implement FORK-MVP slice';
        $promptBuilder = new ForkTaskPromptBuilder();
        $expectedUser = $promptBuilder->buildTaskUserMessage($task);

        $snapshot = new ForkSessionSnapshotDTO(
            messages: [
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
            agentsMd: 'AGENTS block',
            skillsContext: 'skills block',
            agentsContext: 'agents catalog block',
        );

        $this->assertStringContainsString('delegated child agent', $composed['systemPrompt']);
        $this->assertStringContainsString('AGENTS block', $composed['systemPrompt']);
        $this->assertStringContainsString('agents catalog block', $composed['systemPrompt']);

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

        $contractText = '';
        foreach ($composed['messages'] as $message) {
            if ('user-context' === $message->role && 'agent_child_contract' === ($message->metadata['source'] ?? null)) {
                $contractText = (string) ($message->content[0]['text'] ?? '');
            }
        }
        $this->assertStringContainsString('agent_test123', $contractText);
        $this->assertStringContainsString('delegated task', $contractText);
        $this->assertStringNotContainsString('Allowed tools:', $contractText);
        $this->assertStringNotContainsString('fork child agent', strtolower($contractText));
        $this->assertStringNotContainsString('Pi-style', $contractText);
        $this->assertStringNotContainsString('Fork child contract', $contractText);
        $this->assertStringNotContainsString('fork tool', strtolower($contractText));
        $this->assertStringNotContainsString('fork task=', $composed['systemPrompt']);
        $this->assertStringNotContainsString('Use fork', $composed['systemPrompt']);
        $this->assertStringNotContainsString('launch fork child', strtolower($composed['systemPrompt']));
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

        $this->assertNotEmpty($composed['messages']);
        $this->assertSame('system', $composed['messages'][0]->role);
        $firstSystemText = (string) ($composed['messages'][0]->content[0]['text'] ?? '');
        $this->assertStringContainsString('delegated child agent', $firstSystemText);
        $this->assertStringNotContainsString('fork task=', $firstSystemText);
        $this->assertStringNotContainsString('launch fork child', strtolower($firstSystemText));

        $allMessageText = '';
        foreach ($composed['messages'] as $message) {
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '')) {
                    $allMessageText .= (string) $block['text']."\n";
                }
            }
        }
        $this->assertStringNotContainsString('fork task=', $allMessageText);
        $this->assertStringNotContainsString('stale parent skills', $allMessageText);
        $this->assertStringContainsString('fresh skills block', $allMessageText);
        $this->assertStringContainsString('prior user', $allMessageText);
        $this->assertStringContainsString('read', $firstSystemText);
        $this->assertStringNotContainsString('fork task=', $firstSystemText);
        $this->assertStringNotContainsString('use fork', strtolower($firstSystemText));
    }
}
