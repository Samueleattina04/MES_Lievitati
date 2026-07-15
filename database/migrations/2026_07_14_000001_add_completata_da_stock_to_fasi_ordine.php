<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change #1 (§5.3): una fase-nodo puo' essere soddisfatta "da stock" indicando un lotto di
 * semilavorato gia' esistente. In tal caso la fase e' chiusa automaticamente SENZA consumare i
 * propri componenti. Questo flag distingue le fasi prodotte in quest'ordine da quelle prelevate
 * da stock (per audit, export e genealogia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fasi_ordine', function (Blueprint $table) {
            $table->boolean('completata_da_stock')->default(false)->after('split_completato');
        });
    }

    public function down(): void
    {
        Schema::table('fasi_ordine', function (Blueprint $table) {
            $table->dropColumn('completata_da_stock');
        });
    }
};
