<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

/** Strict mirror of the Hatfield 360 v1 event schema, excluding generated credentials and event ID. */
final class EventSchema
{
    private const array ACTOR = ['actorId' => 'id', 'actorStart' => 'sequence'];
    private const array TURN = self::ACTOR + ['turnId' => 'id', 'turnStart' => 'sequence'];
    private const array EVENTS = [
        'session.started' => ['provider' => 'id', 'capabilities' => 'capabilities'],
        'session.ended' => [],
        'actor.started' => ['actorId' => 'id', 'parentId' => 'id', 'parentStart' => 'sequence'],
        'actor.ended' => self::ACTOR,
        'script.started' => ['scriptId' => 'id'],
        'script.ended' => ['scriptId' => 'id', 'scriptStart' => 'sequence'],
        'turn.started' => self::ACTOR + ['turnId' => 'id'],
        'turn.ended' => self::TURN + ['outcome' => ['completed', 'failed', 'cancelled', 'unknown']],
        'activity.changed' => self::TURN + ['activity' => ['working', 'idle']],
        'wait.started' => self::TURN + ['waitId' => 'id', 'reason' => ['permission', 'question', 'external-input']],
        'wait.resolved' => self::TURN + ['waitId' => 'id', 'waitStart' => 'sequence'],
        'notification' => ['title' => 200, 'body' => 2000],
    ];
    private const array CAPABILITIES = ['waits', 'actors', 'notifications', 'session-end', 'scripts'];

    /** @param array<array-key, mixed> $fields */
    public static function accepts(string $kind, string $sessionId, int $sequence, int $sessionEpoch, array $fields): bool
    {
        $required = self::EVENTS[$kind] ?? null;
        if (null === $required || !self::id($sessionId) || !self::sequence($sequence)
            || !self::sequence($sessionEpoch) || $sessionEpoch > $sequence) {
            return false;
        }
        $allowed = $required + ['at' => 64] + ('activity.changed' === $kind ? ['tool' => 128] : []);
        if ([] !== array_diff_key($required, $fields) || [] !== array_diff_key($fields, $allowed)) {
            return false;
        }
        foreach ($allowed as $key => $rule) {
            if (\array_key_exists($key, $fields) && !self::matches($fields[$key], $rule)) {
                return false;
            }
        }

        return true;
    }

    public static function id(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $value);
    }

    public static function sequence(mixed $value): bool
    {
        return \is_int($value) && $value > 0 && $value <= 9_007_199_254_740_991;
    }

    /** @param int|string|list<string> $rule */
    private static function matches(mixed $value, int|string|array $rule): bool
    {
        if (\is_array($rule)) {
            return \in_array($value, $rule, true);
        }
        if (\is_int($rule)) {
            return \is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $rule;
        }

        return match ($rule) {
            'id' => self::id($value),
            'sequence' => self::sequence($value),
            'capabilities' => self::capabilities($value),
            default => false,
        };
    }

    private static function capabilities(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) > \count(self::CAPABILITIES)) {
            return false;
        }
        $seen = [];
        foreach ($value as $capability) {
            if (!\in_array($capability, self::CAPABILITIES, true) || isset($seen[$capability])) {
                return false;
            }
            $seen[$capability] = true;
        }

        return true;
    }
}
