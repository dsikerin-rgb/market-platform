@php
    use App\Support\RoleScenarioCatalog;
@endphp

<div style="background: radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 35%), linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%); border-radius: 1.5rem; padding: 1.5rem; margin-bottom: 1.5rem; width: 100%; box-sizing: border-box;">
    <div style="display:flex; align-items:flex-start; gap:1rem; margin-bottom:1rem;">
        <div style="display:flex; align-items:center; justify-content:center; width:3rem; height:3rem; border-radius:1rem; background:rgba(37, 99, 235, 0.12); color:#2563eb; flex-shrink:0;">
            <svg style="width:1.5rem; height:1.5rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
        </div>
        <div style="flex: 1; min-width: 0;">
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.25rem;">
                <a href="{{ $backUrl }}" style="display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; background:rgba(37, 99, 235, 0.1); color:#2563eb; text-decoration:none; transition: background 0.15s ease;">
                    <svg style="width:1.1rem; height:1.1rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </a>
                <h1 style="margin:0; font-size:1.5rem; font-weight:800; color:#0f172a; letter-spacing: -0.02em;">{{ $roleName }}</h1>
                @if($isSystem)
                    <span style="display:inline-block; padding:0.15rem 0.6rem; border-radius:9999px; background:rgba(37, 99, 235, 0.1); color:#2563eb; font-size:0.75rem; font-weight:600;">Системная</span>
                @endif
            </div>
            @if($profileLabel || $profileDescription)
                <div style="margin-top:0.35rem; padding-left:2.75rem;">
                    @if($profileLabel)
                        <p style="margin:0; color:#0f172a; font-size:0.9375rem; font-weight:600;">{{ $profileLabel }}</p>
                    @endif
                    @if($profileDescription)
                        <p style="margin:0.15rem 0 0; color:#475569; font-size:0.875rem; line-height:1.5;">{{ $profileDescription }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
