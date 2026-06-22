<x-filament-panels::page>
    @php
        $workspace = $this->documentWorkspaceData();
    @endphp

    <div>
        @component('filament.widgets.market-documents-workspace-widget', [
            'activeTab' => $workspace['activeTab'],
            'sections' => $workspace['sections'],
            'folderGroups' => $workspace['folderGroups'],
        ])
            {{ $this->table }}
        @endcomponent
    </div>
</x-filament-panels::page>
