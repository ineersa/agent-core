<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills\Validation;

use Ineersa\CodingAgent\Skills\DTO\SkillFrontmatterDTO;
use Ineersa\CodingAgent\Skills\Exception\SkillFrontmatterValidationException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Single SKILL.md frontmatter rule shared by skill discovery and the setup editor.
 */
final readonly class SkillFrontmatterValidator
{
    private const string FLAG = 'disable-model-invocation';

    /** DTO property path → frontmatter key, for user-facing messages. */
    private const array FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'disableModelInvocation' => self::FLAG,
    ];

    public function __construct(
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param array<array-key, mixed> $frontmatter Parsed YAML frontmatter; unknown keys are allowed
     *
     * @throws SkillFrontmatterValidationException
     */
    public function validate(array $frontmatter): SkillFrontmatterDTO
    {
        // Lenient flag: accept common boolean spellings before strict denormalization.
        // Unrecognized values pass through unchanged so the denormalizer rejects them.
        if (\array_key_exists(self::FLAG, $frontmatter)) {
            $flag = $frontmatter[self::FLAG];
            $frontmatter[self::FLAG] = match (true) {
                null === $flag => false,
                0 === $flag, 1 === $flag => 1 === $flag,
                \is_string($flag) => filter_var($flag, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? $flag,
                default => $flag,
            };
        }

        try {
            $dto = $this->denormalizer->denormalize($frontmatter, SkillFrontmatterDTO::class);
        } catch (NotNormalizableValueException $e) {
            $field = self::FIELDS[$e->getPath() ?? ''] ?? (string) $e->getPath();
            $message = self::FLAG === $field
                ? \sprintf('"%s" must be true or false.', $field)
                : \sprintf('"%s" must be a string.', $field);

            throw new SkillFrontmatterValidationException($field, $message, $e);
        }

        $violations = $this->validator->validate($dto);
        if (0 !== \count($violations)) {
            $path = $violations[0]->getPropertyPath();

            throw new SkillFrontmatterValidationException(self::FIELDS[$path] ?? $path, (string) $violations[0]->getMessage());
        }

        return $dto;
    }
}
