@php
    use App\Filament\Widgets\MapReviewDataQualitySignalsWidget;
    use Livewire\Livewire;

    $activeReviewResultsTab = in_array(request()->query('tab', 'review'), ['review', 'unconfirmed_links', 'data_quality', 'applied'], true)
        ? request()->query('tab', 'review')
        : 'review';
@endphp

@if ($activeReviewResultsTab === 'data_quality')
    <div id="mrrDataQualitySignalsSource" hidden>
        {!! Livewire::mount(MapReviewDataQualitySignalsWidget::class) !!}
    </div>
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

        const activeTabCopy = {
            review: {
                title: 'Нужно уточнить',
                copy: 'Места со спорным или незавершённым ревизионным результатом.',
            },
            unconfirmed_links: {
                title: 'Нужно уточнить',
                copy: 'Места на карте, где статус взят по арендатору, но точная связь с местом не подтверждена.',
            },
            data_quality: {
                title: 'Сигналы качества данных',
                copy: 'Read-only сигналы по справочникам и 1С-сопоставлениям. Они ничего не меняют автоматически и нужны для ручной проверки.',
            },
            applied: {
                title: 'Применено',
                copy: 'Безопасные изменения, уже прошедшие через SPACE_REVIEW.',
            },
        };

        const enhanceTabs = () => {
            const toggle = document.querySelector('.mrr-sort-toggle');
            const panels = Array.from(document.querySelectorAll('.aw-column > .aw-panel'));
            const needsPanel = panels[0] || null;
            const appliedPanel = panels[1] || null;

            if (!(toggle instanceof HTMLElement) || !(needsPanel instanceof HTMLElement)) {
                return;
            }

            const needsTitle = needsPanel.querySelector('.aw-panel-title');
            const needsCopy = needsPanel.querySelector('.aw-panel-copy');
            const needsBody = needsPanel.querySelector('.aw-panel-body');

            if (!(needsBody instanceof HTMLElement)) {
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

            const copy = activeTabCopy[activeTab] || activeTabCopy.review;

            if (needsTitle instanceof HTMLElement) {
                needsTitle.textContent = copy.title;
            }

            if (needsCopy instanceof HTMLElement) {
                needsCopy.textContent = copy.copy;
            }

            if (appliedPanel instanceof HTMLElement) {
                appliedPanel.hidden = true;
            }

            if (activeTab === 'data_quality') {
                const source = document.getElementById('mrrDataQualitySignalsSource');
                const dataQualitySection = source instanceof HTMLElement
                    ? source.querySelector('section') || source.firstElementChild
                    : null;

                if (dataQualitySection instanceof Element) {
                    dataQualitySection.querySelector('.aw-panel-head')?.remove();
                    needsBody.replaceChildren(dataQualitySection);
                }

                return;
            }

            if (activeTab === 'applied') {
                const appliedBody = appliedPanel instanceof HTMLElement
                    ? appliedPanel.querySelector('.aw-panel-body')
                    : null;

                if (appliedBody instanceof HTMLElement) {
                    needsBody.replaceChildren(...Array.from(appliedBody.childNodes));
                }

                return;
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enhanceTabs, { once: true });
        } else {
            enhanceTabs();
        }
    })();
</script>
