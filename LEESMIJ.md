# EMS-planner — vervanging van accusturen.php

Drie bestanden. `accusturen.php` en `accubasis.php` blijven staan; deze draaien
ernaast tot je ze vertrouwt.

| bestand | wat het doet |
|---|---|
| `accubasis2.php` | configuratie |
| `accuplan.php` | de planner (alleen functies, geen output) |
| `accusturen2.php` | laadt data, roept de planner aan, echoot het commando |
| `test-planner.php` | scenario's + boekhoudcontrole, draaien met `php test-planner.php` |
| `test-langzaam.php` | los testje voor het langzaamladen |

## Installeren

Zet de drie bestanden naast je bestaande scripts. Ze gebruiken dezelfde
`ha-data.json`, `uurdata.json`, `solar-data.json`, `db_config.php` en dezelfde
refresh-includes. Er wordt niets overschreven.

Testen zonder te sturen:

    https://.../accusturing/accusturen2.php?debug=1&dryrun=1

`dryrun=1` slaat het wegschrijven van `ems-plan.json` over. Laat dit een paar
dagen naast je oude script draaien en vergelijk de besluiten voordat je omzet.

Zonder webserver, en zonder dat er data voor nodig is:

    php test-planner.php

Dat draait zes scenario's — volle accu op een zonnige dag, dezelfde dag in de
winter, lege accu met een goedkope nacht, negatieve terugleverprijs,
langzaamladen, en een vlakke prijscurve — en controleert bij elk of de SOC-lijn
sluit op de geboekte stromen, of de vermogensgrens wordt gerespecteerd en of er
niet in hetzelfde kwartier geladen én ontladen wordt. Exitcode 1 als er iets
faalt, dus je kunt hem in een cronjob hangen.

## Gerepareerd: de accu bleef vol staan

Je merkte op dat de accu vol bleef terwijl het script uit het net wilde
verbruiken, en dat terwijl er de volgende dag gewoon zon stond. Dat klopte, en
het lag aan één regel in `accuplan.php`:

```php
$eindwaarde = $cfg['eindwaardefactor'] * $mediaanKoop * $effD;
```

De waarde van "vasthouden" werd uitgedrukt in de **inkoopprijs**. Verkopen wordt
betaald tegen de **verkoopprijs**. Daartussen zit je belasting plus opslag,
€0,13/kWh. Die wig stond permanent in het voordeel van niets doen.

Uitgerekend op een gewone zomerdag (mediane inkoop €0,211):

| actie | loont pas vanaf een kale beursprijs van |
|---|---|
| verkopen aan het net | **€0,212/kWh** |
| het huis voeden uit de accu | €0,101/kWh |

Een kale kwartierprijs van 21 cent komt in de zomer niet voor. De accu verkocht
dus nooit, hield vast tot in het oneindige, en het huis kocht in zodra de prijs
onder de 10 cent zakte. Precies wat je zag.

Nagerekend op een zonnige dag (accu 90%, morgen 55 kWh zon, 30 uur vooruit):

| | oud | nieuw |
|---|---|---|
| accu → net | 0,0 kWh | 35,9 kWh |
| import | 4,8 kWh | 0,0 kWh |
| SOC aan het eind | 92% | 21% |

Het saldo, met de resterende accu-inhoud aan beide kanten identiek gewaardeerd:

| accu-inhoud gewaardeerd tegen | oud | nieuw | verschil |
|---|---|---|---|
| mediane inkoopprijs (gunstigst voor het oude gedrag) | −€0,41 | −€1,32 | €0,92 |
| mediane verkoopprijs | −€0,35 | −€3,66 | €3,31 |
| 2 ct (realistisch: de zon vult hem toch) | −€0,31 | −€5,34 | €5,03 |

Dat is dertig uur. De bovenste regel is de eerlijkste ondergrens: die neemt aan
dat élke vastgehouden kWh straks een import tegen mediaanprijs vervangt. Op een
zonnige dag is dat niet waar.

### Wat ervoor in de plaats is gekomen

De eindwaarde heeft nu twee treden, want de onderste en de bovenste kWh in de
accu hebben een andere bestemming:

| trede | hoeveel | wat het waard is | waarom |
|---|---|---|---|
| reserve | het diepste tekort in de uren na de horizon | mediane **inkoop**prijs | vervangt straks netstroom |
| daarboven | al het overige | mediane **verkoop**prijs | gaat straks toch het net op |

Hoe groot de reserve is rekent het script zelf uit uit de zonvoorspelling en het
verbruiksprofiel voor het venster ná de horizon. Niet als saldo maar als
**diepste punt van het cumulatieve tekort** — een venster met 8 kWh nacht
gevolgd door 30 kWh zon telt op tot een overschot, maar de accu moet die nacht
wel eerst door.

Daarmee komt het seizoensgedrag er vanzelf uit, uit één mechanisme:

| | reserve | gedrag |
|---|---|---|
| zomer, zonnige dag morgen | 3,7 kWh (12%) | verkoopt op de avondpiek, maakt ruimte |
| winter, donkere dure dag | 16,0 kWh (50%) | houdt vast, koopt 's nachts goedkoop bij |

En de prikkel om 's nachts goedkoop bij te laden puur om het vást te houden is
weg: zit de accu al voller dan de reserve, dan is de enige bestemming voor extra
lading de bovenste trede, en daar valt tegen inkoopprijs niets aan te verdienen.

## Ook gerepareerd: het script viel om als de database wegviel

Vanaf PHP 8.1 gooit `mysqli` een exception in plaats van `false` terug te geven.
Het patroon `@new mysqli(...)` gevolgd door `mysqli_connect_errno()` vangt dat
niet af — de terugval op `$fallbackVerbruikbasis` werd dus nooit bereikt en het
hele script stierf met een fatal error. Je omvormer kreeg dan geen commando.
Staat nu in een `try/catch`, en `?debug=1` meldt het als de database niet is
gebruikt.

## Wat de planner doet

Na aftrek van zon die rechtstreeks naar het verbruik gaat, heeft elk kwartier
óf een zonoverschot óf een resterend verbruik. Nooit allebei. Daarmee is het
hele probleem een koppeling tussen bronnen en putten:

| bronnen (accu in) | kost | putten (accu uit) | levert op |
|---|---|---|---|
| zonoverschot | de gemiste verkoopprijs | restverbruik | de vermeden inkoopprijs |
| het net | de inkoopprijs | export | de verkoopprijs |
| wat er nu in zit | de eindwaarde | wat na de horizon overblijft | de eindwaarde |

De planner koppelt herhaaldelijk de best betalende put aan de goedkoopste bron
die ervóór ligt, zolang dat na rendementsverlies en slijtage geld oplevert én de
SOC-lijn ertussenin binnen de grenzen blijft.

Dat ene mechanisme dekt je vier wensen af zonder aparte regels:

* Zon opslaan voor eigen verbruik levert de inkoopprijs op (~€0,32) tegen de
  gemiste verkoopprijs (~€0,20). Dat wint bijna altijd, dus zon gaat vanzelf
  eerst naar eigen gebruik.
* Wat je morgen nodig hebt wordt niet verkocht, want verbruiken is meer waard
  dan exporteren.
* Een tekort wordt gedekt vanaf het goedkoopste kwartier ervóór.
* Een echt overschot gaat naar het duurste kwartier.

## Wat er uit komt

`COMMANDO,<doel-SOC in %>`. Het tweede getal betekent nu bij álle commando's
hetzelfde: het SOC-percentage dat aan het eind van dit kwartier bereikt moet
zijn. Dat was in het oude script per commando verschillend.

| commando | betekenis |
|---|---|
| `NIETS` | normaal zelfverbruik, accu doet zijn ding |
| `LADEN` | uit het net laden, vol vermogen |
| `LANGZAAMLADEN` | zonoverschot nu verkopen in plaats van opslaan; de accu laadt op de langzaamlaadstroom en komt later op de dag alsnog vol |
| `ONTLADEN` | terugleveren aan het net |
| `NETLADEN-SOLARUIT` | inkoopprijs is negatief, alles uit het net |
| `NULOPDEMETER` | terugleverprijs is negatief, niets exporteren |
| `HOUDEN` | **nieuw** — accu stil, verbruik uit het net |

`LANGZAAMLADEN` betekent hier dus wat jij ermee bedoelt: op beperkte stroom uit
eigen zon laden zodat het overschot verkocht kan worden. Het is nadrukkelijk
geen net-laadstand. Zet `$langzaamladenAmpere` en `$accuSpanning` goed; staat de
25 A aan de AC-kant, zet `$accuSpanning` dan op 230.

Wanneer hij fireert: zodra de planner zonoverschot heeft dat hij bewust niet
opslaat, omdat de terugleverprijs nu beter is dan wat die kWh later oplevert en
er verderop genoeg zon staat om alsnog vol te komen. Voorbeeld uit de test —
een zonnige dag met een dure ochtend, spotgoedkope middag en dure namiddag:

| tijd | verkoopprijs | zonoverschot | naar accu | naar net | SOC | commando |
|---|---|---|---|---|---|---|
| 08:00–10:45 | €0,320 | 0,85 | 0,00 | 0,85 | 60% | `LANGZAAMLADEN` |
| 11:00–14:30 | €0,030 | 0,85 | 0,85 | 0,00 | 60→95% | `NIETS` |
| 14:45 | €0,030 | 0,85 | 0,72 | 0,13 | 98% | `LANGZAAMLADEN` |
| 15:00–17:45 | €0,300 | 0,85 | 0,00 | 0,85 | 100% | `NIETS` |

De hele ochtendopwek gaat verkocht, de accu vult zich in de goedkope middag, en
zodra hij vol is valt het commando terug op `NIETS`.

`HOUDEN` moet je nog koppelen aan je omvormer. Het is het geval waarin je nu
zonder reden de accu leegtrekt op een moment dat netstroom goedkoper is dan wat
die kWh later waard is. Kan de omvormer dat niet, map hem dan voorlopig op
hetzelfde als `NIETS`; je verliest dan alleen die optimalisatie.

## Twee dingen die uit je MariaDB-tabel kwamen

### 1. De zonvoorspelling is ongeveer een factor 2 te laag

Dit is belangrijker dan de hele planner. De energiebalans op je gemeten data
sluit netjes:

| dag | zon | import | export | volgt: huisverbruik |
|---|---|---|---|---|
| 30-07 | 54,90 | 0,30 | 38,92 | 19,88 |
| 04-08 | 63,40 | 0,29 | 42,68 | 19,91 |
| 13-08 | 69,90 | 0,61 | 32,69 | 36,02 |

Je meet dus 55-71 kWh opwek per dag. `solar-data.json` voorspelt voor vandaag
28,4 kWh, met drie installaties van samen 2,9 kW piek. Uit 2,9 kW piek komt
nooit 60 kWh op een dag. De meting klopt (de balans sluit), dus de forecastbron
kent maar een deel van je installatie.

Gevolg voor de sturing: de planner denkt dat je morgen 6 kWh tekortkomt terwijl
je in werkelijkheid ~22 kWh overhoudt. Hij reserveert dan de accu voor eigen
verbruik in plaats van die ruimte te gebruiken om overschot op te vangen en op
het duurste moment te verkopen. Precies verkeerd om.

`$pvKalibratie` staat daarom voorlopig op `2.0`. Dat is een pleister op basis
van drie schone dagen, geen oplossing. Zoek uit welke strings er in je
forecastbron ontbreken en zet hem daarna terug op `1.0`.

Met kalibratie ziet het plan voor morgen er zo uit: 53,7 kWh zon tegen 29,0 kWh
verbruik, 22,4 kWh terug het net op, saldo **+€2,70** in plaats van -€1,43.

### 2. Meetgaten in `dagelijks`

Op dagen waarop de logging uitvalt staat `zon = 0` en `bat_charge = 0`, en de
dag erna telt dubbel:

| dag | zon | verbruiksformule |
|---|---|---|
| 02-08 | 0,00 | **-54,43** (meetgat) |
| 03-08 | 145,20 | **90,61** (dubbeltelling) |
| 04-08 | 63,40 | 19,52 (normaal) |

In het 30-daags gemiddelde heffen die elkaar grotendeels op (22,51 met, 23,62
zonder), dus daar was het niet zichtbaar. Maar het gemiddelde `autolader` per
weekdag heeft maar ~8 waarnemingen, en daar slaat één zo'n dag hard door. De
query sluit nu het meetgat en de dag erna uit, plus een sanity-filter op 3-80
kWh. Ik heb het venster voor de auto verruimd naar 90 dagen om het verlies aan
waarnemingen op te vangen.

Terzijde: `graad` staat overal op 0,00. Als dat graaddagen zijn, heb je daarmee
in de winter een betere voorspeller voor de warmtepomp dan de vaste formule
`verwachtVerbruikWP()`. Je hebt `wp` per dag staan, dus je kunt de relatie
tussen die twee gewoon fitten in plaats van hem te schatten.

## Parameters die je waarschijnlijk wilt aanraken

**`$cycluskosten`** (nu €0,03/kWh) — de slijtage per kWh die je door de accu
duwt. Dit is de drempel die marginale handel tegenhoudt. Reken zelf:
aanschafprijs ÷ (aantal cycli × capaciteit). Zet je hem op 0, dan pakt de
planner elk verschil van een cent; dat levert meer omzet en minder accu op.

**`$eindwaardefactor`** (nu 0,95) — wat een kWh in de **reserve** waard is, als
deel van de mediane inkoopprijs. Raak deze zelden aan.

**`$surpluswaardefactor`** (nu 1,0) — wat een kWh **boven** de reserve waard is,
als deel van de mediane verkoopprijs. Dit is de knop die bepaalt hoe gretig hij
aan het eind van het venster verkoopt. Lager = gretiger.

**`$naHorizonUren`** (nu 12) — hoeveel uur na de horizon meetelt bij het bepalen
van de reserve. Een etmaal is te lang (dan zit de zon van overmorgen erin), een
paar uur te kort om de nacht te overbruggen.

**`$reserveZonfactor`** (nu 0,7) — met welk deel van de voorspelde zon gerekend
wordt bíj het bepalen van de reserve. Bewust lager dan 1, omdat de fout scheef
is: reken je te veel zon, dan sta je 's ochtends leeg en koop je in op de piek;
reken je te weinig, dan heb je de avond ervoor een paar kWh minder verkocht. Het
eerste kost meer. Raakt alleen de reserve — de planning zelf gebruikt de volle
voorspelling.

**`$pvKalibratie`** (nu 2,0) — correctie op de zonvoorspelling, zie hierboven.
Dit is op dit moment de parameter met de grootste invloed op het resultaat.

**`$verbruikProfiel` en `$autoProfiel`** — de vorm van je verbruik over de dag.
Nu een schatting. Vervang ze zodra je echte data hebt.

**`$socMinProc`** (nu 10%) — de oude 35%-ondergrens is weg. Die was een noodrem
omdat het script niet vooruit kon kijken; dat is nu niet meer nodig. Wil je een
noodreserve voor stroomuitval, zet hem dan hoger.

## Wat ik heb weggehaald

`$hogeverkoop`, `$lageverkoop`, `$dumpverkoop`, `$verkoopmarge`, `$handelmarge`,
`$accudoelsoclaagladen`, `$kwhmargezomerhoog`, `$negatieveinkoop`,
`$accuverkoopminimumcapaciteit`, `$prijsvoornietlangzaamladen`,
`$urengeledennetgeladen`, `$margepv`, `$ontlaadKwartierenBeschikbaar`.

Dat zijn allemaal drempels die een antwoord probeerden te geven op de vraag "is
dit een goed moment". Die vraag beantwoordt de planner nu uit de prijzen zelf.

Ook weg: de vlag `laatst_netladen_timestamp`. In het verslag adviseerde ik die
te vervangen door een kostprijsadministratie — dat advies trek ik in. De prijs
waarvoor je hebt gekocht wat er in de accu zit is een verzonken kost en hoort
niet in een beslissing over de toekomst. Als verkopen nu meer oplevert dan wat
die kWh je later nog waard is, moet je verkopen, ook als je hem duur hebt
gekocht. Wat wél telt is de opportuniteitskost, en dat is `$eindwaarde`.
(Een kostprijsadministratie blijft nuttig om achteraf je rendement te meten.)

## Kanttekeningen — lees deze

**De eindwaarde blijft een schatting, ook met twee treden.** De omvang van de
reserve komt uit de zonvoorspelling voor overmorgen, en die is precies het stuk
data waarvan we weten dat het niet klopt (zie `$pvKalibratie` hierboven).
`$reserveZonfactor` dekt dat gedeeltelijk af, maar niet volledig. De prijs van
beide treden komt uit een mediaan over de horizon; bij een extreem scheve
prijscurve kan die te zuinig of te gul uitpakken. Draai een paar dagen met
`debug=1` en kijk of de regel *Reserve: x kWh* je bevalt bij het weer van die
dag.

**De horizon reikt 's ochtends niet tot de zon van morgen.** De prijzen van
morgen komen rond 13:00 binnen. Vóór dat moment kijkt de planner soms maar tien
uur vooruit, en dan doet de eindwaarde al het werk. Dat is precies waarom de
reserve uit de zónvoorspelling komt en niet uit de prijshorizon: die kijkt wel
verder. Reikt je forecast niet ver genoeg, dan komt er nul zon uit en wordt de
reserve ruimer — dat is de kant waar je op wilt missen.

**Het is greedy, niet bewijsbaar optimaal.** Het koppelt steeds het beste paar
dat nog past. Voor dit type probleem zit dat dicht bij het optimum, maar er zijn
theoretische gevallen waar een LP-oplosser een paar cent beter doet. Dat lijkt
me de complexiteit niet waard.

**Het vermogen is één budget per kwartier.** Laden en ontladen delen dezelfde
5 kW en een kwartier doet maar één van beide. Als jouw installatie DC-gekoppeld
is en zon rechtstreeks naar de accu kan bovenop het omvormervermogen, dan is de
planner hier iets te voorzichtig.

**De langzaamlaadstroom wordt niet meegerekend.** Als de planner besluit zon te
verkopen in plaats van op te slaan, rekent hij met nul lading. In werkelijkheid
loopt er nog ~0,32 kWh per kwartier in op 25 A. Over een ochtend van drie uur is
dat ~3,8 kWh die de planner niet zag aankomen. Hij leest elk kwartier de echte
SOC terug uit Home Assistant en past het plan aan, dus het loopt niet weg — maar
de vooruitblik in de debugtabel is op zulke ochtenden iets aan de lage kant.

**Het verbruiksprofiel is geraden.** Zolang dat zo is, is de avondpiek een
aanname. Dit is het eerste wat je wilt vervangen — de rest van het model is er
gevoelig voor.

**De timing van de auto is een aanname.** `$autoProfiel` staat nu op 's nachts.
Klopt dat niet, dan zit de grootste enkele verbruikspost op het verkeerde
moment en gaat de hele planning scheef.

## Wat ik nog zou bouwen

Een logger die elk kwartier SOC, verbruik, levering, zon, prijs en het gegeven
commando wegschrijft. Dat levert twee dingen: je eigen verbruiksprofiel dat
vanzelf aangroeit, en de meting om vast te stellen of dit daadwerkelijk beter
is dan het oude script. Zonder dat tweede blijft het een gevoelskwestie.
