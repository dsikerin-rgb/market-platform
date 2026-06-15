<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

final class MessageAttachmentStorage
{
    /**
     * @param array<int, mixed> $files
     * @return list<array<string, mixed>>
     */
    public static function store(array $files, string $directory): array
    {
        $attachments = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $mimeType = (string) ($file->getMimeType() ?: 'application/octet-stream');
            $path = MarketplaceMediaStorage::store($file, $directory);

            $attachments[] = [
                'path' => $path,
                'name' => (string) $file->getClientOriginalName(),
                'mime' => $mimeType,
                'size' => (int) ($file->getSize() ?: 0),
                'is_image' => str_starts_with($mimeType, 'image/'),
            ];
        }

        return $attachments;
    }

    /**
     * @param mixed $attachments
     * @return list<array<string, mixed>>
     */
    public static function present(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $prepared = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $path = trim((string) ($attachment['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $name = trim((string) ($attachment['name'] ?? ''));
            $mime = trim((string) ($attachment['mime'] ?? ''));
            $size = (int) ($attachment['size'] ?? 0);
            $isImage = (bool) ($attachment['is_image'] ?? str_starts_with($mime, 'image/'));
            $url = MarketplaceMediaStorage::url($path);

            if (! $url) {
                continue;
            }

            $prepared[] = [
                'path' => $path,
                'url' => $url,
                'preview_url' => $isImage ? MarketplaceMediaStorage::previewUrl($path) : null,
                'name' => $name !== '' ? $name : 'Файл',
                'mime' => $mime !== '' ? $mime : 'файл',
                'size' => $size,
                'size_label' => $size > 0 ? number_format($size / 1024 / 1024, 1, ',', ' ') . ' МБ' : null,
                'is_image' => $isImage,
            ];
        }

        return $prepared;
    }
}
