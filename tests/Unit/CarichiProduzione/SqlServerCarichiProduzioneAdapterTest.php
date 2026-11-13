<?php

declare(strict_types=1);

namespace Tests\Unit\CarichiProduzione;

use App\CarichiProduzione\SqlServerCarichiProduzioneAdapter;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la SQL generata dall'adapter reale (il punto piu' delicato) senza un vero DB: una
 * connessione mockata cattura query e binding. Controlla join, macrofamiglia "a 2 salti" e filtri.
 */
final class SqlServerCarichiProduzioneAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array{sql:string, bind:array<int,mixed>} */
    private function eseguiCattura(array $config, string $operatore, array $codOpMag): array
    {
        $catturato = ['sql' => '', 'bind' => []];
        $conn = Mockery::mock(ConnectionInterface::class);
        $conn->shouldReceive('select')->once()->andReturnUsing(function (string $sql, array $bind = []) use (&$catturato) {
            $catturato = ['sql' => $sql, 'bind' => $bind];

            return [];
        });

        $adapter = new SqlServerCarichiProduzioneAdapter($conn, $config);
        self::assertSame([], $adapter->carichi($operatore, $codOpMag));

        return $catturato;
    }

    /** @return array<string,mixed> Config allineata ai default di config/mes.php. */
    private function config(): array
    {
        return [
            'doc' => ['tabella' => 'DocElencoGen', 'col_operatore' => 'FirmaCreazOperatore', 'join' => ['DBGruppo', 'IdDocumento']],
            'movim' => [
                'tabella' => 'MovimMagazzino', 'col_codice' => 'CodArt', 'col_cod_op_mag' => 'CodOpMag',
                'col_data' => 'DataRegistrazione', 'col_causale' => 'CausaleMag', 'col_quantita' => 'QuantitaUmMag',
                'col_um' => 'UmMag', 'col_magazzino' => 'CodMag', 'join_lotto' => ['DBGruppo', 'IdDocumento', 'IdRigaDoc', 'IdRigaMag'],
            ],
            'movim_lotto' => ['tabella' => 'MovimMagLotto', 'col_lotto' => 'RifLottoAlfanum', 'col_lotto_pf' => 'RifLottoPFAlfanum'],
            'articoli' => ['tabella' => 'ArtAnagrafica', 'col_codice' => 'CodArt', 'col_descrizione' => 'DesArt'],
            'famiglie' => [
                'famiglia' => ['tabella' => 'Famiglia', 'art_key' => 'CodFamiglia', 'key' => 'CodFamiglia', 'descr' => 'Des', 'art_col' => 'CodFamiglia'],
                'macrofamiglia' => ['tabella' => 'MacrofamiglieArt', 'via' => 'famiglia', 'via_key' => 'CodMacrofamiglia', 'key' => 'CodMacrofamiglia', 'descr' => 'Des'],
                'microfamiglia' => ['tabella' => 'MicrofamArt', 'art_key' => 'CodMicrofamigliaArt', 'key' => 'CodMicrofamigliaArt', 'descr' => 'Des', 'art_col' => 'CodMicrofamigliaArt'],
            ],
        ];
    }

    public function test_join_filtri_e_macrofamiglia_a_due_salti(): void
    {
        $r = $this->eseguiCattura($this->config(), 'N.CELESTINO', ['50', '850']);
        $sql = $r['sql'];

        self::assertStringContainsString('FROM [MovimMagazzino] MM', $sql);
        self::assertStringContainsString('LEFT JOIN [MovimMagLotto] ML WITH (NOLOCK) ON ML.[DBGruppo] = MM.[DBGruppo]', $sql);
        self::assertStringContainsString('LEFT JOIN [DocElencoGen] D WITH (NOLOCK) ON D.[DBGruppo] = MM.[DBGruppo]', $sql);
        self::assertStringContainsString('LEFT JOIN [ArtAnagrafica] A WITH (NOLOCK) ON A.DBGruppo = MM.DBGruppo AND A.[CodArt] = MM.[CodArt]', $sql);

        // Famiglia: lookup diretta su ArtAnagrafica.
        self::assertStringContainsString('LEFT JOIN [Famiglia] F_famiglia WITH (NOLOCK) ON F_famiglia.DBGruppo = A.DBGruppo AND F_famiglia.[CodFamiglia] = A.[CodFamiglia]', $sql);
        // Macrofamiglia: 2 salti, agganciata alla Famiglia (non ad ArtAnagrafica).
        self::assertStringContainsString('LEFT JOIN [MacrofamiglieArt] F_macrofamiglia WITH (NOLOCK) ON F_macrofamiglia.DBGruppo = F_famiglia.DBGruppo AND F_macrofamiglia.[CodMacrofamiglia] = F_famiglia.[CodMacrofamiglia]', $sql);
        // Microfamiglia: lookup diretta su ArtAnagrafica.
        self::assertStringContainsString('LEFT JOIN [MicrofamArt] F_microfamiglia WITH (NOLOCK) ON F_microfamiglia.DBGruppo = A.DBGruppo AND F_microfamiglia.[CodMicrofamigliaArt] = A.[CodMicrofamigliaArt]', $sql);

        // Descrizioni famiglia in SELECT.
        self::assertStringContainsString('F_famiglia.[Des] AS Famiglia', $sql);
        self::assertStringContainsString('F_macrofamiglia.[Des] AS Macrofamiglia', $sql);
        self::assertStringContainsString('F_microfamiglia.[Des] AS Microfamiglia', $sql);

        // Filtri bound: CodOpMag IN (?,?) + operatore; i codici numerici diventano interi.
        self::assertStringContainsString('MM.[CodOpMag] IN (?,?)', $sql);
        self::assertStringContainsString('D.[FirmaCreazOperatore] = ?', $sql);
        self::assertSame([50, 850, 'N.CELESTINO'], $r['bind']);
    }

    public function test_senza_testata_documento_niente_filtro_operatore(): void
    {
        $config = $this->config();
        $config['doc']['tabella'] = ''; // testata non configurata

        $r = $this->eseguiCattura($config, 'N.CELESTINO', ['50']);

        self::assertStringNotContainsString('[DocElencoGen]', $r['sql']);
        self::assertStringContainsString("'' AS Operatore", $r['sql']);
        self::assertStringNotContainsString('FirmaCreazOperatore', $r['sql']);
        // Resta solo il filtro CodOpMag.
        self::assertSame([50], $r['bind']);
    }

    public function test_fallback_a_codice_quando_manca_la_lookup(): void
    {
        $config = $this->config();
        // Famiglia senza tabella di lookup ma con art_col -> mostra il codice da ArtAnagrafica.
        $config['famiglie']['famiglia'] = ['art_col' => 'CodFamiglia'];
        // Macrofamiglia dipende dalla famiglia (via): senza lookup famiglia non puo' agganciarsi -> vuota.
        $r = $this->eseguiCattura($config, '', []);

        self::assertStringContainsString('A.[CodFamiglia] AS Famiglia', $r['sql']);
        self::assertStringNotContainsString('[Famiglia] F_famiglia', $r['sql']);
        self::assertStringContainsString("'' AS Macrofamiglia", $r['sql']);
        self::assertSame([], $r['bind']);
    }
}
