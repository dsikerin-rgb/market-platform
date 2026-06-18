<?php
# app/Providers/OneCWarningModalServiceProvider.php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class OneCWarningModalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            $response = $event->response;

            if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
                return;
            }

            $content = (string) $response->getContent();

            if ($content === '' || ! str_contains($content, 'id="dashboardOneCWarningModal"') || str_contains($content, 'data-onec-warning-modal-fix="1"')) {
                return;
            }

            $script = $this->script();
            $needle = '</body>';

            if (str_contains($content, $needle)) {
                $content = str_replace($needle, $script . PHP_EOL . $needle, $content);
            } else {
                $content .= PHP_EOL . $script;
            }

            $response->setContent($content);
        });
    }

    private function script(): string
    {
        return <<<'HTML'
<script data-onec-warning-modal-fix="1">
(function () {
  const modalId = 'dashboardOneCWarningModal';
  const closeSelector = '[data-onec-warning-close]';

  function getModal() {
    return document.getElementById(modalId);
  }

  function storageDismissed(modal) {
    const storageKey = modal?.dataset?.storageKey || '';

    if (!storageKey) {
      return false;
    }

    try {
      return window.localStorage.getItem(storageKey) === 'dismissed';
    } catch (error) {
      return false;
    }
  }

  function rememberDismissed(modal) {
    const storageKey = modal?.dataset?.storageKey || '';

    if (!storageKey) {
      return;
    }

    try {
      window.localStorage.setItem(storageKey, 'dismissed');
    } catch (error) {
      // localStorage can be unavailable in private mode or locked WebView contexts.
    }
  }

  function closeModal(modal) {
    if (!modal) {
      return;
    }

    modal.classList.remove('is-open');
    rememberDismissed(modal);
  }

  function openIfNeeded(modal) {
    if (!modal || storageDismissed(modal)) {
      return;
    }

    modal.classList.add('is-open');
  }

  function bindModal(modal) {
    if (!modal || modal.dataset.dismissFixBound === '1') {
      return;
    }

    modal.dataset.dismissFixBound = '1';
    openIfNeeded(modal);
  }

  function init() {
    bindModal(getModal());
  }

  document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target.closest(closeSelector) : null;

    if (!target) {
      return;
    }

    const modal = target.closest('#' + modalId) || getModal();

    if (!modal) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    closeModal(modal);
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }

    const modal = getModal();

    if (modal?.classList.contains('is-open')) {
      closeModal(modal);
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }

  document.addEventListener('livewire:navigated', init);
  document.addEventListener('livewire:load', init);
  document.addEventListener('turbo:load', init);
  document.addEventListener('filament:navigated', init);
})();
</script>
HTML;
    }
}
