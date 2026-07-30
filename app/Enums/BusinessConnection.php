<?php

namespace App\Enums;

use App\Domain\BusinessContext\Exceptions\InvalidBusinessConnection;

enum BusinessConnection: string
{
    case Business1 = 'business_1';
    case Business2 = 'business_2';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromConfiguredValue(?string $value): self
    {
        $connection = is_string($value) ? self::tryFrom($value) : null;

        if (
            ! $connection
            || ! in_array($connection->value, config('business.allowed_connections', []), true)
        ) {
            throw InvalidBusinessConnection::create();
        }

        return $connection;
    }

    public function connectionName(): string
    {
        return $this->value;
    }
}
