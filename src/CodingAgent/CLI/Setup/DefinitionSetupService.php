<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Setup;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
use Ineersa\CodingAgent\CLI\Setup\Exception\DefinitionSetupRefusalException;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SetupSettingsService;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\DTO\SkillFrontmatterDTO;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

final readonly class DefinitionSetupService
{
    public function __construct(
        private AgentDefinitionDiscovery $agents,
        private SkillDiscovery $skills,
        private AgentDefinitionParser $agentParser,
        private MarkdownFrontmatterExtractor $extractor,
        private SettingsPathResolver $paths,
        private AppConfig $app,
        private AppResourceLocator $resources,
        private Filesystem $filesystem,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
        private SetupSettingsService $settings,
    ) {
    }

    /** @return array<string, array{name: string, owner: string, editable: bool, builtIn: bool, shadowedBy: ?string, reveals: ?string}> */
    public function entries(bool $skill, SettingsLayerEnum $scope): array
    {
        $entries = [];
        $seen = [];
        foreach ($skill ? $this->skills->sources() : $this->agents->sources() as $path => $source) {
            $name = $source['name'];
            $owner = $source['origin'] ?? (($source['custom'] ?? false) ? 'custom path' : 'local');
            $builtIn = 'built-in' === $owner || (!$skill && 'local' === $owner
                && $path === rtrim($this->paths->getHomeDir(), '/').'/.hatfield/agents/'.basename($path)
                && is_file($this->resources->getBuiltinAgentsPath().'/'.basename($path)));
            if ('local' === $owner || $builtIn) {
                $owner = $this->owner($path, $skill);
            }
            // Skill names collide case-insensitively in SkillDiscovery; agent names compare exactly.
            $key = $skill ? strtolower($name) : $name;
            $winner = $seen[$key] ?? null;
            $entries[$path] = ['name' => $name, 'owner' => $owner, 'editable' => $scope->value === $owner, 'builtIn' => $builtIn, 'shadowedBy' => $winner, 'reveals' => null];
            if (null === $winner) {
                $seen[$key] = $path;
            } else {
                $entries[$winner]['reveals'] ??= $path;
            }
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    public function fields(string $raw, bool $preserveYamlTypes = false): array
    {
        $frontmatter = $this->extractor->extract($raw);
        $values = null === $frontmatter['yamlBlock'] ? [] : Yaml::parse($frontmatter['yamlBlock'], $preserveYamlTypes ? Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME | Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE : 0);
        if ($values instanceof \stdClass) {
            $values = get_object_vars($values);
        }
        if (($frontmatter['hasOpeningDelimiter'] && !$frontmatter['hasClosingDelimiter']) || (null !== $values && (!\is_array($values) || ([] !== $values && array_is_list($values))))) {
            throw new DefinitionSetupRefusalException('Frontmatter must be a closed YAML mapping.');
        }

        return $values ?? [];
    }

    /** @return array<string, string> */
    public function supportedFields(bool $skill): array
    {
        $fields = [];
        foreach (new \ReflectionClass($skill ? SkillFrontmatterDTO::class : AgentFrontmatterDTO::class)->getProperties() as $property) {
            $name = $property->getAttributes(SerializedName::class)[0] ?? null;
            $fields[$name?->newInstance()->serializedName ?? $property->getName()] = (string) $property->getType();
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    public function choices(string $key, string $type, array $fields): array
    {
        $app = \in_array($key, ['model', 'thinking'], true) ? $this->settings->loadConfig() : null;

        return match (true) {
            'bool' === $type => ['', 'true', 'false'],
            'systemPromptMode' === $key => ['', ...array_column(SystemPromptModeEnum::cases(), 'value')],
            'model' === $key => ['', ...array_map(static fn ($ref): string => $ref->toString(), $app?->catalog?->allModels() ?? [])],
            'thinking' === $key => ['', 'off', ...array_values(array_diff(array_keys($this->thinkingMap($fields, $app)), ['off']))],
            default => [],
        };
    }

    /** @return list<class-string> */
    public function availableExtensions(): array
    {
        return $this->settings->loadConfig()->extensions->enabledClasses();
    }

    public function fieldHelp(bool $skill, string $field): ?string
    {
        // Match the frontmatter reference in docs/agents.md and docs/skills.md.
        $help = match ($field) {
            'name' => $skill ? 'Skill name used in /skill commands; defaults to the directory name.' : 'Launch key used to select this named agent.',
            'description' => 'Describe when to use this definition; shown in the catalog.',
            'tools' => 'Built-in and MCP tool allowlist. Leave unset to inherit tools available to the parent.',
            'model' => 'Model override for this child. Unset inherits the parent model or configured default.',
            'thinking' => 'Reasoning effort for the child model. Unset inherits the parent or default effort.',
            'skills' => 'Skill names whose full instructions are loaded for the child, including on-demand skills.',
            'extensions' => 'Optional child extensions. Always-on agent extensions are applied separately. Space toggles; Enter applies; Esc cancels.',
            'inheritProjectContext' => 'Include project instructions such as AGENTS.md in the child context. Defaults to true.',
            'systemPromptMode' => 'Replace uses the agent prompt; append also includes APPEND_SYSTEM.md and extension prompt contributions. Defaults to replace.',
            'disable-model-invocation' => 'Hide this skill from the model catalog while keeping explicit invocation. Defaults to false.',
            default => null,
        };
        $metadata = $this->validator->getMetadataFor($skill ? SkillFrontmatterDTO::class : AgentFrontmatterDTO::class);
        if (!$metadata instanceof ClassMetadataInterface) {
            return $help;
        }
        $property = 'disable-model-invocation' === $field ? 'disableModelInvocation' : $field;
        foreach ($metadata->getPropertyMetadata($property) as $item) {
            foreach ($item->getConstraints() as $constraint) {
                if ($constraint instanceof Regex || $constraint instanceof Choice || $constraint instanceof NotBlank) {
                    return null === $help ? $constraint->message : $help.' '.$constraint->message;
                }
            }
        }

        return $help;
    }

    public function read(string $path): string
    {
        return $this->filesystem->readFile($path);
    }

    public function save(bool $skill, SettingsLayerEnum $scope, string $path, string $expected, string $field, string $value): string
    {
        $this->requireEditable($skill, $scope, $path, $expected);
        $fields = $this->fields($expected, preserveYamlTypes: true);
        $type = $this->supportedFields($skill)[$field] ?? throw new DefinitionSetupRefusalException('Unsupported frontmatter field.');
        if ('' === $value && ($skill || !\in_array($field, ['name', 'description'], true))) {
            unset($fields[$field]);
        } else {
            $fields[$field] = match ($type) {
                'bool', 'array', '?array' => Yaml::parse($value),
                default => $value,
            };
        }
        $extracted = $this->extractor->extract($expected, preserveBody: true);
        $body = $extracted['hasClosingDelimiter'] ? $extracted['body'] : $expected;
        // Keep nested YAML node types while ensuring the closing delimiter starts on its own line.
        $yaml = Yaml::dump((object) $fields, 6, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $candidate = "---\n".rtrim($yaml, "\n")."\n---\n".$body;
        $validatedFields = $this->fields($candidate);
        if ($skill) {
            $input = array_intersect_key($validatedFields, $this->supportedFields(true));
            $dto = $this->denormalizer->denormalize($input, SkillFrontmatterDTO::class);
            $violations = $this->validator->validate($dto);
            if (0 !== \count($violations)) {
                throw new DefinitionSetupRefusalException((string) $violations[0]->getMessage());
            }
        } else {
            $this->agentParser->parseContent($candidate, $path);
            $previous = $this->fields($expected);
            if ('' !== $value && \in_array($field, ['model', 'thinking'], true) && ($previous[$field] ?? null) !== $value
                && !\in_array($value, $this->choices($field, $type, $validatedFields), true)) {
                throw new DefinitionSetupRefusalException('That choice is no longer available. Reopen the picker.');
            }
            if ('extensions' === $field && [] !== $added = array_diff($validatedFields[$field] ?? [], $previous[$field] ?? [])) {
                if ([] !== array_diff($added, $this->availableExtensions())) {
                    throw new DefinitionSetupRefusalException('A selected extension is no longer available. Reopen the picker.');
                }
            }
        }
        $this->filesystem->dumpFile($path, $candidate);

        return $candidate;
    }

    public function delete(bool $skill, SettingsLayerEnum $scope, string $path, string $expected): void
    {
        $this->requireEditable($skill, $scope, $path, $expected, deleting: true);
        // Filesystem::remove unlinks symlink entries; it never follows them recursively.
        $this->filesystem->remove($skill ? \dirname($path) : $path);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function thinkingMap(array $fields, ?AppConfig $app): array
    {
        $catalog = $app?->catalog;
        if (null === $catalog) {
            return [];
        }
        $explicit = $fields['model'] ?? null;
        $default = $catalog->defaultModelReference();
        $ref = \is_string($explicit) && '' !== $explicit ? $explicit : (null !== $default && $catalog->isAvailable($default) ? $default : $catalog->firstAvailableModel());
        if (null === $ref) {
            return [];
        }

        return $catalog->getModel($ref)->thinkingLevelMap ?? [];
    }

    private function owner(string $path, bool $skill): string
    {
        clearstatcache(true);
        if (realpath($path) !== $path) {
            return 'linked path';
        }
        foreach (['project' => $this->app->cwd, 'user' => $this->paths->getHomeDir()] as $scope => $base) {
            $roots = $skill ? ['/.hatfield/skills/', '/.agents/skills/'] : ['/.hatfield/agents/', '/.agents/'];
            foreach ($roots as $root) {
                if (str_starts_with($path, rtrim($base, '/').$root)) {
                    if ($skill && rtrim($base, '/').rtrim($root, '/') === \dirname($path)) {
                        return 'shared skills root';
                    }

                    return $scope;
                }
            }
        }

        return 'outside supported roots';
    }

    private function requireEditable(bool $skill, SettingsLayerEnum $scope, string $path, string $expected, bool $deleting = false): void
    {
        $entry = $this->entries($skill, $scope)[$path] ?? null;
        if (null === $entry || !$entry['editable'] || $scope->value !== $this->owner($path, $skill) || $expected !== $this->read($path)) {
            throw new DefinitionSetupRefusalException('Definition is not editable in this scope or changed on disk. Reopen it before editing.');
        }
        if ($deleting && $entry['builtIn']) {
            throw new DefinitionSetupRefusalException('Built-in definitions cannot be deleted in setup.');
        }
    }
}
