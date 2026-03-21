<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\StaffConversation;
use App\Models\StaffConversationMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class StaffConversationService
{
    public function startConversation(User $author, User $recipient, string $subject, string $body): StaffConversation
    {
        $resolvedSubject = $this->resolveSubject($subject, $body);

        $conversation = StaffConversation::query()->create([
            'market_id' => (int) $recipient->market_id,
            'created_by_user_id' => (int) $author->id,
            'recipient_user_id' => (int) $recipient->id,
            'subject' => $resolvedSubject,
            'last_message_at' => now(),
        ]);

        $this->addMessage($conversation, $author, $body, notifyRecipient: false);
        $this->notifyRecipient(
            $recipient,
            'Новое сообщение от сотрудника',
            $resolvedSubject,
            $conversation
        );

        return $conversation;
    }

    public function addMessage(
        StaffConversation $conversation,
        User $author,
        string $body,
        bool $notifyRecipient = true,
    ): StaffConversationMessage {
        $message = StaffConversationMessage::query()->create([
            'staff_conversation_id' => (int) $conversation->id,
            'user_id' => (int) $author->id,
            'body' => trim($body),
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        if ($notifyRecipient) {
            $recipient = $this->resolveCounterparty($conversation, $author);

            if ($recipient instanceof User) {
                $this->notifyRecipient(
                    $recipient,
                    'Новое сообщение от сотрудника',
                    trim((string) $conversation->subject) !== ''
                        ? trim((string) $conversation->subject)
                        : 'Внутренний диалог',
                    $conversation
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
    ): void {
        $url = '/admin/requests?' . http_build_query([
            'channel' => 'staff',
            'conversation_id' => (int) $conversation->id,
        ]);

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->actions([
                Action::make('open')
                    ->label('Открыть')
                    ->url($url)
                    ->markAsRead(),
            ]);

        $notification->sendToDatabase($recipient);
    }
}
