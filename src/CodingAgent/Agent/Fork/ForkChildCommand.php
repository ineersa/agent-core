<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fork child CLI command — boot a fork agent run from a parent snapshot.
 *
 * This command is invoked by the parent process (FORK-04 tmux launcher) as:
 *   bin/console agent:fork --snapshot=<path> --result-dir=<path> ...
 *
 * Usage:
 *   agent:fork \
 *     --snapshot=/path/to/snapshot.json \
 *     --result-dir=/path/to/result/artifacts \
 *     --parent-run-id=<parentRunId> \
 *     --fork-run-id=<artifactId> \
 *     --child-run-id=<childRunId> \
 *     [--level=middle] \
 *     [--task="Task description"] \
 *     [--cwd=/child/working/dir]
 *
 * Requires HATFIELD_FORK=1 environment variable for defense-in-depth.
 */
#[AsCommand(name: 'agent:fork', description: 'Run a fork child agent from a parent snapshot')]
final class ForkChildCommand extends Command
{
    /** Fork child environment variable name for defense-in-depth guard. */
    public const string ENV_HATFIELD_FORK = 'HATFIELD_FORK';

    public function __construct(
        private readonly ForkChildResultFinalizer $forkResultFinalizer,
        private readonly ForkSessionSnapshotSerializer $forkSnapshotSerializer,
        private readonly ForkChildMessageComposer $forkMessageComposer,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly SystemPromptBuilder $systemPromptBuilder,
        private readonly ToolRegistryInterface $toolRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Run a fork child agent from a parent snapshot')
            ->addOption('snapshot', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Path to the fork snapshot JSON file')
            ->addOption('result-dir', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Result artifact directory path')
            ->addOption('parent-run-id', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Parent session run ID')
            ->addOption('fork-run-id', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Fork artifact ID')
            ->addOption('child-run-id', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Child agent run ID')
            ->addOption('level', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Fork level: junior, middle, senior', '')
            ->addOption('task', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Task description', '')
            ->addOption('cwd', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Child working directory (defaults to current CWD)', '');
    }

    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        OutputInterface $output,
    ): int {
        $snapshot = (string) $input->getOption('snapshot');
        $resultDir = (string) $input->getOption('result-dir');
        $parentRunId = (string) $input->getOption('parent-run-id');
        $forkRunId = (string) $input->getOption('fork-run-id');
        $childRunId = (string) $input->getOption('child-run-id');
        $level = (string) $input->getOption('level');
        $task = (string) $input->getOption('task');
        $cwd = (string) $input->getOption('cwd');

        try {
            // Validate HATFIELD_FORK guard.
            $forkGuard = $_SERVER[self::ENV_HATFIELD_FORK] ?? getenv(self::ENV_HATFIELD_FORK);
            if ('1' !== $forkGuard) {
                throw new \RuntimeException(\sprintf('Fork mode requires %s=1 environment variable for defense-in-depth.', self::ENV_HATFIELD_FORK));
            }

            // Validate required options.
            if ('' === $snapshot) {
                throw new \RuntimeException('--snapshot is required in fork mode.');
            }
            if ('' === $resultDir) {
                throw new \RuntimeException('--result-dir is required in fork mode.');
            }
            if ('' === $parentRunId) {
                throw new \RuntimeException('--parent-run-id is required in fork mode.');
            }
            if ('' === $forkRunId) {
                throw new \RuntimeException('--fork-run-id is required in fork mode.');
            }
            if ('' === $childRunId) {
                throw new \RuntimeException('--child-run-id is required in fork mode.');
            }

            // Set HATFIELD_FORK env var for defense-in-depth.
            $_ENV[self::ENV_HATFIELD_FORK] = '1';
            putenv(self::ENV_HATFIELD_FORK.'=1');

            // Force-exclude the fork tool (defense-in-depth: the child must not
            // see or be able to call fork, even if the launcher forgot to set
            // --tools-excluded). The fork tool is registered by the ToolRegistry
            // but we exclude it here so it is invisible to the child model.
            $this->applyForkToolExclusion();

            // 1. Load snapshot.
            $snapshotDto = $this->forkSnapshotSerializer->fromFile($snapshot);

            // 2. Build fresh system prompt for child CWD.
            $freshSystemPrompt = $this->systemPromptBuilder->build();

            // 3. Compose messages.
            $startRunInput = $this->forkMessageComposer->compose(
                snapshot: $snapshotDto,
                childRunId: $childRunId,
                freshSystemPrompt: $freshSystemPrompt,
            );

            // 4. Start the child agent run.
            $this->agentRunner->start($startRunInput);

            // 5. Ensure the artifact directory exists for result writing.
            if (!is_dir($resultDir)) {
                mkdir($resultDir, 0o755, true);
            }

            // 6. Finalize run: poll for terminal state, validate handoff, write artifacts.
            $forkLevel = '' !== $level ? ForkLevelEnum::fromStringOrNull($level) : null;
            $result = $this->forkResultFinalizer->finalize(
                parentRunId: $parentRunId,
                artifactId: $forkRunId,
                childRunId: $childRunId,
                resultDir: $resultDir,
                cwd: '' !== $cwd ? $cwd : getcwd(),
                task: '' !== $task ? $task : '',
                level: null !== $forkLevel ? $forkLevel->value : ForkLevelEnum::Middle->value,
                resolvedModel: $snapshotDto->resolvedModel,
            );

            // 7. Write exit status JSON to stdout for the completion watcher (FORK-05).
            $exitPayload = json_encode([
                'status' => $result->status->value,
                'artifact_id' => $forkRunId,
                'child_run_id' => $childRunId,
                'error' => $result->error,
                'handoff_path' => $result->handoffPath,
                'validation_attempts' => $result->validationAttempts,
            ], \JSON_THROW_ON_ERROR);

            $output->writeln('---FORK-RESULT-START---');
            $output->writeln($exitPayload);
            $output->writeln('---FORK-RESULT-END---');

            return AgentArtifactStatusEnum::Completed === $result->status
                ? self::SUCCESS
                : self::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('<error>Fork child error: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * Defensively exclude the `fork` tool so the child cannot see or call it.
     *
     * Appends 'fork' to the excluded tools list. The fork tool is registered
     * by ToolRegistry but should never be visible to or callable by a fork
     * child process.
     */
    private function applyForkToolExclusion(): void
    {
        $existingExcluded = $this->toolRegistry->excludedToolNames();

        if (!\in_array('fork', $existingExcluded, true)) {
            $existingExcluded[] = 'fork';
            $this->toolRegistry->setExcludedToolNames($existingExcluded);
        }
    }
}
