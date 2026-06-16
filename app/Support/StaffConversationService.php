<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\User;
use App\Notifications\StaffMessageNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class StaffConversationService
{
    /**
     * @param list<array<string, mixed>> $attachments
     */
    public function startConversation(User $author, User $recipient, string $subject, string $body, array $attachments = []): StaffConversation
    {
        $existingConversation = $this->latestConversationBetween($author, $recipient);

        if ($existingConversation instanceof StaffConversation) {
            $this->addMessage($existingConversation, $author, $body, $attachments);

            return $existingConversation;
        }

        $resolvedSubject = $this->resolveSubject($subject, $body);

        $conversation = StaffConversation::query()->create([
            'market_id' => (int) $recipient->market_id,
            'created_by_user_id' => (int) $author->id,
            'recipient_user_id' => (int) $recipient->id,
            'subject' => $resolvedSubject,
            'last_message_at' => now(),
        ]);

        $this->addMessage($conversation, $author, $body, $attachments, notifyRecipient: false);
        $this->notifyRecipient(
            $recipient,
            'Новое сообщение от ' . ($author->name ?: 'сотрудника'),
            trim($body) !== '' ? trim($body) : ($attachments !== [] ? 'Вложение' : $resolvedSubject),
            $conversation,
            $author,
        );

        return $conversation;
    }

    /**
     * @param list<array<string, mixed>> $attachments
     */
    public function addMessage(
        StaffConversation $conversation,
        User $author,
        string $body,
        array $attachments = [],
        bool $notifyRecipient = true,
    ): StaffConversationMessage {
        $message = StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $author->id,
            'body' => trim($body),
            'attachments' => $attachments !== [] ? $attachments : null,
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        if ($notifyRecipient) {
            $recipient = $this->resolveCounterparty($conversation, $author);

            if ($recipient instanceof User) {
                $this->notifyRecipient(
                    $recipient,
                    'Новое сообщение от ' . ($author->name ?: 'сотрудника'),
                    trim($body) !== ''
                        ? trim($body)
                        : ($attachments !== []
                            ? 'Вложение'
                            : (trim((string) $conversation->subject) !== ''
                                ? trim((string) $conversation->subject)
                                : 'Внутренний диалог')),
                    $conversation,
                    $author,
                );
            }
        }

        return $message;
    }

    public function canAccessConversation(User $user, StaffConversation $conversation): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return in_array((int) $user->id, [
            (int) ($conversation->created_by_user_id ?? 0),
            (int) ($conversation->recipient_user_id ?? 0),
        ], true);
    }

    public function markConversationRead(StaffConversation $conversation, User $reader): void
    {
        if (! Schema::hasColumn('staff_conversation_messages', 'read_at')) {
            return;
        }

        StaffConversationMessage::query()
            ->where('staff_conversation_id', (int) $conversation->id)
            ->where('user_id', '<>', (int) $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markIncomingFromStaffRead(User $reader, User $staff): void
    {
        if (! Schema::hasColumn('staff_conversation_messages', 'read_at')) {
            return;
        }

        StaffConversationMessage::query()
            ->where('user_id', (int) $staff->id)
            ->whereNull('read_at')
            ->whereHas('conversation', function ($query) use ($reader, $staff): void {
                $query->where(function ($pair) use ($reader, $staff): void {
                    $pair
                        ->where('created_by_user_id', (int) $reader->id)
                        ->where('recipient_user_id', (int) $staff->id);
                })->orWhere(function ($pair) use ($reader, $staff): void {
                    $pair
                        ->where('created_by_user_id', (int) $staff->id)
                        ->where('recipient_user_id', (int) $reader->id);
                });
            })
            ->update(['read_at' => now()]);
    }

    private function resolveCounterparty(StaffConversation $conversation, User $author): ?User
    {
        $starterId = (int) ($conversation->created_by_user_id ?? 0);
        $recipientId = (int) ($conversation->recipient_user_id ?? 0);
        $authorId = (int) $author->id;

        $counterpartyId = $authorId === $starterId ? $recipientId : $starterId;

        return $counterpartyId > 0 ? User::query()->find($counterpartyId) : null;
    }

    private function resolveSubject(string $subject, string $body): string
    {
        $subject = trim($subject);
        if ($subject !== '') {
            return mb_substr($subject, 0, 255);
        }

        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($body === '') {
            return 'Новый внутренний диалог';
        }

        return mb_substr($body, 0, 80);
    }

    private function notifyRecipient(
        User $recipient,
        string $title,
        string $body,
        StaffConversation $conversation,
        User $author,
    ): void {
        $recipient->notify(new StaffMessageNotification($conversation, $author, $title, $body));
    }

    private function latestConversationBetween(User $author, User $recipient): ?StaffConversation
    {
        return StaffConversation::query()
            ->where(function (Builder $pair) use ($author, $recipient): void {
                $pair
                    ->where(function (Builder $direct) use ($author, $recipient): void {
                        $direct
                            ->where('created_by_user_id', (int) $author->id)
                            ->where('recipient_user_id', (int) $recipient->id);
                    })
                    ->orWhere(function (Builder $reverse) use ($author, $recipient): void {
                        $reverse
                            ->where('created_by_user_id', (int) $recipient->id)
                            ->where('recipient_user_id', (int) $author->id);
                    });
            })
            ->latest('last_message_at')
            ->latest('updated_at')
            ->first();
    }
}
