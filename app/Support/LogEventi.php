<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LogEvento;
use Illuminate\Database\Eloquent\Model;

/**
 * Helper per l'audit trail (§11). Scrive una riga di log_eventi con riferimento polimorfico
 * al soggetto (ordine, fase, materiale, ...).
 */
final class LogEventi
{
    /**
     * @param array<string,mixed>|null $dati
     */
    public static function registra(
        string $tipoEvento,
        ?Model $soggetto = null,
        ?int $userId = null,
        ?array $dati = null,
        ?string $descrizione = null,
    ): LogEvento {
        return LogEvento::create([
            'user_id' => $userId,
            'tipo_evento' => $tipoEvento,
            'soggetto_type' => $soggetto ? $soggetto::class : null,
            'soggetto_id' => $soggetto?->getKey(),
            'descrizione' => $descrizione,
            'dati' => $dati,
            'created_at' => now(),
        ]);
    }
}
