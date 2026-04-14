<style>
    .roles-hero-card {
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .roles-hero-card:hover {
        transform: translateY(-2px);
        background: #ffffff !important;
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.12);
    }
</style>

<div style="background: radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 35%), linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%); border-radius: 1.5rem; padding: 1.5rem; margin-bottom: 1.5rem; width: 100%; box-sizing: border-box;">
    <div style="display:flex; align-items:flex-start; gap:1rem; margin-bottom:1.5rem;">
        <div style="display:flex; align-items:center; justify-content:center; width:3rem; height:3rem; border-radius:1rem; background:rgba(37, 99, 235, 0.12); color:#2563eb; flex-shrink:0;">
            <svg style="width:1.5rem; height:1.5rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
        </div>
        <div>
            <h1 style="margin:0 0 0.25rem; font-size:1.5rem; font-weight:800; color:#0f172a; letter-spacing: -0.02em;">Роли и доступы</h1>
            <p style="margin:0; color:#475569; font-size:0.9375rem; line-height:1.5;">Управление правами доступа сотрудников к разделам системы.</p>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem;">
        <a href="{{ $createUrl }}" class="roles-hero-card" style="display:flex; align-items:center; gap:1rem; padding:1.1rem; border-radius:1.1rem; background:rgba(255, 255, 255, 0.75); border:1px solid rgba(255, 255, 255, 0.6); text-decoration:none; color:inherit; backdrop-filter: blur(4px);">
            <div style="display:flex; align-items:center; justify-content:center; width:2.75rem; height:2.75rem; border-radius:0.85rem; background:rgba(37, 99, 235, 0.1); color:#2563eb; flex-shrink:0;">
                <svg style="width:1.35rem; height:1.35rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </div>
            <div>
                <p style="margin:0; font-size:0.95rem; font-weight:700; color:#0f172a;">Создать роль</p>
                <p style="margin:0.15rem 0 0; font-size:0.8rem; color:#64748b;">Новый набор прав для сотрудников.</p>
            </div>
        </a>

        <a href="{{ $invitationUrl }}" class="roles-hero-card" style="display:flex; align-items:center; gap:1rem; padding:1.1rem; border-radius:1.1rem; background:rgba(255, 255, 255, 0.75); border:1px solid rgba(255, 255, 255, 0.6); text-decoration:none; color:inherit; backdrop-filter: blur(4px);">
            <div style="display:flex; align-items:center; justify-content:center; width:2.75rem; height:2.75rem; border-radius:0.85rem; background:rgba(37, 99, 235, 0.1); color:#2563eb; flex-shrink:0;">
                <svg style="width:1.35rem; height:1.35rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
            </div>
            <div>
                <p style="margin:0; font-size:0.95rem; font-weight:700; color:#0f172a;">Приглашения</p>
                <p style="margin:0.15rem 0 0; font-size:0.8rem; color:#64748b;">Активные и ожидающие приглашения.</p>
            </div>
        </a>
    </div>
</div>
