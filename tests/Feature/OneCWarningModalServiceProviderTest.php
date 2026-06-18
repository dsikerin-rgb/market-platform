<?php
# tests/Feature/OneCWarningModalServiceProviderTest.php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OneCWarningModalServiceProviderTest extends TestCase
{
    public function test_onec_warning_modal_fix_script_is_injected_into_dashboard_modal_response(): void
    {
        Route::get('/_test/onec-warning-modal', static function () {
            return response('<html><body><div id="dashboardOneCWarningModal" data-storage-key="test-key"><button type="button" data-onec-warning-close>Понятно</button></div></body></html>');
        });

        $response = $this->get('/_test/onec-warning-modal');

        $response->assertOk();
        $response->assertSee('data-onec-warning-modal-fix="1"', false);
        $response->assertSee('document.readyState === \'loading\'', false);
        $response->assertSee('livewire:navigated', false);
        $response->assertSee('data-onec-warning-close', false);
    }
}
