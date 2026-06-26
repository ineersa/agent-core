<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\CodingAgent\Logging\LogEntry;
use Ineersa\CodingAgent\Logging\LogParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogParserTest extends TestCase
{
    private LogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LogParser();
    }

    #[DataProvider('provideValidJsonLines')]
    public function testParseValidJsonLine(string $line, array $expected): void
    {
        $entry = $this->parser->parse($line, 'test.log', 1);

        self::assertNotNull($entry);
        self::assertSame($expected['channel'], $entry->channel);
        self::assertSame($expected['level'], $entry->level);
        self::assertSame($expected['message'], $entry->message);
        self::assertSame('test.log', $entry->sourceFile);
        self::assertSame(1, $entry->lineNumber);
    }

    public function testParseHandlesExtraAndContext(): void
    {
        $line = \json_encode([
            'datetime' => '2026-05-18T10:00:00+00:00',
            'channel' => 'app',
            'level_name' => 'WARNING',
            'message' => 'Something happened',
            'context' => ['user_id' => 42, 'action' => 'login'],
            'extra' => ['ip' => '127.0.0.1'],
        ], \JSON_THROW_ON_ERROR);

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertSame('WARNING', $entry->level);
        self::assertSame(['user_id' => 42, 'action' => 'login'], $entry->context);
        self::assertSame(['ip' => '127.0.0.1'], $entry->extra);
    }

    public function testParseReturnsNullForEmptyString(): void
    {
        self::assertNull($this->parser->parse(''));
        self::assertNull($this->parser->parse('   '));
    }

    public function testParseReturnsNullForInvalidJson(): void
    {
        self::assertNull($this->parser->parse('not valid json'));
        self::assertNull($this->parser->parse('{broken'));
    }

    public function testParseReturnsNullForMissingDatetime(): void
    {
        $line = \json_encode([
            'channel' => 'app',
            'message' => 'No datetime',
        ], \JSON_THROW_ON_ERROR);

        self::assertNull($this->parser->parse($line));
    }

    public function testParseReturnsNullForNonArrayJson(): void
    {
        self::assertNull($this->parser->parse('"just a string"'));
        self::assertNull($this->parser->parse('42'));
    }

    public function testParseAcceptsStringDatetime(): void
    {
        $line = \json_encode([
            'datetime' => '2026-05-20T15:30:00+00:00',
            'channel' => 'test',
            'message' => 'Hello',
        ], \JSON_THROW_ON_ERROR);

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertSame('2026-05-20T15:30:00+00:00', $entry->datetime->format(\DateTimeInterface::ATOM));
    }

    public function testParseAcceptsIntegerTimestamp(): void
    {
        $ts = 1716200000;
        $line = \json_encode([
            'datetime' => $ts,
            'channel' => 'test',
            'message' => 'From timestamp',
        ], \JSON_THROW_ON_ERROR);

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertSame($ts, $entry->datetime->getTimestamp());
    }

    public function testParseDefaultChannelAndLevel(): void
    {
        $line = \json_encode([
            'datetime' => '2026-05-18T10:00:00+00:00',
            'message' => 'Minimal entry',
        ], \JSON_THROW_ON_ERROR);

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertSame('app', $entry->channel);
        self::assertSame('INFO', $entry->level);
    }

    public function testParseSourceFileAndLineNumberOptional(): void
    {
        $line = \json_encode([
            'datetime' => '2026-05-18T10:00:00+00:00',
            'channel' => 'app',
            'message' => 'Test',
        ], \JSON_THROW_ON_ERROR);

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertNull($entry->sourceFile);
        self::assertNull($entry->lineNumber);
    }

    /**
     * @return array<string, array{string, array{channel: string, level: string, message: string}}>
     */
    public static function provideValidJsonLines(): array
    {
        return [
            'basic info entry' => [
                \json_encode([
                    'datetime' => '2026-05-18T10:00:00+00:00',
                    'channel' => 'app',
                    'level_name' => 'INFO',
                    'message' => 'Application started',
                ], \JSON_THROW_ON_ERROR),
                ['channel' => 'app', 'level' => 'INFO', 'message' => 'Application started'],
            ],
            'error entry' => [
                \json_encode([
                    'datetime' => '2026-05-18T10:01:00+00:00',
                    'channel' => 'app',
                    'level_name' => 'ERROR',
                    'message' => 'Something failed',
                    'context' => ['exception' => 'RuntimeException'],
                ], \JSON_THROW_ON_ERROR),
                ['channel' => 'app', 'level' => 'ERROR', 'message' => 'Something failed'],
            ],
        ];
    }
}
