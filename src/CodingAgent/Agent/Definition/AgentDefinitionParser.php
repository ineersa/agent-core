<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Symfony\Component\Serializer\Exception\ExtraAttributesException;
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
 * {@see AgentFrontmatterParser}, denormalizes into {@see AgentFrontmatterDTO}
 * using Symfony Serializer (with strict type enforcement and unknown-field
 * rejection), validates with Symfony Validator (including cross-field callbacks
 * on the DTO), and maps the result into a final {@see AgentDefinitionDTO}.
 *
 * Rules (enforced by Serializer + Validator, not manual is_* checks):
 *  - Unknown fields are rejected (Serializer ALLOW_EXTRA_ATTRIBUTES=false).
 *  - Required fields must be present and of the correct type.
 *  - No type coercion (Serializer type enforcement enabled by default).
 *  - Every error message includes the file path and field property path.
 *
 * @internal
 */
final class AgentDefinitionParser
{
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

        $frontmatter = $this->normalizeFrontmatterFields($frontmatter);

        // 2. Denormalize frontmatter array → AgentFrontmatterDTO
        //    (strict type enforcement + unknown-field rejection via Serializer)
        $frontmatterDto = $this->denormalizeFrontmatter($frontmatter, $filePath);

        // 3. Validate with Symfony Validator
        //    (property-level constraints + cross-field callbacks on the DTO)
        $this->validateFrontmatterDto($frontmatterDto, $filePath);

        // 4. Map to final AgentDefinitionDTO (trim strings, convert enums)
        return $this->mapToDefinition($frontmatterDto, $body, $filePath);
    }

    /**
     * Normalize observed real-world agent frontmatter shapes before strict denormalization.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed>
     */
    private function normalizeFrontmatterFields(array $frontmatter): array
    {
        if (\array_key_exists('skill', $frontmatter)) {
            $fromSkill = \is_string($frontmatter['skill'])
                ? $this->splitCommaSeparatedScalars($frontmatter['skill'])
                : [];
            unset($frontmatter['skill']);
            $existing = [];
            if (\array_key_exists('skills', $frontmatter)) {
                if (\is_string($frontmatter['skills'])) {
                    $existing = $this->splitCommaSeparatedScalars($frontmatter['skills']);
                } elseif (\is_array($frontmatter['skills']) && array_is_list($frontmatter['skills'])) {
                    $existing = $frontmatter['skills'];
                }
            }
            $frontmatter['skills'] = array_values(array_unique(array_merge($existing, $fromSkill)));
        }

        if (\array_key_exists('tools', $frontmatter) && \is_string($frontmatter['tools'])) {
            $frontmatter['tools'] = $this->splitCommaSeparatedScalars($frontmatter['tools']);
        } elseif (!\array_key_exists('tools', $frontmatter)) {
            // Missing tools is common in ~/.agents definitions; default to read for launch policy.
            $frontmatter['tools'] = ['read'];
        }

        if (\array_key_exists('skills', $frontmatter) && \is_string($frontmatter['skills'])) {
            $frontmatter['skills'] = $this->splitCommaSeparatedScalars($frontmatter['skills']);
        }

        return $frontmatter;
    }

    /**
     * @return list<string>
     */
    private function splitCommaSeparatedScalars(string $value): array
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return [];
        }

        if (!str_contains($trimmed, ',')) {
            return [$trimmed];
        }

        $parts = array_map(trim(...), explode(',', $trimmed));

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * Denormalize the frontmatter array into a typed DTO.
     *
     * Uses Symfony Serializer with strict type enforcement (DISABLE_TYPE_ENFORCEMENT
     * NOT set — default is false, so type mismatches are rejected).  Unknown
     * fields are rejected via ALLOW_EXTRA_ATTRIBUTES=false.
     *
     * Nested unknown fields (mcp.*) are checked manually because Symfony
     * Serializer's ExtraAttributesException from nested objects does not
     * carry the parent path in its attribute names.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @throws AgentDefinitionValidationException
     */
    private function denormalizeFrontmatter(array $frontmatter, string $filePath): AgentFrontmatterDTO
    {
        // Reject unknown MCP sub-fields manually: Serializer's
        // ALLOW_EXTRA_ATTRIBUTES=false works for top-level but nested-object
        // ExtraAttributesException does not carry the "mcp." path prefix, so
        // we check mcp sub-fields before denormalization for clear messages.
        if (isset($frontmatter['mcp']) && \is_array($frontmatter['mcp'])) {
            foreach (array_keys($frontmatter['mcp']) as $mcpKey) {
                if (!\in_array($mcpKey, ['mode', 'tools'], true)) {
                    throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): unknown field "mcp.%s".', $filePath, $mcpKey));
                }
            }
        }

        // Trim name so the regex validation on the DTO sees a clean value.
        if (isset($frontmatter['name']) && \is_string($frontmatter['name'])) {
            $frontmatter['name'] = trim($frontmatter['name']);
        }

        try {
            return $this->denormalizer->denormalize(
                $frontmatter,
                AgentFrontmatterDTO::class,
                context: [
                    AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                ],
            );
        } catch (MissingConstructorArgumentsException $e) {
            $missingArgs = $e->getMissingConstructorArguments();
            $first = reset($missingArgs);

            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" is required.', $filePath, ltrim((string) $first, '$')));
        } catch (ExtraAttributesException $e) {
            $extraAttributes = $e->getExtraAttributes();
            $first = reset($extraAttributes);

            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): unknown field "%s".', $filePath, (string) $first));
        } catch (NotNormalizableValueException $e) {
            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s" must be of type %s.', $filePath, $e->getPath() ?? 'a field', implode('|', $e->getExpectedTypes() ?? [])));
        } catch (\TypeError $e) {
            $message = $e->getMessage();
            $field = 'a field';
            if (preg_match('/Argument #\d+ \\((\$\w+)\\)/', $message, $matches)) {
                $field = '"'.ltrim($matches[1], '$').'"';
            }

            throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): %s has an invalid type.', $filePath, $field));
        }
    }

    /**
     * Validate the denormalized DTO using Symfony Validator.
     *
     * This includes property-level constraints and cross-field invariants
     * defined via {@see Assert\Callback} on {@see AgentFrontmatterDTO}.
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

        $path = '' !== $propertyPath ? $propertyPath : 'a field';
        throw new AgentDefinitionValidationException(\sprintf('Agent definition ("%s"): "%s": %s', $filePath, $path, $message));
    }

    /**
     * Map the validated frontmatter DTO into the final AgentDefinitionDTO.
     *
     * Handles trimming of string fields and conversion of enum-like strings
     * into proper enum objects. Tools/skills/mcp.tools are already validated
     * to have no leading/trailing whitespace, but trimming here is a safety net.
     */
    private function mapToDefinition(AgentFrontmatterDTO $dto, string $body, string $filePath): AgentDefinitionDTO
    {
        $mcp = $dto->mcp;
        $mcpMode = null === $mcp ? McpAgentModeEnum::None : McpAgentModeEnum::from($mcp->mode ?? 'none');
        $mcpTools = null === $mcp ? [] : array_values($mcp->tools);

        return new AgentDefinitionDTO(
            name: trim($dto->name),
            description: trim($dto->description),
            tools: array_values($dto->tools),
            mcp: new McpPolicyDTO(mode: $mcpMode, tools: $mcpTools),
            model: null !== $dto->model ? trim($dto->model) : null,
            thinking: null !== $dto->thinking ? trim($dto->thinking) : null,
            skills: array_values($dto->skills),
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
