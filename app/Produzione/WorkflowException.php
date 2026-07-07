<?php

declare(strict_types=1);

namespace App\Produzione;

use RuntimeException;

/**
 * Errore di dominio sull'avanzamento fasi (transizione non ammessa, precedenze non soddisfatte, ecc.).
 */
class WorkflowException extends RuntimeException
{
}
