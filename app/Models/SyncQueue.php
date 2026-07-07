<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coda azioni offline (§8). client_uuid garantisce l'idempotenza: /api/sync ignora
 * silenziosamente un uuid gia' processato (retry di rete).
 */
class SyncQueue extends Model
{
    protected $table = 'sync_queue';

    protected $fillable = [
        'client_uuid',
        'tipo_azione',
        'payload',
        'processato',
        'processato_at',
        'errore',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processato' => 'boolean',
            'processato_at' => 'datetime',
        ];
    }
}
