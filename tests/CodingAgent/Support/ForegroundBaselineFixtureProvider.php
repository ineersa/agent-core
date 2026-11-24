<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

final class ForegroundBaselineFixtureProvider
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedForeground(): iterable
    {
        yield 'missing' => [[]];
        yield 'future sequence' => [['current_foreground_response_seq' => 12, 'pending_foreground_requests' => []]];
        yield 'string sequence' => [['current_foreground_response_seq' => '2', 'pending_foreground_requests' => []]];
        yield 'null attention' => [['current_foreground_response_seq' => null, 'pending_foreground_requests' => null]];
        yield 'content instead of category' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => [['request_id' => 'q', 'category' => 'SECRET prompt']]]];
        yield 'extra content' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => [['request_id' => 'q', 'category' => 'question', 'prompt' => 'SECRET']]]];
        yield 'multibyte byte overflow' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => [['request_id' => str_repeat('é', 513), 'category' => 'question']]]];
        yield 'non-list' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => [2 => ['request_id' => 'q', 'category' => 'question']]]];
        yield 'wire bytes' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => array_fill(0, 40, ['request_id' => str_repeat('x', 1024), 'category' => 'question'])]];
        yield 'item count' => [['current_foreground_response_seq' => 2, 'pending_foreground_requests' => array_fill(0, 2001, ['request_id' => 'q', 'category' => 'question'])]];
    }
}
