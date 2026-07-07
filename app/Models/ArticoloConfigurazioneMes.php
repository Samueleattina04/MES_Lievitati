<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attributi MES di un articolo che NON esistono nel gestionale (reparto, tipo fase, override
 * flag lotto). Chiavata sul codice articolo per sopravvivere alla rigenerazione della cache (§5).
 */
class ArticoloConfigurazioneMes extends Model
{
    protected $table = 'articolo_configurazione_mes';

    protected $fillable = [
        'articolo_codice',
        'reparto_default_id',
        'tipo_fase_id',
        'flag_lotto_override',
        'note',
    ];

    protected function casts(): array
    {
        return ['flag_lotto_override' => 'boolean'];
    }

    public function repartoDefault(): BelongsTo
    {
        return $this->belongsTo(Reparto::class, 'reparto_default_id');
    }

    public function tipoFase(): BelongsTo
    {
        return $this->belongsTo(TipoFase::class);
    }

    public function articolo(): BelongsTo
    {
        return $this->belongsTo(Articolo::class, 'articolo_codice', 'codice');
    }
}
