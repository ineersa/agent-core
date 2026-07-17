<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;

/** @internal Local /settings-show: fresh disk settings as Markdown tables. */
final class SettingsShowCommandHandler implements SlashCommandHandler
{
    private const int MAX_ROWS = 100;
    private const int MAX_CELL = 160;

    public function __construct(
        private readonly AppConfigLoader $loader,
        private readonly AppResourceLocator $resources,
        private readonly AppConfig $activeConfig,
        private readonly SettingsValueResolver $valueResolver,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $resolution = $this->loader->load($this->resources->getDefaultsPath(), $this->activeConfig->cwd);
        $descriptions = $this->extractDescriptions($this->resources->getDefaultsPath(), $resolution->defaultsRaw);
        $filter = trim($command->args);

        return new TranscriptMessage(
            '' === $filter
                ? $this->renderGroups($resolution, $descriptions)
                : $this->renderPath($resolution, $descriptions, $filter),
            'system',
            'markdown',
        );
    }

    /** @param array<string, string> $descriptions */
    private function renderGroups(SettingsResolutionDTO $resolution, array $descriptions): string
    {
        $groups = array_keys($resolution->effective);
        sort($groups);
        $rows = ['## Settings groups', '', '| Group | Description |', '| --- | --- |'];
        foreach ($groups as $group) {
            if (!\is_string($group) || '' === $group) {
                continue;
            }
            $rows[] = \sprintf(
                '| %s | %s |',
                $this->cell($group),
                $this->cell($descriptions[$group] ?? ucwords(str_replace(['_', '-'], ' ', $group)).' settings.'),
            );
        }
        $rows[] = '';
        $rows[] = $this->restartNote($resolution);

        return implode("\n", $rows);
    }

    /** @param array<string, string> $descriptions */
    private function renderPath(SettingsResolutionDTO $resolution, array $descriptions, string $path): string
    {
        $resolved = $this->valueResolver->resolve($resolution, $path);
        if (!$resolved->exists) {
            return \sprintf("## Settings\n\nSetting `%s` was not found.\n\n%s", $path, $this->restartNote($resolution));
        }

        $paths = $resolved->composite ? $this->terminalPaths($path, $resolved->value) : [$path];
        $lines = [\sprintf('## `%s`', $path), ''];
        // Groups/top-level use their own section docs as prose; nested leaves keep parent prose only.
        $prose = $this->nearestDescription(
            $path,
            $descriptions,
            excludeExact: !($resolved->composite || !str_contains($path, '.')),
        );
        if ('' !== $prose) {
            $lines[] = $prose;
            $lines[] = '';
        }
        $lines[] = '| Setting | Value | Source | Description |';
        $lines[] = '| --- | --- | --- | --- |';

        $rows = 0;
        foreach ($paths as $leafPath) {
            if ($rows >= self::MAX_ROWS) {
                $lines[] = '';
                $lines[] = \sprintf('_Showing first %d settings._', self::MAX_ROWS);
                break;
            }
            $leaf = $this->valueResolver->resolve($resolution, $leafPath);
            if (!$leaf->exists || $leaf->composite) {
                continue;
            }
            $lines[] = \sprintf(
                '| %s | %s | %s | %s |',
                $this->cell($leafPath),
                $this->cell($this->formatValue($leaf->value)),
                $this->cell(null !== $leaf->layer ? $leaf->layer->value : 'mixed'),
                $this->cell($this->nearestDescription($leafPath, $descriptions)),
            );
            ++$rows;
        }

        $lines[] = '';
        $lines[] = $this->restartNote($resolution);

        return implode("\n", $lines);
    }

    private function restartNote(SettingsResolutionDTO $resolution): string
    {
        return $resolution->effective !== $this->activeConfig->raw
            ? 'Restart required: disk settings differ from the active session.'
            : 'Disk setting changes require a Hatfield restart to affect this session.';
    }

    /** @return list<string> */
    private function terminalPaths(string $prefix, mixed $value): array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            return [$prefix];
        }

        $paths = [];
        foreach ($value as $key => $child) {
            $childPath = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            array_push($paths, ...$this->terminalPaths($childPath, $child));
        }

        return $paths;
    }

    private function formatValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return (string) $value;
        }

        // Deptrac: TuiListener cannot use Symfony Yaml; JSON is the compact dump for list/map leaves.
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '[unserializable]' : $encoded;
    }

    private function cell(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", '|'], [' ', ' ', ' ', '\\|'], $text);
        if (mb_strlen($text) > self::MAX_CELL) {
            $text = mb_substr($text, 0, self::MAX_CELL - 1).'…';
        }

        return $text;
    }

    /** @param array<string, string> $descriptions */
    private function nearestDescription(string $path, array $descriptions, bool $excludeExact = false): string
    {
        $current = $path;
        while ('' !== $current) {
            if ((!$excludeExact || $current !== $path) && isset($descriptions[$current])) {
                return $descriptions[$current];
            }
            $pos = strrpos($current, '.');
            $current = false === $pos ? '' : substr($current, 0, $pos);
        }

        return '';
    }

    /**
     * Docs from raw defaults lines only; Symfony YAML remains the parser.
     * Paths are constrained to defaultsRaw so commented-out examples never match.
     * Only immediately adjacent comment blocks describe a key; blank lines reset pending prose.
     *
     * @param array<string, mixed> $defaultsRaw
     *
     * @return array<string, string>
     */
    private function extractDescriptions(string $defaultsPath, array $defaultsRaw): array
    {
        $raw = @file_get_contents($defaultsPath);
        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Unable to read defaults resource: %s', $defaultsPath));
        }

        $known = $this->knownPaths($defaultsRaw);
        $descriptions = [];
        $stack = [];
        $pending = [];
        $lines = preg_split("/\R/", $raw);
        foreach (false === $lines ? [] : $lines as $line) {
            if (preg_match('/^\s*#/', $line)) {
                $content = ltrim(substr(ltrim($line), 1));
                // Controlled defaults keys are lowercase/snake/kebab; keep capitalized prose (e.g. Precedence: ...).
                if (str_contains($content, '---') || str_starts_with($content, '===')
                    || preg_match('/^[a-z_][\w-]*\s*:/', $content) || preg_match('/^-\s+\S/', $content)) {
                    $pending = [];
                    continue;
                }
                if ('' !== $content) {
                    $pending[] = $content;
                }
                continue;
            }
            if ('' === trim($line) || !preg_match('/^( *)([A-Za-z_][\w-]*)\s*:/', $line, $match)) {
                $pending = [];
                continue;
            }

            $indent = \strlen($match[1]);
            while ([] !== $stack && $stack[array_key_last($stack)]['indent'] >= $indent) {
                array_pop($stack);
            }
            $stack[] = ['indent' => $indent, 'key' => $match[2]];
            $path = implode('.', array_column($stack, 'key'));
            if (isset($known[$path]) && [] !== $pending) {
                $descriptions[$path] = implode(' ', $pending);
            }
            $pending = [];
        }

        return $descriptions;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, true>
     */
    private function knownPaths(array $data, string $prefix = ''): array
    {
        $paths = [];
        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            $paths[$path] = true;
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $paths += $this->knownPaths($value, $path);
            }
        }

        return $paths;
    }
}
