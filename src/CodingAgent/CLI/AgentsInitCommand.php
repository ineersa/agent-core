<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Install bundled agent definitions into ~/.hatfield/agents.
 *
 * Usage:
 *   bin/console agents:init
 *   bin/console agents:init --force
 */
#[AsCommand(
    name: 'agents:init',
    description: 'Install bundled agent definitions into ~/.hatfield/agents',
)]
final class AgentsInitCommand
{
    public function __construct(
        private readonly AppResourceLocator $resources,
        private readonly SettingsPathResolver $pathResolver,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Overwrite existing bundled agent definition files')]
        bool $force = false,
        ?OutputInterface $output = null,
    ): int {
        $io = new SymfonyStyle(new ArgvInput(), $output);
        $sourceDir = $this->resources->getBuiltinAgentsPath();
        if (!is_dir($sourceDir)) {
            $io->error(\sprintf('Bundled agents directory is missing: %s', $sourceDir));

            return Command::FAILURE;
        }

        $sources = $this->listBundledAgentFiles($sourceDir);
        if ([] === $sources) {
            $io->error(\sprintf('No bundled agent definition files found under: %s', $sourceDir));

            return Command::FAILURE;
        }

        $destinationDir = rtrim($this->pathResolver->getHomeDir(), '/').'/.hatfield/agents';
        $collisions = [];
        foreach ($sources as $source) {
            $target = $destinationDir.'/'.basename($source);
            if (file_exists($target)) {
                $collisions[] = $target;
            }
        }

        if ([] !== $collisions && !$force) {
            $io->error([
                'Refusing to overwrite existing agent definition(s).',
                'Collisions:',
                ...array_map(static fn (string $path): string => '  - '.$path, $collisions),
                'Rerun with --force to overwrite only these bundled filenames.',
            ]);

            return Command::FAILURE;
        }

        $this->filesystem->mkdir($destinationDir);

        $copied = 0;
        foreach ($sources as $source) {
            $target = $destinationDir.'/'.basename($source);
            $this->filesystem->copy($source, $target, true);
            ++$copied;
            $io->writeln(\sprintf('Installed %s', $target));
        }

        $io->success(\sprintf(
            'Installed %d bundled agent definition(s) into %s%s.',
            $copied,
            $destinationDir,
            $force ? ' (force)' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function listBundledAgentFiles(string $sourceDir): array
    {
        // Use scandir, not glob: PHAR stream wrappers do not expand glob patterns.
        $entries = scandir($sourceDir);
        if (false === $entries) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.md')) {
                continue;
            }
            $path = $sourceDir.'/'.$entry;
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        sort($files, \SORT_STRING);

        return $files;
    }
}
