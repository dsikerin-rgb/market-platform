<?php

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketSpacesStatusChartWidgetTest extends TestCase
{
    public function test_status_label_includes_percent_and_area(): void
    {
        $widget = new MarketSpacesStatusChartWidget();
        $method = new ReflectionMethod($widget, 'makeStatusLabel');
        $method->setAccessible(true);

        $this->assertSame(
            'Сдано — 87,5 % (7 305,3 м²)',
            $method->invoke($widget, 'Сдано', 7305.3, 8348.914285714286),
        );
    }
}
