<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\View\View;

class PublicShowcaseController extends Controller
{
    public function __invoke(string $tenantSlug): View
    {
        $tenant = Tenant::query()
            ->where('slug', $tenantSlug)
            ->firstOrFail();

        $showcase = $tenant->showcase()->first();

        return view('cabinet.showcase.public', [
            'tenant' => $tenant,
            'showcase' => $showcase,
        ]);
    }
}
