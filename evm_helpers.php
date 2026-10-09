<?php
/**
 * evm_helpers.php — shared Earned-Value math for progress_api, reports and
 * employee stats. No side effects; safe to include anywhere.
 */

declare(strict_types=1);

/** Smoothstep S-curve: 0..1 -> 0..1 (planned progress shape). */
function smoothstep(float $x): float
{
    $x = max(0.0, min(1.0, $x));
    return $x * $x * (3.0 - 2.0 * $x);
}

/** Planned percent for a date against [start,end]; null when plan unknown. */
function plannedPercentFor(string $start, string $end, string $onDate): ?float
{
    if ($start === '' || $end === '' || $end <= $start) {
        return null;
    }
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $dateTs = strtotime($onDate);
    if ($startTs === false || $endTs === false || $dateTs === false) {
        return null;
    }
    if ($dateTs <= $startTs) {
        return 0.0;
    }
    if ($dateTs >= $endTs) {
        return 100.0;
    }
    $x = ($dateTs - $startTs) / max(1.0, (float)($endTs - $startTs));
    return round(smoothstep($x) * 100.0, 2);
}

/** Forecast finish date + whole-day slippage for an SPI value (nulls safe). */
function evmForecast(string $start, string $end, ?float $spi): array
{
    if ($spi === null || $spi <= 0 || $start === '' || $end === '' || $end <= $start) {
        return ['forecast_end' => null, 'day_slippage' => null];
    }
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $plannedDuration = (float)($endTs - $startTs);
    $estimatedEnd = $startTs + (int)round($plannedDuration / $spi);
    $forecastEnd = date('Y-m-d', $estimatedEnd);
    $daySlippage = (int)round((strtotime($forecastEnd) - strtotime($end)) / 86400);
    return ['forecast_end' => $forecastEnd, 'day_slippage' => $daySlippage];
}
