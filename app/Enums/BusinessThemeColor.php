<?php

namespace App\Enums;

enum BusinessThemeColor: string
{
    case Blue = 'blue';
    case Teal = 'teal';
    case Green = 'green';
    case Lime = 'lime';
    case Yellow = 'yellow';
    case Orange = 'orange';
    case Red = 'red';
    case Pink = 'pink';
    case Purple = 'purple';
    case Slate = 'slate';

    public function label(): string
    {
        return match ($this) {
            self::Blue => 'Modrá',
            self::Teal => 'Tyrkysová',
            self::Green => 'Zelená',
            self::Lime => 'Limetková',
            self::Yellow => 'Žlutá',
            self::Orange => 'Oranžová',
            self::Red => 'Červená',
            self::Pink => 'Růžová',
            self::Purple => 'Fialová',
            self::Slate => 'Šedá',
        };
    }

    /** @return array{background:string,border:string,text:string,accent:string,page:string} */
    public function theme(): array
    {
        return match ($this) {
            self::Blue => ['background' => '#dbeafe', 'border' => '#93c5fd', 'text' => '#1e3a8a', 'accent' => '#2563eb', 'page' => '#f8fbff'],
            self::Teal => ['background' => '#ccfbf1', 'border' => '#5eead4', 'text' => '#134e4a', 'accent' => '#0d9488', 'page' => '#f7fdfc'],
            self::Green => ['background' => '#dcfce7', 'border' => '#86efac', 'text' => '#14532d', 'accent' => '#16a34a', 'page' => '#f8fdf9'],
            self::Lime => ['background' => '#ecfccb', 'border' => '#bef264', 'text' => '#365314', 'accent' => '#65a30d', 'page' => '#fbfef7'],
            self::Yellow => ['background' => '#fef9c3', 'border' => '#fde047', 'text' => '#713f12', 'accent' => '#ca8a04', 'page' => '#fffdf5'],
            self::Orange => ['background' => '#ffedd5', 'border' => '#fdba74', 'text' => '#7c2d12', 'accent' => '#ea580c', 'page' => '#fffaf7'],
            self::Red => ['background' => '#fee2e2', 'border' => '#fca5a5', 'text' => '#7f1d1d', 'accent' => '#dc2626', 'page' => '#fff9f9'],
            self::Pink => ['background' => '#fce7f3', 'border' => '#f9a8d4', 'text' => '#831843', 'accent' => '#db2777', 'page' => '#fff9fc'],
            self::Purple => ['background' => '#f3e8ff', 'border' => '#d8b4fe', 'text' => '#581c87', 'accent' => '#9333ea', 'page' => '#fcf9ff'],
            self::Slate => ['background' => '#e2e8f0', 'border' => '#94a3b8', 'text' => '#1e293b', 'accent' => '#475569', 'page' => '#f8fafc'],
        };
    }
}
