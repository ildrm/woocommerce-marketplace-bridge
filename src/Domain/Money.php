<?php
declare(strict_types=1);
namespace MarketplaceBridge\WooCommerce\Domain;

/** Exact integer Toman conversion; deliberately rejects sub-rial amounts and exponent syntax. */
final class Money {
    public static function toToman(string|int $amount, string $unit): int {
        $input = trim((string)$amount);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $input)) {
            throw new \InvalidArgumentException('Invalid nonnegative money amount');
        }
        if (!in_array($unit, ['IRR','IRT'], true)) {
            throw new \InvalidArgumentException('Unsupported input currency');
        }
        [$integer, $fraction] = array_pad(explode('.', $input, 2), 2, '');
        // WooCommerce may represent whole Rial amounts as 10000.00000000; nonzero
        // fractional units cannot be safely converted to integer Toman.
        if (trim($fraction, '0') !== '') {
            throw new \InvalidArgumentException('Fractional currency units require explicit merchant rounding policy');
        }
        $max = (string)PHP_INT_MAX;
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        if (strlen($integer) > strlen($max) || (strlen($integer) === strlen($max) && strcmp($integer, $max) > 0)) {
            throw new \OverflowException('Money amount exceeds supported integer range');
        }
        $n = (int)$integer;
        if ($unit === 'IRT') { return $n; }
        // Half-up rounding of integer Rial to whole Toman without floating point.
        return intdiv($n, 10) + ((($n % 10) >= 5) ? 1 : 0);
    }
}
