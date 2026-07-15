<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuoloUtente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'ruolo',
        'pin_hash',
        'attivo',
    ];

    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ruolo' => RuoloUtente::class,
            'attivo' => 'boolean',
        ];
    }

    /** Reparti a cui l'operatore e' abilitato (§7). */
    public function reparti(): BelongsToMany
    {
        return $this->belongsToMany(Reparto::class, 'operatore_reparto')->withTimestamps();
    }

    public function haRuolo(RuoloUtente $ruolo): bool
    {
        return $this->ruolo === $ruolo;
    }

    public function eOperatore(): bool
    {
        return $this->ruolo === RuoloUtente::Operatore;
    }

    public function eAdmin(): bool
    {
        return $this->ruolo === RuoloUtente::Admin;
    }

    /** Puo' eseguire l'avanzamento di produzione (operatore o backoffice, change #1). */
    public function puoAvanzareProduzione(): bool
    {
        return (bool) $this->ruolo?->puoAvanzareProduzione();
    }

    /** L'avanzamento e' vincolato ai reparti assegnati (solo operatore, change #1). */
    public function vincolatoAiReparti(): bool
    {
        return (bool) $this->ruolo?->vincolatoAiReparti();
    }
}
