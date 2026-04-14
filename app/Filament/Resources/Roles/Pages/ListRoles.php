<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\StaffInvitationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getHeading(): string|\Illuminate\Support\HtmlString|null
    {
        return null;
    }

    public function getSubheading(): string|\Illuminate\Support\HtmlString|null
    {
        return null;
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-roles-list-page',
        ];
    }

    public function getHeader(): ?View
    {
        return view('filament.pages.roles-hero', [
            'createUrl' => RoleResource::getUrl('create'),
            'invitationUrl' => StaffInvitationResource::getUrl('index'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
