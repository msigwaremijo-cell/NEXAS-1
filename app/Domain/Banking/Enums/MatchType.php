<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `reconciliations.match_type` column comment
 * ('manual|auto|rule').
 */
enum MatchType: string
{
    case Manual = 'manual';
    case Auto = 'auto';
    case Rule = 'rule';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual match',
            self::Auto => 'Automatic match',
            self::Rule => 'Rule match',
        };
    }
}
