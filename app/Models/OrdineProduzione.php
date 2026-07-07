<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrigineOrdine;
use App\Enums\StatoOrdine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdineProduzione extends Model
{
    protected $table = 'ordini_produzione';

    protected $fillable = [
        'numero',
        'articolo_finito_codice',
        'descrizione_articolo',
        'quantita',
        'udm',
        'data',
        'stato',
        'origine',
        'creato_da_id',
        'note',
        'esploso_at',
        'esportato_at',
    ];

    protected function casts(): array
    {
        return [
            'quantita' => 'decimal:6',
            'data' => 'date',
            'stato' => StatoOrdine::class,
            'origine' => OrigineOrdine::class,
            'esploso_at' => 'datetime',
            'esportato_at' => 'datetime',
        ];
    }

    public function creatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creato_da_id');
    }

    public function distintaRighe(): HasMany
    {
        return $this->hasMany(DistintaRiga::class, 'ordine_id');
    }

    public function fasi(): HasMany
    {
        return $this->hasMany(FaseOrdine::class, 'ordine_id');
    }
}
