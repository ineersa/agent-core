<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsDescriptions;
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
        $descriptions = (new SettingsDescriptions())->extract($this->resources->getDefaultsPath(), $resolution->defaultsRaw);
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

        return $this->withWarning($lines, $resolution);
    }

    /** @param array<string, string> $descriptions */
    private function renderPath(SettingsResolutionDTO $resolution, array $descriptions, string $path): string
    {
        $resolved = $this->valueResolver->resolve($resolution, $path);
        if (!$resolved->exists) {
            return $this->withWarning(['## Settings', '', \sprintf('Setting `%s` was not found.', $path)], $resolution);
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
            );
        }

        return $this->withWarning($lines, $resolution);
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
    ): string {
        $pos = strrpos($path, '.');
        $label = false === $pos ? $path : substr($path, $pos + 1);
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

    /** @param list<string> $lines */
    private function withWarning(array $lines, SettingsResolutionDTO $resolution): string
    {
        if ($resolution->effective !== $this->activeConfig->raw) {
            $lines[] = '';
            $lines[] = '> ⚠ **Restart required:** disk settings differ from the active session.';
        }

        return implode("\n", $lines);
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
}
