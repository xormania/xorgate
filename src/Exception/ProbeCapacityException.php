<?php

declare(strict_types=1);

namespace App\Exception;

final class ProbeCapacityException extends \RuntimeException
{
    public function __construct(public readonly array $capacity)
    {
        parent::__construct('Foundation probe capacity reached: '.$capacity['reason'].'.');
    }

    public function response(): array
    {
        return ['accepted' => false, 'error' => $this->capacity['reason'], 'capacity' => $this->capacity];
    }
}
