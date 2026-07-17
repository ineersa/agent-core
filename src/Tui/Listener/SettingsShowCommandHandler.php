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

/**
 * Local /settings-show command: fresh disk settings as Markdown tables.
 *
 * @internal Registered by SettingsShowCommandRegistrar
 */
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
        $resolution = $this->loader->load(
            $this->resources->getDefaultsPath(),
            $this->activeConfig->cwd,
        );
        $descriptions = $this->extractDescriptions($this->resources->getDefaultsPath(), $resolution->defaultsRaw);
        $filter = trim($command->args);
        $markdown = '' === $filter
            ? $this->renderGroups($resolution, $descriptions)
            : $this->renderPath($resolution, $descriptions, $filter);

        return new TranscriptMessage($markdown, 'system', 'markdown');
    }

    /**
     * @param array<string, string> $descriptions
     */
    private function renderGroups(SettingsResolutionDTO $resolution, array $descriptions): string
    {
        $lines = [
            '## Settings groups',
            '',
            '| Group | Description |',
            '| --- | --- |',
        ];

        $groups = array_keys($resolution->effective);
        sort($groups);
        $rows = 0;
        foreach ($groups as $group) {
            if (!\is_string($group) || '' === $group) {
                continue;
            }
            if ($rows >= self::MAX_ROWS) {
                $lines[] = '';
                $lines[] = \sprintf('_Showing first %d groups._', self::MAX_ROWS);
                break;
            }
            $description = $descriptions[$group]
                ?? \sprintf('%s settings.', $this->humanize($group));
            $lines[] = \sprintf(
                '| %s | %s |',
                $this->cell($group),
                $this->cell($description),
            );
            ++$rows;
        }

        $lines[] = '';
        $lines[] = $this->restartNote($resolution);

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $descriptions
     */
    private function renderPath(SettingsResolutionDTO $resolution, array $descriptions, string $path): string
    {
        $resolved = $this->valueResolver->resolve($resolution, $path);
        if (!$resolved->exists) {
            return \sprintf(
                "## Settings\n\nSetting `%s` was not found.\n\n%s",
                $path,
                $this->restartNote($resolution),
            );
        }

        $paths = $resolved->composite
            ? $this->terminalPaths($path, $resolved->value)
            : [$path];

        $groupProse = $this->groupProse($path, $descriptions);
        $lines = [
            \sprintf('## `%s`', $path),
            '',
        ];
        if ('' !== $groupProse) {
            $lines[] = $groupProse;
            $lines[] = '';
        }
        $lines[] = '| Setting | Value | Source | Description |';
        $lines[] = '| --- | --- | --- | --- |';

        $rows = 0;
        $omitted = false;
        foreach ($paths as $leafPath) {
            if ($rows >= self::MAX_ROWS) {
                $omitted = true;
                break;
            }
            $leaf = $this->valueResolver->resolve($resolution, $leafPath);
            if (!$leaf->exists || $leaf->composite) {
                continue;
            }
            $source = null !== $leaf->layer ? $leaf->layer->value : 'mixed';
            $description = $descriptions[$leafPath]
                ?? $descriptions[$this->parentPath($leafPath)]
                ?? '';
            $lines[] = \sprintf(
                '| %s | %s | %s | %s |',
                $this->cell($leafPath),
                $this->cell($this->formatValue($leaf->value)),
                $this->cell($source),
                $this->cell($description),
            );
            ++$rows;
        }

        if ($omitted) {
            $lines[] = '';
            $lines[] = \sprintf('_Showing first %d settings._', self::MAX_ROWS);
        }

        $lines[] = '';
        $lines[] = $this->restartNote($resolution);

        return implode("\n", $lines);
    }

    private function restartNote(SettingsResolutionDTO $resolution): string
    {
        if ($resolution->effective !== $this->activeConfig->raw) {
            return 'Restart required: disk settings differ from the active session.';
        }

        return 'Disk setting changes require a Hatfield restart to affect this session.';
    }

    /**
     * @return list<string>
     */
    private function terminalPaths(string $prefix, mixed $value): array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            return [$prefix];
        }

        $paths = [];
        foreach ($value as $key => $child) {
            $childPath = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            foreach ($this->terminalPaths($childPath, $child) as $path) {
                $paths[] = $path;
            }
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
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_string($value)) {
            return $value;
        }

        // TuiListener may not depend on Symfony Yaml (deptrac). JSON is a compact
        // readable dump for list/map leaves without a new serializer service.
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '[unserializable]' : $encoded;
    }

    private function cell(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $text = str_replace('|', '\\|', $text);
        if (mb_strlen($text) > self::MAX_CELL) {
            $text = mb_substr($text, 0, self::MAX_CELL - 1).'…';
        }

        return $text;
    }

    private function humanize(string $key): string
    {
        $label = str_replace(['_', '-'], ' ', $key);

        return ucwords($label);
    }

    private function parentPath(string $path): string
    {
        $pos = strrpos($path, '.');

        return false === $pos ? '' : substr($path, 0, $pos);
    }

    /**
     * @param array<string, string> $descriptions
     */
    private function groupProse(string $path, array $descriptions): string
    {
        $segments = explode('.', $path);
        $candidates = [];
        $current = '';
        foreach ($segments as $segment) {
            $current = '' === $current ? $segment : $current.'.'.$segment;
            $candidates[] = $current;
        }
        for ($i = \count($candidates) - 1; $i >= 0; --$i) {
            $candidate = $candidates[$i];
            if (isset($descriptions[$candidate]) && $candidate !== $path) {
                return $descriptions[$candidate];
            }
        }

        $root = $segments[0] ?? '';
        if ('' !== $root && isset($descriptions[$root])) {
            return $descriptions[$root];
        }

        return '';
    }

    /**
     * Associate adjacent `#` docs with real mapping keys present in defaultsRaw.
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
        $split = preg_split("/\R/", $raw);
        $lines = false === $split ? [] : $split;
        $descriptions = [];
        $stack = [];
        $pending = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*#/', $line)) {
                $content = ltrim(substr(ltrim($line), 1));
                if ($this->isBannerComment($content) || $this->isCommentedYamlExample($content)) {
                    $pending = [];
                    continue;
                }
                if ('' !== $content) {
                    $pending[] = $content;
                }
                continue;
            }

            if ('' === trim($line)) {
                $pending = [];
                continue;
            }

            if (!preg_match('/^( *)([A-Za-z_][\w-]*)\s*:/', $line, $match)) {
                $pending = [];
                continue;
            }

            $indent = \strlen($match[1]);
            $key = $match[2];
            while ([] !== $stack && $stack[array_key_last($stack)]['indent'] >= $indent) {
                array_pop($stack);
            }
            $stack[] = ['indent' => $indent, 'key' => $key];
            $path = implode('.', array_column($stack, 'key'));

            if (isset($known[$path]) && [] !== $pending) {
                $descriptions[$path] = implode(' ', $pending);
            }
            $pending = [];
        }

        return $descriptions;
    }

    private function isBannerComment(string $content): bool
    {
        return str_contains($content, '---') || str_starts_with($content, '===');
    }

    private function isCommentedYamlExample(string $content): bool
    {
        return (bool) preg_match('/^[A-Za-z_][\w-]*\s*:/', $content)
            || (bool) preg_match('/^-\s+\S/', $content);
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
