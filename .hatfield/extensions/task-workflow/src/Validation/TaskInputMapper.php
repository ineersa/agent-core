<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Validation;

use Ineersa\HatfieldExt\TaskWorkflow\DTO\TaskInputDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskInputException;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

final class TaskInputMapper
{
    /** @param array<string,mixed> $arguments */
    public static function map(array $arguments, string $operation): TaskInputDTO
    {
        $allowed = match ($operation) {
            'create' => ['title', 'body'],
            'read' => ['task', 'artifact'],
            'update' => ['task', 'title', 'body', 'comment', 'artifact'],
            'list' => ['status', 'page', 'perPage'],
            'move' => ['task', 'to', 'from', 'adopt', 'pr', 'branch', 'acceptedHead', 'prTitle', 'prBody'],
            default => throw new TaskInputException([['path' => '', 'message' => 'Unknown task operation.']]),
        };
        $extra = array_diff(array_keys($arguments), $allowed);
        if ([] !== $extra) {
            throw new TaskInputException(array_map(static fn (string $key): array => ['path' => $key, 'message' => 'Unsupported task argument.'], array_values($extra)));
        }
        $serializer = new Serializer([new ObjectNormalizer(
            classMetadataFactory: new ClassMetadataFactory(new AttributeLoader()),
            propertyTypeExtractor: new ReflectionExtractor(),
        )]);
        try {
            $input = $serializer->denormalize($arguments, TaskInputDTO::class, null, [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false]);
        } catch (ExceptionInterface $exception) {
            throw new TaskInputException([['path' => '', 'message' => $exception->getMessage()]]);
        }
        $errors = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($input, null, [$operation]);
        $result = [];
        foreach ($errors as $error) {
            $result[] = ['path' => $error->getPropertyPath(), 'message' => (string) $error->getMessage()];
        }
        if ([] !== $result) {
            throw new TaskInputException($result);
        }

        return $input;
    }
}
