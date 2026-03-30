<script>
    (function () {
        if (window.__marketSessionExpiryGuardInstalled) {
            return;
        }

        window.__marketSessionExpiryGuardInstalled = true;

        const loginUrl = @js(\Filament\Facades\Filament::getLoginUrl() ?? url('/admin/login'));
        const nativeConfirm = window.confirm.bind(window);

        window.confirm = function (message) {
            if (typeof message === 'string' && message.includes('This page has expired')) {
                window.location.replace(loginUrl + (loginUrl.includes('?') ? '&' : '?') + 'session_expired=1');

                return false;
            }

            return nativeConfirm(message);
        };
    })();
</script>
