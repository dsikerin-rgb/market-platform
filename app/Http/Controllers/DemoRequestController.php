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

        $demoRequest = DemoRequest::query()->create([
            'status' => DemoRequest::STATUS_NEW,
            'request_type' => (string) $validated['request_type'],
            'name' => trim((string) $validated['name']),
            'organization' => trim((string) $validated['organization']),
            'email' => mb_strtolower(trim((string) $validated['email'])),
            'phone' => $this->nullableString($validated['phone'] ?? null),
            'city' => $this->nullableString($validated['city'] ?? null),
            'market_format' => $this->nullableString($validated['market_format'] ?? null),
            'spaces_count' => isset($validated['spaces_count']) ? (int) $validated['spaces_count'] : null,
            'message' => $this->nullableString($validated['message'] ?? null),
            'source' => 'demo_landing',
            'ip_hash' => $this->ipHash($request),
            'user_agent' => $this->nullableString(mb_substr((string) $request->userAgent(), 0, 255)),
            'metadata' => [
                'url' => $request->headers->get('referer'),
                'submitted_at' => now()->toIso8601String(),
            ],
        ]);

        try {
            $notified = $this->notifyOwners($demoRequest, $settings);
            if ($notified > 0) {
                $demoRequest->forceFill(['notified_at' => now()])->save();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $this->successfulRedirect();
    }

    private function successfulRedirect(): RedirectResponse
    {
        return redirect()
            ->route('demo.landing', ['request_sent' => 1])
            ->with('demo_request_status', 'sent');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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

    private function notificationBody(DemoRequest $demoRequest): string
    {
        $parts = [
            $demoRequest->organization,
            $demoRequest->name,
            $demoRequest->email,
            DemoRequest::typeLabel((string) $demoRequest->request_type),
        ];

        return implode(' · ', array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $parts,
        )));
    }
}
