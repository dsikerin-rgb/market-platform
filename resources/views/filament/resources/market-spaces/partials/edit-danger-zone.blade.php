<section class="market-space-danger-zone">
    <style>
        .market-space-danger-zone {
            margin-top: 1.5rem;
            border: 1px solid #fecaca;
            border-radius: 1.25rem;
            background: linear-gradient(180deg, #fff7f7 0%, #fff1f2 100%);
            box-shadow: 0 18px 40px rgba(127, 29, 29, 0.08);
            padding: 1.25rem;
        }

        .market-space-danger-zone__eyebrow {
            margin: 0;
            color: #b91c1c;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .market-space-danger-zone__title {
            margin: 0.35rem 0 0;
            color: #7f1d1d;
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .market-space-danger-zone__text {
            margin: 0.7rem 0 0;
            color: #991b1b;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .market-space-danger-zone__actions {
            margin-top: 1rem;
        }

        .market-space-danger-zone__meta {
            display: inline-flex;
            align-items: center;
            margin-top: 0.95rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.7);
            color: #b91c1c;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .dark .market-space-danger-zone {
            border-color: rgba(248, 113, 113, 0.35);
            background: linear-gradient(180deg, rgba(69, 10, 10, 0.42) 0%, rgba(69, 10, 10, 0.28) 100%);
            box-shadow: none;
        }

        .dark .market-space-danger-zone__eyebrow {
            color: #fca5a5;
        }

        .dark .market-space-danger-zone__title {
            color: #fee2e2;
        }

        .dark .market-space-danger-zone__text {
            color: #fecaca;
        }

        .dark .market-space-danger-zone__meta {
            background: rgba(15, 23, 42, 0.35);
            color: #fca5a5;
        }
    </style>

    <p class="market-space-danger-zone__eyebrow">Опасная зона</p>
    <h3 class="market-space-danger-zone__title">Полное удаление доступно только super-admin</h3>
    <p class="market-space-danger-zone__text">
        Это необратимое действие. В отличие от упразднения, место будет удалено из системы полностью.
        Используйте его только для технических случаев, когда карточку нужно убрать окончательно.
    </p>

    @if (! empty($isCascadeDelete))
        <div class="market-space-danger-zone__meta">Будет удалена и фигура на карте</div>
    @endif

    <div class="market-space-danger-zone__actions">
        <x-filament::actions :actions="$actions" alignment="start" />
    </div>
</section>
