<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use Concerns\IndexesEncryptedSearch;

    protected string $encryptedSearchEntity = 'notification';

    protected array $encryptedSearchFields = ['message'];

    public $timestamps = false;

    // Bulk delivery metadata can contain alerts outside a later viewer's agent scope.
    protected $hidden = ['delivery_details'];

    protected $fillable = [
        'alert_id',
        'user_id',
        'chat_id',
        'message',
        'response_status',
        'sent_at',
        'automatic_key',
        'delivery_state',
        'manual_operation_id',
        'delivery_details',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'message' => EncryptedString::class,
        'delivery_details' => EncryptedJson::class,
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSuccess(): bool
    {
        return $this->response_status === 200;
    }

    public function visibleMessage(User $viewer): string
    {
        $group = $this->delivery_details['bulk_group'] ?? null;
        if ($group && ($viewer->isAdmin() || Alert::visibleTo($viewer)->whereIn('id', $group['alert_ids'])->count() === count($group['alert_ids']))) {
            return $group['message'];
        }

        return $this->message;
    }
}
