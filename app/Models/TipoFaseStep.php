<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoFaseStep extends Model
{
    protected $table = 'tipo_fase_step';

    protected $fillable = ['tipo_fase_id', 'reparto_id', 'ordine', 'descrizione', 'consuma_materiali'];

    protected function casts(): array
    {
        return [
            'ordine' => 'integer',
            'consuma_materiali' => 'boolean',
        ];
    }

    public function tipoFase(): BelongsTo
    {
        return $this->belongsTo(TipoFase::class);
    }

    public function reparto(): BelongsTo
    {
        return $this->belongsTo(Reparto::class);
    }
}
