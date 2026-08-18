<?php
/* ============================================================================
 * test-planner.php  —  scenario's en boekhoudcontrole voor accuplan.php
 * ----------------------------------------------------------------------------
 * Draaien:  php test-planner.php
 * Geeft exitcode 1 als er iets faalt, zodat je hem in een cronjob kunt hangen.
 * ========================================================================== */
include('accuplan.php');

$tz    = new DateTimeZone('Europe/Amsterdam');
$fout  = 0;
$goed  = 0;

function meld(bool $ok, string $wat, string $detail = ''): void {
    global $fout, $goed;
    if ($ok) { $goed++; printf("  ok    %s%s\n", $wat, $detail ? "  ($detail)" : ''); }
    else     { $fout++; printf("  FOUT  %s%s\n", $wat, $detail ? "  ($detail)" : ''); }
}

function basisCfg(array $extra = []): array {
    return $extra + [
        'accucapaciteit'=>32, 'socMinProc'=>10, 'socMaxProc'=>100, 'laadvermogen'=>5,
        'rendementLaden'=>0.95, 'rendementOntladen'=>0.95, 'cycluskosten'=>0.03,
        'eindwaardefactor'=>0.95, 'surpluswaardefactor'=>1.0, 'naHorizonUren'=>12,
        'reserveZonfactor'=>0.7,
        'opcentInkoop'=>0.13085, 'opcentVerkoop'=>0.02,
        'maxIteraties'=>400, 'socNuKwh'=>0.90*32, 'langzaamladenKw'=>1.28,
    ];
}

/* Bouwt een tijdas vanaf $start met $n kwartieren; prijs en zon per callback. */
function bouwScenario(string $start, int $n, callable $prijs, callable $zon,
                      float $verbruikPerDag, DateTimeZone $tz): array {
    $profiel = [0.6,0.6,0.6,0.6,0.6,0.6, 0.9,1.3,1.3,1.0,0.9,0.9,
                1.0,0.9,0.9,0.9,1.1,1.5, 1.7,1.5,1.3,1.2,1.0,0.8];
    $som = array_sum($profiel);
    $dt = new DateTime($start, $tz);
    $slots = $pv = $vb = [];
    for ($i = 0; $i < $n; $i++) {
        $d = (clone $dt)->modify('+' . ($i*15) . ' minutes');
        $u = (int)$d->format('G');
        $slots[] = ['dt'=>$d, 'key'=>$d->format('Y-m-d H:i'), 'label'=>$d->format('D H:i'),
                    'uur'=>$u, 'datum'=>$d->format('Y-m-d'), 'prijs'=>$prijs($d)];
        $pv[] = $zon($d);
        $vb[] = $verbruikPerDag * ($profiel[$u] / $som) / 4;
    }
    return [$slots, $pv, $vb];
}

/* Klokvormige zoncurve, geschaald naar $dagtotaal kWh per hele dag. */
function zonCurve(float $dagtotaal): callable {
    return function (DateTime $d) use ($dagtotaal) {
        $h = (float)$d->format('G') + (float)$d->format('i')/60;
        if ($h < 5.5 || $h > 21.5) { return 0.0; }
        $x = ($h - 13.5) / 4.2;
        // 4.2*sqrt(2pi) = 10.53 uur-equivalenten -> *4 kwartieren
        return $dagtotaal * exp(-0.5*$x*$x) / (10.53 * 4);
    };
}

/* --------------------------------------------------------------------------
 * Boekhoudcontrole: klopt de SOC-lijn met de geboekte stromen, en blijft
 * alles binnen de grenzen?
 * ------------------------------------------------------------------------ */
function controleerPlan(array $plan, array $cfg, string $naam): void {
    $n    = $plan['n'];
    $cap  = $cfg['accucapaciteit'];
    $effC = $cfg['rendementLaden'];
    $effD = $cfg['rendementOntladen'];
    $pmax = $cfg['laadvermogen'] * 0.25;
    $socMin = $cap * $cfg['socMinProc'] / 100;
    $socMax = $cap * $cfg['socMaxProc'] / 100;

    $maxAfw = 0.0; $buiten = 0; $overVermogen = 0; $beide = 0;
    for ($i = 0; $i < $n; $i++) {
        $in  = ($plan['laadNet'][$i] + $plan['laadZon'][$i]) * $effC;
        $uit = ($plan['ontVerbruik'][$i] + $plan['ontNet'][$i]) / $effD;
        $maxAfw = max($maxAfw, abs(($plan['soc'][$i] + $in - $uit) - $plan['soc'][$i+1]));
        if ($plan['soc'][$i] < $socMin - 1e-6 || $plan['soc'][$i] > $socMax + 1e-6) { $buiten++; }
        if ($plan['laadNet'][$i] + $plan['laadZon'][$i] > $pmax + 1e-6) { $overVermogen++; }
        if ($plan['ontVerbruik'][$i] + $plan['ontNet'][$i] > $pmax + 1e-6) { $overVermogen++; }
        if (($plan['laadNet'][$i] + $plan['laadZon'][$i] > 1e-6)
         && ($plan['ontVerbruik'][$i] + $plan['ontNet'][$i] > 1e-6)) { $beide++; }
    }
    if ($plan['soc'][$n] < $socMin - 1e-6 || $plan['soc'][$n] > $socMax + 1e-6) { $buiten++; }

    meld($maxAfw < 1e-6,      "$naam: SOC-lijn sluit op de geboekte stromen", sprintf('afwijking %.2e kWh', $maxAfw));
    meld($buiten === 0,       "$naam: SOC blijft binnen de grenzen");
    meld($overVermogen === 0, "$naam: vermogensgrens gerespecteerd");
    meld($beide === 0,        "$naam: nooit laden en ontladen in hetzelfde kwartier");
}

/* Wat het plan kost, inclusief een waardering van wat er in de accu overblijft. */
function saldo(array $plan, float $waardePerKwh): float {
    $s = 0.0;
    for ($i = 0; $i < $plan['n']; $i++) {
        $s += $plan['import'][$i] * $plan['koop'][$i] - $plan['export'][$i] * $plan['verk'][$i];
    }
    return $s - ($plan['soc'][$plan['n']] - $plan['soc'][0]) * $waardePerKwh;
}


echo "\n=== 1. VOLLE ACCU, ZONNIGE DAG MORGEN ===\n";
echo "De klacht: de accu blijft vol staan, het huis koopt in, en de zon van\n";
echo "morgen gaat tegen 2 cent het net op.\n\n";
{
    $prijs = function (DateTime $d) {
        $u = (int)$d->format('G');
        if ($u < 6)  return 0.06;
        if ($u < 10) return 0.09;
        if ($u < 17) return 0.00;
        if ($u < 22) return 0.13;
        return 0.08;
    };
    [$slots, $pv, $vb] = bouwScenario('2026-06-20 18:00', 120, $prijs, zonCurve(55.0), 25.0, $tz);
    $cfg  = basisCfg();
    $plan = planOptimaal($slots, $pv, $vb, $cfg);
    [$c, $doel, $reden] = commandoUitPlan($plan, $cfg);

    printf("  reserve na de horizon : %.1f kWh  (%.0f%% van de accu)\n", $plan['reserveKwh'], $plan['reserveKwh']/32*100);
    printf("  eindwaarde reserve    : EUR %.4f/kWh\n", $plan['eindReserve']);
    printf("  eindwaarde daarboven  : EUR %.4f/kWh\n", $plan['eindSurplus']);
    printf("  besluit nu            : %s,%d  (%s)\n", $c, $doel, $reden);
    printf("  SOC                   : %.0f%% -> %.0f%%\n", $plan['socProc'][0], $plan['socProc'][120]);
    printf("  accu -> net           : %5.1f kWh\n", array_sum($plan['ontNet']));
    printf("  accu -> huis          : %5.1f kWh\n", array_sum($plan['ontVerbruik']));
    printf("  net  -> accu          : %5.1f kWh\n", array_sum($plan['laadNet']));
    printf("  import                : %5.1f kWh\n", array_sum($plan['import']));
    echo "\n";

    meld(array_sum($plan['ontNet']) > 5.0,  'de accu verkoopt op de avondpiek', sprintf('%.1f kWh', array_sum($plan['ontNet'])));
    meld(array_sum($plan['import']) < 1.0,  'het huis koopt niets in terwijl de accu vol staat');
    meld(array_sum($plan['laadNet']) < 0.1, 'er wordt niet uit het net geladen om vast te houden');
    meld($plan['socProc'][120] < 60,        'er is ruimte gemaakt voor de zon van morgen', sprintf('eindigt op %.0f%%', $plan['socProc'][120]));
    meld($plan['reserveKwh'] > 1.0,         'er blijft wel een nachtreserve staan', sprintf('%.1f kWh', $plan['reserveKwh']));
    controleerPlan($plan, $cfg, 'scenario 1');
}

echo "\n=== 2. DEZELFDE DAG, MAAR DONKER EN DUUR (winter) ===\n";
echo "Hier hoort de accu juist wel vast te houden.\n\n";
{
    $prijs = function (DateTime $d) {
        $u = (int)$d->format('G');
        if ($u < 6)  return 0.09;
        if ($u < 10) return 0.22;
        if ($u < 17) return 0.16;
        if ($u < 22) return 0.28;
        return 0.14;
    };
    [$slots, $pv, $vb] = bouwScenario('2026-01-15 18:00', 120, $prijs, zonCurve(3.0), 40.0, $tz);
    $cfg  = basisCfg(['socNuKwh' => 0.90*32]);
    $plan = planOptimaal($slots, $pv, $vb, $cfg);
    [$c, $doel, $reden] = commandoUitPlan($plan, $cfg);

    printf("  reserve na de horizon : %.1f kWh  (%.0f%% van de accu)\n", $plan['reserveKwh'], $plan['reserveKwh']/32*100);
    printf("  besluit nu            : %s,%d  (%s)\n", $c, $doel, $reden);
    printf("  SOC                   : %.0f%% -> %.0f%%\n", $plan['socProc'][0], $plan['socProc'][120]);
    printf("  net  -> accu          : %5.1f kWh (goedkope nacht inkopen)\n", array_sum($plan['laadNet']));
    printf("  accu -> huis          : %5.1f kWh\n", array_sum($plan['ontVerbruik']));
    echo "\n";

    meld($plan['reserveKwh'] > 8.0,   'de reserve groeit mee met de donkere dag', sprintf('%.1f kWh', $plan['reserveKwh']));
    meld($plan['socProc'][120] > 25,  'de accu wordt niet leeggetrokken', sprintf('eindigt op %.0f%%', $plan['socProc'][120]));
    meld(array_sum($plan['ontVerbruik']) > 5.0, 'de accu voedt het huis op de dure uren');
    controleerPlan($plan, $cfg, 'scenario 2');
}

echo "\n=== 3. LEGE ACCU, GOEDKOPE NACHT, DURE OCHTEND ===\n";
{
    $prijs = function (DateTime $d) {
        $u = (int)$d->format('G');
        if ($u >= 1 && $u < 5) return -0.02;
        if ($u >= 7 && $u < 10) return 0.30;
        return 0.10;
    };
    [$slots, $pv, $vb] = bouwScenario('2026-02-01 22:00', 60, $prijs, zonCurve(2.0), 35.0, $tz);
    $cfg  = basisCfg(['socNuKwh' => 0.15*32]);
    $plan = planOptimaal($slots, $pv, $vb, $cfg);
    [$c, $doel, $reden] = commandoUitPlan($plan, $cfg);

    printf("  besluit nu   : %s,%d  (%s)\n", $c, $doel, $reden);
    printf("  net  -> accu : %5.1f kWh\n", array_sum($plan['laadNet']));
    printf("  accu -> huis : %5.1f kWh\n", array_sum($plan['ontVerbruik']));
    printf("  SOC          : %.0f%% -> max %.0f%%\n", $plan['socProc'][0], max($plan['socProc']));
    echo "\n";

    meld(array_sum($plan['laadNet']) > 5.0, 'laadt bij op de negatieve nachtprijs');
    meld(array_sum($plan['ontVerbruik']) > 3.0, 'gebruikt dat in de dure ochtend');
    controleerPlan($plan, $cfg, 'scenario 3');
}

echo "\n=== 4. NEGATIEVE TERUGLEVERPRIJS MET ZONOVERSCHOT ===\n";
{
    $prijs = fn(DateTime $d) => ((int)$d->format('G') >= 11 && (int)$d->format('G') < 16) ? -0.09 : 0.07;
    [$slots, $pv, $vb] = bouwScenario('2026-05-10 09:00', 56, $prijs, zonCurve(45.0), 20.0, $tz);
    $cfg  = basisCfg(['socNuKwh' => 0.40*32]);
    $plan = planOptimaal($slots, $pv, $vb, $cfg);
    [$c, $doel, $reden] = commandoUitPlan($plan, $cfg);

    $exportBijNegatief = 0.0;
    for ($i = 0; $i < $plan['n']; $i++) { if ($plan['verk'][$i] < 0) { $exportBijNegatief += $plan['export'][$i]; } }

    printf("  besluit nu            : %s,%d  (%s)\n", $c, $doel, $reden);
    printf("  export bij verk < 0   : %.3f kWh\n", $exportBijNegatief);
    printf("  afgeknepen (curtail)  : %.1f kWh\n", array_sum($plan['curtail']));
    printf("  zon -> accu           : %.1f kWh\n", array_sum($plan['laadZon']));
    echo "\n";

    meld($exportBijNegatief < 1e-6, 'er wordt niets geexporteerd tegen een negatieve prijs');
    meld(array_sum($plan['laadZon']) > 3.0, 'de zon gaat de accu in in plaats van het net op');
    controleerPlan($plan, $cfg, 'scenario 4');
}

echo "\n=== 5. LANGZAAMLADEN (regressie) ===\n";
echo "Dure ochtend, spotgoedkope middag: ochtendzon verkopen, middagzon opslaan.\n\n";
{
    $prijzen = array_merge(array_fill(0,12,0.30), array_fill(0,16,0.01), array_fill(0,12,0.28));
    $dt = new DateTime('2026-06-21 08:00', $tz);
    $slots = $pv = $vb = [];
    foreach ($prijzen as $i => $pr) {
        $d = (clone $dt)->modify('+' . ($i*15) . ' minutes');
        $slots[] = ['dt'=>$d,'key'=>$d->format('Y-m-d H:i'),'label'=>$d->format('H:i'),
                    'uur'=>(int)$d->format('G'),'datum'=>$d->format('Y-m-d'),'prijs'=>$pr];
        $pv[] = 1.10; $vb[] = 0.25;
    }
    $cfg  = basisCfg(['socNuKwh' => 19.2]);
    $plan = planOptimaal($slots, $pv, $vb, $cfg);

    $langzaam = 0; $regels = [];
    foreach ($slots as $i => $s) {
        $sub = $plan;
        foreach (['pvRest','laadZon','laadNet','ontNet','ontVerbruik','curtail','loadRest','koop','verk'] as $k) {
            $sub[$k] = array_slice($plan[$k], $i);
        }
        $sub['soc'] = array_slice($plan['soc'], $i);
        [$cc,,] = commandoUitPlan($sub, $cfg);
        if ($cc === 'LANGZAAMLADEN') { $langzaam++; }
        if ($i % 8 === 0) { $regels[] = sprintf("  %s  verk %.3f  zon->accu %.2f  SOC %3.0f%%  %s",
            $s['label'], $plan['verk'][$i], $plan['laadZon'][$i], $plan['socProc'][$i], $cc); }
    }
    echo implode("\n", $regels) . "\n\n";
    meld($langzaam > 0, 'LANGZAAMLADEN vuurt op de dure ochtend', "$langzaam kwartieren");
    $ochtendOpslag = array_sum(array_slice($plan['laadZon'], 0, 12));
    $middagOpslag  = array_sum(array_slice($plan['laadZon'], 12, 16));
    meld($middagOpslag > $ochtendOpslag, 'de goedkope middagzon gaat de accu in, niet de dure ochtendzon',
         sprintf('ochtend %.1f kWh, middag %.1f kWh', $ochtendOpslag, $middagOpslag));
    controleerPlan($plan, $cfg, 'scenario 5');
}

echo "\n=== 6. GEEN SPOOKWINST OP EEN VLAKKE PRIJS ===\n";
echo "Zonder prijsverschil valt er niets te verdienen; de planner hoort stil te staan.\n\n";
{
    [$slots, $pv, $vb] = bouwScenario('2026-03-03 12:00', 96, fn($d) => 0.10, zonCurve(0.0), 24.0, $tz);
    $cfg  = basisCfg(['socNuKwh' => 0.50*32]);
    $plan = planOptimaal($slots, $pv, $vb, $cfg);
    printf("  geboekte winst : EUR %.4f\n", $plan['winst']);
    printf("  net -> accu    : %.3f kWh\n", array_sum($plan['laadNet']));
    printf("  accu -> net    : %.3f kWh\n\n", array_sum($plan['ontNet']));
    meld($plan['winst'] < 0.01, 'geen winst geboekt op een vlakke prijscurve', sprintf('EUR %.4f', $plan['winst']));
    meld(array_sum($plan['ontNet']) < 0.01, 'er wordt niet zinloos verhandeld');
    controleerPlan($plan, $cfg, 'scenario 6');
}

printf("\n----------------------------------------\n%d geslaagd, %d gefaald\n\n", $goed, $fout);
exit($fout > 0 ? 1 : 0);
