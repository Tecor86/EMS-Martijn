<?php
/* ============================================================================
 * accusturen2.php  —  hoofdscript
 * ----------------------------------------------------------------------------
 * Laadt de data, bouwt de vectoren, laat accuplan.php het schema berekenen en
 * echoot het commando voor het huidige kwartier.
 *
 * Uitvoer:  COMMANDO,<doel-SOC in %>
 * Debug:    ?debug=1  geeft de volledige planning als tabel.
 * Droogloop: ?dryrun=1 schrijft geen state weg (handig om te vergelijken met
 *            het oude script terwijl dat nog stuurt).
 * ========================================================================== */

include('accubasis2.php');
include('accuplan.php');
@include_once('db_config.php');

$tz  = new DateTimeZone('Europe/Amsterdam');
$nu  = new DateTime('now', $tz);
$dryrun = $_GET['dryrun'] ?? 0;

/* ----------------------------------------------------------------------------
 * Hulpfuncties
 * -------------------------------------------------------------------------- */
function loadJson(string $pad): array
{
    $json = @file_get_contents($pad);
    if ($json === false) { die("Fout: kon $pad niet lezen\n"); }
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) { die("JSON fout in $pad: " . json_last_error_msg() . "\n"); }
    return $data;
}

function fileAgeHours(string $pad): float
{
    if (!file_exists($pad)) { return -1; }
    return (time() - filemtime($pad)) / 3600;
}

function verwachtVerbruikWP($temperatuur)
{
    if ($temperatuur >= 20) {
        $kwh = 0.6 * $temperatuur - 8;    // koelen
    } else {
        $kwh = -1.4 * $temperatuur + 21;  // verwarmen
    }
    return round(($kwh < 4 ? 4 : $kwh), 1);
}

/* ----------------------------------------------------------------------------
 * Data verversen
 * -------------------------------------------------------------------------- */
if (fileAgeHours($urlSolar) > 3 || fileAgeHours($urlSolar) == -1) { include('solar-data.php'); }
if (fileAgeHours($urlUD) > 12 || fileAgeHours($urlUD) == -1
    || ((int)$nu->format('G') == 15 && fileAgeHours($urlUD) > 1)) { include('uurdata.php'); }

$haData    = loadJson($urlHA);
$uurdata   = loadJson($urlUD);
$solarData = loadJson($urlSolar)['combined'] ?? [];

$accuSoc = isset($haData[0]['battery']) ? (float)$haData[0]['battery'] : null;
$temp    = isset($haData[0]['temp'])    ? (float)$haData[0]['temp']    : null;
if ($accuSoc === null || $temp === null) { die("Fout: SOC of temperatuur ontbreekt in ha-data.json\n"); }

/* ----------------------------------------------------------------------------
 * Dagtotalen uit MariaDB
 * We halen het gemiddelde per weekdag op voor alle dagen die in de horizon
 * vallen, zodat een weekend of een laaddag automatisch anders uitpakt.
 * -------------------------------------------------------------------------- */
$dagenInHorizon = [];
foreach ($uurdata as $entry) {
    $d = (new DateTime($entry['start']))->setTimezone($tz)->format('Y-m-d');
    $dagenInHorizon[$d] = true;
}
$dagenInHorizon = array_keys($dagenInHorizon);

/* ----------------------------------------------------------------------------
 * Dagtotalen uit MariaDB
 *
 * LET OP - meetgaten. In `dagelijks` staan dagen waarop de logging is
 * uitgevallen: zon = 0 en bat_charge = 0 terwijl dat onmogelijk is. Op zo'n dag
 * levert de verbruiksformule een grote negatieve waarde op, en de dag erna telt
 * dubbel. Voorbeeld uit je eigen data:
 *
 *     02-08   zon 0.00     -> formule -54.43   (meetgat)
 *     03-08   zon 145.20   -> formule  90.61   (dubbeltelling)
 *     04-08   zon  63.40   -> formule  19.52   (normaal)
 *
 * Ze heffen elkaar grotendeels op in het gemiddelde over 30 dagen, maar in het
 * gemiddelde per weekdag (autolader, ~8 waarnemingen) slaat één zo'n dag hard
 * door. Daarom sluiten we het meetgat en de dag erna uit.
 * -------------------------------------------------------------------------- */
$dagtotalen = ['_default' => ['huis' => $fallbackVerbruikbasis + verwachtVerbruikWP($temp), 'auto' => 0.0]];

$SCHOON = "
    d.zon > 0 AND d.bat_charge > 0
    AND v.datum IS NOT NULL AND v.zon > 0 AND v.bat_charge > 0
";

/* LET OP - vanaf PHP 8.1 gooit mysqli een exception in plaats van false terug
 * te geven. Het oude patroon (@new mysqli + mysqli_connect_errno) vangt dat
 * niet af: valt de database weg, dan sterft het hele script en krijgt de
 * omvormer geen commando. Vandaar de expliciete try/catch. */
$mysqli = null;
if (class_exists('mysqli')) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($DB_HOST ?? '', $DB_USER ?? '', $DB_PASS ?? '', $DB_NAME ?? '');
        if ($mysqli->connect_errno) { $mysqli = null; }
    } catch (Throwable $e) {
        $mysqli = null;
    }
}
if ($mysqli !== null) {
  try {
    // Zuiver huishoudelijk verbruik: alles behalve warmtepomp en auto.
    // `levering` staat negatief in de tabel, dus optellen is aftrekken van export.
    $q = $mysqli->query("
        SELECT AVG(huis) AS huis FROM (
            SELECT (d.verbruik + d.levering + d.zon - d.bat_charge + d.bat_discharge
                    - d.wp - d.autolader) AS huis
            FROM `dagelijks` d
            LEFT JOIN `dagelijks` v ON v.datum = DATE_SUB(d.datum, INTERVAL 1 DAY)
            WHERE d.datum >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
              AND d.datum < CURDATE()
              AND $SCHOON
        ) t
        WHERE huis BETWEEN 3 AND 80
    ");
    $basis = ($q && ($r = $q->fetch_assoc()) && $r['huis'] !== null)
        ? round((float)$r['huis'], 1) : $fallbackVerbruikbasis;

    foreach ($dagenInHorizon as $d) {
        $weekdag = (int)(new DateTime($d, $tz))->format('N') - 1;  // WEEKDAY(): 0 = maandag
        $qa = $mysqli->query("
            SELECT AVG(d.autolader) AS auto
            FROM `dagelijks` d
            LEFT JOIN `dagelijks` v ON v.datum = DATE_SUB(d.datum, INTERVAL 1 DAY)
            WHERE d.datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              AND d.datum < CURDATE()
              AND WEEKDAY(d.datum) = $weekdag
              AND d.autolader >= 0
              AND $SCHOON
        ");
        $auto = ($qa && ($ra = $qa->fetch_assoc()) && $ra['auto'] !== null) ? round((float)$ra['auto'], 1) : 0.0;
        $dagtotalen[$d] = ['huis' => $basis + verwachtVerbruikWP($temp), 'auto' => $auto];
    }
    // De raming voor het venster NA de horizon valt op '_default' terug, want
    // die dag zit niet in $dagenInHorizon. Geef die dus de gemeten basis mee
    // in plaats van de terugvalwaarde.
    $laatsteAuto = end($dagtotalen)['auto'] ?? 0.0;
    $dagtotalen['_default'] = ['huis' => $basis + verwachtVerbruikWP($temp), 'auto' => $laatsteAuto];
    $mysqli->close();
  } catch (Throwable $e) {
    // Query mislukt halverwege: val terug op de standaardwaarden voor alle
    // dagen die nog niet gevuld zijn, in plaats van het script te laten sterven.
    $dbFout = $e->getMessage();
    foreach ($dagenInHorizon as $d) {
        if (!isset($dagtotalen[$d])) { $dagtotalen[$d] = $dagtotalen['_default']; }
    }
  }
} else {
    $dbFout = 'geen verbinding met de database';
    foreach ($dagenInHorizon as $d) { $dagtotalen[$d] = $dagtotalen['_default']; }
}

/* ----------------------------------------------------------------------------
 * Vectoren en plan
 * -------------------------------------------------------------------------- */
$slots    = bouwSlots($uurdata, $nu, $tz);
$pv       = pvPerSlot($solarData, $slots, $tz, $pvKalibratie);
$verbruik = verbruikPerSlot($slots, $dagtotalen, $verbruikProfiel, $autoProfiel);

/* De prijzen houden op bij het einde van de horizon, het huis niet. De
 * zonvoorspelling kijkt verder vooruit dan de prijzen, dus daarmee kunnen we
 * ramen hoeveel de accu na de horizon nog moet overbruggen. Dat getal bepaalt
 * hoeveel van de accu-inhoud "reserve" is (inkoopprijs waard) en hoeveel
 * "surplus" (verkoopprijs waard). */
$raming = ramingNaHorizon($slots, $solarData, $dagtotalen, $verbruikProfiel,
                          $autoProfiel, $tz, $pvKalibratie, $naHorizonUren,
                          $reserveZonfactor);

$cfg = [
    'accucapaciteit'    => $accucapaciteit,
    'socMinProc'        => $socMinProc,
    'socMaxProc'        => $socMaxProc,
    'laadvermogen'      => $laadvermogen,
    'rendementLaden'    => $rendementLaden,
    'rendementOntladen' => $rendementOntladen,
    'cycluskosten'      => $cycluskosten,
    'eindwaardefactor'    => $eindwaardefactor,
    'surpluswaardefactor' => $surpluswaardefactor,
    'naHorizonUren'       => $naHorizonUren,
    'naHorizonTekort'     => $raming['tekort'],
    'reserveZonfactor'    => $reserveZonfactor,
    'opcentInkoop'      => $opcentInkoop,
    'opcentVerkoop'     => $opcentVerkoop,
    'maxIteraties'      => $maxIteraties,
    'socNuKwh'          => ($accuSoc / 100) * $accucapaciteit,
    'langzaamladenKw'   => $langzaamladenAmpere * $accuSpanning / 1000,
];

$plan = planOptimaal($slots, $pv, $verbruik, $cfg);
[$commando, $doel, $reden] = commandoUitPlan($plan, $cfg);

/* ----------------------------------------------------------------------------
 * Plan wegschrijven (voor grafieken en om achteraf te kunnen terugkijken)
 * -------------------------------------------------------------------------- */
if (!$dryrun && !empty($plan['slots'])) {
    $dump = ['berekend' => $nu->format('c'), 'commando' => $commando, 'doel' => $doel, 'kwartieren' => []];
    foreach ($plan['slots'] as $i => $s) {
        $dump['kwartieren'][] = [
            't'        => $s['key'],
            'prijs'    => round($s['prijs'], 4),
            'koop'     => round($plan['koop'][$i], 4),
            'verkoop'  => round($plan['verk'][$i], 4),
            'pv'       => round($plan['pv'][$i], 3),
            'verbruik' => round($plan['verbruik'][$i], 3),
            'laadNet'  => round($plan['laadNet'][$i], 3),
            'laadZon'  => round($plan['laadZon'][$i], 3),
            'ontVerbr' => round($plan['ontVerbruik'][$i], 3),
            'ontNet'   => round($plan['ontNet'][$i], 3),
            'soc'      => $plan['socProc'][$i],
        ];
    }
    @file_put_contents($urlPlanFile, json_encode($dump, JSON_PRETTY_PRINT), LOCK_EX);
}

/* ----------------------------------------------------------------------------
 * Debugweergave
 * -------------------------------------------------------------------------- */
if ($debug == 1) {
    $n = $plan['n'];
    $eindSlot = $plan['slots'][$n - 1]['dt']->format('D d M H:i');

    echo "<h3>=== EMS PLANNER ===</h3>";
    echo "<b>Nu:</b> " . $nu->format('D d M H:i') . " &nbsp;|&nbsp; <b>Horizon:</b> $n kwartieren tot $eindSlot<br>";
    echo "<b>Accu:</b> " . $accuSoc . "% = " . round($cfg['socNuKwh'], 1) . " kWh "
       . "(grenzen " . $socMinProc . "-" . $socMaxProc . "%)<br>";
    echo "<b>Temp:</b> " . $temp . " &deg;C &rarr; warmtepomp " . verwachtVerbruikWP($temp) . " kWh/dag<br>";
    if (isset($dbFout)) {
        echo "<b style='color:#b00'>Let op:</b> database niet gebruikt (" . htmlspecialchars($dbFout)
           . ") &rarr; teruggevallen op \$fallbackVerbruikbasis<br>";
    }
    echo "<b>Verwacht:</b> zon " . round(array_sum($plan['pv']), 1) . " kWh, "
       . "verbruik " . round(array_sum($plan['verbruik']), 1) . " kWh over de hele horizon<br>";
    $resProc = round($plan['reserveKwh'] / $accucapaciteit * 100);
    echo "<b>Na de horizon</b> (tot " . $raming['tot'] . "): verbruik "
       . round($raming['verbruik'], 1) . " kWh, zon " . round($raming['zon'], 1)
       . " kWh (waarvan " . round($reserveZonfactor*100) . "% meetelt voor de reserve)"
       . " &rarr; diepste tekort " . round($raming['tekort'], 1) . " kWh<br>";
    echo "<b>Reserve:</b> " . round($plan['reserveKwh'], 1) . " kWh ($resProc% van de accu) "
       . "&agrave; &euro;" . round($plan['eindReserve'], 4) . "/kWh &nbsp;|&nbsp; "
       . "<b>daarboven:</b> &euro;" . round($plan['eindSurplus'], 4) . "/kWh<br>";
    $drempelVerkoop = ($plan['eindSurplus'] + $cycluskosten) / $rendementOntladen - $opcentVerkoop;
    $drempelHuis    = ($plan['eindSurplus'] + $cycluskosten) / $rendementOntladen - $opcentInkoop;
    echo "<b>Drempels boven de reserve:</b> verkopen vanaf een kale prijs van &euro;"
       . round($drempelVerkoop, 3) . ", zelf verbruiken vanaf &euro;" . round($drempelHuis, 3) . "<br>";
    echo "<b>Planner:</b> " . $plan['iteraties'] . " koppelingen, verwachte opbrengst &euro;"
       . round($plan['winst'], 2) . " t.o.v. niets doen met de accu<br><br>";

    echo "<b style='font-size:15px'>BESLUIT NU: $commando,$doel</b> &nbsp; <i>($reden)</i><br><br>";

    // Samenvatting per dag
    $perDag = [];
    foreach ($plan['slots'] as $i => $s) {
        $d = $s['datum'];
        if (!isset($perDag[$d])) { $perDag[$d] = ['zon'=>0,'verbruik'=>0,'import'=>0,'export'=>0,'laadNet'=>0,'ontNet'=>0,'kosten'=>0]; }
        $perDag[$d]['zon']      += $plan['pv'][$i];
        $perDag[$d]['verbruik'] += $plan['verbruik'][$i];
        $perDag[$d]['import']   += $plan['import'][$i];
        $perDag[$d]['export']   += $plan['export'][$i];
        $perDag[$d]['laadNet']  += $plan['laadNet'][$i];
        $perDag[$d]['ontNet']   += $plan['ontNet'][$i];
        $perDag[$d]['kosten']   += $plan['import'][$i] * $plan['koop'][$i] - $plan['export'][$i] * $plan['verk'][$i];
    }
    echo "<b>--- PER DAG ---</b><br>";
    echo "<table border=1 cellpadding=4 cellspacing=0 style='border-collapse:collapse;font-family:monospace;font-size:12px'>";
    echo "<tr style='background:#eee'><th>datum</th><th>zon</th><th>verbruik</th><th>inkoop</th><th>verkoop</th><th>waarvan net&rarr;accu</th><th>waarvan accu&rarr;net</th><th>saldo</th></tr>";
    foreach ($perDag as $d => $v) {
        printf("<tr><td>%s</td><td>%.1f</td><td>%.1f</td><td>%.1f</td><td>%.1f</td><td>%.1f</td><td>%.1f</td><td>&euro;%.2f</td></tr>",
            $d, $v['zon'], $v['verbruik'], $v['import'], $v['export'], $v['laadNet'], $v['ontNet'], $v['kosten']);
    }
    echo "</table><br>";

    // Volledige planning
    echo "<b>--- PLANNING PER KWARTIER ---</b><br>";
    echo "<table border=1 cellpadding=3 cellspacing=0 style='border-collapse:collapse;font-family:monospace;font-size:11px'>";
    echo "<tr style='background:#eee'><th>tijd</th><th>koop</th><th>verk</th><th>zon</th><th>verbruik</th>"
       . "<th>actie</th><th>kWh</th><th>SOC%</th></tr>";
    foreach ($plan['slots'] as $i => $s) {
        $actie = '&middot;'; $kwh = 0; $kleur = '';
        if ($plan['laadNet'][$i] > 1e-4)      { $actie = 'net &rarr; accu';  $kwh = $plan['laadNet'][$i];     $kleur = 'background:#d6f5d6'; }
        elseif ($plan['ontNet'][$i] > 1e-4)   { $actie = 'accu &rarr; net';  $kwh = $plan['ontNet'][$i];      $kleur = 'background:#f7d6d6'; }
        elseif ($plan['laadZon'][$i] > 1e-4)  { $actie = 'zon &rarr; accu';  $kwh = $plan['laadZon'][$i];     $kleur = 'background:#fff3c4'; }
        elseif ($plan['ontVerbruik'][$i] > 1e-4) { $actie = 'accu &rarr; huis'; $kwh = $plan['ontVerbruik'][$i]; $kleur = 'background:#e8e8ff'; }
        elseif ($plan['loadRest'][$i] > 1e-4) { $actie = 'net &rarr; huis';  $kwh = $plan['import'][$i]; }
        elseif ($plan['pvRest'][$i] > 1e-4)   { $actie = 'zon &rarr; net';   $kwh = $plan['export'][$i]; }

        $rand = ($i === 0) ? 'font-weight:bold;outline:2px solid #000' : '';
        printf("<tr style='%s%s'><td>%s</td><td>%.3f</td><td>%.3f</td><td>%.2f</td><td>%.2f</td><td>%s</td><td>%.2f</td><td>%.0f</td></tr>",
            $kleur, $rand, $s['label'], $plan['koop'][$i], $plan['verk'][$i],
            $plan['pv'][$i], $plan['verbruik'][$i], $actie, $kwh, $plan['socProc'][$i]);
    }
    echo "</table><br>";
    echo "--------------------------------------------------<br>";
}

echo $commando . "," . $doel;
?>
