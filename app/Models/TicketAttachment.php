<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TicketAttachment extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'file_path', 'original_name'];

    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            if (blank($attachment->file_path)) {
                return;
            }

            $disks = array_values(array_unique(array_filter([
                config('filament.default_filesystem_disk'),
                config('filesystems.default'),
                'public',
            ])));

            foreach ($disks as $disk) {
                try {
                    Storage::disk((string) $disk)->delete((string) $attachment->file_path);
                } catch (\Throwable) {
                    // File cleanup must not block deleting the database record.
                }
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
