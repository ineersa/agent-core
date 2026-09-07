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
use Castor\Event\ContextCreatedEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

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
    unset($event);
    hatfield_castor_putenv([
        'CASTOR_DISABLE_VERSION_CHECK' => '1',
        'NO_COLOR' => '1',
        'CLICOLOR' => '0',
    ]);
}

#[AsListener(event: ContextCreatedEvent::class)]
function hatfield_castor_compact_context_env(ContextCreatedEvent $event): void
{
    $event->context = $event->context->withEnvironment([
        'CASTOR_DISABLE_VERSION_CHECK' => '1',
        'NO_COLOR' => '1',
        'CLICOLOR' => '0',
    ]);
}

#[AsListener(event: ConsoleCommandEvent::class)]
function hatfield_castor_compact_list_defaults(ConsoleCommandEvent $event): void
{
    $command = $event->getCommand();
    if (null === $command || 'list' !== $command->getName()) {
        return;
    }

    $input = $event->getInput();
    if (!$input->hasParameterOption(['--format'], true)) {
        $input->setOption('format', 'md');
    }
    if (!$input->hasParameterOption(['--short'], true)) {
        $input->setOption('short', true);
    }
}
