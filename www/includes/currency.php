<?php
/** USD formatting. All monetary database columns MUST first be migrated to USD. */
function rl_usd(float|int|string|null $value): string
{
    return '$' . number_format((float)($value ?? 0), 2, '.', ',');
}
function rl_usd_compact(float|int|string|null $value): string
{
    $value = (float)($value ?? 0);
    $sign = $value < 0 ? '-' : '';
    $abs = abs($value);
    foreach ([1000000000 => 'B', 1000000 => 'M', 1000 => 'K'] as $divisor => $suffix) {
        if ($abs >= $divisor) return $sign . '$' . rtrim(rtrim(number_format($abs/$divisor, 2, '.', ''), '0'), '.') . $suffix;
    }
    return rl_usd($value);
}
