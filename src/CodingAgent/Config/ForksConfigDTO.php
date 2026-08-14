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
     * @param array<string, mixed> $raw
     */
    public static function fromRaw(array $raw): self
    {
        $model = null;
        if (\array_key_exists('model', $raw) && (\is_string($raw['model']) || null === $raw['model'])) {
            $model = null === $raw['model'] || '' === trim($raw['model']) ? null : trim($raw['model']);
        }

        $thinkingLevel = null;
        if (\array_key_exists('thinking_level', $raw) && (\is_string($raw['thinking_level']) || null === $raw['thinking_level'])) {
            $thinkingLevel = null === $raw['thinking_level'] || '' === trim($raw['thinking_level'])
                ? null
                : trim($raw['thinking_level']);
        }

        return new self(
            model: $model,
            thinkingLevel: $thinkingLevel,
            extensions: ChildExtensionsConfigDTO::fromRaw($raw['extensions'] ?? null, 'forks.extensions'),
        );
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->forks;
    }
}
