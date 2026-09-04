<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/px-archiv.php';
require_once __DIR__ . '/../libs/px-chronik.php';

/**
 * ProxmoxLagebild (PXL) — die Befundtabelle und das Lagebild ueber ALLE Hosts.
 *
 * WARUM EIN DRITTES MODUL: PXV und PXB sind je Host. Die Frage 'was ist gerade zu tun?'
 * ist aber keine Frage an einen Host, sondern an die Anlage. Sie braucht also einen Ort,
 * der quer liest - und genau einen, damit die Tabelle nicht viermal halb entsteht.
 *
 * DIE TABELLE LIEST DEN BAUM, sie wird nicht gepflegt. Eine von Hand gefuellte
 * Kachelliste kennt einen neuen Knoten erst, wenn jemand eine Zeile ergaenzt; diese
 * Tabelle kennt ihn beim naechsten Lauf. Genau daran ist die alte Ueberwachung
 * gescheitert: fuenf abgehakte Ereignisse, ein halbes Jahr Februarstaende, und niemandem
 * fiel es auf, weil nichts quer schaute.
 *
 * Hier liegt auch die ARCHIVPFLEGE. Nicht aus Verlegenheit, sondern weil dieses Modul
 * den Teilbaum ohnehin durchlaeuft - und weil die Archivierung vorher eine einmalige
 * Handlung war: gemessen am 03.09.2026 hatte Proxplex 59 Variablen und davon 0
 * archiviert, Falbala und Gutemine je 11 und davon 0. Ein neuer Knoten kam stumm ins
 * Haus. Als Regel an dieser Stelle kann das nicht wieder passieren.
 */
class ProxmoxLagebild extends IPSModule
{
    /** Reihenfolge der Dringlichkeit. Bestimmt die Sortierung der Tabelle. */
    private const RANG = ['kritisch' => 0, 'hoch' => 1, 'mittel' => 2, 'niedrig' => 3];

    /** Archivpflege einmal je Stunde - oefter braucht es nicht, seltener uebersieht etwas. */
    private const ARCHIV_TAKT = 3600;

    public function Create(): void
    {
        parent::Create();

        // Die Wurzel des Proxmox-Teilbaums. Leer = unter dieser Instanz.
        $this->RegisterPropertyInteger('Wurzel', 0);
        $this->RegisterPropertyInteger('Takt', 60);
        $this->RegisterPropertyBoolean('ArchivPflege', true);

        $this->RegisterAttributeInteger('LetzteArchivpflege', 0);
        $this->RegisterTimer('Auswerten', 0, 'PXL_Auswerten($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableInteger('BefundeOffen', 'Befunde offen', '', 1);
        // 0 in Ordnung, 1 Aufmerksamkeit, 2 Handlungsbedarf.
        $this->RegisterVariableInteger('Lage', 'Lage', '', 2);
        $this->RegisterVariableString('Befunde', 'Befunde', '', 3);
        $this->RegisterVariableString('Knotenvergleich', 'Knotenvergleich', '', 4);

        $takt = max(30, $this->ReadPropertyInteger('Takt'));
        $this->SetTimerInterval('Auswerten', $takt * 1000);
        $this->SetStatus($this->wurzel() > 0 ? 102 : 104);
    }

    /** Ein Auswertungslauf. */
    public function Auswerten(): void
    {
        $wurzel = $this->wurzel();
        if ($wurzel <= 0) { $this->SetStatus(104); return; }
        $this->SetStatus(102);

        $zeilen = [];
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $this->hostPruefen($k, $zeilen);
        }
        usort($zeilen, fn($a, $b) => (self::RANG[$a[0]] ?? 9) <=> (self::RANG[$b[0]] ?? 9));

        $lage = 0;
        foreach ($zeilen as $z) {
            if ($z[0] === 'kritisch') { $lage = 2; break; }
            if ($z[0] === 'hoch')     { $lage = 1; }
        }

        $ausgabe = array_map(function ($z) {
            $z[0] = mb_strtoupper($z[0]);   // KRITISCH / HOCH / MITTEL / NIEDRIG
            return $z;
        }, $zeilen);
        $tab  = array_merge([['Stufe', 'Host', 'Objekt', 'Befund', 'seit']], $ausgabe);
        $json = json_encode($tab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $vgl  = $this->knotenvergleich($wurzel);
        $this->SetValue('Befunde',         $json);
        $this->SetValue('BefundeOffen',    count($zeilen));
        $this->SetValue('Lage',            $lage);
        $this->SetValue('Knotenvergleich', $vgl);
        $this->schreib($wurzel, 'Knotenmatrix', 3, '', $this->matrix($wurzel));

        // SPIEGELN wie PXV und PXB. Die Seiten binden auf die Variablen unter der WURZEL,
        // nicht auf die der Instanz - stuenden die Werte nur hier, zeigten die Seiten
        // stillschweigend den Stand vom Umschalttag. Gemessen waren es 3,3 Stunden, bis
        // es auffiel, und aufgefallen ist es nur beim Aufraeumen.
        $this->schreib($wurzel, 'Befunde',         3, '', $json);
        $this->schreib($wurzel, 'Befunde offen',   1, '', count($zeilen));
        $this->schreib($wurzel, 'Lage',            1, '', $lage);
        $this->schreib($wurzel, 'Knotenvergleich', 3, '', $vgl);

        // Je Host die Kachelzeilen fuer das Lagebild. Sie stehen bewusst HIER und nicht
        // in der Seite: was auf einer Knotenkarte wichtig ist, haengt vom Zustand ab -
        // bei Ruhe die Routinezahlen, bei Last oder Stoerung das, was klemmt. Diese
        // Entscheidung ist Logik, keine Gestaltung, und gehoert deshalb ins Modul.
        $this->kacheln($wurzel, $zeilen);

        // Legende des Gaestegitters. Ein Gitter aus Farbfeldern ohne Zahlen zwingt zum
        // Zaehlen - die Vorlage nennt sie deshalb ausdruecklich.
        $ges = 0; $lauf = 0;
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $g = $this->lies($k, 'Gäste gesamt');
            if (!is_numeric($g)) { continue; }
            $ges  += (int) $g;
            $lauf += (int) $this->lies($k, 'Gäste laufen');
        }
        $this->verdichtung($wurzel);

        $this->schreib($wurzel, 'Gäste Legende', 3, '',
            $ges . ' Gäste · ' . $lauf . ' laufen · ' . ($ges - $lauf) . ' gestoppt');

        // Dieselben Zahlen einzeln, damit eine Kachel die blanke Zahl gross zeigen kann,
        // ohne den Legendentext zerlegen zu muessen.
        $this->schreib($wurzel, 'Gäste gesamt',   1, '', $ges);
        $this->schreib($wurzel, 'Gäste laufen',   1, '', $lauf);
        $this->schreib($wurzel, 'Gäste gestoppt', 1, '', $ges - $lauf);

        $this->chronik($wurzel);

        if ($this->ReadPropertyBoolean('ArchivPflege')
            && (time() - $this->ReadAttributeInteger('LetzteArchivpflege')) >= self::ARCHIV_TAKT) {
            $this->WriteAttributeInteger('LetzteArchivpflege', time());
            $this->pflegeLauf($wurzel);
        }
    }

    /**
     * Den ganzen Teilbaum gegen die Archivregel halten. Faengt ein, was vor der Regel
     * entstand und was von Hand angelegt wurde.
     *
     * NUR EINSCHALTEN. AC_SetLoggingStatus(..., false) loescht die aufgezeichnete Reihe -
     * unwiderruflich. Was faelschlich archiviert ist, wird ins Meldungslog geschrieben
     * und bleibt liegen, bis ein Mensch entscheidet.
     */
    public function Archivpflege(): array
    {
        $wurzel = $this->wurzel();
        return $wurzel > 0 ? $this->pflegeLauf($wurzel) : ['fehler' => 'keine Wurzel'];
    }

    // ---------------------------------------------------------------- privat

    private function wurzel(): int
    {
        $w = $this->ReadPropertyInteger('Wurzel');
        return ($w > 0 && IPS_ObjectExists($w)) ? $w : 0;
    }

    private function pflegeLauf(int $wurzel): array
    {
        $ac = px_archiv_instanz();
        if (!$ac) { return ['fehler' => 'keine Archivinstanz']; }
        $b = ['geprueft' => 0, 'eingeschaltet' => 0, 'aggregation' => 0, 'ungefragt' => []];
        $geh = function (int $id) use (&$geh, $ac, &$b) {
            foreach (IPS_GetChildrenIDs($id) as $c) {
                if (IPS_VariableExists($c)) {
                    $b['geprueft']++;
                    $tat = px_archiv_anwenden($ac, $c, IPS_GetName($c), (int) IPS_GetVariable($c)['VariableType']);
                    if ($tat === 'ein')     { $b['eingeschaltet']++; }
                    elseif ($tat === 'agg') { $b['aggregation']++; }
                    elseif ($tat !== '')    { $b['ungefragt'][] = IPS_GetName(IPS_GetParent($c)) . '\\' . IPS_GetName($c); }
                } elseif (IPS_GetObject($c)['ObjectType'] == 0) {
                    $geh($c);
                }
            }
        };
        $geh($wurzel);
        if ($b['eingeschaltet'] || $b['aggregation']) {
            $this->LogMessage(sprintf('Archivpflege: %d eingeschaltet, %d Aggregation berichtigt (von %d geprüft)',
                                      $b['eingeschaltet'], $b['aggregation'], $b['geprueft']), KL_NOTIFY);
        }
        if ($b['ungefragt']) {
            // Die Meldung muss sagen, was zu tun ist. Eine Warnung, die nur feststellt,
            // dass etwas nicht zur Regel passt, und zugleich mitteilt, dass nichts
            // unternommen wird, ist eine Sackgasse - sie wiederholt sich stuendlich und
            // laesst den Leser ratlos zurueck.
            $this->LogMessage('Archivpflege: ' . count($b['ungefragt'])
                              . ' Variablen werden archiviert, obwohl die Regel sie nicht vorsieht. '
                              . 'Absichtlich NICHT abgeschaltet — AC_SetLoggingStatus(false) löscht die '
                              . 'aufgezeichnete Reihe. Zu entscheiden ist von Hand: entweder die '
                              . 'Aufzeichnung im Archiv abschalten (Reihe geht verloren) oder den Namen '
                              . 'in px_archiv_regel() aus der Ausnahmeliste nehmen. Betroffen: '
                              . implode(', ', array_slice($b['ungefragt'], 0, 10)), KL_WARNING);
        }
        return $b;
    }

    /**
     * Die Chronik: dreissig Tage Verlauf, zusammengetragen aus dem, was die beiden
     * anderen Module in den Baum geschrieben haben.
     *
     * Vier der sechs Zonen sind SOFORT gefuellt, weil Proxmox selbst ein
     * Aufgabenprotokoll ueber Wochen fuehrt. Die Erreichbarkeitsbaender kommen aus dem
     * Symcon-Archiv und koennen deshalb nur so weit zurueckreichen, wie hier
     * aufgezeichnet wurde; die Zustandswechsel entstehen ueberhaupt erst durch den
     * Vergleich zweier Laeufe. Beide fangen leer an - das ist ehrlicher, als sie mit
     * dem heutigen Stand rueckwaerts aufzufuellen.
     */
    private function chronik(int $wurzel): void
    {
        $aufgaben = []; $alter = [];
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $a = $this->lies($k, 'Aufgabenliste');
            if (is_string($a) && $a !== '') { $aufgaben[] = json_decode($a, true) ?: []; }
            $b = $this->lies($k, 'Sicherungsalter');
            if (is_string($b) && $b !== '') { $alter[] = json_decode($b, true) ?: []; }
        }

        $prot = pxc_protokoll($aufgaben, 60);
        $alt  = pxc_alter($alter, 12);
        $this->schreib($wurzel, 'Aufgabenprotokoll', 3, '', $this->tab($prot));
        $this->schreib($wurzel, 'Sicherungsläufe',   3, '', $this->tab(pxc_laeufe($aufgaben, 30, 10)));
        $this->schreib($wurzel, 'Sicherungsalter',   3, '', $this->tab($alt));
        $this->schreib($wurzel, 'Sicherungsalter Balken', 3, '', $this->tab(pxc_altersbalken($alt)));

        // Die beiden Kennzahlen der Chronik, die keine Zaehlung sind, sondern ein
        // Spitzenwert: der aelteste Stand ueberhaupt und wie viele Gaeste noch nie
        // gesichert wurden. Beide stehen schon in der Liste - aber eine Kachel soll
        // nicht erst eine Tabelle zerlegen muessen, um eine Zahl zu zeigen.
        $ohne = 0; $spitze = '—'; $spitzeWer = '—';
        foreach (array_slice($alt, 1) as $z) {
            if ((int) $z[2] < 0) { $ohne++; continue; }
            // Ein Gast, der NIE gesichert wurde, hat kein Alter. Er hat seine eigene
            // Kachel; ihn hier als "aeltesten Stand" zu zeigen ergaebe die Zahl 'nie'.
            if ($spitze === '—') { $spitze = (string) $z[3]; $spitzeWer = $z[0] . ' · ' . $z[1]; }
        }
        $this->schreib($wurzel, 'Älteste Sicherung',      3, '', $spitze);
        $this->schreib($wurzel, 'Älteste Sicherung wer',  3, '', $spitzeWer);
        $this->schreib($wurzel, 'Gäste ohne Sicherung',   1, '', $ohne);

        // Fehlgeschlagene Laeufe der letzten sieben Tage. Gezaehlt wird ueber ALLE Hosts,
        // denn eine Sicherung, die auf einem Knoten scheitert, ist nicht dadurch harmlos,
        // dass sie auf den anderen vieren durchlief.
        $grenze = time() - 7 * 86400; $fehler = 0;
        foreach ($aufgaben as $rows) {
            foreach (array_slice(is_array($rows) ? $rows : [], 1) as $z) {
                if (!is_array($z) || count($z) < 7) { continue; }
                if ((int) $z[6] >= $grenze && pxc_ergebnis((string) $z[5]) === 'fehler') { $fehler++; }
            }
        }
        $this->schreib($wurzel, 'Jobfehler 7 Tage', 1, '', $fehler);

        $this->baender($wurzel);
        $this->wechsel($wurzel);
    }

    /**
     * Die Erreichbarkeitsbaender: je Host ein Streifen aus dreissig Tageszellen.
     *
     * Gerechnet wird ueber die TAGESAGGREGATION des Archivs, nicht ueber die Rohwerte.
     * Ein Tag mit zwei Messpunkten und ein Tag mit tausend muessen dasselbe Gewicht
     * haben; die zeitgewichtete Aggregation liefert genau das - den Anteil des Tages,
     * an dem der Host antwortete.
     *
     * Ein Tag ohne jede Aufzeichnung bleibt LEER statt gruen. Nicht gemessen ist nicht
     * dasselbe wie in Ordnung, und gerade am Anfang ist fast alles nicht gemessen.
     */
    private function baender(int $wurzel): void
    {
        $arch = px_archiv_instanz();
        $tage = 30;
        $bis  = strtotime('tomorrow') - 1;
        $von  = strtotime('today') - ($tage - 1) * 86400;

        $zeilen = [['Host', 'Art', 'Zustände', 'Kennzahl']];
        $neustarts = 0;
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $ist_pve = $this->lies($k, 'Gäste gesamt') !== null;
            $ist_pbs = $this->lies($k, 'Datastores')   !== null;
            if (!$ist_pve && !$ist_pbs) { continue; }

            // Ein Knoten, der weniger als dreissig Tage laeuft, ist in diesem Fenster
            // neu gestartet worden. Das ist keine Zaehlung der Neustarts, sondern der
            // KNOTEN mit Neustart - und genau so ist die Kachel beschriftet.
            $up = $this->lies($k, 'uptime');
            if (is_numeric($up) && (float) $up > 0 && (float) $up < $tage) { $neustarts++; }

            $vid = 0;
            foreach (IPS_GetChildrenIDs($k) as $c) {
                if (IPS_VariableExists($c) && IPS_GetName($c) === 'Erreichbar') { $vid = $c; break; }
            }
            $zellen = array_fill(0, $tage, '');
            $summe = 0.0; $gezaehlt = 0;
            if ($vid > 0 && $arch > 0 && @AC_GetLoggingStatus($arch, $vid)) {
                $w = @AC_GetAggregatedValues($arch, $vid, 1, $von, $bis, 0);
                foreach (is_array($w) ? $w : [] as $e) {
                    $i = (int) floor(((int) $e['TimeStamp'] - $von) / 86400);
                    if ($i < 0 || $i >= $tage) { continue; }
                    $a = (float) ($e['Avg'] ?? 0);
                    $zellen[$i] = ($a >= 0.999) ? 'ok' : (($a >= 0.9) ? 'warn' : 'fehler');
                    $summe += $a; $gezaehlt++;
                }
            }
            $zeilen[] = [
                IPS_GetName($k),
                $ist_pve ? 'pve' : 'pbs',
                implode(',', $zellen),
                $gezaehlt > 0 ? (number_format($summe / $gezaehlt * 100, 1, ',', '') . ' %') : '—',
            ];
        }
        $this->schreib($wurzel, 'Erreichbarkeitsband', 3, '', $this->tab($zeilen));
        $this->schreib($wurzel, 'Knoten mit Neustart', 1, '', $neustarts);
    }

    /**
     * Zustandswechsel: was sich seit dem letzten Lauf geaendert hat.
     *
     * Beobachtet werden drei Dinge je Host - ob er antwortet, wie seine Kachel steht und
     * ob eine Aufgabe zuletzt scheiterte. Der Vergleich braucht ein Gedaechtnis, und das
     * steht im Attribut, nicht im Baum: eine Variable traegt immer nur den JETZIGEN Wert,
     * ein Wechsel ist aber die Differenz zweier Zeitpunkte.
     *
     * Beim allerersten Lauf wird nichts gemeldet. Sonst stuenden dreissig Zeilen
     * "unbekannt -> erreichbar" in der Chronik, die kein Ereignis beschreiben, sondern
     * nur, dass hier gerade eingeschaltet wurde.
     */
    private function wechsel(int $wurzel): void
    {
        // Gedaechtnis im BAUM, nicht im Attribut. Ein Attribut waere der naheliegende Ort,
        // aber es entsteht nur beim Anlegen der Instanz - ein nachtraeglich ergaenztes
        // gibt es auf einer laufenden Anlage erst nach dem naechsten Neustart. Im Baum
        // steht es sofort und ueberlebt den Neustart ebenfalls.
        $roh   = $this->lies($wurzel, 'Chronik Stand');
        $stand = is_string($roh) ? json_decode($roh, true) : null;
        $erst  = !is_array($stand) || !count($stand);
        if (!is_array($stand)) { $stand = []; }
        $roh2  = $this->lies($wurzel, 'Chronik Wechsel');
        $liste = is_string($roh2) ? json_decode($roh2, true) : null;
        if (!is_array($liste)) { $liste = []; }

        $beob = [
            'Erreichbar'             => 'Erreichbarkeit',
            'Kachel Zustand'         => 'Zustand',
            'Letzter Aufgabenfehler' => 'Aufgabe',
        ];
        $neu = [];
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $host = IPS_GetName($k);
            foreach ($beob as $name => $titel) {
                $v = $this->lies($k, $name);
                if ($v === null) { continue; }
                $t = is_bool($v) ? ($v ? 'erreichbar' : 'stumm') : trim((string) $v);
                if ($t === '') { $t = '—'; }
                $t = mb_substr($t, 0, 42);
                $schl = $host . '|' . $name;
                $neu[$schl] = $t;
                if ($erst || !isset($stand[$schl]) || $stand[$schl] === $t) { continue; }
                $liste[] = [date('d.m. H:i'), $host, $titel . ': ' . $stand[$schl] . ' → ' . $t];
            }
        }
        $liste = array_slice($liste, -80);
        $this->schreib($wurzel, 'Chronik Stand',   3, '', (string) json_encode($neu,   JSON_UNESCAPED_UNICODE));
        $this->schreib($wurzel, 'Chronik Wechsel', 3, '', (string) json_encode($liste, JSON_UNESCAPED_UNICODE));

        $zeilen = [['Zeit', 'Host', 'Wechsel']];
        foreach (array_reverse($liste) as $z) { $zeilen[] = $z; }
        $this->schreib($wurzel, 'Zustandswechsel', 3, '', $this->tab($zeilen));
    }

    /** Eine Zeilentabelle so schreiben, wie das Tabellen-Widget sie liest. */
    private function tab(array $zeilen): string
    {
        return (string) json_encode($zeilen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Variable unter $eltern finden oder anlegen und schreiben. */
    private function schreib(int $eltern, string $name, int $typ, string $profil, $wert): void
    {
        $id = 0;
        foreach (IPS_GetChildrenIDs($eltern) as $c) {
            if (IPS_VariableExists($c) && IPS_GetName($c) === $name) { $id = $c; break; }
        }
        if ($id === 0) {
            $id = IPS_CreateVariable($typ);
            IPS_SetName($id, $name);
            IPS_SetParent($id, $eltern);
            if ($profil !== '') { @IPS_SetVariableCustomProfile($id, $profil); }
            px_archiv_anwenden(px_archiv_instanz(), $id, $name, $typ);
        }
        @SetValue($id, $wert);
    }

    /**
     * Dieselben Zahlen wie der Knotenvergleich, nur GEDREHT: Kennzahlen als Zeilen,
     * Knoten als Spalten.
     *
     * Der Unterschied ist nicht kosmetisch. Man vergleicht Knoten MITEINANDER - dafuer
     * muessen ihre Werte nebeneinander in einer Zeile stehen. Andersherum liest man je
     * Knoten eine Zeile und muss die Spalten im Kopf zusammensuchen.
     *
     * Ein Knoten, der nicht antwortet, bekommt eine LEERE Spalte statt seiner letzten
     * bekannten Werte - alte Zahlen sehen aus wie aktuelle und sind schlimmer als eine
     * Luecke.
     */
    private function matrix(int $wurzel): string
    {
        $knoten = [];
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            if ($this->lies($k, 'Gäste gesamt') === null) { continue; }
            $knoten[IPS_GetName($k)] = $k;
        }
        if (!$knoten) { return '[]'; }

        $zeilen = [
            ['CPU %',      'CPU',            0],
            ['RAM %',      'RAM used',       0],
            ['SWAP %',     'SWAP used',      0],
            ['Platte %',   'HDD used',       0],
            ['I/O-Wait %', 'I/O Wait',       0],
            // Die Einheit steht im Zeilennamen; in der Matrix darf sie NICHT noch einmal
            // als Spaltenzusatz gesetzt werden, sonst liest man '% %'.
            ['Last',       'Last 15 min',    2],
            ['Gäste',      'Gäste gesamt',   0],
            ['läuft',      'Gäste laufen',   0],
        ];
        $kopf = array_merge([''], array_keys($knoten));
        $tab  = [$kopf];
        foreach ($zeilen as [$titel, $feld, $dec]) {
            $r = [$titel];
            foreach ($knoten as $n => $k) {
                $erreichbar = $this->lies($k, 'Erreichbar');
                $v = $this->lies($k, $feld);
                $r[] = ($erreichbar === false || !is_numeric($v))
                    ? '—'
                    : number_format((float) $v, $dec, ',', '');
            }
            $tab[] = $r;
        }
        return json_encode($tab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Kennzahlen der Verdichtung: wie viel MEHR zugeteilt ist, als vorhanden.
     *
     * Ueberbuchung ist die eigentliche Frage einer Virtualisierung - nicht "wie voll ist
     * der Speicher", sondern "was passiert, wenn alle Gaeste gleichzeitig holen, was
     * ihnen versprochen wurde". Der Faktor steht deshalb vor der Belegung.
     */
    private function verdichtung(int $wurzel): void
    {
        $ramZu = 0.0; $ramDa = 0.0; $vcpu = 0; $kerne = 0;
        $belegt = 0.0; $gesamt = 0.0; $dedup = [];

        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $ram = $this->lies($k, 'RAM');            // GB des Knotens
            if (is_numeric($ram)) { $ramDa += (float) $ram; }
            $c = $this->lies($k, 'CPU Cores');
            if (is_numeric($c)) { $kerne += (int) $c; }

            // Gaeste: zugeteilter Speicher und vCPU. Sie liegen als Dummy-Instanzen
            // direkt unter dem Host oder als Kategorien unter 'Gäste'.
            $sammle = function (int $eltern) use (&$sammle, &$ramZu, &$vcpu) {
                foreach (IPS_GetChildrenIDs($eltern) as $c2) {
                    if (IPS_VariableExists($c2)) { continue; }
                    $m = $this->lies($c2, 'RAM max GB');
                    if (is_numeric($m)) { $ramZu += (float) $m; }
                    $cp = $this->lies($c2, 'cpus');
                    if (is_numeric($cp)) { $vcpu += (int) $cp; }
                    $sammle($c2);
                }
            };
            $sammle($k);

            // Sicherungsspeicher, JE HOST EINMAL.
            //
            // PBS meldet fuer jeden Datastore die Werte des darunterliegenden
            // DATEISYSTEMS. Galantine hat vier Datastores, und alle vier melden dieselben
            // 80.417 GB - wer sie addiert, kommt auf das Vierfache des vorhandenen
            // Platzes (gemessen 339 TB statt gut 100). Gleiche Gesamt/Frei-Paare zaehlen
            // deshalb nur einmal.
            //
            // Die Speicher der PVE-Knoten fehlen hier bewusst: der Sammler schreibt fuer
            // sie kein 'Gesamt', nur Belegung und Frei. Diese Kennzahl ist also die des
            // SICHERUNGSSPEICHERS, und so heisst sie auch.
            $gesehen = [];
            foreach (IPS_GetChildrenIDs($k) as $s2) {
                if (IPS_GetObject($s2)['ObjectType'] != 0) { continue; }
                $g = $this->lies($s2, 'Gesamt'); $f = $this->lies($s2, 'Frei');
                if (is_numeric($g) && is_numeric($f) && $g > 0) {
                    $schluessel = round((float) $g, 1) . '/' . round((float) $f, 1);
                    if (!isset($gesehen[$schluessel])) {
                        $gesehen[$schluessel] = true;
                        $gesamt += (float) $g; $belegt += ((float) $g - (float) $f);
                    }
                }
                $d = $this->lies($s2, 'Dedup-Faktor');
                if (is_numeric($d) && $d > 0) { $dedup[] = (float) $d; }
            }
        }

        if ($ramDa > 0) {
            $this->schreib($wurzel, 'RAM Überbuchung', 2, '', round($ramZu / $ramDa, 2));
            $this->schreib($wurzel, 'RAM Überbuchung Text', 3, '',
                round($ramZu) . ' von ' . round($ramDa) . ' GB zugeteilt');
        }
        if ($kerne > 0) {
            $this->schreib($wurzel, 'vCPU Überbuchung', 2, '', round($vcpu / $kerne, 1));
            $this->schreib($wurzel, 'vCPU Überbuchung Text', 3, '',
                $vcpu . ' vCPU auf ' . $kerne . ' Kerne');
        }
        if ($gesamt > 0) {
            $this->schreib($wurzel, 'Sicherungsspeicher belegt TB', 2, '', round($belegt / 1024, 1));
            $this->schreib($wurzel, 'Sicherungsspeicher Text', 3, '',
                'von ' . round($gesamt / 1024, 1) . ' TB · ' . round($belegt / $gesamt * 100) . ' %');
        }
        if ($dedup) {
            $this->schreib($wurzel, 'Dedup-Faktor gesamt', 2, '', round(array_sum($dedup) / count($dedup), 1));
            $this->schreib($wurzel, 'Dedup Text', 3, '', count($dedup) . ' Datastores gemittelt');
        }
    }

    /**
     * Zustandswort und zwei Kontextzeilen je Host.
     *
     * Die zweite Zeile ist VERAENDERLICH: laeuft alles rund, stehen dort die
     * Routinezahlen (CPU, RAM, Laufzeit). Greift eine Regel, steht dort stattdessen der
     * dringlichste Befund im Klartext. Eine Karte, die immer dasselbe zeigt, zwingt zum
     * Weiterklicken; eine, die sich nach der Lage richtet, beantwortet die Frage sofort.
     */
    private function kacheln(int $wurzel, array $zeilen): void
    {
        // Befunde nach Host gruppieren, dringlichster zuerst (die Liste ist bereits sortiert)
        $proHost = [];
        foreach ($zeilen as $z) { $proHost[$z[1]][] = $z; }

        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            $host = IPS_GetName($k);
            $err  = $this->lies($k, 'Erreichbar');
            if ($err === null) { continue; }

            $ist_pve = $this->lies($k, 'Gäste gesamt') !== null;
            $bef     = $proHost[$host] ?? [];
            $stufe   = 0; $zustand = 'ok'; $z1 = ''; $z2 = '';

            if ($err === false) {
                // Unbekannt, nicht kaputt: ein Knoten im Neustart ist kein Ausfall.
                $stufe = 3; $zustand = '?';
                $z1 = 'keine Antwort';
                $le = $this->lies($k, 'Letzte Abfrage');
                $z2 = is_numeric($le) && $le > 0
                    ? ('seit ' . date('H:i', (int) $le) . ' ohne Antwort')
                    : 'Zustand unbekannt';
            } else {
                $ernst = array_values(array_filter($bef, function ($z) {
                    return $z[0] === 'kritisch' || $z[0] === 'hoch';
                }));
                if ($ernst) {
                    // Zwei Stufen, zwei Woerter: die Kachel bindet auf DIESEN Text, nicht
                    // auf die Kennziffer - der assoc zeigt den Wert der Variablen gross und
                    // den zugeordneten Text nur als kleine Pille. Also muss der Wert selbst
                    // das Wort sein, und die Farbe kommt aus der Zuordnung darauf.
                    $stufe   = ($ernst[0][0] === 'kritisch') ? 2 : 1;
                    $zustand = ($stufe === 2) ? '!!' : '!';
                } else {
                    $stufe = 0; $zustand = 'ok';
                }

                if ($ist_pve) {
                    $g = (int) $this->lies($k, 'Gäste gesamt');
                    $l = (int) $this->lies($k, 'Gäste laufen');
                    $z1 = $g . ($g === 1 ? ' Gast · ' : ' Gäste · ') . $l . ' laufen';
                } else {
                    $ds = (int) $this->lies($k, 'Datastores');
                    $z1 = $ds . ' ' . ($ds === 1 ? 'Datastore' : 'Datastores');
                }

                if ($ernst) {
                    // Der dringlichste Befund, gekuerzt auf eine Zeile.
                    $z2 = $ernst[0][2] . ': ' . $ernst[0][3];
                    if (mb_strlen($z2) > 62) { $z2 = mb_substr($z2, 0, 60) . '…'; }
                } elseif ($ist_pve) {
                    $cpu = $this->lies($k, 'CPU'); $ram = $this->lies($k, 'RAM used');
                    $up  = $this->lies($k, 'uptime');
                    $t = [];
                    if (is_numeric($cpu)) { $t[] = 'CPU ' . round($cpu) . ' %'; }
                    if (is_numeric($ram)) { $t[] = 'RAM ' . round($ram) . ' %'; }
                    if (is_numeric($up))  { $t[] = round($up) . ' d'; }
                    $z2 = implode(' · ', $t);
                } else {
                    $a = $this->lies($k, 'Ältester Gast ohne Sicherung');
                    $z2 = is_numeric($a) ? ('älteste Sicherung ' . round($a, 1) . ' d') : 'Sicherungen frisch';
                }
            }

            $this->schreib($k, 'Kachel Zustand', 3, '', $zustand);
            $this->schreib($k, 'Kachel Stufe',   1, '', $stufe);
            $this->schreib($k, 'Kachel Zeile 1', 3, '', $z1);
            $this->schreib($k, 'Kachel Zeile 2', 3, '', $z2);
        }
    }

    /** Wert einer Variablen unter $eltern, oder null. */
    private function lies(int $eltern, string $name)
    {
        foreach (IPS_GetChildrenIDs($eltern) as $c) {
            if (IPS_VariableExists($c) && IPS_GetName($c) === $name) { return GetValue($c); }
        }
        return null;
    }

    /** Wann der Wert zuletzt geschrieben wurde - die Spalte 'seit'. */
    private function wann(int $eltern, string $name): string
    {
        foreach (IPS_GetChildrenIDs($eltern) as $c) {
            if (IPS_VariableExists($c) && IPS_GetName($c) === $name) {
                return date('H:i', IPS_GetVariable($c)['VariableUpdated']);
            }
        }
        return '';
    }

    private function hostPruefen(int $k, array &$zeilen): void
    {
        $host = IPS_GetName($k);
        $err  = $this->lies($k, 'Erreichbar');
        if ($err === null) { return; }             // keine vom Sammler betreute Kategorie

        if ($err === false) {
            // Alles Weitere waere veraltet - ein stummer Host wird EINMAL gemeldet und
            // nicht mit zwanzig Folgebefunden aus alten Werten zugedeckt.
            $zeilen[] = ['kritisch', $host, 'Abfrage', 'keine Antwort', $this->wann($k, 'Erreichbar')];
            return;
        }

        $hdd = $this->lies($k, 'HDD used');
        if (is_numeric($hdd) && $hdd >= 88) {
            $zeilen[] = [$hdd >= 95 ? 'kritisch' : 'hoch', $host, 'Systemplatte',
                         'Belegung ' . round($hdd, 1) . ' %', $this->wann($k, 'HDD used')];
        }
        // Ein PBS, der antwortet aber keinen Datastore zeigt, ist der gefaehrlichste Fall:
        // PBS liefert einem rechtelosen Token LEERE Listen statt eines 403. Die
        // Ueberwachung meldete dann 'null Probleme' und waere blind.
        $ds = $this->lies($k, 'Datastores');
        if ($ds !== null && (int) $ds === 0) {
            $zeilen[] = ['hoch', $host, 'Rechte',
                         'antwortet, zeigt aber keinen Datastore — Token ohne Datastore.Audit',
                         $this->wann($k, 'Datastores')];
        }
        $ram = $this->lies($k, 'RAM used');
        if (is_numeric($ram) && $ram >= 90) {
            $zeilen[] = ['hoch', $host, 'Arbeitsspeicher', 'Belegung ' . round($ram, 1) . ' %', $this->wann($k, 'RAM used')];
        }
        // Voller Auslagerungsspeicher ist ein Vorbote, kein Zustand: der Knoten laeuft
        // noch, wird aber bei der naechsten Anforderung zaeh. Gemessen hatte Majestix
        // 99,4 Prozent belegt, und niemandem war es aufgefallen.
        $sw = $this->lies($k, 'SWAP used');
        if (is_numeric($sw) && $sw >= 80) {
            $zeilen[] = [$sw >= 95 ? 'hoch' : 'mittel', $host, 'Auslagerungsspeicher',
                         'Belegung ' . round($sw, 1) . ' %', $this->wann($k, 'SWAP used')];
        }
        $tf = $this->lies($k, 'Aufgaben mit Fehler 24h');
        if (is_numeric($tf) && $tf > 0) {
            $zeilen[] = ['hoch', $host, 'Aufgaben', $tf . ' fehlgeschlagen in 24 h — '
                         . (string) $this->lies($k, 'Letzter Aufgabenfehler'), $this->wann($k, 'Aufgaben mit Fehler 24h')];
        }
        $zt = $this->lies($k, 'Zertifikat Resttage');
        if (is_numeric($zt) && $zt < 30) {
            $zeilen[] = [$zt < 7 ? 'kritisch' : 'mittel', $host, 'Zertifikat',
                         'läuft in ' . (int) $zt . ' Tagen ab', $this->wann($k, 'Zertifikat Resttage')];
        }
        $db = $this->lies($k, 'Datenträger mit Befund');
        if (is_numeric($db) && $db > 0) {
            $zeilen[] = ['hoch', $host, 'Datenträger', $db . ' mit SMART-Befund', $this->wann($k, 'Datenträger mit Befund')];
        }
        $ge = $this->lies($k, 'Gäste gestoppt');
        if (is_numeric($ge) && $ge > 0) {
            $zeilen[] = ['niedrig', $host, 'Gäste', $ge . ' gestoppt', $this->wann($k, 'Gäste gestoppt')];
        }
        $pf = $this->lies($k, 'Prüfaufträge im Fehler');
        if (is_numeric($pf) && $pf > 0) {
            $zeilen[] = ['kritisch', $host, 'Prüfauftrag', $pf . ' fehlgeschlagen', $this->wann($k, 'Prüfaufträge im Fehler')];
        }
        $af = $this->lies($k, 'Abgleichaufträge im Fehler');
        if (is_numeric($af) && $af > 0) {
            $zeilen[] = ['kritisch', $host, 'Abgleich', $af . ' fehlgeschlagen', $this->wann($k, 'Abgleichaufträge im Fehler')];
        }
        $ao = $this->lies($k, 'Abgleichaufträge');
        if ($ao !== null && (int) $ao === 0 && $this->lies($k, 'Prüfaufträge') !== null) {
            // Null sichtbare Auftraege ist eine AUSSAGE, kein leeres Feld: entweder gibt
            // es keine, oder der Token sieht sie nicht. Beides gehoert bemerkt.
            $zeilen[] = ['niedrig', $host, 'Abgleich', 'kein Abgleichauftrag sichtbar', $this->wann($k, 'Abgleichaufträge')];
        }
        $al = $this->lies($k, 'Ältester Gast ohne Sicherung');
        if (is_numeric($al) && $al > 2) {
            $zeilen[] = [$al > 7 ? 'kritisch' : 'hoch', $host, 'Sicherung',
                         'ältester Gast ' . round($al, 1) . ' Tage ohne Sicherung',
                         $this->wann($k, 'Ältester Gast ohne Sicherung')];
        }
        $gos = $this->lies($k, 'Gruppen ohne Sicherung');
        if (is_numeric($gos) && $gos > 0) {
            $zeilen[] = ['hoch', $host, 'Sicherung', $gos . ' Gruppen ohne jede Sicherung', $this->wann($k, 'Gruppen ohne Sicherung')];
        }

        // Datastores und Speicher eine Ebene tiefer.
        foreach (IPS_GetChildrenIDs($k) as $sub) {
            if (IPS_GetObject($sub)['ObjectType'] != 0) { continue; }
            $sn = IPS_GetName($sub);
            if ($sn === 'Gäste' || $sn === 'Datenträger') { continue; }
            $kinder = ($sn === 'Speicher') ? IPS_GetChildrenIDs($sub) : [$sub];
            foreach ($kinder as $sp) {
                $bel = $this->lies($sp, 'Belegung');
                if (is_numeric($bel) && $bel >= 85) {
                    $zeilen[] = [$bel >= 92 ? 'kritisch' : 'hoch', $host, IPS_GetName($sp),
                                 'Belegung ' . round($bel, 1) . ' %', $this->wann($sp, 'Belegung')];
                }
                $vw = $this->lies($sp, 'Verwaiste Gruppen');
                if (is_numeric($vw) && $vw > 0) {
                    // Bewusst 'niedrig': eine Gruppe ohne Gast altert ewig weiter. Als
                    // kritisch gemeldet stuende die Seite dauerhaft rot, ohne dass jemand
                    // etwas tun koennte ausser die Gruppe zu loeschen.
                    $zeilen[] = ['niedrig', $host, IPS_GetName($sp),
                                 $vw . ' verwaiste Sicherungsgruppen (Gast gibt es nicht mehr)',
                                 $this->wann($sp, 'Verwaiste Gruppen')];
                }
                $sf = $this->lies($sp, 'Sicherungen fehlerhaft');
                if (is_numeric($sf) && $sf > 0) {
                    $zeilen[] = ['kritisch', $host, IPS_GetName($sp),
                                 $sf . ' Sicherungen mit fehlgeschlagener Prüfung',
                                 $this->wann($sp, 'Sicherungen fehlerhaft')];
                }
                $su = $this->lies($sp, 'Sicherungen unverifiziert');
                $sg = $this->lies($sp, 'Sicherungen gesamt');
                if (is_numeric($su) && $su > 0 && is_numeric($sg) && $sg > 0) {
                    // Eine nie gepruefte Sicherung ist eine Vermutung, keine Sicherung.
                    $zeilen[] = [$su >= $sg ? 'hoch' : 'mittel', $host, IPS_GetName($sp),
                                 $su . ' von ' . $sg . ' Sicherungen nie geprüft',
                                 $this->wann($sp, 'Sicherungen unverifiziert')];
                }
                $ajs = $this->lies($sp, 'Alter jüngste Sicherung');
                if (is_numeric($ajs) && $ajs > 2) {
                    $zeilen[] = ['kritisch', $host, IPS_GetName($sp),
                                 'jüngste Sicherung ' . round($ajs, 1) . ' Tage alt', $this->wann($sp, 'Alter jüngste Sicherung')];
                }
            }
        }
    }

    /**
     * Der Knotenvergleich als zweite Tabelle. Dieselbe Ueberlegung wie bei den Befunden:
     * wer den Baum liest, kennt einen neuen Knoten sofort.
     */
    private function knotenvergleich(int $wurzel): string
    {
        $vgl = [['Knoten', 'CPU %', 'RAM %', 'Platte %', 'Last', 'Gäste', 'läuft', 'Tage']];
        foreach (IPS_GetChildrenIDs($wurzel) as $k) {
            if (IPS_GetObject($k)['ObjectType'] != 0) { continue; }
            if ($this->lies($k, 'Gäste gesamt') === null) { continue; }      // nur PVE-Knoten
            $f = function (string $n, int $d = 1) use ($k) {
                $v = $this->lies($k, $n);
                return is_numeric($v) ? number_format((float) $v, $d, ',', '') : '—';
            };
            $vgl[] = [IPS_GetName($k), $f('CPU'), $f('RAM used'), $f('HDD used'),
                      $f('Last 15 min', 2), (string) ($this->lies($k, 'Gäste gesamt') ?? '—'),
                      (string) ($this->lies($k, 'Gäste laufen') ?? '—'), $f('uptime')];
        }
        return json_encode($vgl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
