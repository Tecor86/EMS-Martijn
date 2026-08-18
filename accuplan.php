<?php
/* ============================================================================
 * accuplan.php  —  de planner
 * ----------------------------------------------------------------------------
 * Alleen functies. Geen output, geen side effects.
 *
 * KERNIDEE
 * --------
 * Elk kwartier heeft, na aftrek van zon die rechtstreeks naar het verbruik gaat,
 * OF een zonoverschot OF een resterend verbruik. Nooit allebei. Daarmee wordt
 * het hele probleem een koppeling tussen bronnen en putten:
 *
 *   BRONNEN (energie de accu in)          PUTTEN (energie de accu uit)
 *   - zonoverschot   kost p_verkoop       - restverbruik  levert p_inkoop op
 *   - het net        kost p_inkoop        - export        levert p_verkoop op
 *   - wat er nu al in de accu zit         - wat er na de horizon overblijft
 *
 * De planner koppelt herhaaldelijk de best betalende put aan de goedkoopste
 * bron die ervoor ligt, zolang dat na rendementsverlies en slijtage geld
 * oplevert en de SOC-lijn ertussenin binnen de grenzen blijft.
 *
 * Dat ene mechanisme dekt alle vier de wensen af, zonder aparte regels:
 *   - zon opslaan voor eigen verbruik levert de inkoopprijs op (~0,32) tegen
 *     de gemiste verkoopprijs (~0,20), dus zon gaat vanzelf eerst naar
 *     eigen gebruik;
 *   - wat morgen nodig is wordt niet verkocht, want verbruiken is meer waard
 *     dan exporteren;
 *   - een tekort wordt gedekt vanaf het goedkoopste kwartier ervoor;
 *   - een echt overschot gaat naar het duurste kwartier.
 *
 * WAT ER BEWUST NIET IN ZIT
 * -------------------------
 * De prijs waarvoor je hebt gekocht wat er nu in de accu zit. Dat is een
 * verzonken kost en hoort niet in een beslissing over de toekomst. Wat er wel
 * in zit is de opportuniteitskost: wat die kWh waard is als je hem gewoon laat
 * zitten.
 * (In het eerdere verslag adviseerde ik een kostprijsadministratie; die is
 * nuttig om achteraf je rendement te meten, maar hoort niet in de sturing.)
 *
 * DE EINDWAARDE HEEFT TWEE TREDEN
 * -------------------------------
 * Niet alle kWh in de accu zijn evenveel waard. De eerste paar kWh houd je
 * achter voor de nacht na de horizon: die vervangen straks netstroom, dus ze
 * zijn de INKOOPPRIJS waard. Alles daarboven wordt na de horizon toch door de
 * zon vervangen; dat gaat het net op en is dus niet meer dan de VERKOOPPRIJS
 * waard.
 *
 * Dat onderscheid is niet kosmetisch. Tussen inkoop en verkoop zit je
 * belasting plus opslag (~EUR 0,13/kWh). Waardeer je de hele accu-inhoud tegen
 * de inkoopprijs, dan ligt de verkoopdrempel structureel EUR 0,13 te hoog en
 * verkoopt de accu nooit meer iets: hij blijft vol staan terwijl het huis
 * inkoopt en de zon van morgen tegen 2 cent het net op gaat. Precies dat deed
 * de vorige versie.
 *
 * Hoe groot de onderste trede is, volgt uit de raming voor het venster NA de
 * horizon: verwacht verbruik minus verwachte zon. Zonnige dag -> kleine
 * reserve -> de accu mag leeg voor de avondpiek. Donkere winterdag -> grote
 * reserve -> de accu houdt vast en koopt zo nodig goedkoop bij.
 * ========================================================================== */


/* ----------------------------------------------------------------------------
 * 1. TIJDAS
 * -------------------------------------------------------------------------- */
function bouwSlots(array $uurdata, DateTime $nu, DateTimeZone $tz): array
{
    $start = clone $nu;
    $start->setTime((int)$nu->format('G'), ((int)floor((int)$nu->format('i') / 15)) * 15, 0);

    $slots = [];
    foreach ($uurdata as $entry) {
        $dt = new DateTime($entry['start']);
        $dt->setTimezone($tz);
        if ($dt < $start) { continue; }
        $slots[] = [
            'dt'    => $dt,
            'key'   => $dt->format('Y-m-d H:i'),
            'label' => $dt->format('D H:i'),
            'uur'   => (int)$dt->format('G'),
            'datum' => $dt->format('Y-m-d'),
            'prijs' => (float)$entry['price'],
        ];
    }
    usort($slots, fn($a, $b) => $a['dt'] <=> $b['dt']);
    return $slots;
}


/* ----------------------------------------------------------------------------
 * 2. ZON PER KWARTIER
 * De forecast geeft per heel uur een waarde in W; over dat uur is dat evenveel
 * Wh. Lineair interpoleren tussen de uurpunten zodat de curve niet blokkerig
 * wordt, dan delen door 4.
 * -------------------------------------------------------------------------- */
function pvPerSlot(array $combined, array $slots, DateTimeZone $tz, float $kalibratie = 1.0): array
{
    $punten = [];
    foreach ($combined as $ts => $w) {
        $dt = new DateTime($ts, $tz);
        // Alleen hele uren; de zonop-/ondergangsregels (06:27:55) zijn ruis.
        if ((int)$dt->format('i') !== 0 || (int)$dt->format('s') !== 0) { continue; }
        $punten[$dt->getTimestamp()] = (float)$w;
    }
    ksort($punten);
    $tijden  = array_keys($punten);
    $laatste = empty($tijden) ? 0 : $tijden[count($tijden) - 1];

    $pv = [];
    foreach ($slots as $i => $s) {
        $t = $s['dt']->getTimestamp() + 450;   // midden van het kwartier
        $w = 0.0;

        if (!empty($tijden) && $t >= $tijden[0] && $t <= $laatste) {
            $vorige = null;
            foreach ($tijden as $tt) {
                if ($tt <= $t) { $vorige = $tt; continue; }
                if ($vorige !== null) {
                    $frac = ($t - $vorige) / max(1, ($tt - $vorige));
                    $w = $punten[$vorige] + $frac * ($punten[$tt] - $punten[$vorige]);
                }
                break;
            }
        }
        $pv[$i] = max(0.0, ($w * 0.25 * $kalibratie) / 1000.0);   // kWh dit kwartier
    }
    return $pv;
}


/* ----------------------------------------------------------------------------
 * 3. VERBRUIK PER KWARTIER
 * -------------------------------------------------------------------------- */
function verbruikPerSlot(array $slots, array $dagtotalen, array $profielHuis, array $profielAuto): array
{
    $somHuis = array_sum($profielHuis);
    $somAuto = array_sum($profielAuto);

    $verbruik = [];
    foreach ($slots as $i => $s) {
        $d = $s['datum'];
        $h = $s['uur'];
        $huisDag = $dagtotalen[$d]['huis'] ?? ($dagtotalen['_default']['huis'] ?? 25.0);
        $autoDag = $dagtotalen[$d]['auto'] ?? ($dagtotalen['_default']['auto'] ?? 0.0);

        $huis = $somHuis > 0 ? $huisDag * ($profielHuis[$h] / $somHuis) / 4 : $huisDag / 96;
        $auto = $somAuto > 0 ? $autoDag * ($profielAuto[$h] / $somAuto) / 4 : 0.0;

        $verbruik[$i] = $huis + $auto;
    }
    return $verbruik;
}


/* ----------------------------------------------------------------------------
 * 3b. HOEVEEL DE ACCU NA DE HORIZON NOG MOET DEKKEN
 * ----------------------------------------------------------------------------
 * De prijzen houden op bij het einde van de horizon, het huis niet. Wat er dan
 * nog in de accu zit dekt het verbruik van de eerstvolgende uren -- totdat de
 * zon het overneemt. Dat aantal kWh is de onderste trede van de eindwaarde.
 *
 * Drie manieren om eraan te komen, in volgorde van betrouwbaarheid:
 *
 *   1. De aanroeper rekent het uit met de echte zonvoorspelling, die verder
 *      vooruit kijkt dan de prijzen. Zie ramingNaHorizon() hieronder; dat is
 *      wat accusturen2.php doet.
 *   2. Dezelfde kloktijden van een etmaal eerder uit de horizon zelf. Werkt
 *      zodra de horizon langer is dan een dag.
 *   3. Het horizongemiddelde. Grove terugval voor een korte horizon; die geeft
 *      een ruime reserve, en dat is de veilige kant om op te missen.
 * -------------------------------------------------------------------------- */
function reserveNaHorizon(array $slots, array $pv, array $verbruik, array $cfg): float
{
    if (isset($cfg['naHorizonTekort'])) {
        return max(0.0, (float)$cfg['naHorizonTekort']);
    }

    $n = count($slots);
    $q = (int)round(($cfg['naHorizonUren'] ?? 12) * 4);
    if ($n === 0 || $q <= 0) { return 0.0; }

    // Startindex van het stuk horizon dat als proxy dient: bij voorkeur exact
    // een etmaal eerder, anders de staart van de horizon (rondlopend).
    $bron  = ($n >= 96 && $q <= 96) ? ($n - 96) : max(0, $n - $q);
    $lengte = max(1, $n - $bron);

    $zonfactor = (float)($cfg['reserveZonfactor'] ?? 1.0);
    $reeks = [];
    for ($k = 0; $k < $q; $k++) {
        $j = $bron + ($k % $lengte);
        $reeks[] = $verbruik[$j] - $pv[$j] * $zonfactor;
    }
    return piekTekort($reeks);
}


/* ----------------------------------------------------------------------------
 * Het diepste punt van het cumulatieve tekort.
 *
 * NIET de som. Een venster met 8 kWh nachtverbruik gevolgd door 30 kWh zon
 * telt op tot een overschot, maar de accu moet die nacht wel eerst door. Wat
 * hij moet kunnen overbruggen is de diepste dip, niet het eindsaldo.
 * -------------------------------------------------------------------------- */
function piekTekort(array $nettoPerKwartier): float
{
    $cum = 0.0; $piek = 0.0;
    foreach ($nettoPerKwartier as $x) {
        $cum += $x;
        if ($cum > $piek) { $piek = $cum; }
    }
    return $piek;
}


/* ----------------------------------------------------------------------------
 * 3c. RAMING VOOR HET VENSTER NA DE HORIZON
 * ----------------------------------------------------------------------------
 * Bouwt kwartieren voor de uren direct na het laatste prijsslot en telt op wat
 * de zonvoorspelling en het verbruiksprofiel daar zeggen. Reikt de forecast
 * niet zo ver, dan komt er nul zon uit en wordt de reserve dus ruimer -- dat
 * is de kant waar je op wilt missen.
 * -------------------------------------------------------------------------- */
function ramingNaHorizon(array $slots, array $solarData, array $dagtotalen,
                         array $profielHuis, array $profielAuto,
                         DateTimeZone $tz, float $kalibratie = 1.0, float $uren = 12.0,
                         float $zonfactor = 1.0): array
{
    $n = count($slots);
    $q = (int)round($uren * 4);
    if ($n === 0 || $q <= 0) {
        return ['tekort' => 0.0, 'verbruik' => 0.0, 'zon' => 0.0, 'kwartieren' => 0, 'tot' => '-'];
    }

    $eind = clone $slots[$n - 1]['dt'];
    $na   = [];
    for ($k = 1; $k <= $q; $k++) {
        $d = (clone $eind)->modify('+' . ($k * 15) . ' minutes');
        $na[] = [
            'dt'    => $d,
            'key'   => $d->format('Y-m-d H:i'),
            'label' => $d->format('D H:i'),
            'uur'   => (int)$d->format('G'),
            'datum' => $d->format('Y-m-d'),
            'prijs' => 0.0,
        ];
    }

    $vb  = verbruikPerSlot($na, $dagtotalen, $profielHuis, $profielAuto);
    $zon = pvPerSlot($solarData, $na, $tz, $kalibratie);

    // De kosten van een verkeerde reserve zijn scheef. Te weinig reserve
    // betekent dat je morgenochtend op de piek moet inkopen; te veel reserve
    // betekent dat je gisteravond een paar kWh minder hebt verkocht. Het eerste
    // is duurder, dus rekenen we hier bewust met minder zon dan voorspeld.
    $netto = [];
    for ($k = 0; $k < $q; $k++) { $netto[] = $vb[$k] - $zon[$k] * $zonfactor; }

    return [
        'tekort'     => piekTekort($netto),
        'verbruik'   => array_sum($vb),
        'zon'        => array_sum($zon),
        'kwartieren' => $q,
        'tot'        => $na[$q - 1]['key'],
    ];
}


/* ----------------------------------------------------------------------------
 * 4. DE PLANNER
 * -------------------------------------------------------------------------- */
function planOptimaal(array $slots, array $pv, array $verbruik, array $cfg): array
{
    $n = count($slots);
    if ($n === 0) { return ['slots' => [], 'n' => 0, 'fout' => 'geen prijsdata']; }

    $effC   = $cfg['rendementLaden'];
    $effD   = $cfg['rendementOntladen'];
    $cap    = $cfg['accucapaciteit'];
    $socMin = $cap * $cfg['socMinProc'] / 100;
    $socMax = $cap * $cfg['socMaxProc'] / 100;
    $pmax   = $cfg['laadvermogen'] * 0.25;      // kWh netzijde per kwartier
    $cyclus = $cfg['cycluskosten'];
    $soc0   = min($socMax, max($socMin, $cfg['socNuKwh']));

    /* --- 4a. Zon rechtstreeks naar verbruik ------------------------------- */
    $pvRest = $loadRest = $pvDirect = [];
    for ($i = 0; $i < $n; $i++) {
        $direct       = min($pv[$i], $verbruik[$i]);
        $pvDirect[$i] = $direct;
        $pvRest[$i]   = $pv[$i] - $direct;
        $loadRest[$i] = $verbruik[$i] - $direct;
    }

    /* --- 4b. Prijzen ------------------------------------------------------ */
    $koop = $verk = [];
    foreach ($slots as $i => $s) {
        $koop[$i] = $s['prijs'] + $cfg['opcentInkoop'];
        $verk[$i] = $s['prijs'] + $cfg['opcentVerkoop'];
    }

    /* --- 4b2. Wat de accu-inhoud waard is NA de horizon -------------------
     * Twee treden, want de eerste kWh en de laatste kWh hebben een heel
     * andere bestemming. Zie de toelichting bovenaan dit bestand.
     *
     *   onderste trede  reserveKwh kWh, vervangt straks netstroom -> INKOOP
     *   bovenste trede  al het overige, gaat straks het net op    -> VERKOOP
     * -------------------------------------------------------------------- */
    $koopSort = $koop; sort($koopSort);
    $verkSort = $verk; sort($verkSort);
    $mediaanKoop = $koopSort[(int)floor($n / 2)];
    $mediaanVerk = $verkSort[(int)floor($n / 2)];

    $reserveKwh = min(
        reserveNaHorizon($slots, $pv, $verbruik, $cfg),
        max(0.0, $socMax - $socMin)
    );

    $eindReserve = $cfg['eindwaardefactor'] * $mediaanKoop * $effD;
    $eindSurplus = ($cfg['surpluswaardefactor'] ?? 1.0) * $mediaanVerk * $effD;
    // Surplus is nooit meer waard dan reserve, en nooit negatief: bij een
    // negatieve prijs verkoop je niet, dan houd je hem gewoon.
    $eindSurplus = max(0.0, min($eindSurplus, $eindReserve));

    /* --- 4c. Bronnen en putten -------------------------------------------
     * Hoeveelheden zijn ACCUZIJDIG (kWh die echt in de accu zit).
     * Prijzen zijn per accuzijdige kWh, dus al voor rendement gecorrigeerd.
     * ---------------------------------------------------------------------- */
    $inAccu = max(0.0, $soc0 - $socMin);       // vrij beschikbaar, nu

    $bronnen = [];
    // Wat er nu in zit, gesplitst. De BOVENSTE kWh komen er als eerste uit,
    // dus die krijgen de lage prijs: goedkoop los te laten.
    if ($inAccu - $reserveKwh > 1e-9) {
        $bronnen[] = ['t' => -1, 'type' => 'accu', 'prijs' => $eindSurplus,
                      'rest' => $inAccu - $reserveKwh];
    }
    if (min($inAccu, $reserveKwh) > 1e-9) {
        $bronnen[] = ['t' => -1, 'type' => 'accu', 'prijs' => $eindReserve,
                      'rest' => min($inAccu, $reserveKwh)];
    }
    for ($i = 0; $i < $n; $i++) {
        if ($pvRest[$i] > 1e-9) {
            $bronnen[] = ['t' => $i, 'type' => 'zon',
                          'prijs' => $verk[$i] / $effC, 'rest' => $pvRest[$i] * $effC];
        }
        $bronnen[] = ['t' => $i, 'type' => 'net',
                      'prijs' => $koop[$i] / $effC, 'rest' => INF];
    }

    $putten = [];
    for ($i = 0; $i < $n; $i++) {
        if ($loadRest[$i] > 1e-9) {
            $putten[] = ['t' => $i, 'type' => 'verbruik',
                         'prijs' => $koop[$i] * $effD, 'rest' => $loadRest[$i] / $effD];
        }
        $putten[] = ['t' => $i, 'type' => 'export',
                     'prijs' => $verk[$i] * $effD, 'rest' => INF];
    }
    // Alleen het deel van de reserve dat er nog NIET in zit is het waard om
    // voor bij te kopen. Zit de accu al voller dan de reserve, dan is de enige
    // bestemming voor extra lading de bovenste trede -- en daarmee vervalt de
    // prikkel om 's nachts goedkoop bij te laden puur om het vast te houden.
    if ($reserveKwh - $inAccu > 1e-9) {
        $putten[] = ['t' => $n, 'type' => 'eind', 'prijs' => $eindReserve,
                     'rest' => $reserveKwh - $inAccu];
    }
    $putten[] = ['t' => $n, 'type' => 'eind', 'prijs' => $eindSurplus, 'rest' => INF];

    // Zoekvolgorde: dure putten eerst, goedkope bronnen eerst.
    $putOrder  = array_keys($putten);
    $bronOrder = array_keys($bronnen);
    usort($putOrder,  fn($a, $b) => $putten[$b]['prijs']  <=> $putten[$a]['prijs']);
    usort($bronOrder, fn($a, $b) => $bronnen[$a]['prijs'] <=> $bronnen[$b]['prijs']);

    /* --- 4d. Boekhouding --------------------------------------------------- */
    $soc         = array_fill(0, $n + 1, $soc0);   // SOC aan het BEGIN van slot i
    $restLaad    = array_fill(0, $n, $pmax);       // netzijdige ruimte per kwartier
    $restOntlaad = array_fill(0, $n, $pmax);
    $richting    = array_fill(0, $n, 0);           // -1 ontladen, +1 laden, 0 vrij

    $laadNet  = array_fill(0, $n, 0.0);
    $laadZon  = array_fill(0, $n, 0.0);
    $ontVerbr = array_fill(0, $n, 0.0);
    $ontNet   = array_fill(0, $n, 0.0);

    /* --- 4e. Greedy koppelen ---------------------------------------------- */
    $iter = 0;
    $winstTotaal = 0.0;

    while ($iter++ < $cfg['maxIteraties']) {

        // Goedkoopste bron die nog voorraad heeft. Dit is puur de afkapwaarde
        // voor de zoeklus hieronder; bronnen raken op, dus hij moet elke ronde
        // opnieuw bepaald worden.
        $goedkoopsteBron = INF;
        foreach ($bronOrder as $bi) {
            if ($bronnen[$bi]['rest'] > 1e-9) { $goedkoopsteBron = $bronnen[$bi]['prijs']; break; }
        }
        if (!is_finite($goedkoopsteBron)) { break; }

        $besteWinst = 1e-6;
        $besteB = $besteP = null;
        $besteQ = 0.0;

        foreach ($putOrder as $pi) {
            $put = $putten[$pi];
            // Kan deze put uberhaupt nog beter dan wat we al hebben?
            if ($put['prijs'] - $goedkoopsteBron - $cyclus <= $besteWinst) { break; }
            if ($put['rest'] <= 1e-9) { continue; }

            $t2 = $put['t'];
            if ($t2 < $n) {
                if ($restOntlaad[$t2] <= 1e-9) { continue; }
                if ($richting[$t2] === 1) { continue; }
            }

            foreach ($bronOrder as $bi) {
                $bron  = $bronnen[$bi];
                $winst = $put['prijs'] - $bron['prijs'] - $cyclus;
                if ($winst <= $besteWinst) { break; }   // bronnen lopen op in prijs
                if ($bron['rest'] <= 1e-9) { continue; }

                $t1 = $bron['t'];
                if ($t1 >= $t2) { continue; }
                // Lading die er al in zit en er ook in blijft: er beweegt niets,
                // dus hier valt niets te verdienen. Zonder deze regel zou de
                // planner de twee treden van de eindwaarde tegen elkaar
                // wegstrepen en winst boeken op een stroom die niet bestaat.
                if ($bron['type'] === 'accu' && $put['type'] === 'eind') { continue; }
                if ($t1 >= 0) {
                    if ($restLaad[$t1] <= 1e-9) { continue; }
                    if ($richting[$t1] === -1) { continue; }
                }

                // Grootte van het blok.
                $q = min($bron['rest'], $put['rest']);
                if ($t1 >= 0) { $q = min($q, $restLaad[$t1] * $effC); }
                if ($t2 < $n) { $q = min($q, $restOntlaad[$t2] / $effD); }
                if ($q <= 1e-6) { continue; }

                // Past het binnen de SOC-grenzen?
                // Laden op t1 en ontladen op t2  -> de lijn ligt HOGER tussen t1+1 en t2.
                // Bestaande lading gebruiken op t2 -> de lijn ligt LAGER vanaf t2+1.
                $ruimte = INF;
                if ($t1 >= 0) {
                    for ($k = $t1 + 1; $k <= min($t2, $n); $k++) { $ruimte = min($ruimte, $socMax - $soc[$k]); }
                } else {
                    for ($k = $t2 + 1; $k <= $n; $k++) { $ruimte = min($ruimte, $soc[$k] - $socMin); }
                }
                $q = min($q, $ruimte);
                if ($q <= 1e-6) { continue; }

                $besteWinst = $winst;
                $besteB = $bi; $besteP = $pi; $besteQ = $q;
                break;   // goedkoopste haalbare bron voor deze put is gevonden
            }
        }

        if ($besteB === null) { break; }

        /* --- boeken --- */
        $q  = $besteQ;
        $t1 = $bronnen[$besteB]['t'];
        $t2 = $putten[$besteP]['t'];

        if (is_finite($bronnen[$besteB]['rest'])) { $bronnen[$besteB]['rest'] -= $q; }
        if (is_finite($putten[$besteP]['rest']))  { $putten[$besteP]['rest']  -= $q; }

        if ($t1 >= 0) {
            $in = $q / $effC;
            $restLaad[$t1] -= $in;
            $richting[$t1]  = 1;
            if ($bronnen[$besteB]['type'] === 'net') { $laadNet[$t1] += $in; }
            else                                     { $laadZon[$t1] += $in; }
            for ($k = $t1 + 1; $k <= min($t2, $n); $k++) { $soc[$k] += $q; }
        } else {
            // Uit de bestaande lading: die zit er al in, dus de lijn zakt pas NA de put.
            for ($k = $t2 + 1; $k <= $n; $k++) { $soc[$k] -= $q; }
        }

        if ($t2 < $n) {
            $uit = $q * $effD;
            $restOntlaad[$t2] -= $uit;
            $richting[$t2]     = -1;
            if ($putten[$besteP]['type'] === 'verbruik') { $ontVerbr[$t2] += $uit; }
            else                                         { $ontNet[$t2]   += $uit; }
        }

        $winstTotaal += $besteWinst * $q;
    }

    /* --- 4f. Wat er buiten de accu om loopt -------------------------------- */
    $import = $export = $curtail = [];
    for ($i = 0; $i < $n; $i++) {
        $import[$i]  = max(0.0, $loadRest[$i] - $ontVerbr[$i]) + $laadNet[$i];
        $zonOver     = max(0.0, $pvRest[$i] - $laadZon[$i]);
        $curtail[$i] = ($verk[$i] < 0) ? $zonOver : 0.0;   // niet exporteren bij negatieve prijs
        $export[$i]  = (($verk[$i] < 0) ? 0.0 : $zonOver) + $ontNet[$i];
    }

    return [
        'slots'       => $slots,
        'n'           => $n,
        'pv'          => $pv,
        'verbruik'    => $verbruik,
        'pvDirect'    => $pvDirect,
        'pvRest'      => $pvRest,
        'loadRest'    => $loadRest,
        'koop'        => $koop,
        'verk'        => $verk,
        'soc'         => $soc,
        'socProc'     => array_map(fn($x) => round($x / $cap * 100, 1), $soc),
        'laadNet'     => $laadNet,
        'laadZon'     => $laadZon,
        'ontVerbruik' => $ontVerbr,
        'ontNet'      => $ontNet,
        'import'      => $import,
        'export'      => $export,
        'curtail'     => $curtail,
        'eindReserve' => $eindReserve,
        'eindSurplus' => $eindSurplus,
        'reserveKwh'  => $reserveKwh,
        'eindwaarde'  => $eindSurplus,   // marginale waarde: die van de bovenste kWh
        'iteraties'   => $iter - 1,
        'winst'       => $winstTotaal,
    ];
}


/* ----------------------------------------------------------------------------
 * 5. COMMANDO VOOR HET HUIDIGE KWARTIER
 * -------------------------------------------------------------------------- */
function commandoUitPlan(array $plan, array $cfg): array
{
    if (empty($plan['slots'])) { return ['NIETS', 0, 'geen plan beschikbaar']; }

    $cap  = $cfg['accucapaciteit'];
    $doel = (int)round($plan['soc'][1] / $cap * 100);   // SOC na dit kwartier

    // 1. Uit het net laden.
    if ($plan['laadNet'][0] > 1e-4) {
        if ($plan['koop'][0] < 0) {
            return ['NETLADEN-SOLARUIT', 100, 'inkoopprijs is negatief'];
        }
        return ['LADEN', $doel, 'inkopen voor later verbruik of verkoop'];
    }

    // 2. Terugleveren aan het net.
    if ($plan['ontNet'][0] > 1e-4) {
        return ['ONTLADEN', $doel, 'verkopen tegen een prijs die later niet terugkomt'];
    }

    // 3. Terugleveren kost geld: afknijpen.
    if ($plan['curtail'][0] > 1e-4) {
        return ['NULOPDEMETER', $doel, 'terugleverprijs negatief, niet exporteren'];
    }

    // 4. Zonoverschot dat NIET (helemaal) de accu in mag.
    //    Dit is het geval waarvoor LANGZAAMLADEN bestaat: de teruglever-
    //    prijs is nu goed, en later op de dag is er zon genoeg om de accu
    //    alsnog te vullen. De omvormer laadt op de langzaamlaadstroom en
    //    de rest van het overschot gaat verkocht.
    $socMaxKwh = $cap * $cfg['socMaxProc'] / 100;
    if ($plan['pvRest'][0] > 1e-4
        && $plan['laadZon'][0] < $plan['pvRest'][0] - 1e-4
        && $plan['soc'][0] < $socMaxKwh - 0.1) {
        $verkocht = round($plan['pvRest'][0] - $plan['laadZon'][0], 2);
        $trickle  = round(min($plan['pvRest'][0], ($cfg['langzaamladenKw'] ?? 0) * 0.25), 2);
        return ['LANGZAAMLADEN', $doel,
                "zon nu verkopen ($verkocht kWh), accu komt later op de dag alsnog vol"
                . " - er loopt nog $trickle kWh in op de langzaamlaadstroom"];
    }

    // 5. Accu sparen: dit verbruik is goedkoper uit het net.
    if ($plan['loadRest'][0] > 1e-4 && $plan['ontVerbruik'][0] <= 1e-4) {
        $socMinKwh = $cap * $cfg['socMinProc'] / 100;
        $reden = ($plan['soc'][0] > $socMinKwh + 0.1)
            ? 'accu sparen voor een duurder moment, nu goedkoper uit het net'
            : 'accu staat op de ondergrens';
        return ['HOUDEN', $doel, $reden];
    }

    return ['NIETS', $doel, 'normaal zelfverbruik'];
}
?>
