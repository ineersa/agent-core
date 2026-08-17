<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Fork tool defaults and child extension selection. Not applied globally to parent sessions.
 */
final readonly class ForksConfigDTO
{
    public function __construct(
        public ?string $model = null,
        public ?string $thinkingLevel = null,
        public ChildExtensionsConfigDTO $extensions = new ChildExtensionsConfigDTO(),
    ) {
    }

    /**
     * Build from raw config data (e.g. a YAML-parsed array).
     *
     * Explicitly configured values are validated strictly: a present value
     * of the wrong type fails configuration load. Omission (the key absent
     * from the merged config) yields the defaults; explicit null or blank
     * strings for model / thinking_level keep the documented "unset"
     * default.
     */
    public static function fromRaw(mixed $raw): self
    {
        if (null === $raw) {
            throw new \InvalidArgumentException('Invalid value for forks: expected mapping, got null.');
        }

        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for forks: expected mapping, got %s.', get_debug_type($raw)));
        }

        if ([] !== $raw && array_is_list($raw)) {
            throw new \InvalidArgumentException('Invalid value for forks: expected mapping, got list.');
        }

        $unknown = array_diff(array_keys($raw), ['model', 'thinking_level', 'extensions']);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Invalid key for forks: "%s" is not supported.', array_key_first($unknown)));
        }

        return new self(
            model: self::parseOptionalScalar($raw, 'model', 'forks.model'),
            thinkingLevel: self::parseOptionalScalar($raw, 'thinking_level', 'forks.thinking_level'),
            extensions: \array_key_exists('extensions', $raw)
                ? ChildExtensionsConfigDTO::fromRaw($raw['extensions'], 'forks.extensions')
                : new ChildExtensionsConfigDTO(),
        );
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->forks;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function parseOptionalScalar(array $raw, string $key, string $path): ?string
    {
        if (!\array_key_exists($key, $raw)) {
            return null;
        }

        $value = $raw[$key];
        if (!\is_string($value) && null !== $value) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for %s: expected string or null, got %s.', $path, get_debug_type($value)));
        }

        if (null === $value || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }
}
