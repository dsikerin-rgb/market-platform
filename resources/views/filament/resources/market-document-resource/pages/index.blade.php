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
        ])
            {{ $this->table }}
        @endcomponent
    </div>
</x-filament-panels::page>
