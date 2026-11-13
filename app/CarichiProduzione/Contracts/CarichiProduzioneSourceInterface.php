<?php

declare(strict_types=1);

namespace App\CarichiProduzione\Contracts;

use App\CarichiProduzione\CaricoProduzione;

/**
 * Sorgente dei carichi di produzione da prima nota (§ Carichi). Disaccoppia il dominio dalla
 * sorgente reale (ESOLVER su SQL Server) o dai fixture (dev/test).
 */
interface CarichiProduzioneSourceInterface
{
    /**
     * Carichi di produzione filtrati per codici operazione magazzino e per operatore creatore
     * del documento (DocElencoGenerale.FirmaCreazOperatore).
     *
     * @param  string  $operatore  firma operatore creatore; '' = nessun filtro operatore
     * @param  list<string>  $codOpMag  codici operazione magazzino ammessi; [] = nessun filtro
     * @return list<CaricoProduzione>
     */
    public function carichi(string $operatore, array $codOpMag): array;
}
