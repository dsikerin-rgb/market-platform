@php
    $activeReviewResultsTab = in_array(request()->query('tab', 'review'), ['review', 'unconfirmed_links', 'data_quality', 'applied'], true)
        ? request()->query('tab', 'review')
        : 'review';
@endphp

@if ($activeReviewResultsTab === 'data_quality')
    @include('filament.widgets.map-review-data-quality-signals-widget')
@endif

<script>
    (() => {
        const activeTab = @json($activeReviewResultsTab);
        const tabItems = [
            { key: 'review', label: 'Ревизионные решения', url: @json(request()->fullUrlWithQuery(['tab' => 'review'])) },
            { key: 'unconfirmed_links', label: 'Связь не подтверждена', url: @json(request()->fullUrlWithQuery(['tab' => 'unconfirmed_links'])) },
            { key: 'data_quality', label: 'Сигналы качества данных', url: @json(request()->fullUrlWithQuery(['tab' => 'data_quality'])) },
            { key: 'applied', label: 'Применено', url: @json(request()->fullUrlWithQuery(['tab' => 'applied'])) },
        ];

        const enhanceTabs = () => {
            const toggle = document.querySelector('.mrr-sort-toggle');
            const panels = Array.from(document.querySelectorAll('.aw-column > .aw-panel'));
            const needsPanel = panels[0] || null;
            const appliedPanel = panels[1] || null;

            if (!(toggle instanceof HTMLElement)) {
                return;
            }

            toggle.replaceChildren();

            tabItems.forEach((item) => {
                const link = document.createElement('a');
                link.className = 'mrr-sort-toggle__link';
                link.href = item.url;
                link.textContent = item.label;

                if (item.key === activeTab) {
                    link.classList.add('is-active');
                }

                toggle.appendChild(link);
            });

            if (needsPanel instanceof HTMLElement) {
                needsPanel.hidden = !['review', 'unconfirmed_links'].includes(activeTab);
            }

            if (appliedPanel instanceof HTMLElement) {
                appliedPanel.hidden = activeTab !== 'applied';
            }

            const activeTitle = document.querySelector('.aw-panel-title');
            const activeCopy = document.querySelector('.aw-panel-copy');

            if (activeTab === 'applied') {
                document.title = document.title;
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enhanceTabs, { once: true });
        } else {
            enhanceTabs();
        }
    })();
</script>
