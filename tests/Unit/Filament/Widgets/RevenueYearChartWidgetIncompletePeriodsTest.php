<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\RevenueYearChartWidget;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class RevenueYearChartWidgetIncompletePeriodsTest extends TestCase
{
    #[Test]
    public function it_hides_obvious_leading_incomplete_periods(): void
    {
        $widget = new RevenueYearChartWidget();

        $filtered = $this->invokeFilter(
            $widget,
            [
                '2026-02' => [
                    'rows' => 2,
                    'payable' => 450100.0,
                    'spaces' => [1 => true, 2 => true],
                ],
                '2026-03' => [
                    'rows' => 120,
                    'payable' => 6700000.0,
                    'spaces' => array_fill_keys(range(1, 60), true),
                ],
                '2026-04' => [
                    'rows' => 112,
                    'payable' => 6300000.0,
                    'spaces' => array_fill_keys(range(1, 58), true),
                ],
            ],
            120,
        );

        $this->assertArrayNotHasKey('2026-02', $filtered);
        $this->assertArrayHasKey('2026-03', $filtered);
        $this->assertArrayHasKey('2026-04', $filtered);
    }

    #[Test]
    public function it_keeps_series_when_values_are_consistently_small(): void
    {
        $widget = new RevenueYearChartWidget();

        $filtered = $this->invokeFilter(
            $widget,
            [
                '2026-02' => [
                    'rows' => 4,
                    'payable' => 18000.0,
                    'spaces' => [1 => true, 2 => true],
                ],
                '2026-03' => [
                    'rows' => 5,
                    'payable' => 21000.0,
                    'spaces' => [1 => true, 2 => true, 3 => true],
                ],
                '2026-04' => [
                    'rows' => 5,
                    'payable' => 20000.0,
                    'spaces' => [1 => true, 2 => true, 3 => true],
                ],
            ],
            20,
        );

        $this->assertArrayHasKey('2026-02', $filtered);
        $this->assertArrayHasKey('2026-03', $filtered);
        $this->assertArrayHasKey('2026-04', $filtered);
    }

    #[Test]
    public function it_turns_leading_zero_coverage_points_into_nulls(): void
    {
        $widget = new RevenueYearChartWidget();

        $normalized = $this->invokeZeroCoverageNormalizer(
            $widget,
            [0.0, 0.0, 52.3, 50.1]
        );

        $this->assertNull($normalized[0]);
        $this->assertNull($normalized[1]);
        $this->assertSame(52.3, $normalized[2]);
        $this->assertSame(50.1, $normalized[3]);
    }

    /**
     * @param  array<string, array{rows:int,payable:float,spaces:array<int,true>}>  $periodStats
     * @return array<string, array{rows:int,payable:float,spaces:array<int,true>}>
     */
    private function invokeFilter(RevenueYearChartWidget $widget, array $periodStats, int $totalSpaces): array
    {
        $method = new ReflectionMethod($widget, 'filterLeadingIncompleteDebtPeriods');
        $method->setAccessible(true);

        /** @var array<string, array{rows:int,payable:float,spaces:array<int,true>}> $result */
        $result = $method->invoke($widget, $periodStats, $totalSpaces);

        return $result;
    }

    /**
     * @param  list<float|null>  $coveragePctData
     * @return list<float|null>
     */
    private function invokeZeroCoverageNormalizer(RevenueYearChartWidget $widget, array $coveragePctData): array
    {
        $method = new ReflectionMethod($widget, 'nullLeadingZeroCoveragePoints');
        $method->setAccessible(true);

        /** @var list<float|null> $result */
        $result = $method->invoke($widget, $coveragePctData);

        return $result;
    }
}
