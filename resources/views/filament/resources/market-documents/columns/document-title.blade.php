@php
    /** @var \App\Models\MarketDocument $record */
    $title = \App\Filament\Resources\MarketDocumentResource::documentTitleLabel($record);
    $type = \App\Filament\Resources\MarketDocumentResource::documentTypeMetaForRecord($record);
    $kind = $type['kind'];
    $mark = $type['mark'];
    $background = $type['background'];
    $foreground = $type['foreground'];
@endphp

<span style="display:inline-flex;align-items:center;gap:10px;min-width:0;max-width:100%;">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;flex:0 0 24px;" title="{{ $type['label'] }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" style="display:block;width:22px;height:22px;overflow:visible;flex:0 0 22px;">
            <path
                d="M6 2.75h7.3L18 7.45v13.8H6z"
                fill="#fff"
                stroke="{{ $background }}"
                stroke-width="1.7"
                stroke-linejoin="round"
            />
            <path d="M13.3 2.75v4.7H18" fill="#fff" stroke="{{ $background }}" stroke-width="1.7" stroke-linejoin="round" />

            @if ($kind === 'sheet')
                <rect x="7.7" y="8.9" width="8.6" height="7.9" rx="1" fill="{{ $background }}" />
                <path d="M10.55 8.9v7.9M13.45 8.9v7.9M7.7 11.55h8.6M7.7 14.2h8.6" stroke="#fff" stroke-width=".65" opacity=".9" />
            @elseif ($kind === 'image')
                <rect x="7.5" y="8.5" width="9" height="8" rx="1.3" fill="{{ $background }}" />
                <circle cx="10" cy="10.9" r=".85" fill="#fff" />
                <path d="M8.5 15.2l2.6-2.5 1.7 1.7 1.4-1.2 1.8 2" fill="none" stroke="#fff" stroke-width=".8" stroke-linecap="round" stroke-linejoin="round" />
            @elseif ($kind === 'file')
                <path d="M8.7 10.2h6.6M8.7 12.8h5.6M8.7 15.4h4.5" stroke="{{ $background }}" stroke-width="1.45" stroke-linecap="round" />
            @else
                <rect x="7.2" y="9.1" width="9.6" height="7.1" rx="1.1" fill="{{ $background }}" />
                <text
                    x="12"
                    y="14.25"
                    text-anchor="middle"
                    fill="{{ $foreground }}"
                    font-size="{{ strlen($mark) > 1 ? '4.1' : '6.7' }}"
                    font-weight="800"
                    font-family="Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
                >{{ $mark }}</text>
            @endif
        </svg>
    </span>

    <span style="min-width:0;color:#0f172a;font-weight:650;line-height:1.2;overflow-wrap:anywhere;">
        {{ $title }}
    </span>
</span>
