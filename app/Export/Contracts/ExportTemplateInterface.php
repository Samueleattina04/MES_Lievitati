<?php

declare(strict_types=1);

namespace App\Export\Contracts;

use App\Models\OrdineProduzione;

/**
 * Motore di export a template (§10). Ogni tracciato per un gestionale/formato target implementa
 * questa interfaccia; il core non cambia quando arriveranno i tracciati reali del committente.
 */
interface ExportTemplateInterface
{
    /** Chiave stabile del template (usata in config/UI). */
    public function chiave(): string;

    /** Etichetta leggibile. */
    public function etichetta(): string;

    public function nomeFile(OrdineProduzione $ordine): string;

    public function mime(): string;

    /** Contenuto del file per l'ordine dato. */
    public function contenuto(OrdineProduzione $ordine): string;
}
