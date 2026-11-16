<?php

namespace App\Models\Concerns;

use App\Services\EncryptedSearchIndex;

trait IndexesEncryptedSearch
{
    private bool $refreshEncryptedIndex = false;

    public static function bootIndexesEncryptedSearch(): void
    {
        static::saving(function ($model): void {
            $model->refreshEncryptedIndex = $model->exists ? $model->isDirty([...$model->encryptedSearchFields, 'alert_id'])
                : collect($model->encryptedSearchFields)->contains(fn ($field) => ($model->getAttributes()[$field] ?? null) !== null);
        });
        static::saved(function ($model): void {
            if ($model->refreshEncryptedIndex) {
                app(EncryptedSearchIndex::class)->replace($model->encryptedSearchEntity, $model->id,
                    $model->encryptedSearchEntity === 'alert' ? $model->id : $model->alert_id,
                    array_map(fn ($field) => $model->{$field}, $model->encryptedSearchFields));
                $model->refreshEncryptedIndex = false;
            }
        });
    }

    public function save(array $options = [])
    {
        // Payload and search entries commit together. Ingestion uses a single
        // transaction for an entire batch and does not instantiate saved events.
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }
}
