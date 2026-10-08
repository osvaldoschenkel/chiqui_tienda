<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class Money
{
    // Integer cents are used through every calculation. Comma is accepted as a decimal separator.
    public const MAX_CENTS = 9_000_000_000_000;

    public static function cents(string|int $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^([0-9]{1,11})(?:[.,]([0-9]{1,2}))?$/D', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Ingresá un importe positivo con hasta dos decimales, sin separadores de miles.']);
        }
        $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw ValidationException::withMessages(['amount' => 'El importe supera el máximo permitido.']);
        }

        return $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-$ ' : '$ ';
        $absolute = abs($cents);

        return $sign.number_format(intdiv($absolute, 100), 0, ',', '.').','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
