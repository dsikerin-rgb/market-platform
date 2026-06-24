@php
    /** @var \App\Models\MarketDocument $record */
    $title = \App\Filament\Resources\MarketDocumentResource::documentTitleLabel($record);
    $type = \App\Filament\Resources\MarketDocumentResource::documentTypeMetaForRecord($record);
    $kind = $type['kind'];
    $mark = $type['mark'];
    $background = $type['background'];
    $foreground = $type['foreground'];
    $openUrl = filled($record->file_path) ? route('filament.admin.market-documents.open', ['document' => $record]) : null;
@endphp

@if ($openUrl)
    <a
        href="{{ $openUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        style="display:inline-flex;align-items:center;gap:10px;min-width:0;max-width:100%;color:inherit;text-decoration:none;"
    >
@else
    <span style="display:inline-flex;align-items:center;gap:10px;min-width:0;max-width:100%;color:inherit;text-decoration:none;">
@endif
    <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;flex:0 0 24px;" title="{{ $type['label'] }}">
        <svg aria-hidden="true" viewBox="0 0 32 32" width="24" height="24" style="display:block;width:24px;height:24px;overflow:hidden;flex:0 0 24px;">
            <path
                d="M8.25 2.75h12.9L27 8.65v20.1a2 2 0 0 1-2 2H8.25a2 2 0 0 1-2-2v-24a2 2 0 0 1 2-2Z"
                fill="#fff"
                stroke="{{ $background }}"
                stroke-width="1.35"
                stroke-linejoin="round"
            />
            <path d="M21.15 2.9v5.75H27" fill="#eaf2ff" stroke="{{ $background }}" stroke-width="1.35" stroke-linejoin="round" />
            <rect x="2.9" y="11.1" width="17.8" height="14.2" rx="2.1" fill="{{ $background }}" />

            @if ($kind === 'sheet')
                <text
                    x="8.25"
                    y="20.7"
                    text-anchor="middle"
                    fill="#fff"
                    font-size="8.35"
                    font-weight="800"
                    font-family="Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
                >X</text>
                <path d="M12.2 14.35h5.05v7.3H12.2zM14.72 14.35v7.3M12.2 16.78h5.05M12.2 19.22h5.05" fill="none" stroke="#fff" stroke-width=".75" opacity=".9" />
            @elseif ($kind === 'image')
                <circle cx="8.65" cy="15.55" r="1.15" fill="#fff" />
                <path d="M6.1 21.3l3.75-3.55 2.4 2.2 1.85-1.75 3.1 3.1" fill="none" stroke="#fff" stroke-width="1.15" stroke-linecap="round" stroke-linejoin="round" />
            @elseif ($kind === 'file')
                <path d="M10.9 15.5h12.3M10.9 19.15h10.2M10.9 22.8h8.1" stroke="{{ $background }}" stroke-width="1.35" stroke-linecap="round" opacity=".75" />
                <path d="M7 15.45h9.55M7 18.3h8.1M7 21.15h6.45" stroke="#fff" stroke-width="1.15" stroke-linecap="round" />
            @else
                <text
                    x="11.8"
                    y="{{ strlen($mark) > 1 ? '20.1' : '21.15' }}"
                    text-anchor="middle"
                    fill="{{ $foreground }}"
                    font-size="{{ strlen($mark) > 2 ? '4.3' : (strlen($mark) > 1 ? '5.15' : '8.9') }}"
                    font-weight="800"
                    font-family="Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
                >{{ $mark }}</text>
            @endif
        </svg>
    </span>

    <span style="min-width:0;color:#1f2937;font-size:12.5px;font-weight:400;line-height:1.25;overflow-wrap:anywhere;">
        {{ $title }}
    </span>
@if ($openUrl)
    </a>
@else
    </span>
@endif
