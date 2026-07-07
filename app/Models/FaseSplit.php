<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ripartizione (split) di un nodo condiviso prodotto una sola volta (§5-bis):
 * sorgente = fase del nodo condiviso; destinazione = fase padre che ne consuma una quota.
 */
class FaseSplit extends Model
{
    protected $table = 'fase_splits';

    protected $fillable = [
        'fase_sorgente_id',
        'fase_destinazione_id',
        'quantita_assegnata',
        'operatore_id',
        'client_uuid',
    ];

    protected function casts(): array
    {
        return ['quantita_assegnata' => 'decimal:6'];
    }

    public function faseSorgente(): BelongsTo
    {
        return $this->belongsTo(FaseOrdine::class, 'fase_sorgente_id');
    }

    public function faseDestinazione(): BelongsTo
    {
        return $this->belongsTo(FaseOrdine::class, 'fase_destinazione_id');
    }

    public function operatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operatore_id');
    }
}
