<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Session;

use Doctrine\DBAL\Exception as DbalException;
use Ineersa\CodingAgent\Session\DTO\SessionCatalogFilterDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: self::NAME,
    description: 'List sessions persisted in the selected project store',
    help: <<<'HELP'
        Read the selected session catalog without starting a session or migrating storage.

          hatfield session:list --json --cwd /projects/example --limit 5
          hatfield session:list --json --since 2026-11-11T00:00:00Z --limit 20 --offset 5
          hatfield session:list --json --query restoration
        HELP,
)]
final class SessionListCommand
{
    public const string NAME = 'session:list';

    public function __construct(
        private readonly HatfieldSessionStore $store,
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('Return the versioned JSON catalog')] bool $json = false,
        #[Option('Select the project/resume context (default: invocation working directory)')] ?string $cwd = null,
        #[Option('Include activity at or after this RFC3339 timestamp')] ?string $since = null,
        #[Option('Include activity before this RFC3339 timestamp')] ?string $until = null,
        #[Option('Match a literal substring of the display title or session ID')] ?string $query = null,
        #[Option('Match the recorded absolute session directory exactly')] ?string $sessionCwd = null,
        #[Option('Page size (1–200)')] string $limit = '50',
        #[Option('Number of matching rows to skip')] string $offset = '0',
    ): int {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $scope = false === ($current = getcwd()) ? false : realpath($current);
            if (false === $scope || (null !== $cwd && ('' === $cwd || realpath($cwd) !== $scope))) {
                throw new InvalidArgumentException('Invalid project working directory.');
            }
            if (null !== $sessionCwd && (!Path::isAbsolute($sessionCwd) || !str_starts_with($sessionCwd, '/') || str_contains($sessionCwd, "\0"))) {
                throw new InvalidArgumentException('--session-cwd must be an absolute directory path.');
            }
            $lower = $this->parseTime($since, 'since');
            $upper = $this->parseTime($until, 'until');
            if (null !== $lower && null !== $upper && ($lower[0] > $upper[0] || (0 === ($lower[0] <=> $upper[0]) && strcmp($lower[1], $upper[1]) >= 0))) {
                throw new InvalidArgumentException('--since must be earlier than --until.');
            }
            $pageSize = $this->parseInteger($limit, 'limit', 1, 200);
            $pageOffset = $this->parseInteger($offset, 'offset', 0, \PHP_INT_MAX - 200);
            $filter = new SessionCatalogFilterDTO(
                $this->sqliteBound($lower),
                $this->sqliteBound($upper),
                $query,
                null === $sessionCwd ? null : Path::canonicalize($sessionCwd),
                $pageSize,
                $pageOffset,
            );
        } catch (InvalidArgumentException $e) {
            $errorOutput->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }

        try {
            $sessions = $this->store->listCatalogPage($filter);
            $nextOffset = null;
            if (\count($sessions) > $pageSize) {
                array_pop($sessions);
                $nextOffset = $pageOffset + $pageSize;
            }
            if ($json) {
                $document = $this->serializer->serialize([
                    'version' => 1,
                    'scope' => ['cwd' => $scope],
                    'sessions' => $sessions,
                    'nextOffset' => $nextOffset,
                ], 'json');
                $output->writeln($document, OutputInterface::OUTPUT_RAW);
            } else {
                (new Table($output))->setHeaders(['ID', 'Title', 'Session directory', 'Created', 'Last activity'])->setRows($sessions)->render();
                if (null !== $nextOffset) {
                    $output->writeln(\sprintf('Next page: --offset %d', $nextOffset));
                }
            }
        } catch (DbalException|SerializationException|\UnexpectedValueException|\TypeError) {
            // Do not print storage exception details or row contents to integrations.
            $errorOutput->writeln('Unable to read the session catalog. Check store permissions, integrity, schema, and contention.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function parseInteger(string $value, string $option, int $min, int $max): int
    {
        $digits = ltrim($value, '0');
        $digits = '' === $digits ? '0' : $digits;
        $number = (int) $digits;
        if ('' === $value || 0 !== $this->validator->validate($value, new Assert\Regex('/\A[0-9]+\z/'))->count() || (string) $number !== $digits || $number < $min || $number > $max) {
            throw new InvalidArgumentException(\sprintf('--%s must be an integer between %d and %d.', $option, $min, $max));
        }

        return $number;
    }

    /** @return array{\DateTimeImmutable, string}|null Seconds and exact fractional digits, without trailing zeroes. */
    private function parseTime(?string $value, string $option): ?array
    {
        if (null === $value) {
            return null;
        }
        $pattern = '/\A([0-9]{4}-[0-9]{2}-[0-9]{2}[Tt](?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])(?:\.([0-9]+))?([Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/';
        if ('' === $value || 0 !== $this->validator->validate($value, new Assert\Regex($pattern))->count()) {
            throw new InvalidArgumentException(\sprintf('--%s must be an RFC3339 timestamp with an explicit timezone.', $option));
        }
        preg_match($pattern, $value, $matches);
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', strtoupper($matches[1].$matches[3]));
        if (false === $at || false !== \DateTimeImmutable::getLastErrors()) {
            throw new InvalidArgumentException(\sprintf('--%s must be a valid RFC3339 timestamp.', $option));
        }

        return [$at, rtrim($matches[2], '0')];
    }

    /** @param array{\DateTimeImmutable, string}|null $time */
    private function sqliteBound(?array $time): ?string
    {
        if (null === $time) {
            return null;
        }
        [$at, $fraction] = $time;
        // Doctrine persists whole seconds. Both >= and < use the next whole
        // second when the specified bound lies strictly between stored seconds.
        if ('' !== $fraction) {
            $at = $at->add(new \DateInterval('PT1S'));
        }

        return $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
