<?php

declare(strict_types=1);

namespace App\Exception;

final class IntakeCapacityException extends \RuntimeException
{
    public function __construct(public readonly array $capacity)
    {
        parent::__construct('Report intake capacity reached. Inspect the installation limits.');
    }

    public function response(): array
    {
        return ['schema_version' => 1, 'accepted' => false,
            'error' => ['code' => 'capacity', 'message' => $this->getMessage()], 'capacity' => $this->capacity];
    }
}
