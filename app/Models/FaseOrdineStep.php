<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatoFase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Step di lavorazione di una fase su uno specifico reparto (§3). La fase e' chiusa quando
 * tutti i suoi step sono chiusi (criterio 4).
 */
class FaseOrdineStep extends Model
{
    protected $table = 'fase_ordine_step';

    protected $fillable = [
        'fase_ordine_id',
        'reparto_id',
        'ordine',
        'descrizione',
        'consuma_materiali',
        'stato',
        'operatore_id',
        'timestamp_inizio',
        'timestamp_fine',
    ];

    protected function casts(): array
    {
        return [
            'ordine' => 'integer',
            'consuma_materiali' => 'boolean',
            'stato' => StatoFase::class,
            'timestamp_inizio' => 'datetime',
            'timestamp_fine' => 'datetime',
        ];
    }

    public function fase(): BelongsTo
    {
        return $this->belongsTo(FaseOrdine::class, 'fase_ordine_id');
    }

    public function reparto(): BelongsTo
    {
        return $this->belongsTo(Reparto::class);
    }

    public function operatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operatore_id');
    }
}
