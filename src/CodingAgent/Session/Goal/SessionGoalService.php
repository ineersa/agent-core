<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Goal;

use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Ineersa\CodingAgent\Session\Goal\DTO\GoalObjectiveDTO;
use Ineersa\CodingAgent\Session\Goal\Exception\GoalAccessException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class SessionGoalService
{
    public function __construct(private HatfieldSessionRepository $sessions, private ValidatorInterface $validator)
    {
    }

    public function validateObjective(string $objective): string
    {
        $objective = trim($objective);
        $input = new GoalObjectiveDTO($objective);
        $errors = $this->validator->validate($input);
        if (0 !== $errors->count()) {
            throw new ValidationFailedException($input, $errors);
        }

        return $objective;
    }

    public function get(string $runId): ?string
    {
        $relationship = $this->sessions->findRelationship($runId);
        if (null === $relationship || null !== $relationship['parent_run_id']) {
            throw new GoalAccessException('Goals are available only to the owning persistent main session.');
        }

        return $this->sessions->findGoal($runId);
    }

    public function set(string $runId, ?string $objective): ?string
    {
        $objective = null === $objective ? null : $this->validateObjective($objective);
        if (!$this->sessions->updateGoal($runId, $objective)) {
            throw new GoalAccessException('Goals are available only to the owning persistent main session.');
        }

        return $objective;
    }
}
