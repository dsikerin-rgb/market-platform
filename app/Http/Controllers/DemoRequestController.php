<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use App\Models\User;
use App\Support\DemoPilotSettings;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DemoRequestController extends Controller
{
    public function store(Request $request, DemoPilotSettings $settings): RedirectResponse
    {
        if (filled(trim((string) $request->input('company_website', '')))) {
            return $this->successfulRedirect();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:120'],
            'market_format' => ['nullable', 'string', 'max:120'],
            'spaces_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'request_type' => ['required', Rule::in([
                DemoRequest::TYPE_DEMO,
                DemoRequest::TYPE_PILOT,
                DemoRequest::TYPE_FREE,
            ])],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'Укажите имя.',
            'organization.required' => 'Укажите организацию или рынок.',
            'email.required' => 'Укажите email.',
            'email.email' => 'Укажите корректный email.',
            'request_type.required' => 'Выберите формат подключения.',
            'consent.accepted' => 'Подтвердите согласие на обработку заявки.',
        ]);

        $demoRequest = $this->createDemoRequest(
            $request,
            $validated,
            (string) $validated['request_type'],
            'demo_landing',
        );

        $this->notifyOwnersSafely($demoRequest, $settings);

        return $this->successfulRedirect();
    }

    public function quickStart(
        Request $request,
        DemoPilotSettings $settings,
        DemoAccessController $accessController,
    ): RedirectResponse
    {
        if (filled(trim((string) $request->input('company_website', '')))) {
            return $this->quickStartPendingRedirect();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'Укажите имя.',
            'organization.required' => 'Укажите город, рынок или организацию.',
            'email.required_without' => 'Укажите email или телефон.',
            'email.email' => 'Укажите корректный email.',
            'phone.required_without' => 'Укажите телефон или email.',
            'consent.accepted' => 'Подтвердите согласие на обработку заявки.',
        ]);

        $emailMissing = $this->nullableString($validated['email'] ?? null) === null;

        $demoRequest = $this->createDemoRequest(
            $request,
            $validated,
            DemoRequest::TYPE_DEMO,
            'demo_quick_start',
            [
                'quick_start' => true,
                'public_login_enabled' => $settings->publicLoginEnabled(),
                'contact_email_missing' => $emailMissing,
            ],
        );

        $this->notifyOwnersSafely($demoRequest, $settings);

        if ($settings->publicLoginEnabled()) {
            return $accessController->signIn($request, $settings);
        }

        return $this->quickStartPendingRedirect();
    }

    private function successfulRedirect(): RedirectResponse
    {
        return redirect()
            ->route('demo.landing', ['request_sent' => 1])
            ->with('demo_request_status', 'sent');
    }

    private function quickStartPendingRedirect(): RedirectResponse
    {
        return redirect()
            ->route('demo.landing', ['request_sent' => 1])
            ->with('demo_request_status', 'sent')
            ->with('demo_quick_start_status', 'access_pending');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $metadata
     */
    private function createDemoRequest(
        Request $request,
        array $validated,
        string $requestType,
        string $source,
        array $metadata = [],
    ): DemoRequest {
        return DemoRequest::query()->create([
            'status' => DemoRequest::STATUS_NEW,
            'request_type' => $requestType,
            'name' => trim((string) $validated['name']),
            'organization' => trim((string) $validated['organization']),
            'email' => $this->leadEmail($request, $validated),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'city' => $this->nullableString($validated['city'] ?? null),
            'market_format' => $this->nullableString($validated['market_format'] ?? null),
            'spaces_count' => isset($validated['spaces_count']) ? (int) $validated['spaces_count'] : null,
            'message' => $this->nullableString($validated['message'] ?? null),
            'source' => $source,
            'ip_hash' => $this->ipHash($request),
            'user_agent' => $this->nullableString(mb_substr((string) $request->userAgent(), 0, 255, 'UTF-8')),
            'metadata' => [
                'url' => $request->headers->get('referer'),
                'submitted_at' => now()->toIso8601String(),
                ...$metadata,
            ],
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function leadEmail(Request $request, array $validated): string
    {
        $email = mb_strtolower(trim((string) ($validated['email'] ?? '')), 'UTF-8');

        if ($email !== '') {
            return $email;
        }

        $phone = trim((string) ($validated['phone'] ?? ''));
        $fingerprint = hash_hmac(
            'sha256',
            implode('|', [$phone, (string) $this->ipHash($request), (string) $request->userAgent()]),
            (string) config('app.key', 'market-platform'),
        );

        return 'phone-only-' . substr($fingerprint, 0, 20) . '@demo-request.local';
    }

    private function ipHash(Request $request): ?string
    {
        $ip = trim((string) $request->ip());
        if ($ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key', 'market-platform'));
    }

    private function notifyOwners(DemoRequest $demoRequest, DemoPilotSettings $settings): int
    {
        $emails = $settings->ownerEmails();
        if ($emails === []) {
            return 0;
        }

        $owners = User::query()
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->get();

        foreach ($owners as $owner) {
            Notification::make()
                ->title('Новая заявка на демо')
                ->body($this->notificationBody($demoRequest))
                ->sendToDatabase($owner);
        }

        return $owners->count();
    }

    private function notifyOwnersSafely(DemoRequest $demoRequest, DemoPilotSettings $settings): void
    {
        try {
            $notified = $this->notifyOwners($demoRequest, $settings);
            if ($notified > 0) {
                $demoRequest->forceFill(['notified_at' => now()])->save();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function notificationBody(DemoRequest $demoRequest): string
    {
        $contact = (bool) data_get($demoRequest->metadata, 'contact_email_missing')
            ? $demoRequest->phone
            : $demoRequest->email;

        $parts = [
            $demoRequest->organization,
            $demoRequest->name,
            $contact,
            DemoRequest::typeLabel((string) $demoRequest->request_type),
        ];

        return implode(' · ', array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $parts,
        )));
    }
}
