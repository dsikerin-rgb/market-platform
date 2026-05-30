<style>
    .mrr-retire-choice-note {
        border-radius: 0.9rem;
        border: 1px solid rgba(148, 163, 184, 0.24);
        background: rgba(248, 250, 252, 0.92);
        padding: 0.72rem 0.82rem;
        font-size: 0.82rem;
        line-height: 1.45;
        color: #475569;
    }

    .dark .mrr-retire-choice-note {
        border-color: rgba(148, 163, 184, 0.2);
        background: rgba(15, 23, 42, 0.62);
        color: #cbd5e1;
    }

    .mrr-link--retire-no-canonical {
        border-color: rgba(220, 38, 38, 0.25) !important;
        color: #991b1b !important;
        background: rgba(254, 242, 242, 0.86) !important;
    }

    .dark .mrr-link--retire-no-canonical {
        border-color: rgba(248, 113, 113, 0.28) !important;
        color: #fecaca !important;
        background: rgba(127, 29, 29, 0.22) !important;
    }
</style>

<div id="mrrRetireSpaceModal" class="mrr-clarify-modal" hidden aria-hidden="true">
    <div class="mrr-clarify-modal__backdrop" data-mrr-retire-space-close></div>
    <div
        class="mrr-clarify-modal__dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mrrRetireSpaceTitle"
        aria-describedby="mrrRetireSpaceDescription"
    >
        <button type="button" class="mrr-clarify-modal__close" data-mrr-retire-space-close aria-label="Закрыть">×</button>
        <div class="mrr-clarify-modal__eyebrow">Архивация места</div>
        <h3 id="mrrRetireSpaceTitle" class="mrr-clarify-modal__title">Место больше не существует</h3>
        <p id="mrrRetireSpaceDescription" class="mrr-clarify-modal__description">
            Используйте этот сценарий, если место физически исчезло с рынка и нет другой карточки, которую нужно оставить основной.
        </p>

        <div class="mrr-retire-choice-note">
            Система снимет активную разметку с карты, выведет карточку из текущего фонда и сохранит историю начислений, договоров и действий в аудите.
        </div>

        <div class="mrr-clarify-modal__field">
            <label class="mrr-clarify-modal__label" for="mrrRetireSpaceDate">Дата действия</label>
            <input id="mrrRetireSpaceDate" class="mrr-clarify-modal__input" type="date">
            <div class="mrr-quick-review__hint">Обычно это дата, с которой место фактически отсутствует на рынке.</div>
        </div>

        <div class="mrr-clarify-modal__field">
            <label class="mrr-clarify-modal__label" for="mrrRetireSpaceReason">Комментарий</label>
            <textarea
                id="mrrRetireSpaceReason"
                class="mrr-clarify-modal__input mrr-quick-review__field"
                rows="3"
                placeholder="Например: промостойка демонтирована, на рынке больше не используется"
            ></textarea>
        </div>

        <div class="mrr-clarify-modal__error" id="mrrRetireSpaceError" aria-live="polite"></div>

        <div class="mrr-clarify-modal__actions">
            <button type="button" class="mrr-clarify-modal__button" data-mrr-retire-space-close>Отмена</button>
            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-retire-space-save>Архивировать место</button>
        </div>
    </div>
</div>

<script>
    (() => {
        const endpointUrl = @json(route('filament.admin.map-review-results.retire-space'));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const modal = document.getElementById('mrrRetireSpaceModal');
        const dateInput = document.getElementById('mrrRetireSpaceDate');
        const reasonInput = document.getElementById('mrrRetireSpaceReason');
        const errorTarget = document.getElementById('mrrRetireSpaceError');
        const saveButton = modal?.querySelector('[data-mrr-retire-space-save]');
        let activeSpaceId = 0;
        let activeSpaceLabel = '';

        if (!(modal instanceof HTMLElement)
            || !(dateInput instanceof HTMLInputElement)
            || !(reasonInput instanceof HTMLTextAreaElement)
            || !(saveButton instanceof HTMLButtonElement)
        ) {
            return;
        }

        const setError = (message) => {
            if (errorTarget instanceof HTMLElement) {
                errorTarget.textContent = message || '';
            }
        };

        const today = () => {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd}`;
        };

        const openModal = (button) => {
            activeSpaceId = Number(button.dataset.mrrSpaceId || 0);
            activeSpaceLabel = String(button.dataset.mrrSpaceLabel || '').trim();
            dateInput.value = button.dataset.mrrEffectiveDate || today();
            reasonInput.value = button.dataset.mrrReason || (activeSpaceLabel ? `${activeSpaceLabel}: место физически больше не существует на рынке.` : 'Место физически больше не существует на рынке.');
            setError('');
            modal.hidden = false;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            reasonInput.focus();
        };

        const closeModal = () => {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            modal.hidden = true;
            activeSpaceId = 0;
            activeSpaceLabel = '';
            setError('');
        };

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const openButton = target?.closest('[data-mrr-retire-space-open]');
            if (openButton instanceof HTMLElement) {
                event.preventDefault();
                openModal(openButton);
                return;
            }

            if (target?.closest('[data-mrr-retire-space-close]')) {
                event.preventDefault();
                closeModal();
            }
        }, true);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                event.preventDefault();
                closeModal();
            }
        });

        saveButton.addEventListener('click', async () => {
            const effectiveDate = dateInput.value;
            const reason = reasonInput.value.trim();

            if (!activeSpaceId) {
                setError('Не удалось определить место для архивации. Закройте окно и откройте действие заново.');
                return;
            }

            if (!effectiveDate) {
                setError('Укажите дату действия.');
                dateInput.focus();
                return;
            }

            if (!reason) {
                setError('Добавьте комментарий: почему место больше не существует.');
                reasonInput.focus();
                return;
            }

            saveButton.setAttribute('disabled', 'disabled');
            setError('');

            try {
                const response = await fetch(endpointUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        market_space_id: activeSpaceId,
                        effective_date: effectiveDate,
                        reason,
                    }),
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok || !data?.ok) {
                    setError(String(data?.message || 'Не удалось архивировать место.'));
                    return;
                }

                window.location.reload();
            } catch (error) {
                setError('Не удалось отправить запрос. Попробуйте ещё раз.');
            } finally {
                saveButton.removeAttribute('disabled');
            }
        });

        const enhanceRetireActions = () => {
            document.querySelectorAll('[data-mrr-merge-retire-open]').forEach((mergeButton) => {
                if (!(mergeButton instanceof HTMLElement) || mergeButton.dataset.mrrRetireChoiceEnhanced === '1') {
                    return;
                }

                mergeButton.dataset.mrrRetireChoiceEnhanced = '1';
                mergeButton.textContent = 'Старая карточка другого места';

                const retireButton = document.createElement('button');
                retireButton.type = 'button';
                retireButton.className = mergeButton.className.replace('mrr-link--primary', '').trim() + ' mrr-link--retire-no-canonical';
                retireButton.textContent = 'Места больше нет';
                retireButton.dataset.mrrRetireSpaceOpen = '1';
                retireButton.dataset.mrrSpaceId = mergeButton.dataset.mrrSpaceId || '';
                retireButton.dataset.mrrSpaceLabel = mergeButton.dataset.mrrSpaceLabel || '';
                retireButton.dataset.mrrEffectiveDate = today();

                const row = mergeButton.closest('[data-mrr-attention-card]');
                const reasonText = row?.querySelector('.mrr-conflict-brief__hint-text, .mrr-place__decision-reason')?.textContent?.trim() || '';
                if (reasonText) {
                    retireButton.dataset.mrrReason = reasonText;
                }

                mergeButton.insertAdjacentElement('afterend', retireButton);

                const actionGroup = mergeButton.closest('.mrr-card-actions__group');
                const hint = actionGroup?.querySelector('.mrr-card-actions__label');
                if (hint instanceof HTMLElement) {
                    hint.textContent = 'Выберите сценарий';
                }
            });
        };

        enhanceRetireActions();
        const observer = new MutationObserver(() => enhanceRetireActions());
        observer.observe(document.body, { childList: true, subtree: true });
    })();
</script>
