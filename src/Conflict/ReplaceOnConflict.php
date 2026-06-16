<?php

declare(strict_types=1);

namespace Ivanfuhr\Ingestor\Conflict;

final readonly class ReplaceOnConflict implements ConflictStrategy
{
    /**
     * @param non-empty-list<string> $columns
     */
    private function __construct(
        private array $columns,
        private DuplicateInBatch $duplicateInBatch,
    ) {
    }

    public static function by(string|DuplicateInBatch ...$args): self
    {
        $duplicateInBatch = DuplicateInBatch::LastWins;
        $columnArgs = $args;

        if ($columnArgs !== []) {
            $last = $columnArgs[count($columnArgs) - 1];

            if ($last instanceof DuplicateInBatch) {
                $duplicateInBatch = $last;
                $columnArgs = array_slice($columnArgs, 0, -1);
            }
        }

        $columns = array_map(
            static function (string|DuplicateInBatch $arg): string {
                if (!$arg instanceof DuplicateInBatch) {
                    return $arg;
                }

                throw new \InvalidArgumentException('DuplicateInBatch must be the last argument to by().');
            },
            $columnArgs,
        );

        return new self(ConflictColumns::from(...$columns), $duplicateInBatch);
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function type(): ConflictType
    {
        return ConflictType::Replace;
    }

    public function duplicateInBatch(): DuplicateInBatch
    {
        return $this->duplicateInBatch;
    }
}
