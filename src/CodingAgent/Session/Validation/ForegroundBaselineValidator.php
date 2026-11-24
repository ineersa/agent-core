<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Validation;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

/** The single wire boundary for sealed and ready foreground state. */
final class ForegroundBaselineValidator
{
    /** @return array{current_foreground_response_seq: ?int, pending_foreground_requests: list<array{request_id: string, category: string}>} */
    public static function validate(mixed $value, int $canonicalSequence): array
    {
        $errors = Validation::createValidator()->validate($value, new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection([
                'current_foreground_response_seq' => new Assert\Sequentially([new Assert\Type('integer'), new Assert\Positive(), new Assert\LessThanOrEqual($canonicalSequence)]),
                'pending_foreground_requests' => new Assert\Sequentially([
                    new Assert\NotNull(), new Assert\Type('array'), new Assert\Count(max: 2000),
                    new Assert\Callback(static function (array $requests, ExecutionContextInterface $context): void {
                        if (!array_is_list($requests)) {
                            $context->buildViolation('Foreground requests must be an ordered list.')->addViolation();
                        }
                    }),
                    new Assert\All([new Assert\Sequentially([
                        new Assert\NotNull(), new Assert\Type('array'),
                        new Assert\Collection([
                            'request_id' => new Assert\Sequentially([new Assert\NotNull(), new Assert\Type('string'), new Assert\Length(min: 1, max: 1024, countUnit: Assert\Length::COUNT_BYTES)]),
                            'category' => [new Assert\NotNull(), new Assert\Choice(choices: ['question', 'approval'])],
                        ]),
                    ])]),
                ]),
            ]),
            new Assert\Callback(static function (array $foreground, ExecutionContextInterface $context): void {
                $encoded = json_encode($foreground);
                if (false === $encoded || \strlen($encoded) > 32768) {
                    $context->buildViolation('Foreground baseline exceeds its wire budget or is not valid UTF-8.')->addViolation();
                }
            }),
        ]));
        if (\count($errors) > 0) {
            throw new ValidationFailedException($value, $errors);
        }

        return $value;
    }
}
