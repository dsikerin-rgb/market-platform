<x-filament-panels::page>
    @php
        $workspace = $this->documentWorkspaceData();
    @endphp

    <div>
        @component('filament.widgets.market-documents-workspace-widget', [
            'activeTab' => $workspace['activeTab'],
            'sections' => $workspace['sections'],
            'folderGroups' => $workspace['folderGroups'],
            'activeFolder' => $workspace['activeFolder'],
            'canViewActivityLog' => $workspace['canViewActivityLog'],
            'activityLogUrl' => $workspace['activityLogUrl'],
        ])
            {{ $this->table }}
        @endcomponent
    </div>
</x-filament-panels::page>
