<?php

declare(strict_types=1);

/**
 * Die Chronik: was in den letzten dreissig Tagen passiert ist.
 *
 * Alle anderen Seiten zeigen den AUGENBLICK. Diese hier zeigt den Verlauf, und das ist
 * eine andere Sorte Frage: nicht "laeuft es", sondern "lief es durch". Vier der sechs
 * Zonen lassen sich sofort fuellen, weil Proxmox selbst ein Aufgabenprotokoll fuehrt -
 * das ist die einzige rueckwirkende Quelle im ganzen Aufbau. Die Erreichbarkeitsbaender
 * und die Zustandswechsel koennen dagegen erst ab dem Tag wachsen, an dem hier
 * angefangen wird zu schreiben; sie stehen anfangs bewusst leer da statt gefaelscht.
 *
 * Die Funktionen sind absichtlich frei und nehmen ihre Daten als Argumente: so laesst
 * sich jede einzeln pruefen, ohne eine Instanz zu bauen.
 */

/** Zeitspanne so schreiben, wie man sie ausspricht: "4 d 6 h", "22 h", "35 min". */
function pxc_dauer(int $sek): string
{
    if ($sek < 0)    { return 'nie'; }
    if ($sek < 3600) { return max(1, (int) round($sek / 60)) . ' min'; }
    $h = (int) floor($sek / 3600);
    if ($h < 48)     { return $h . ' h'; }
    $t = (int) floor($h / 24);
    return $t . ' d ' . ($h - $t * 24) . ' h';
}

/**
 * Aufgabenzeilen vieler Hosts zu EINEM Protokoll verschmelzen, neueste zuerst.
 *
 * Die Quellzeilen tragen den Startzeitpunkt als letzte Spalte mit - nur damit sich
 * ueber Hosts hinweg sortieren laesst. Fuer die Anzeige faellt sie wieder weg: eine
 * Unix-Zahl neben einer lesbaren Uhrzeit ist Ballast.
 */
function pxc_protokoll(array $listen, int $max = 60): array
{
    $alle = [];
    foreach ($listen as $rows) {
        if (!is_array($rows) || count($rows) < 2) { continue; }
        foreach (array_slice($rows, 1) as $z) {
            if (!is_array($z) || count($z) < 7) { continue; }
            if (pxc_rauschen((string) $z[2])) { continue; }
            $alle[] = $z;
        }
    }
    usort($alle, function ($a, $b) { return ((int) $b[6]) <=> ((int) $a[6]); });
    $aus = [['Zeit', 'Host', 'Aufgabe', 'Objekt', 'Dauer', 'Ergebnis']];
    foreach (array_slice($alle, 0, $max) as $z) { $aus[] = array_slice($z, 0, 6); }
    return $aus;
}

/**
 * Aufgabenarten, die kein Ereignis sind, sondern Betrieb.
 *
 * PBS protokolliert JEDEN Lesezugriff auf einen Datastore als eigene Aufgabe - allein
 * beim Abfragen der Gruppen entstehen dutzende 'reader'-Eintraege, und die haetten die
 * Chronik binnen einer Minute vollstaendig zugedeckt. Dasselbe gilt fuer Terminal-
 * Sitzungen und Paketlisten: sie stehen im Protokoll, weil sie liefen, nicht weil
 * jemand von ihnen erfahren muesste.
 */
function pxc_rauschen(string $art): bool
{
    static $still = ['reader', 'termproxy', 'vncproxy', 'vncshell', 'spiceproxy',
                     'aptupdate', 'imgcopy', 'download', 'srvreload', 'logrotate'];
    $a = strtolower(trim($art));
    return in_array($a, $still, true);
}

/**
 * Ein Ergebnis-Text auf einen der drei Zustaende abbilden.
 *
 * Proxmox schreibt in dieselbe Spalte "OK", "WARNINGS: 1", "job errors", einen
 * Exit-Code oder einen ganzen Satz. Fuer den Streifen zaehlt nur: durchgelaufen,
 * gestolpert, oder noch unterwegs.
 */
function pxc_ergebnis(string $status): string
{
    $s = trim($status);
    if ($s === '' || $s === 'läuft')                        { return 'lauf'; }
    if (strcasecmp($s, 'OK') === 0)                         { return 'ok'; }
    if (stripos($s, 'WARNING') !== false)                   { return 'warn'; }
    return 'fehler';
}

/**
 * Die Sicherungsauftraege als Laufstreifen: je Auftrag die letzten Laeufe.
 *
 * Gruppiert wird ueber Host + Aufgabenart + Objekt. Das Objekt gehoert dazu, weil
 * "sync galantine->zechine" und "sync falbala->zechine" derselbe Aufgabentyp auf
 * demselben Host sind und trotzdem zwei verschiedene Auftraege, die getrennt
 * scheitern koennen.
 *
 * Aufgenommen wird nur, was mit Sicherung zu tun hat. Ein Streifen aus 30 Anmeldungen
 * am Web-Zugang sagt nichts darueber, ob die Sicherungen laufen.
 */
function pxc_laeufe(array $listen, int $spalten = 30, int $maxZeilen = 12): array
{
    $arten = ['vzdump', 'backup', 'verify', 'verificationjob', 'sync', 'syncjob',
              'prune', 'garbage_collection', 'gc'];
    $grp = [];
    foreach ($listen as $rows) {
        if (!is_array($rows) || count($rows) < 2) { continue; }
        foreach (array_slice($rows, 1) as $z) {
            if (!is_array($z) || count($z) < 7) { continue; }
            $art = strtolower((string) $z[2]);
            $treffer = false;
            foreach ($arten as $a) { if (strpos($art, $a) !== false) { $treffer = true; break; } }
            if (!$treffer) { continue; }
            // Das Objekt kuerzen: PBS haengt an die Auftrags-ID einen Doppelpunkt und
            // den Datastore, PVE eine Gaesteliste. Fuer die Gruppierung reicht der Kopf.
            $obj = (string) $z[3];
            $obj = preg_replace('/[,:].*$/', '', $obj) ?? $obj;
            // Eine Sicherung protokolliert PVE JE GAST als eigene Aufgabe. Zwanzig Gaeste
            // ergaeben zwanzig Streifen fuer EINEN naechtlichen Lauf - und jeder davon
            // saehe aus wie ein eigener Auftrag mit eigenem Takt. Bei vzdump zaehlt
            // deshalb nur der Knoten; bei Abgleich und Pruefung dagegen IST das Objekt
            // der Auftrag, denn zwei Abgleiche desselben Servers koennen getrennt
            // scheitern.
            if (strpos($art, 'vzdump') !== false || strpos($art, 'backup') !== false) { $obj = ''; }
            $k = $z[1] . '|' . $art . '|' . $obj;
            $grp[$k][] = ['t' => (int) $z[6], 'e' => pxc_ergebnis((string) $z[5])];
        }
    }
    // Auftraege mit den meisten Laeufen zuerst - das sind die taeglichen, und die sind
    // die aussagekraeftigsten. Ein Auftrag mit einem einzigen Lauf traegt keinen Streifen.
    uasort($grp, function ($a, $b) { return count($b) <=> count($a); });

    $aus = [['Auftrag', 'Takt', 'Zustände', 'Kennzahl']];
    foreach (array_slice($grp, 0, $maxZeilen, true) as $k => $laeufe) {
        [$host, $art, $obj] = array_pad(explode('|', $k, 3), 3, '');
        usort($laeufe, function ($a, $b) { return $a['t'] <=> $b['t']; });
        // Eine naechtliche Sicherung besteht aus einer Aufgabe JE GAST. Zwanzig davon
        // sind EIN Lauf, nicht zwanzig - ungebuendelt zeigte der Streifen die Gaeste
        // einer einzigen Nacht statt dreissig Naechte. Gebuendelt wird nach Kalendertag,
        // und die Zelle traegt das SCHLECHTESTE Ergebnis des Tages: eine Sicherung, bei
        // der ein Gast scheiterte, ist nicht durchgelaufen.
        if (strpos($art, 'vzdump') !== false || strpos($art, 'backup') !== false) {
            $rang = ['ok' => 0, 'lauf' => 1, 'warn' => 2, 'fehler' => 3];
            $tage = [];
            foreach ($laeufe as $l) {
                $d = date('Y-m-d', $l['t']);
                if (!isset($tage[$d]) || $rang[$l['e']] > $rang[$tage[$d]['e']]) { $tage[$d] = $l; }
            }
            ksort($tage);
            $laeufe = array_values($tage);
        }
        $laeufe = array_slice($laeufe, -$spalten);
        $zust   = array_map(function ($x) { return $x['e']; }, $laeufe);
        $schlecht = 0;
        foreach ($zust as $z2) { if ($z2 === 'fehler') { $schlecht++; } }
        $name = $art . ' ' . $host . ($obj !== '' && $obj !== '—' ? ' · ' . $obj : '');
        $aus[] = [
            $name,
            pxc_takt($laeufe),
            implode(',', $zust),
            $schlecht > 0 ? ($schlecht . ' Fehler') : (count($zust) . ' ok'),
        ];
    }
    return $aus;
}

/**
 * Den Takt eines Auftrags aus seinen Startzeiten ERRATEN, statt ihn zu erfragen.
 *
 * Die Auftragsdefinition steht in PVE und PBS an je eigener Stelle und in je eigenem
 * Format (Kalenderausdruecke wie "sat 02:30"). Der Abstand zwischen den Laeufen sagt
 * dasselbe und gilt fuer beide - und er sagt zusaetzlich, was WIRKLICH passiert ist.
 */
function pxc_takt(array $laeufe): string
{
    if (count($laeufe) < 2) { return '—'; }
    $abst = [];
    for ($i = 1; $i < count($laeufe); $i++) { $abst[] = $laeufe[$i]['t'] - $laeufe[$i - 1]['t']; }
    sort($abst);
    $med = $abst[intdiv(count($abst), 2)];
    $uhr = date('H:i', $laeufe[count($laeufe) - 1]['t']);
    if ($med <= 5400)   { return 'stündlich'; }
    if ($med <= 129600) { return 'täglich ' . $uhr; }
    if ($med <= 864000) { return 'wöchentlich ' . strtr(date('D', $laeufe[count($laeufe) - 1]['t']),
                                 ['Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do',
                                  'Fri' => 'Fr', 'Sat' => 'Sa', 'Sun' => 'So']); }
    return 'selten';
}

/**
 * Alterslisten mehrerer Sicherungsserver zu einer machen, aeltester zuerst.
 *
 * Gaeste ohne jede Sicherung stehen oben. Sie haben kein messbares Alter, sind aber
 * der schlimmere Fall - eine nach Zahlen sortierte Liste haette sie ans Ende gespuelt.
 */
function pxc_alter(array $listen, int $max = 12): array
{
    $alle = [];
    foreach ($listen as $rows) {
        if (!is_array($rows) || count($rows) < 2) { continue; }
        foreach (array_slice($rows, 1) as $z) {
            if (is_array($z) && count($z) >= 4) { $alle[] = $z; }
        }
    }
    usort($alle, function ($a, $b) {
        $x = (int) $a[2]; $y = (int) $b[2];
        if (($x < 0) !== ($y < 0)) { return $x < 0 ? -1 : 1; }
        return $y <=> $x;
    });
    // Derselbe Gast erscheint zweimal, wenn zwei Sicherungsserver auf DIESELBE Ablage
    // schauen - die Namen unterscheiden sich dann nur in der Schreibweise. Zwei Zeilen
    // mit identischem Alter sind keine zwei Befunde, sondern einer.
    $aus = [['Gast', 'Ablage', 'Sekunden', 'Alter']];
    $gesehen = [];
    foreach ($alle as $z) {
        $k = mb_strtolower($z[0] . '|' . $z[1]);
        if (isset($gesehen[$k])) { continue; }
        $gesehen[$k] = true;
        $aus[] = $z;
        if (count($aus) > $max) { break; }
    }
    return $aus;
}

/**
 * Die Altersliste als Balkenstreifen, wie ihn das Laufstreifen-Widget liest.
 *
 * Ein Balken statt einer Zahl, weil es hier auf das VERHAELTNIS ankommt: dass ein Gast
 * seit 1085 Tagen nicht gesichert ist, versteht man erst neben einem, der seit neun
 * Stunden nicht gesichert ist. Die Laenge ist linear am aeltesten Eintrag gemessen -
 * eine logarithmische Skala wuerde genau den Abstand einebnen, um den es geht.
 *
 * Die Farbe kommt nicht aus der Laenge, sondern aus einer Schwelle: laenger als dreissig
 * Tage ist ein Befund, laenger als sieben ein Hinweis, alles darunter Betrieb. Sonst
 * saehe der laengste Balken einer gesunden Anlage genauso rot aus wie der einer kranken.
 */
function pxc_altersbalken(array $alter, int $zellen = 26): array
{
    $max = 1;
    foreach (array_slice($alter, 1) as $z) { $max = max($max, (int) $z[2]); }
    $aus = [['Gast', 'Ablage', 'Zustände', 'Alter']];
    foreach (array_slice($alter, 1) as $z) {
        $sek = (int) $z[2];
        $art = ($sek < 0 || $sek > 30 * 86400) ? 'fehler' : (($sek > 7 * 86400) ? 'warn' : 'ok');
        $n   = ($sek < 0) ? $zellen : max(1, (int) round($sek / $max * $zellen));
        $aus[] = [$z[0], $z[1],
                  implode(',', array_merge(array_fill(0, $n, $art), array_fill(0, $zellen - $n, ''))),
                  $z[3]];
    }
    return $aus;
}
