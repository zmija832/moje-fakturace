<?php

namespace App\Enums;

enum BusinessRole: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';

    public static function isAdministrator(?string $role): bool
    {
        return self::tryFrom((string) $role) === self::Admin;
    }
}
