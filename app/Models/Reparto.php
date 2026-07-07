<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reparto extends Model
{
    protected $table = 'reparti';

    protected $fillable = ['codice', 'descrizione', 'attivo'];

    protected function casts(): array
    {
        return ['attivo' => 'boolean'];
    }

    /** Operatori abilitati a questo reparto (§7). */
    public function operatori(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'operatore_reparto')->withTimestamps();
    }

    public function stepTipoFase(): HasMany
    {
        return $this->hasMany(TipoFaseStep::class);
    }
}
