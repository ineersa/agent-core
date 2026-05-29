<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy;

/**
 * Loads SafeGuard policy from filesystem with Pi-compatible precedence.
 *
 * Precedence:
 *   1. Project-local: <cwd>/.hatfield/safe-guard.json
 *   2. Global: ~/.hatfield/safe-guard.json
 *   3. Built-in defaults
 *
 * Project-local replaces global entirely (not merged).
 * protectedReadPatterns is always additive: built-in defaults + file patterns.
 * Invalid JSON files are silently ignored.
 */
final class SafeGuardPolicyStore
{
    /**
     * Default protected read patterns, matching Pi's DEFAULT_PROTECTED_READ_PATTERNS.
     *
     * These are always active — a policy file can add more via its
     * protectedReadPatterns field but cannot remove these defaults.
     *
     * @var list<string>
     */
    public const DEFAULT_PROTECTED_READ_PATTERNS = [
        // env files with real secrets (tracked .env / .env.dev / .env.prod are fine)
        '.env.local',
        '.env.dev.local',
        '.env.prod.local',
        '.env.staging.local',
        '.env.test.local',
        // auth / credentials
        'auth.json',
        'credentials.json',
        '.netrc',
        '.npmrc',
        // shell configs (may contain secrets, tokens, API keys)
        '.bashrc',
        '.zshrc',
        '.bash_profile',
        '.zprofile',
        '.profile',
        '.bash_history',
        '.zsh_history',
        // SSH
        '.ssh/id_',
        '.ssh/config',
        '.ssh/known_hosts',
        // Cloud / kube
        '.aws/credentials',
        '.aws/config',
        '.kube/config',
        '.gcp/',
        '.config/gcloud/',
        '.azure/',
        // Key / certificate files
        '.pem',
        '.pkcs12',
        '.p12',
        '.pfx',
        // Service accounts
        'service-account',
    ];

    /**
     * Default policy used when no policy file exists anywhere.
     */
    public function defaultPolicy(): SafeGuardPolicy
    {
        return new SafeGuardPolicy(
            allowCommandPatterns: [],
            allowWriteOutsideCwd: [],
            allowDestructiveInPaths: [],
            protectedReadPatterns: self::DEFAULT_PROTECTED_READ_PATTERNS,
            dangerousCommandPatterns: [],
        );
    }

    /**
     * Load the effective policy for a given working directory.
     *
     * Mirrors Pi's loadPolicy(cwd).
     */
    public function load(string $cwd): SafeGuardPolicy
    {
        // Project-local policy takes precedence
        $local = $this->readFile($this->policyFilePath($cwd));
        if (null !== $local) {
            return $local;
        }

        // Fall back to global policy (~/.hatfield/safe-guard.json)
        $global = $this->readFile($this->globalPolicyFilePath());
        if (null !== $global) {
            return $global;
        }

        // No policy file anywhere — use built-in defaults
        return $this->defaultPolicy();
    }

    /**
     * Project-local policy path: <cwd>/.hatfield/safe-guard.json
     */
    public function policyFilePath(string $cwd): string
    {
        return $cwd . '/.hatfield/safe-guard.json';
    }

    /**
     * Global policy path: ~/.hatfield/safe-guard.json
     */
    public function globalPolicyFilePath(): string
    {
        $home = \getenv('HOME') ?: '~';

        return $home . '/.hatfield/safe-guard.json';
    }

    /**
     * Read and parse a single policy file. Returns null on any failure.
     *
     * Mirrors Pi's readPolicyFile().
     */
    private function readFile(string $filePath): ?SafeGuardPolicy
    {
        if (!\is_file($filePath) || !\is_readable($filePath)) {
            return null;
        }

        try {
            $contents = \file_get_contents($filePath);
            if (false === $contents || '' === \trim($contents)) {
                return null;
            }

            $raw = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($raw)) {
                return null;
            }

            return $this->fromArray($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a SafeGuardPolicy from a decoded JSON array.
     *
     * protectedReadPatterns is additive: defaults + file-specified patterns.
     * All other fields use the file's value or empty defaults.
     */
    private function fromArray(array $raw): SafeGuardPolicy
    {
        $fileProtected = $raw['protectedReadPatterns'] ?? [];

        return new SafeGuardPolicy(
            allowCommandPatterns: $this->stringList($raw, 'allowCommandPatterns'),
            allowWriteOutsideCwd: $this->stringList($raw, 'allowWriteOutsideCwd'),
            allowDestructiveInPaths: $this->stringList($raw, 'allowDestructiveInPaths'),
            protectedReadPatterns: \array_values(\array_unique([
                ...self::DEFAULT_PROTECTED_READ_PATTERNS,
                ...(\is_array($fileProtected) ? $fileProtected : []),
            ])),
            dangerousCommandPatterns: $this->stringList($raw, 'dangerousCommandPatterns'),
        );
    }

    /**
     * Extract a list of strings from an array key, defaulting to [].
     *
     * @return list<string>
     */
    private function stringList(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];

        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter(
            \array_map(static fn (mixed $item): string => (string) $item, $value),
            static fn (string $s): bool => '' !== $s,
        ));
    }
}
