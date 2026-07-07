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

        $this->assertStringContainsString('FORK MODE IS ENABLED', $composed['systemPrompt']);
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
        $this->assertStringContainsString('subagent', $contractText);
        $this->assertStringContainsString('Allowed tools: read, subagent', $contractText);
    }
}
