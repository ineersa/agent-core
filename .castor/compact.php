<?php

declare(strict_types=1);

/**
 * Project-wide compact Castor execution defaults.
 *
 * Replaces the former castor-llm-mode tool-call rewrite extension: quiet
 * non-TTY output, disabled Castor update nag, and compact `list` defaults
 * live in the Castor lifecycle instead of LLM_MODE / bash rewriting.
 */

use Castor\Attribute\AsListener;
use Castor\Event\AfterBootEvent;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @param array<string, string> $values
 */
function hatfield_castor_putenv(array $values): void
{
    foreach ($values as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

#[AsListener(event: AfterBootEvent::class)]
function hatfield_castor_compact_boot_env(AfterBootEvent $event): void
{
    hatfield_castor_putenv([
        'CASTOR_DISABLE_VERSION_CHECK' => '1',
        'NO_COLOR' => '1',
        'CLICOLOR' => '0',
    ]);

    $event->application->addCommand(new class extends ListCommand {
        protected function configure(): void
        {
            parent::configure();
            $this->getDefinition()->getOption('format')->setDefault('md');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            // VALUE_NONE options cannot default to true. Set this after Console's
            // final input bind, which would overwrite a console.command change.
            $input->setOption('short', true);
            if (!$input->hasParameterOption('--ansi', true)) {
                $output->setDecorated(false);
            }

            return parent::execute($input, $output);
        }
    });
}
