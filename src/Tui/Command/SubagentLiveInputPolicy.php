<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Central input policy while subagent live view owns the screen.
 *
 * Precedence (enforced in {@see \Ineersa\Tui\Listener\SubmitListener}):
 * question overlay → live-view policy → main-session routing.
 *
 * Slash/shell blocking rationale: parent session commands (!, /new, /resume, …)
 * would mutate parent state or spawn shell against the parent run while the UI
 * shows a child transcript — strand the user under the wrong session (POC lesson).
 */
final readonly class SubagentLiveInputPolicy
{
    /** @var list<string> */
    private const ALLOWED_SLASH_NAMES = [
        'agents-main',
        'main',
        'agents-live',
    ];

    public function __construct(
        private CommandParser $parser = new CommandParser(),
    ) {
    }

    public function blockedLeaveLiveViewMessage(): string
    {
        return 'Leave subagent live view first with /agents-main before running other commands.';
    }

    public function parseSubmitted(string $submittedText): CommandParseResult
    {
        return $this->parser->parse($submittedText);
    }

    public function terminalChildInputBlockedMessage(): string
    {
        return 'This subagent has finished. Use /agents-main to continue with the main agent.';
    }


    /**
     * True when submitted text is an allowed live-view navigation slash (/agents-main, /main, /agents-live).
     */
    public function isAllowedLiveViewNavigationSlash(string $submittedText): bool
    {
        $parseResult = $this->parser->parse($submittedText);
        if (!$parseResult instanceof SlashCommand) {
            return false;
        }

        return $this->isAllowedSlashCommand($parseResult->name);
    }

    public function isAllowedSlashCommand(string $name): bool
    {
        return \in_array(strtolower($name), self::ALLOWED_SLASH_NAMES, true);
    }

    /**
     * True when live view is active and this submission must not reach parent runtime or unrelated slash handlers.
     */
    public function shouldBlockInLiveView(string $submittedText): bool
    {
        $parseResult = $this->parser->parse($submittedText);

        if ($parseResult instanceof ShellCommand) {
            return true;
        }

        if ($parseResult instanceof SlashCommand) {
            return !$this->isAllowedSlashCommand($parseResult->name);
        }

        return false;
    }

    public function isNormalPrompt(string $submittedText): bool
    {
        return $this->parser->parse($submittedText) instanceof NormalPromptCommand;
    }

    /**
     * Child steer vs follow_up mirrors parent {@see RunActivityStateEnum} semantics.
     *
     * Steer is non-interruptive: it is queued for the child run and applies at the
     * next runtime/command boundary; it cannot stop an in-flight tool or shell batch.
     */
    public function childUserCommandType(bool $childActivityIsActive): string
    {
        return $childActivityIsActive ? 'steer' : 'follow_up';
    }

    public function dispatchConfirmationMessage(string $commandType, string $agentName): string
    {
        if ('steer' === $commandType) {
            return \sprintf(
                'Sent steer to subagent %s — applies at next safe step.',
                $agentName,
            );
        }

        return \sprintf('Sent follow_up to subagent %s.', $agentName);
    }
}
