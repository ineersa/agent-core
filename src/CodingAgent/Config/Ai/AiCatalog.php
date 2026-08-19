<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Bundled AI provider catalog + user copy under ~/.hatfield/ai-catalog.yaml.
 *
 * Bundled config/ai-catalog.yaml is frozen in the install. First load copies it
 * to the user path. Runtime parses the user copy (bundled fallback if missing/
 * corrupt). models.dev never touches this class — providers:update writes the
 * user catalog.
 */
final class AiCatalog
{
    public const USER_CATALOG_RELATIVE_PATH = '.hatfield/ai-catalog.yaml';

    public function __construct(
        private readonly string $catalogPath,
        private readonly string $homeDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Settings-shaped layer: `{ ai: { providers: { ... } } }`, or [] when neither
     * user nor bundled catalog is readable.
     *
     * @return array<string, mixed>
     */
    public function loadProviders(): array
    {
        $this->ensureUserCatalog();

        $user = $this->readCatalogFile($this->userCatalogPath());
        $bundled = $this->readCatalogFile($this->catalogPath);

        $source = null !== $user ? $user : $bundled;
        if (null === $source) {
            return [];
        }

        $this->warnIfBundledNewer($bundled, $user);

        $shaped = [];
        foreach ($source['providers'] as $id => $provider) {
            if (!\is_string($id) || !\is_array($provider)) {
                continue;
            }
            unset($provider['label'], $provider['kind'], $provider['auth_command']);
            $shaped[$id] = $provider;
        }

        return ['ai' => ['providers' => $shaped]];
    }

    /**
     * Copy bundled default to ~/.hatfield/ai-catalog.yaml when the user copy is absent.
     */
    public function ensureUserCatalog(): void
    {
        $userPath = $this->userCatalogPath();
        if (is_readable($userPath)) {
            return;
        }

        if (!is_readable($this->catalogPath)) {
            return;
        }

        $contents = file_get_contents($this->catalogPath);
        if (false === $contents || '' === trim($contents)) {
            return;
        }

        AtomicFileWriter::write($userPath, $contents, fileMode: 0o600, directoryMode: 0o700);
    }

    public function userCatalogPath(): string
    {
        return rtrim($this->homeDir, '/').'/'.self::USER_CATALOG_RELATIVE_PATH;
    }

    /**
     * True when the bundled default version is newer than the readable user copy.
     * Does not bootstrap or mutate files — callers decide when to ensure/copy.
     */
    public function isBundledNewerThanUser(): bool
    {
        $user = $this->readCatalogFile($this->userCatalogPath());
        $bundled = $this->readCatalogFile($this->catalogPath);
        if (null === $bundled || null === $user) {
            return false;
        }

        return $bundled['version'] > $user['version'];
    }

    /**
     * @return array{version: int, providers: array<string, mixed>}|null
     */
    public function readBundledCatalog(): ?array
    {
        return $this->readCatalogFile($this->catalogPath);
    }

    /**
     * @return array{version: int, providers: array<string, mixed>}|null
     */
    public function readUserCatalog(): ?array
    {
        return $this->readCatalogFile($this->userCatalogPath());
    }

    /**
     * @param array{version: int, providers: array<string, mixed>} $catalog
     */
    public function writeUserCatalog(array $catalog): void
    {
        $yaml = Yaml::dump([
            'version' => $catalog['version'],
            'providers' => $catalog['providers'],
        ], 6, 4);

        AtomicFileWriter::write($this->userCatalogPath(), $yaml, fileMode: 0o600, directoryMode: 0o700);
    }

    /**
     * @return array{version: int, providers: array<string, mixed>}|null
     */
    private function readCatalogFile(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        try {
            $data = Yaml::parse($content);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        $providers = $data['providers'] ?? null;
        if (!\is_array($providers)) {
            return null;
        }

        $version = $data['version'] ?? 0;
        if (!is_numeric($version)) {
            $version = 0;
        }

        return [
            'version' => (int) $version,
            'providers' => $providers,
        ];
    }

    /**
     * @param array{version: int, providers: array<string, mixed>}|null $bundled
     * @param array{version: int, providers: array<string, mixed>}|null $user
     */
    private function warnIfBundledNewer(?array $bundled, ?array $user): void
    {
        if (null === $bundled || null === $user || $bundled['version'] <= $user['version']) {
            return;
        }

        $this->logger?->warning(\sprintf(
            'AI catalog default version %d is newer than your copy (%d). Run `hatfield providers:update` to refresh %s.',
            $bundled['version'],
            $user['version'],
            $this->userCatalogPath(),
        ));
    }
}
