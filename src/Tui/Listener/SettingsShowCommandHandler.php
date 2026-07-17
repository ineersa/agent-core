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

/** @internal Local /settings-show: fresh disk settings as nested Markdown lists. */
final class SettingsShowCommandHandler implements SlashCommandHandler
{
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
        $lines = ['## Settings groups', ''];
        foreach ($groups as $group) {
            if (!\is_string($group) || '' === $group) {
                continue;
            }
            $description = $descriptions[$group] ?? ucwords(str_replace(['_', '-'], ' ', $group)).' settings.';
            $lines[] = \sprintf('- `%s` — %s', $group, $description);
        }
        $warning = $this->restartWarning($resolution);
        if (null !== $warning) {
            $lines[] = '';
            $lines[] = $warning;
        }

        return implode("\n", $lines);
    }

    /** @param array<string, string> $descriptions */
    private function renderPath(SettingsResolutionDTO $resolution, array $descriptions, string $path): string
    {
        $resolved = $this->valueResolver->resolve($resolution, $path);
        if (!$resolved->exists) {
            $lines = ['## Settings', '', \sprintf('Setting `%s` was not found.', $path)];
            $warning = $this->restartWarning($resolution);
            if (null !== $warning) {
                $lines[] = '';
                $lines[] = $warning;
            }

            return implode("\n", $lines);
        }

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

        if ($resolved->composite && \is_array($resolved->value)) {
            array_push($lines, ...$this->renderTree($resolution, $descriptions, $path, $resolved->value, 0));
        } else {
            $lines[] = $this->renderLeafBullet(
                $path,
                $resolved->value,
                null === $resolved->layer ? 'mixed' : $resolved->layer->value,
                $descriptions,
                0,
                basenamePath: true,
            );
        }

        $warning = $this->restartWarning($resolution);
        if (null !== $warning) {
            $lines[] = '';
            $lines[] = $warning;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $descriptions
     * @param array<string, mixed>  $map
     *
     * @return list<string>
     */
    private function renderTree(
        SettingsResolutionDTO $resolution,
        array $descriptions,
        string $prefix,
        array $map,
        int $depth,
    ): array {
        $keys = array_keys($map);
        sort($keys);
        $lines = [];
        $indent = str_repeat('  ', $depth);

        foreach ($keys as $key) {
            $childPath = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            $child = $this->valueResolver->resolve($resolution, $childPath);
            if (!$child->exists) {
                continue;
            }

            if ($child->composite && \is_array($child->value)) {
                $lines[] = \sprintf('%s- `%s`', $indent, $key);
                array_push($lines, ...$this->renderTree($resolution, $descriptions, $childPath, $child->value, $depth + 1));
                continue;
            }

            $lines[] = $this->renderLeafBullet(
                $childPath,
                $child->value,
                null === $child->layer ? 'mixed' : $child->layer->value,
                $descriptions,
                $depth,
                basenamePath: true,
            );
        }

        return $lines;
    }

    /** @param array<string, string> $descriptions */
    private function renderLeafBullet(
        string $path,
        mixed $value,
        string $source,
        array $descriptions,
        int $depth,
        bool $basenamePath,
    ): string {
        $label = $basenamePath ? $this->pathSegment($path) : $path;
        $indent = str_repeat('  ', $depth);
        $line = \sprintf(
            '%s- `%s`: %s — **%s**',
            $indent,
            $label,
            $this->inlineCode($this->formatValue($value)),
            $source,
        );
        $description = $this->nearestDescription($path, $descriptions);
        if ('' !== $description) {
            $line .= "\n".$indent.'  '.$description;
        }

        return $line;
    }

    private function restartWarning(SettingsResolutionDTO $resolution): ?string
    {
        if ($resolution->effective === $this->activeConfig->raw) {
            return null;
        }

        return '> ⚠ **Restart required:** disk settings differ from the active session.';
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

    private function inlineCode(string $text): string
    {
        if (!str_contains($text, '`')) {
            return '`'.$text.'`';
        }

        $fence = '``';
        while (str_contains($text, $fence)) {
            $fence .= '`';
        }

        return $fence.' '.$text.' '.$fence;
    }

    private function pathSegment(string $path): string
    {
        $pos = strrpos($path, '.');

        return false === $pos ? $path : substr($path, $pos + 1);
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
