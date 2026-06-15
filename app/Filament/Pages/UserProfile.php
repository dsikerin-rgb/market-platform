<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\MarketplaceMediaStorage;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UserProfile extends EditProfile
{
    protected static ?string $title = 'Профиль';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        Forms\Components\TextInput::make('phone')
                            ->label('Телефон')
                            ->tel()
                            ->maxLength(32)
                            ->nullable()
                            ->placeholder('+7 900 000-00-00')
                            ->helperText('Необязательный номер для связи. Его смогут видеть другие сотрудники.')
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null),
                    ])
                    ->columns(2),

                Section::make('Аватар')
                    ->description('Фото показывается в верхнем меню, живой ленте и правой панели сотрудников. Если фото не загружено, используется цветная иконка с инициалами.')
                    ->schema([
                        Forms\Components\FileUpload::make('staff_avatar_path')
                            ->label('Фото')
                            ->disk(MarketplaceMediaStorage::disk())
                            ->directory('staff-avatars')
                            ->visibility('public')
                            ->image()
                            ->avatar()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1'])
                            ->maxSize(5120)
                            ->getUploadedFileUsing(fn (string $file): ?array => $this->getUploadedAvatarFile($file))
                            ->helperText('До 5 МБ. Файл хранится в облаке.'),

                        Forms\Components\ColorPicker::make('staff_avatar_color')
                            ->label('Цвет иконки')
                            ->default('#2563eb')
                            ->nullable()
                            ->rule('regex:/^#[0-9A-Fa-f]{6}$/')
                            ->helperText('Используется для инициалов, если фото не загружено.'),
                    ])
                    ->columns(2),

                Section::make('Пароль')
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ])
                    ->columns(2),
            ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);

        $color = trim((string) ($data['staff_avatar_color'] ?? ''));
        $data['staff_avatar_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1
            ? strtolower($color)
            : '#2563eb';

        return $data;
    }

    /**
     * @return array{name: string, size: int, type: string, url: string}|null
     */
    protected function getUploadedAvatarFile(string $file): ?array
    {
        $path = trim($file);
        if ($path === '') {
            return null;
        }

        $metadata = $this->getAvatarFileMetadata($path);

        return [
            'name' => basename($path),
            'size' => $metadata['size'],
            'type' => $metadata['type'],
            'url' => MarketplaceMediaStorage::previewUrl($path) ?? MarketplaceMediaStorage::url($path) ?? '',
        ];
    }

    /**
     * @return array{size: int, type: string}
     */
    private function getAvatarFileMetadata(string $path): array
    {
        $fallbackDisk = MarketplaceMediaStorage::fallbackDisk();
        $disks = array_values(array_unique(array_filter([
            MarketplaceMediaStorage::disk(),
            $fallbackDisk,
            's3',
        ])));

        foreach ($disks as $disk) {
            try {
                $storage = Storage::disk($disk);

                if (! $storage->exists($path)) {
                    continue;
                }

                return [
                    'size' => (int) $storage->size($path),
                    'type' => (string) ($storage->mimeType($path) ?: 'image/*'),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return [
            'size' => 0,
            'type' => 'image/*',
        ];
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->label('Имя');
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('Email');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Новый пароль');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label('Подтверждение пароля');
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->label('Текущий пароль');
    }
}
