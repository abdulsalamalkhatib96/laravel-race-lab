<?php

namespace RaceLab\LaravelRaceLab\Enums;

enum QueryOperation: string
{
    case Select = 'select';
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
    case Other = 'other';

    public static function detect(string $sql): self
    {
        $sql = ltrim($sql);
        $first = strtolower(strtok($sql, " \t\r\n") ?: '');

        return match ($first) {
            'select' => self::Select,
            'insert' => self::Insert,
            'update' => self::Update,
            'delete' => self::Delete,
            default => self::Other,
        };
    }
}
