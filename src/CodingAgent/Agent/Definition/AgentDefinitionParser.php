<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Parses and validates a single agent definition Markdown file.
 *
 * Reads the file (or accepts raw content), extracts YAML frontmatter via
 * {@see AgentFrontmatterParser}, validates every field against the schema,
 * applies defaults, and returns a fully-typed {@see AgentDefinitionDTO}.
 *
 * Rules:
 *  - Unknown top-level frontmatter fields are rejected with an actionable error.
 *  - Required fields must be present and of the correct type.
 *  - No type coercion: "3" is not an int, "true" is not a bool.
 *  - Every error message includes the file path and field name.
 *
 * @internal
 */
final class AgentDefinitionParser
{
    /** Valid reasoning/thinking levels. */
    private const VALID_THINKING = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    private const ALLOWED_FIELDS = [
        'name',
        'description',
        'type',
        'model',
        'thinking',
        'tools',
        'mcp',
        'skills',
        'inheritProjectContext',
        'inheritAgentsMd',
        'systemPromptMode',
        'maxDepth',
        'backgroundAllowed',
        'foregroundAllowed',
        'parallelAllowed',
        'disabled',
        'handoffFormat',
    ];

    public function __construct(
        private readonly AgentFrontmatterParser $frontmatterParser = new AgentFrontmatterParser(),
    ) {
    }

    /**
     * Parse a single agent definition file.
     *
     * @param string $filePath Absolute path to the definition file
     *
     * @throws AgentDefinitionValidationException for any validation failure
     */
    public function parseFile(string $filePath): AgentDefinitionDTO
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): file not found or not readable.', $filePath));
        }

        $raw = file_get_contents($filePath);
        if (false === $raw) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): failed to read file.', $filePath));
        }

        return $this->parseContent($raw, $filePath);
    }

    /**
     * Parse raw Markdown content (useful for tests without real files).
     *
     * @param string $raw      Raw file content
     * @param string $filePath File path for error messages (may be synthetic for tests)
     *
     * @throws AgentDefinitionValidationException for any validation failure
     */
    public function parseContent(string $raw, string $filePath): AgentDefinitionDTO
    {
        $parsed = $this->frontmatterParser->parse($raw, $filePath);

        $frontmatter = $parsed['frontmatter'];
        $body = $parsed['body'];

        // --- Unknown field check ---
        $this->checkUnknownFields($frontmatter, $filePath);

        // --- Required fields ---
        $name = $this->validateName($frontmatter, $filePath);
        $description = $this->validateDescription($frontmatter, $filePath);
        $type = $this->validateType($frontmatter, $filePath);
        $tools = $this->validateTools($frontmatter, $filePath);

        // --- Optional fields with defaults ---
        $model = $this->validateOptionalString('model', $frontmatter, $filePath);
        $thinking = $this->validateThinking($frontmatter, $filePath);
        $skills = $this->validateOptionalStringList('skills', $frontmatter, $filePath);
        $inheritProjectContext = $this->validateOptionalBool('inheritProjectContext', $frontmatter, $filePath, true);
        $inheritAgentsMd = $this->validateOptionalBool('inheritAgentsMd', $frontmatter, $filePath, true);
        $systemPromptMode = $this->validateSystemPromptMode($frontmatter, $filePath);
        $maxDepth = $this->validateMaxDepth($frontmatter, $filePath);
        $backgroundAllowed = $this->validateOptionalBool('backgroundAllowed', $frontmatter, $filePath, true);
        $foregroundAllowed = $this->validateOptionalBool('foregroundAllowed', $frontmatter, $filePath, true);
        $parallelAllowed = $this->validateOptionalBool('parallelAllowed', $frontmatter, $filePath, false);
        $disabled = $this->validateOptionalBool('disabled', $frontmatter, $filePath, false);
        $handoffFormat = $this->validateOptionalString('handoffFormat', $frontmatter, $filePath);
        $mcp = $this->validateMcp($frontmatter, $filePath);

        // --- Cross-field invariants ---
        if (!$backgroundAllowed && !$foregroundAllowed) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "backgroundAllowed" and "foregroundAllowed" cannot both be false — the agent would never be launchable.', $filePath));
        }

        $sourceDir = \dirname($filePath);

        return new AgentDefinitionDTO(
            name: $name,
            description: $description,
            type: $type,
            model: $model,
            thinking: $thinking,
            tools: $tools,
            mcp: $mcp,
            skills: $skills,
            inheritProjectContext: $inheritProjectContext,
            inheritAgentsMd: $inheritAgentsMd,
            systemPromptMode: $systemPromptMode,
            maxDepth: $maxDepth,
            backgroundAllowed: $backgroundAllowed,
            foregroundAllowed: $foregroundAllowed,
            parallelAllowed: $parallelAllowed,
            disabled: $disabled,
            handoffFormat: $handoffFormat,
            instructions: $body,
            sourcePath: $filePath,
            sourceDirectory: $sourceDir,
        );
    }

    // --- Unknown field guard ---

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function checkUnknownFields(array $frontmatter, string $filePath): void
    {
        foreach (array_keys($frontmatter) as $key) {
            if (!\in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): unknown field "%s". Allowed fields: %s.', $filePath, $key, implode(', ', self::ALLOWED_FIELDS)));
            }
        }
    }

    // --- Required field validators ---

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateName(array $frontmatter, string $filePath): string
    {
        if (!\array_key_exists('name', $frontmatter)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "name" is required.', $filePath));
        }

        $name = $frontmatter['name'];

        if (!\is_string($name)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "name" must be a string, got %s.', $filePath, \gettype($name)));
        }

        $trimmed = trim($name);
        if ('' === $trimmed) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "name" must not be empty.', $filePath));
        }

        if (!preg_match('/^[a-z][a-z0-9-]{0,47}$/', $trimmed)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "name" must be lowercase alphanumeric with hyphens (e.g. "my-agent"), got "%s".', $filePath, $trimmed));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateDescription(array $frontmatter, string $filePath): string
    {
        if (!\array_key_exists('description', $frontmatter)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "description" is required.', $filePath));
        }

        $desc = $frontmatter['description'];

        if (!\is_string($desc)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "description" must be a string, got %s.', $filePath, \gettype($desc)));
        }

        $trimmed = trim($desc);
        if ('' === $trimmed) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "description" must not be empty.', $filePath));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateType(array $frontmatter, string $filePath): AgentTypeEnum
    {
        if (!\array_key_exists('type', $frontmatter)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "type" is required.', $filePath));
        }

        $type = $frontmatter['type'];

        if (!\is_string($type)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "type" must be a string, got %s.', $filePath, \gettype($type)));
        }

        $agentType = AgentTypeEnum::tryFrom($type);
        if (null === $agentType) {
            $allowed = array_map(static fn (AgentTypeEnum $e) => $e->value, AgentTypeEnum::cases());
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "type" must be one of [%s], got "%s".', $filePath, implode(', ', $allowed), $type));
        }

        return $agentType;
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    private function validateTools(array $frontmatter, string $filePath): array
    {
        if (!\array_key_exists('tools', $frontmatter)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "tools" is required.', $filePath));
        }

        $tools = $frontmatter['tools'];

        if (!\is_array($tools)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "tools" must be an array, got %s.', $filePath, \gettype($tools)));
        }

        if ([] === $tools) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "tools" must be a non-empty list of strings.', $filePath));
        }

        if (!array_is_list($tools)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "tools" must be a list (sequential array).', $filePath));
        }

        foreach ($tools as $i => $tool) {
            if (!\is_string($tool)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "tools[%d]" must be a string, got %s.', $filePath, $i, \gettype($tool)));
            }
            if ('' === trim($tool)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "tools[%d]" must not be empty.', $filePath, $i));
            }
        }

        /* @var list<string> */
        return $tools;
    }

    // --- Optional field validators ---

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateOptionalString(string $field, array $frontmatter, string $filePath): ?string
    {
        if (!\array_key_exists($field, $frontmatter)) {
            return null;
        }

        $value = $frontmatter[$field];
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must be a string or null, got %s.', $filePath, $field, \gettype($value)));
        }

        $trimmed = trim($value);
        if ('' === $trimmed) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must not be empty.', $filePath, $field));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateOptionalBool(string $field, array $frontmatter, string $filePath, bool $default): bool
    {
        if (!\array_key_exists($field, $frontmatter)) {
            return $default;
        }

        $value = $frontmatter[$field];

        if (!\is_bool($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must be a boolean (true/false), got %s.', $filePath, $field, \gettype($value)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return list<string>
     */
    private function validateOptionalStringList(string $field, array $frontmatter, string $filePath): array
    {
        if (!\array_key_exists($field, $frontmatter)) {
            return [];
        }

        $value = $frontmatter[$field];

        if (!\is_array($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must be an array, got %s.', $filePath, $field, \gettype($value)));
        }

        if ([] === $value) {
            return [];
        }

        if (!array_is_list($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must be a list (sequential array).', $filePath, $field));
        }

        foreach ($value as $i => $item) {
            if (!\is_string($item)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s[%d]" must be a string, got %s.', $filePath, $field, $i, \gettype($item)));
            }
        }

        /* @var list<string> */
        return $value;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateThinking(array $frontmatter, string $filePath): ?string
    {
        if (!\array_key_exists('thinking', $frontmatter)) {
            return null;
        }

        $value = $frontmatter['thinking'];
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "thinking" must be a string or null, got %s.', $filePath, \gettype($value)));
        }

        $trimmed = trim($value);
        if (!\in_array($trimmed, self::VALID_THINKING, true)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "thinking" must be one of [%s], got "%s".', $filePath, implode(', ', self::VALID_THINKING), $trimmed));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateSystemPromptMode(array $frontmatter, string $filePath): SystemPromptModeEnum
    {
        if (!\array_key_exists('systemPromptMode', $frontmatter)) {
            return SystemPromptModeEnum::Replace;
        }

        $value = $frontmatter['systemPromptMode'];

        if (!\is_string($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "systemPromptMode" must be a string, got %s.', $filePath, \gettype($value)));
        }

        $mode = SystemPromptModeEnum::tryFrom($value);
        if (null === $mode) {
            $allowed = array_map(static fn (SystemPromptModeEnum $e) => $e->value, SystemPromptModeEnum::cases());
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "systemPromptMode" must be one of [%s], got "%s".', $filePath, implode(', ', $allowed), $value));
        }

        return $mode;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateMaxDepth(array $frontmatter, string $filePath): int
    {
        if (!\array_key_exists('maxDepth', $frontmatter)) {
            return 1;
        }

        $value = $frontmatter['maxDepth'];

        if (!\is_int($value)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "maxDepth" must be an integer, got %s.', $filePath, \gettype($value)));
        }

        if ($value < 0 || $value > 5) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "maxDepth" must be between 0 and 5, got %d.', $filePath, $value));
        }

        return $value;
    }

    // --- MCP validation ---

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateMcp(array $frontmatter, string $filePath): McpPolicyDTO
    {
        if (!\array_key_exists('mcp', $frontmatter)) {
            return new McpPolicyDTO(mode: McpAgentModeEnum::None, tools: []);
        }

        $mcp = $frontmatter['mcp'];

        if (!\is_array($mcp)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp" must be an object (mapping), got %s.', $filePath, \gettype($mcp)));
        }

        // Validate mcp sub-fields
        $knownMcpFields = ['mode', 'tools'];
        foreach (array_keys($mcp) as $key) {
            if (!\in_array($key, $knownMcpFields, true)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): unknown field "mcp.%s". Allowed MCP fields: %s.', $filePath, $key, implode(', ', $knownMcpFields)));
            }
        }

        // mode
        if (!\array_key_exists('mode', $mcp)) {
            return new McpPolicyDTO(mode: McpAgentModeEnum::None, tools: []);
        }

        $modeRaw = $mcp['mode'];
        if (!\is_string($modeRaw)) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.mode" must be a string, got %s.', $filePath, \gettype($modeRaw)));
        }

        $mode = McpAgentModeEnum::tryFrom($modeRaw);
        if (null === $mode) {
            $allowed = array_map(static fn (McpAgentModeEnum $e) => $e->value, McpAgentModeEnum::cases());
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.mode" must be one of [%s], got "%s".', $filePath, implode(', ', $allowed), $modeRaw));
        }

        // tools
        $tools = [];
        if (\array_key_exists('tools', $mcp)) {
            $toolsRaw = $mcp['tools'];

            if (!\is_array($toolsRaw)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.tools" must be an array, got %s.', $filePath, \gettype($toolsRaw)));
            }

            if ([] !== $toolsRaw && !array_is_list($toolsRaw)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.tools" must be a list (sequential array).', $filePath));
            }

            foreach ($toolsRaw as $i => $tool) {
                if (!\is_string($tool)) {
                    throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.tools[%d]" must be a string, got %s.', $filePath, $i, \gettype($tool)));
                }
            }

            /* @var list<string> */
            $tools = $toolsRaw;
        }

        // Cross-field MCP invariants
        if (McpAgentModeEnum::Specific === $mode && [] === $tools) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.mode" is "specific" but "mcp.tools" is empty or missing — at least one tool must be listed.', $filePath));
        }

        if (McpAgentModeEnum::Specific !== $mode && [] !== $tools) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.tools" is set but "mcp.mode" is "%s". Tools are only meaningful when mode is "specific". Remove "mcp.tools" or set "mcp.mode" to "specific".', $filePath, $mode->value));
        }

        return new McpPolicyDTO(mode: $mode, tools: $tools);
    }
}
