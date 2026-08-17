<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Nested child-run extension selection under agents.extensions / forks.extensions.
 *
 * @param list<string> $alwaysOn Always-on extension class names (stable order)
 * @param list<string> $enabled  Optional extension class names (forks only; agents ignore)
 */
final readonly class ChildExtensionsConfigDTO
{
    /**
     * @param list<string> $alwaysOn
     * @param list<string> $enabled
     */
    public function __construct(
        public array $alwaysOn = [],
        public array $enabled = [],
    ) {
    }

    public static function fromRaw(mixed $raw, string $path, bool $acceptEnabled = true): self
    {
        if (null === $raw) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for %s: expected mapping, got null.', $path));
        }

        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for %s: expected mapping, got %s.', $path, get_debug_type($raw)));
        }

        if ([] !== $raw && array_is_list($raw)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for %s: expected mapping, got list.', $path));
        }

        if (!$acceptEnabled && \array_key_exists('enabled', $raw)) {
            throw new \InvalidArgumentException(\sprintf('Invalid key for %s: "enabled" is not supported (use frontmatter extensions or always_on).', $path));
        }

        $allowed = ['always_on'];
        if ($acceptEnabled) {
            $allowed[] = 'enabled';
        }
        $unknown = array_diff(array_keys($raw), $allowed);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Invalid key for %s: "%s" is not supported.', $path, array_key_first($unknown)));
        }

        $alwaysOn = self::parseClassList($raw, 'always_on', $path.'.always_on');
        if (!$acceptEnabled) {
            return new self(alwaysOn: $alwaysOn);
        }

        $enabled = \array_key_exists('enabled', $raw)
            ? self::parseClassList($raw, 'enabled', $path.'.enabled')
            : [];

        return new self(alwaysOn: $alwaysOn, enabled: $enabled);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private static function parseClassList(array $raw, string $key, string $path): array
    {
        if (!\array_key_exists($key, $raw)) {
            return [];
        }

        $value = $raw[$key];
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for %s: expected list of strings, got %s.', $path, get_debug_type($value)));
        }

        $classes = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for %s: every entry must be a non-empty string.', $path));
            }
            $classes[] = trim($item);
        }

        return $classes;
    }
}
