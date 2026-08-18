<?php
/* ============================================================================
 * accubasis2.php  —  configuratie voor de EMS-planner
 * ----------------------------------------------------------------------------
 * Vervangt accubasis.php. Alle oude "$hogeverkoop / $lageverkoop / $dumpverkoop /
 * $accudoelsoclaagladen / $verkoopmarge / $handelmarge / $accuverkoopminimum..."
 * drempels zijn weg: de planner leidt die beslissingen af uit de prijzen zelf.
 * ========================================================================== */

/* --- Bestandslocaties ---------------------------------------------------- */
$urlHA           = "ha-data.json";
$urlUD           = "uurdata.json";
$urlSolar        = "solar-data.json";
$urlEmsStateFile = "ems-state.json";
$urlPlanFile     = "ems-plan.json";   // laatst berekende plan (voor grafiek/logging)

$debug = $_GET['debug'] ?? 0;

/* --- Tarieven ------------------------------------------------------------- */
// Wat je BOVENOP de kale kwartierprijs betaalt bij inkoop (belasting + opslag).
$opcentInkoop  = 0.11085 + 0.02;
// Wat je BOVENOP de kale kwartierprijs ontvangt bij teruglevering.
$opcentVerkoop = 0.02;

/* --- Accu ----------------------------------------------------------------- */
$accucapaciteit = 32;    // kWh bruto
$socMinProc     = 10;    // % nooit onder (accugezondheid + noodreserve)
$socMaxProc     = 100;   // % nooit boven
$laadvermogen   = 5;     // kW omvormer, geldt voor laden én ontladen

// Langzaamladen: de accu op beperkte stroom uit eigen zon laden, zodat het
// overschot verkocht kan worden terwijl de accu later op de dag alsnog vol
// komt. Dit is GEEN net-laadstand.
$langzaamladenAmpere = 25;
$accuSpanning        = 51.2;   // V. Staat de 25 A aan de AC-kant, zet hier 230.

// Retourrendement, gesplitst. 0.95 * 0.95 = 90% round-trip.
$rendementLaden    = 0.95;
$rendementOntladen = 0.95;

// Slijtage per kWh die je door de accu heen duwt. Vuistregel:
// (aanschafprijs / (aantal cycli * capaciteit)). Bij €6.000 / (6.000 * 32) = ~€0,03.
// Dit is de drempel die marginale handel tegenhoudt. Zet op 0 om alles te pakken.
$cycluskosten = 0.03;

/* --- Waarde van wat er ná de horizon nog in de accu zit -------------------
 * Twee treden, want de onderste kWh en de bovenste kWh hebben een andere
 * bestemming.
 *
 * ONDERSTE TREDE - de reserve. Die kWh vervangen straks netstroom, dus ze zijn
 * de INKOOPPRIJS waard. Hoe groot de reserve is rekent het script zelf uit:
 * het diepste punt van het cumulatieve tekort in de uren na de horizon
 * (verwacht verbruik minus verwachte zon). Zonnige dag -> kleine reserve.
 * Donkere winterdag -> grote reserve.
 *
 * BOVENSTE TREDE - alles daarboven. Dat wordt na de horizon toch door de zon
 * vervangen, gaat dus het net op, en is niet meer dan de VERKOOPPRIJS waard.
 *
 * Waarom dit onderscheid er moet zijn: tussen inkoop en verkoop zit
 * EUR 0,13/kWh aan belasting en opslag. Waardeer je de hele accu tegen de
 * inkoopprijs, dan ligt de verkoopdrempel EUR 0,13 te hoog en verkoopt de accu
 * nooit meer iets - hij blijft vol staan terwijl het huis inkoopt.
 */
// Onderste trede, als deel van de mediane INKOOPprijs.
$eindwaardefactor = 0.95;
// Bovenste trede, als deel van de mediane VERKOOPprijs.
// Lager = agressiever verkopen aan het eind van het venster, hoger = zuiniger.
$surpluswaardefactor = 1.0;
// Hoeveel uur na de horizon telt mee bij het bepalen van de reserve. Een etmaal
// is te lang (dan zit de zon van overmorgen erin), een paar uur te kort om de
// nacht te overbruggen. 12 uur dekt nacht plus ochtend.
$naHorizonUren = 12;
// Met welk deel van de voorspelde zon we rekenen BIJ HET BEPALEN VAN DE RESERVE.
// Bewust lager dan 1: de fout is scheef. Reken je te veel zon, dan sta je
// morgenochtend leeg en koop je in op de piek. Reken je te weinig, dan heb je
// gisteravond een paar kWh minder verkocht. Het eerste kost meer.
// Dit raakt alleen de reserve, niet de planning zelf - die gebruikt de volle
// voorspelling. Vertrouw je je forecast, zet hem dan richting 1.0.
$reserveZonfactor = 0.7;

/* --- Kalibratie van de zonvoorspelling ------------------------------------
 * De forecast in solar-data.json en de gemeten `zon` in MariaDB lopen ver
 * uiteen: de forecast voorspelt ~28 kWh voor een dag waarop je in dezelfde
 * periode 55-71 kWh meet. De energiebalans op de gemeten data sluit, dus de
 * meting klopt en de forecast is te laag - vermoedelijk staan niet al je
 * strings in de forecastbron (die geeft drie installaties, samen 2,9 kW piek).
 *
 * Zoek dat uit bij de bron. Tot die tijd corrigeert deze factor de voorspelling.
 * Zet hem op 1.0 zodra de forecast zelf klopt.
 */
$pvKalibratie = 2.0;

/* --- Verbruiksvoorspelling ------------------------------------------------ */
// Dagtotaal komt uit MariaDB; deze wegingen bepalen de VORM over de dag.
// Vervang ze zodra je echte uur- of kwartierdata hebt (zie logger).
// Index = uur 0..23. De schaal doet er niet toe, alleen de verhouding.
$verbruikProfiel = [
    0.6, 0.6, 0.6, 0.6, 0.6, 0.6,   // 00-05 nacht
    0.9, 1.3, 1.3, 1.0, 0.9, 0.9,   // 06-11 ochtendpiek
    1.0, 0.9, 0.9, 0.9, 1.1, 1.5,   // 12-17
    1.7, 1.5, 1.3, 1.2, 1.0, 0.8,   // 18-23 avondpiek
];

// Wanneer laadt de auto? Aparte vorm, want dat is een blokverbruik.
// Standaard: 's nachts. Laad je overdag op zon, verschuif de gewichten.
$autoProfiel = [
    1, 1, 1, 1, 1, 1,               // 00-05
    0, 0, 0, 0, 0, 0,               // 06-11
    0, 0, 0, 0, 0, 0,               // 12-17
    0, 0, 0, 0, 0, 1,               // 18-23
];

// Terugval als de database niet bereikbaar is.
$fallbackVerbruikbasis = 25;

/* --- Veiligheid ----------------------------------------------------------- */
$maxIteraties = 400;  // harde stop voor de planner-lus
?>
