<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;

/**
 * Canonical JSON-Schema validation for tool call arguments.
 *
 * Built-in tools also resolve into typed DTOs and run Symfony Validator via
 * ValidateToolCallArgumentsListener. Dynamic MCP/extension tools keep raw-array
 * public handlers, so this validator is their runtime contract against the
 * exact provider-visible schema (reflection cannot invent DTOs for those).
 *
 * Does not log raw arguments or schema contents.
 */
final class ToolCallArgumentsValidator
{
    private readonly Validator $validator;

    public function __construct(?Validator $validator = null)
    {
        // Collect a small batch so the concise error can include the first pointer.
        $this->validator = $validator ?? new Validator(null, 5, false);
    }

    /**
     * Reject unusable runtime-fed schemas before a tool is exposed.
     *
     * @param array<string, mixed> $schema
     *
     * @throws \InvalidArgumentException When the schema cannot be used for validation
     */
    public function assertSchemaIsUsable(array $schema, string $toolName): void
    {
        if ([] === $schema) {
            return;
        }

        try {
            $this->toSchemaObject($schema);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(\sprintf('Tool "%s" has an unusable parameters JSON Schema.', $toolName), 0, $e);
        }

        // Opis loads/parses on validate; probe with an empty object.
        try {
            $result = $this->validator->validate(new \stdClass(), $this->toSchemaObject($schema));
            // Valid or invalid instance is fine; internal schema parse failures throw.
            unset($result);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(\sprintf('Tool "%s" has an unusable parameters JSON Schema.', $toolName), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $arguments Flat provider-visible tool arguments
     * @param array<string, mixed> $schema    Canonical parameters JSON Schema
     *
     * @throws InvalidToolCallArgumentsException
     */
    public function assertValid(array $arguments, array $schema, string $toolName): void
    {
        if ([] === $schema) {
            return;
        }

        try {
            $schemaObject = $this->toSchemaObject($schema);
            $data = $this->convertDataForValidator($arguments);
            $result = $this->validator->validate($data, $schemaObject);
        } catch (\Throwable) {
            throw new InvalidToolCallArgumentsException(\sprintf('Invalid arguments for tool "%s": arguments failed schema validation.', $toolName));
        }

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        if (null === $error) {
            throw new InvalidToolCallArgumentsException(\sprintf('Invalid arguments for tool "%s".', $toolName));
        }

        $formatted = (new ErrorFormatter())->format($error, true);
        $message = $this->firstErrorMessage($formatted);

        throw new InvalidToolCallArgumentsException(\sprintf('Invalid arguments for tool "%s"%s.', $toolName, '' !== $message ? ': '.$message : ''));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function toSchemaObject(array $schema): object
    {
        $json = json_encode($schema, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $decoded = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        if (!\is_object($decoded)) {
            throw new \InvalidArgumentException('JSON Schema must decode to an object.');
        }

        return $decoded;
    }

    private function convertDataForValidator(mixed $data): mixed
    {
        if (\is_array($data)) {
            if ([] === $data) {
                return new \stdClass();
            }

            $isList = array_keys($data) === range(0, \count($data) - 1);
            if ($isList) {
                return array_map($this->convertDataForValidator(...), $data);
            }

            $object = new \stdClass();
            foreach ($data as $key => $value) {
                $object->{$key} = $this->convertDataForValidator($value);
            }

            return $object;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $formatted
     */
    private function firstErrorMessage(array $formatted): string
    {
        foreach ($formatted as $path => $messages) {
            if (\is_array($messages)) {
                foreach ($messages as $message) {
                    if (\is_string($message) && '' !== $message) {
                        $pointer = \is_string($path) ? $path : '';

                        return ('' !== $pointer ? $pointer.': ' : '').$message;
                    }
                    if (\is_array($message)) {
                        $nested = $this->firstErrorMessage($message);
                        if ('' !== $nested) {
                            return $nested;
                        }
                    }
                }
            } elseif (\is_string($messages) && '' !== $messages) {
                $pointer = \is_string($path) ? $path : '';

                return ('' !== $pointer ? $pointer.': ' : '').$messages;
            }
        }

        return '';
    }
}
