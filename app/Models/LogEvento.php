<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit trail (§11): chi/quando su avvii, modifiche quantita', lotti, chiusure, split.
 * Append-only: nessun updated_at.
 */
class LogEvento extends Model
{
    protected $table = 'log_eventi';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'tipo_evento',
        'soggetto_type',
        'soggetto_id',
        'descrizione',
        'dati',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'dati' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function soggetto(): MorphTo
    {
        return $this->morphTo();
    }
}
