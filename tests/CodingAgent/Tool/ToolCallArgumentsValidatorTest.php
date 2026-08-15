<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\ToolCallArgumentsValidator;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;

final class ToolCallArgumentsValidatorTest extends TestCase
{
    public function testValidArgumentsPass(): void
    {
        $validator = new ToolCallArgumentsValidator();
        $validator->assertValid(
            ['path' => 'a.txt'],
            [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            'read',
        );
        $this->addToAssertionCount(1);
    }

    public function testMissingRequiredFails(): void
    {
        $validator = new ToolCallArgumentsValidator();
        $this->expectException(InvalidToolCallArgumentsException::class);
        $this->expectExceptionMessage('Invalid arguments for tool "read"');
        $validator->assertValid(
            [],
            [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            'read',
        );
    }

    public function testUnknownPropertyFails(): void
    {
        $validator = new ToolCallArgumentsValidator();
        $this->expectException(InvalidToolCallArgumentsException::class);
        $validator->assertValid(
            ['path' => 'a.txt', 'extra' => 1],
            [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            'read',
        );
    }

    public function testMalformedSchemaRejectedAtAssertSchemaIsUsable(): void
    {
        $validator = new ToolCallArgumentsValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unusable parameters JSON Schema');
        // Invalid schema type keyword value that Opis cannot parse as schema.
        $validator->assertSchemaIsUsable(['type' => ['not', 'a', 'valid', 'schema', 'type', []]], 'bad');
    }
}
