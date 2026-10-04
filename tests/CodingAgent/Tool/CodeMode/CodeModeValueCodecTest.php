<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Tool\CodeMode\CodeModeValueCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeModeValueCodec::class)]
final class CodeModeValueCodecTest extends TestCase
{
    public function testAcceptsJsonCompatibleScalarsAndArrays(): void
    {
        $this->assertNull(CodeModeValueCodec::assertEncodable(null, 'value'));
        $this->assertTrue(CodeModeValueCodec::assertEncodable(true, 'value'));
        $this->assertSame(7, CodeModeValueCodec::assertEncodable(7, 'value'));
        $this->assertSame(1.5, CodeModeValueCodec::assertEncodable(1.5, 'value'));
        $this->assertSame('ok', CodeModeValueCodec::assertEncodable('ok', 'value'));
        $this->assertSame(['a' => 1], CodeModeValueCodec::assertEncodable(['a' => 1], 'value'));
    }

    public function testRejectsCyclicArraysBeforeHanging(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;

        try {
            CodeModeValueCodec::assertEncodable($cycle, 'value');
            $this->fail('Expected RuntimeException for cyclic array');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Tool call failed: its result could not be safely inspected.', $exception->getMessage());
        }
    }

    public function testRejectsInvalidUtf8WithoutSubstitution(): void
    {
        try {
            CodeModeValueCodec::assertEncodable("bad\x80text", 'value');
            $this->fail('Expected RuntimeException for invalid UTF-8');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Tool call failed: its result contained malformed UTF-8.', $exception->getMessage());
        }
    }

    public function testRejectsObjectsWithPrivateStateInsteadOfPublicPropertyLeak(): void
    {
        $object = new class {
            private string $secret = 'hidden';
            public string $visible = 'public';
        };

        try {
            CodeModeValueCodec::assertEncodable($object, 'value');
            $this->fail('Expected RuntimeException for unsupported object');
        } catch (\RuntimeException $exception) {
            $this->assertSame('value contains an unsupported IPC value.', $exception->getMessage());
        }
    }

    public function testRejectsJsonSerializableWithoutInvokingSerialization(): void
    {
        $calls = 0;
        $object = new class($calls) implements \JsonSerializable {
            public function __construct(private int &$calls)
            {
            }

            public function jsonSerialize(): mixed
            {
                ++$this->calls;

                return ['n' => $this->calls];
            }
        };

        try {
            CodeModeValueCodec::assertEncodable($object, 'value');
            $this->fail('Expected RuntimeException for JsonSerializable object');
        } catch (\RuntimeException $exception) {
            $this->assertSame('value contains an unsupported IPC value.', $exception->getMessage());
        }

        $this->assertSame(0, $calls, 'Rejected objects must never execute serialization hooks');
    }
}
