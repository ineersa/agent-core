<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads curated config/ai-catalog.yaml into an ai.providers-shaped array.
 */
final class AiCatalogLoader
{
    /**
     * @return array<string, mixed> providers map (may be empty)
     */
    public function loadProviders(string $catalogPath): array
    {
        if (!is_readable($catalogPath)) {
            return [];
        }

        $content = file_get_contents($catalogPath);
        if (false === $content || '' === trim($content)) {
            return [];
        }

        $data = Yaml::parse($content);
        if (!\is_array($data)) {
            return [];
        }

        $providers = $data['providers'] ?? null;

        return \is_array($providers) ? $providers : [];
    }
}
