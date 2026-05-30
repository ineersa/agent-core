<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

/**
 * SafeGuard settings resolved from Hatfield config via the Extension API.
 *
 * Immutable value object. Contains policy fields, tool name mappings,
 * and the built-in protected-read/default-pattern constants.
 *
 * protectedReadPatterns are always additive: the built-in defaults plus
 * any patterns specified in the YAML extensions.settings.safe_guard
 * section. The built-in defaults cannot be removed through config —
 * they mirror Pi's DEFAULT_PROTECTED_READ_PATTERNS.
 *
 * Extensions receive config through ExtensionApiInterface::getSettings('safe_guard'),
 * not through direct AppConfig access. This class is constructed from
 * the settings array provided by the Extension API.
 */
final readonly class SafeGuardConfig
{
    /**
     * Default protected read patterns, matching Pi's DEFAULT_PROTECTED_READ_PATTERNS.
     *
     * These are always active — YAML config can add more via
     * protected_read_patterns but cannot remove these defaults.
     *
     * @var list<string>
     */
    public const array DEFAULT_PROTECTED_READ_PATTERNS = [
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
     * @param list<string> $allowCommandPatterns       Command substrings that bypass destructive/dangerous checks
     * @param list<string> $allowWriteOutsideCwd        Absolute paths where writes outside CWD are always allowed
     * @param list<string> $allowDestructiveInPaths     Paths where destructive file ops are always allowed (compat — not wired)
     * @param list<string> $protectedReadPatterns       Effective protected read patterns (defaults + YAML patterns)
     * @param list<string> $dangerousCommandPatterns    Extra command substrings to treat as dangerous (added to built-ins)
     * @param string       $bashToolName                Tool name used for bash (default: 'bash')
     * @param string       $writeToolName               Tool name used for write (default: 'write')
     * @param string       $editToolName                Tool name used for edit (default: 'edit')
     * @param string       $readToolName                Tool name used for read (default: 'read')
     */
    public function __construct(
        public array $allowCommandPatterns = [],
        public array $allowWriteOutsideCwd = [],
        public array $allowDestructiveInPaths = [],
        public array $protectedReadPatterns = self::DEFAULT_PROTECTED_READ_PATTERNS,
        public array $dangerousCommandPatterns = [],
        public string $bashToolName = 'bash',
        public string $writeToolName = 'write',
        public string $editToolName = 'edit',
        public string $readToolName = 'read',
    ) {
    }

    /**
     * Build SafeGuardConfig from raw YAML data, merging defaults.
     *
     * protected_read_patterns from YAML are additive on top of the built-in
     * defaults. All other fields use the YAML value or empty defaults.
     *
     * @param array<string, mixed> $data Raw safe_guard section from merged config
     */
    public static function fromArray(array $data): self
    {
        $toolNames = \is_array($data['tool_names'] ?? null) ? $data['tool_names'] : [];

        return new self(
            allowCommandPatterns: self::stringListValue($data, 'allow_command_patterns'),
            allowWriteOutsideCwd: self::stringListValue($data, 'allow_write_outside_cwd'),
            allowDestructiveInPaths: self::stringListValue($data, 'allow_destructive_in_paths'),
            protectedReadPatterns: self::mergedProtectedReadPatterns(
                self::stringListValue($data, 'protected_read_patterns'),
            ),
            dangerousCommandPatterns: self::stringListValue($data, 'dangerous_command_patterns'),
            bashToolName: (string) ($toolNames['bash'] ?? 'bash'),
            writeToolName: (string) ($toolNames['write'] ?? 'write'),
            editToolName: (string) ($toolNames['edit'] ?? 'edit'),
            readToolName: (string) ($toolNames['read'] ?? 'read'),
        );
    }

    /**
     * Merge built-in defaults with YAML-specified protected read patterns,
     * deduplicating so each pattern appears only once.
     *
     * @param list<string> $yamlPatterns Patterns from YAML config
     * @return list<string>
     */
    private static function mergedProtectedReadPatterns(array $yamlPatterns): array
    {
        return \array_values(\array_unique([
            ...self::DEFAULT_PROTECTED_READ_PATTERNS,
            ...$yamlPatterns,
        ]));
    }

    /**
     * Extract a non-empty list of strings from a config key, defaulting to [].
     *
     * @param array<string, mixed> $data Raw config data
     * @param string               $key  Config key
     * @return list<string>
     */
    private static function stringListValue(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter(
            \array_map(static fn (mixed $item): string => (string) $item, $value),
            static fn (string $s): bool => '' !== $s,
        ));
    }
}
