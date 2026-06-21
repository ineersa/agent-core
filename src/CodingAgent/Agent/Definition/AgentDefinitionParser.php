<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Parses and validates a single agent definition Markdown file.
 *
 * Reads the file (or accepts raw content), extracts YAML frontmatter via
 * {@see AgentFrontmatterParser}, checks for unknown fields, denormalizes
 * into {@see AgentFrontmatterDTO} using Symfony Serializer, validates with
 * Symfony Validator, and maps the result into a final {@see AgentDefinitionDTO}.
 *
 * Rules (enforced by Serializer + Validator, not manual is_* checks):
 *  - Unknown top-level frontmatter fields are rejected (known-key check).
 *  - Required fields must be present and of the correct type (Validator).
 *  - No type coercion (Serializer type enforcement disabled).
 *  - Every error message includes the file path and field property path.
 *
 * @internal
 */
final class AgentDefinitionParser
{
    /** Allowed top-level YAML fields (kept in sync with AgentFrontmatterDTO properties). */
    private const ALLOWED_FIELDS = [
        'name',
        'description',
        'tools',
        'model',
        'thinking',
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
        'mcp',
    ];

    /** Allowed MCP sub-fields (kept in sync with McpFrontmatterDTO properties). */
    private const ALLOWED_MCP_FIELDS = ['mode', 'tools'];

    public function __construct(
        private readonly AgentFrontmatterParser $frontmatterParser,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
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
        // 1. Extract frontmatter array from Markdown
        $parsed = $this->frontmatterParser->parse($raw, $filePath);
        $frontmatter = $parsed['frontmatter'];
        $body = $parsed['body'];

        // 2. Denormalize frontmatter array → AgentFrontmatterDTO (rejects unknown fields)
        $frontmatterDto = $this->denormalizeFrontmatter($frontmatter, $filePath);

        // 3. Validate with Symfony Validator
        $this->validateFrontmatterDto($frontmatterDto, $filePath);

        // 4. Cross-field invariants (not expressible as property-level Validator constraints)
        $this->checkCrossFieldInvariants($frontmatterDto, $filePath);

        // 5. Map to final AgentDefinitionDTO (trim strings, convert enums)
        return $this->mapToDefinition($frontmatterDto, $body, $filePath);
    }

    /**
     * Denormalize the frontmatter array into a typed DTO.
     *
     * Rejects unknown fields before denormalization.  Uses Symfony Serializer
     * with DISABLE_TYPE_ENFORCEMENT=true to avoid silent scalar coercion.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @throws AgentDefinitionValidationException
     */
    private function denormalizeFrontmatter(array $frontmatter, string $filePath): AgentFrontmatterDTO
    {
        // Reject unknown top-level fields
        foreach (array_keys($frontmatter) as $key) {
            if (!\in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): unknown field "%s".', $filePath, $key));
            }
        }

        // Reject optional list fields when they are not arrays
        foreach (['skills'] as $listField) {
            if (\array_key_exists($listField, $frontmatter) && !\is_array($frontmatter[$listField])) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must be an array, got %s.', $filePath, $listField, \gettype($frontmatter[$listField])));
            }
        }

        // Reject mcp when it is not null, not an array/mapping
        if (\array_key_exists('mcp', $frontmatter) && null !== $frontmatter['mcp'] && !\is_array($frontmatter['mcp'])) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp" must be an object (mapping) or null, got %s.', $filePath, \gettype($frontmatter['mcp'])));
        }

        // Reject unknown MCP sub-fields
        if (isset($frontmatter['mcp']) && \is_array($frontmatter['mcp'])) {
            foreach (array_keys($frontmatter['mcp']) as $mcpKey) {
                if (!\in_array($mcpKey, self::ALLOWED_MCP_FIELDS, true)) {
                    throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): unknown field "mcp.%s".', $filePath, $mcpKey));
                }
            }
        }

        // Trim string fields where the DTO expects clean values
        if (isset($frontmatter['name']) && \is_string($frontmatter['name'])) {
            $frontmatter['name'] = trim($frontmatter['name']);
        }

        try {
            return $this->denormalizer->denormalize(
                $frontmatter,
                AgentFrontmatterDTO::class,
                context: [
                    AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true,
                ],
            );
        } catch (MissingConstructorArgumentsException $e) {
            $missingArgs = $e->getMissingConstructorArguments();
            $first = reset($missingArgs);

            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" is required.', $filePath, ltrim((string) $first, '$')));
        } catch (NotNormalizableValueException $e) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): %s must be of type %s.', $filePath, $e->getPath() ?? 'a field', implode('|', $e->getExpectedTypes() ?? [])));
        } catch (\TypeError $e) {
            $message = $e->getMessage();
            $field = 'a field';
            if (preg_match('/Argument #\d+ \((\$\w+)\)/', $message, $matches)) {
                $field = '"'.ltrim($matches[1], '$').'"';
            }

            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): %s has an invalid type.', $filePath, $field));
        }
    }

    /**
     * Validate the denormalized DTO using Symfony Validator.
     *
     * @throws AgentDefinitionValidationException
     */
    private function validateFrontmatterDto(AgentFrontmatterDTO $dto, string $filePath): void
    {
        $violations = $this->validator->validate($dto);

        if (0 === $violations->count()) {
            return;
        }

        /** @var ConstraintViolationInterface $violation */
        $violation = $violations->get(0);
        $propertyPath = $violation->getPropertyPath();
        $message = $violation->getMessage();

        throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s": %s', $filePath, $propertyPath ?: 'a field', $message));
    }

    /**
     * Cross-field invariants that cannot be expressed as property-level
     * Validator constraints.
     *
     * @throws AgentDefinitionValidationException
     */
    private function checkCrossFieldInvariants(AgentFrontmatterDTO $dto, string $filePath): void
    {
        // backgroundAllowed and foregroundAllowed cannot both be false.
        if (!$dto->backgroundAllowed && !$dto->foregroundAllowed) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "backgroundAllowed" and "foregroundAllowed" cannot both be false — the agent would never be launchable.', $filePath));
        }

        // MCP invariants
        if (null !== $dto->mcp) {
            $mcp = $dto->mcp;
            $mcpMode = $mcp->mode ?? 'none';

            // mode=specific requires non-empty tools
            if ('specific' === $mcpMode && [] === $mcp->tools) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.mode" is "specific" but "mcp.tools" is empty — at least one tool must be listed.', $filePath));
            }

            // tools set but mode is not specific
            if ('specific' !== $mcpMode && [] !== $mcp->tools) {
                throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "mcp.tools" is set but "mcp.mode" is "%s". Tools are only meaningful when mode is "specific". Remove "mcp.tools" or set "mcp.mode" to "specific".', $filePath, $mcpMode));
            }
        }
    }

    /**
     * Map the validated frontmatter DTO into the final AgentDefinitionDTO.
     *
     * Handles trimming of string fields and conversion of enum-like strings
     * into proper enum objects.
     */
    private function mapToDefinition(AgentFrontmatterDTO $dto, string $body, string $filePath): AgentDefinitionDTO
    {
        $mcpMode = McpAgentModeEnum::from($dto->mcp?->mode ?? 'none');
        $mcpTools = array_values(array_map(trim(...), $dto->mcp?->tools ?? []));

        return new AgentDefinitionDTO(
            name: trim($dto->name),
            description: trim($dto->description),
            tools: array_values(array_map(trim(...), $dto->tools)),
            mcp: new McpPolicyDTO(mode: $mcpMode, tools: $mcpTools),
            model: null !== $dto->model ? trim($dto->model) : null,
            thinking: null !== $dto->thinking ? trim($dto->thinking) : null,
            skills: array_values(array_map(trim(...), $dto->skills)),
            inheritProjectContext: $dto->inheritProjectContext,
            inheritAgentsMd: $dto->inheritAgentsMd,
            systemPromptMode: SystemPromptModeEnum::from($dto->systemPromptMode),
            maxDepth: $dto->maxDepth,
            backgroundAllowed: $dto->backgroundAllowed,
            foregroundAllowed: $dto->foregroundAllowed,
            parallelAllowed: $dto->parallelAllowed,
            disabled: $dto->disabled,
            handoffFormat: null !== $dto->handoffFormat ? trim($dto->handoffFormat) : null,
            instructions: $body,
            sourcePath: $filePath,
            sourceDirectory: \dirname($filePath),
        );
    }
}
