<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/px-zugriff.php';
require_once __DIR__ . '/../libs/px-archiv.php';

/**
 * ProxmoxNode (PXV) — ein Proxmox-VE-Knoten.
 *
 * WARUM EIN MODUL UND NICHT WEITER SKRIPTE: nicht wegen der Abfrage - die kann ein Skript
 * genauso. Sondern wegen des TIMERS. Am 15.02.2026 wurden fuenf Ereignisse abgehakt, und
 * ein halbes Jahr lang fiel niemandem auf, dass Majestix und Asterix keine Werte mehr
 * liefern: rund 1.050 Variablen zeigten Februarstaende, teils verlinkt in einer Ansicht.
 * Ein Modultimer ist ein verstecktes Objekt - er erscheint in der Baumansicht gar nicht
 * und kann deshalb nicht versehentlich weggeklickt werden.
 *
 * REIN LESEND. Es gibt keine RequestAction, kein Starten, kein Stoppen. Die Schreibseite
 * der alten Bibliothek lag jahrelang unbenutzt; wer sie will, soll sie bewusst nachruesten.
 *
 * SPIEGELBETRIEB: geschrieben wird per SetValue in die BESTEHENDEN Objekt-IDs unter
 * Hardware\Proxmox. Damit bleiben 983 Archivreihen und alle Verknuepfungen der
 * Visualisierung erhalten. Eigene Variablen legt die Instanz nur zwei an - ihren eigenen
 * Zustand.
 */
class ProxmoxNode extends IPSModule
{
    /** Setzt Vollabfrage(), damit auch die Rasterbloecke einmal durchlaufen. */
    private bool $vollstaendig = false;

    /** Kuerzeste erlaubte Abfrage. Darunter belastet man den pvedaemon ohne Gewinn:
     *  pvestatd frischt seinen Zwischenspeicher ohnehin nur alle 10 Sekunden auf. */
    private const TAKT_MIN = 30;

    /**
     * Der ZAEHLER wird nur im Viertelstundenraster geschrieben. Er waechst monoton und
     * wird als Zaehler aggregiert; fuer den Tagesverbrauch genuegt eine grobe Stuetzstelle,
     * und im Minutentakt waeren es bei 81 Gaesten rund 466.000 Datensaetze am Tag.
     *
     * Fuer den DURCHSATZ gilt das Gegenteil, und diese Unterscheidung fehlte hier zuerst:
     * eine vierminuetige Sicherungsspitze um 03:00 verschwindet in einem
     * Viertelstundenmittel spurlos. Er hat deshalb seinen eigenen, feinen Takt
     * (Eigenschaft DurchsatzTakt, Vorgabe 120 s). Die alten Skripte rechneten alle 15 s.
     */
    private const ZAEHLER_RASTER = 15;

    /** Last, Auftragsfehler, Zertifikat: alle fuenf Minuten. */
    private const DETAIL_TAKT = 300;
    /** SMART: alle sechs Stunden. Platten altern nicht im Minutentakt, und die Abfrage
     *  weckt jede Platte einzeln. */
    private const DISK_TAKT = 21600;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Adresse', '');
        $this->RegisterPropertyInteger('Port', 8006);
        $this->RegisterPropertyString('Benutzer', '');
        $this->RegisterPropertyString('Realm', 'pve');
        $this->RegisterPropertyString('Passwort', '');
        $this->RegisterPropertyString('Token', '');
        // Das Geheimnis soll NICHT in den Properties landen: die stehen in settings.json,
        // 0644 und im Klartext. Steht hier ein Name, wird der Zugang aus der
        // geschuetzten Datei (0600 root) geholt und die Felder oben bleiben leer.
        $this->RegisterPropertyString('Zugang', '');
        $this->RegisterPropertyInteger('Takt', 60);
        // Wohin geschrieben wird. Leer = die Instanz legt ihre Werte unter sich selbst ab.
        $this->RegisterPropertyInteger('Ziel', 0);
        $this->RegisterPropertyBoolean('Gaeste', true);
        $this->RegisterPropertyBoolean('Speicher', true);
        // Durchsatz getrennt vom Zaehler takten - siehe zaehlerUndRate().
        $this->RegisterPropertyInteger('DurchsatzTakt', 120);
        // Letzter Zaehlerstand je Gast: [vmid => [zeit, netin, netout]]. Er steht bewusst
        // NICHT in der Zaehlervariablen, damit Durchsatz und Zaehler unabhaengig takten.
        $this->RegisterAttributeString('Zaehlerstand', '{}');

        $this->RegisterAttributeInteger('LetzteDetails', 0);
        $this->RegisterAttributeInteger('LetzteDatentraeger', 0);

        $this->RegisterTimer('Abfrage', 0, 'PXV_Abfragen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Erreichbar', 'Erreichbar', '~Alert.Reversed', 1);
        $this->RegisterVariableInteger('LetzteAbfrage', 'Letzte Abfrage', '~UnixTimestamp', 2);

        // Konfiguriert ist die Instanz, wenn ENTWEDER eine Adresse eingetragen ist ODER
        // ein Name in der Zugangsdatei steht - im zweiten Fall kommt die Adresse von
        // dort, und ein Abgleich nur gegen 'Adresse' liesse den Timer auf 0 stehen.
        $takt = max(self::TAKT_MIN, $this->ReadPropertyInteger('Takt'));
        $bereit = $this->ReadPropertyString('Adresse') !== '' || trim($this->ReadPropertyString('Zugang')) !== '';
        $this->SetTimerInterval('Abfrage', $bereit ? $takt * 1000 : 0);

        if (!$bereit) {
            $this->SetStatus(104);                      // Instanz nicht konfiguriert
            return;
        }
        $this->SetStatus(102);
    }

    /**
     * Alles in einem Lauf, ohne Ruecksicht auf die langsamen Takte. Fuer den ersten
     * Aufbau, den Knopf im Formular und den Abgleich gegen eine andere Quelle - dort
     * waere ein 'kommt in fuenf Minuten' als Lücke missdeutet worden.
     */
    public function Vollabfrage(): void
    {
        $this->WriteAttributeInteger('LetzteDetails', 0);
        $this->WriteAttributeInteger('LetzteDatentraeger', 0);
        $this->vollstaendig = true;
        try { $this->Abfragen(); } finally { $this->vollstaendig = false; }
    }

    /** Ein Abfragelauf. Oeffentlich, damit der Timer und ein Skript ihn aufrufen koennen. */
    public function Abfragen(): void
    {
        $px = $this->zugriff();
        $r  = $px->get('/cluster/resources');

        if (!$r['ok']) {
            // Ein Knoten im Neustart ist KEIN Fehler. Er wird vermerkt, nicht gemeldet -
            // genau diese Groesse fehlte beim Ausfall ab Februar.
            $this->SetValue('Erreichbar', false);
            $z = $this->ReadPropertyInteger('Ziel');
            if ($z > 0 && IPS_ObjectExists($z)) {
                $this->schreib($z, 'Erreichbar', 0, '~Alert.Reversed', false);
            }
            $this->SetStatus($r['code'] === 401 ? 201 : 202);
            return;
        }
        $this->SetValue('Erreichbar', true);
        $this->SetValue('LetzteAbfrage', time());
        $this->SetStatus(102);

        $ziel = $this->ReadPropertyInteger('Ziel');
        if ($ziel <= 0 || !IPS_ObjectExists($ziel)) { $ziel = $this->InstanceID; }

        $knoten = null; $gaeste = []; $speicher = [];
        foreach ((array) $r['data'] as $x) {
            switch ($x['type'] ?? '') {
                case 'node':    $knoten = $x;      break;
                case 'qemu':
                case 'lxc':     $gaeste[]   = $x;  break;
                case 'storage': $speicher[] = $x;  break;
            }
        }
        // Im Spiegelbetrieb muessen diese beiden auch im ZIEL stehen: die Seiten und die
        // Befundtabelle lesen sie dort. Stuenden sie nur an der Instanz, blieben die
        // Werte im Baum stehen - genau die Blindheit, die abgestellt werden soll.
        if ($ziel !== $this->InstanceID) {
            $this->schreib($ziel, 'Erreichbar',     0, '~Alert.Reversed', true);
            $this->schreib($ziel, 'Letzte Abfrage', 1, '~UnixTimestamp',  time());
        }
        if ($knoten !== null) { $this->knotenSchreiben($ziel, $knoten); }
        if ($this->ReadPropertyBoolean('Gaeste'))   { $this->gaesteSchreiben($ziel, $gaeste); }
        if ($this->ReadPropertyBoolean('Speicher')) { $this->speicherSchreiben($ziel, $speicher); }

        // /cluster/resources beschreibt nur den Betrieb. Was eine Stoerung MELDET -
        // Last, gescheiterte Auftraege, ablaufende Zertifikate, kranke Platten - liegt
        // an anderen Pfaden und kostet mehr. Deshalb ein eigener, langsamerer Takt.
        $kname = $this->knotenname($px);
        if ($kname !== '') {
            if ((time() - $this->ReadAttributeInteger('LetzteDetails')) >= self::DETAIL_TAKT) {
                $this->WriteAttributeInteger('LetzteDetails', time());
                $this->knotenDetails($px, $ziel, $kname);
            }
            if ((time() - $this->ReadAttributeInteger('LetzteDatentraeger')) >= self::DISK_TAKT) {
                $this->WriteAttributeInteger('LetzteDatentraeger', time());
                $this->datentraeger($px, $ziel, $kname);
            }
        }
    }

    // ---------------------------------------------------------------- privat

    /** Die geschuetzte Zugangsdatei. 0600 root - Symcon laeuft als root und darf sie lesen. */
    private const ZUGANGSDATEI = '/var/lib/symcon/scripts/proxmox.zugang.json';

    private function zugriff(): PxZugriff
    {
        $cfg = [
            'typ'      => 'pve',
            'adresse'  => $this->ReadPropertyString('Adresse'),
            'port'     => $this->ReadPropertyInteger('Port'),
            'benutzer' => $this->ReadPropertyString('Benutzer'),
            'realm'    => $this->ReadPropertyString('Realm'),
            'passwort' => $this->ReadPropertyString('Passwort'),
            'token'    => $this->ReadPropertyString('Token'),
        ];
        // Ein Name in 'Zugang' gewinnt. Adresse und Port kommen dann ebenfalls von dort,
        // damit ein Umzug an EINER Stelle gepflegt wird und nicht an zweien.
        $z = trim($this->ReadPropertyString('Zugang'));
        if ($z !== '' && is_readable(self::ZUGANGSDATEI)) {
            $alle = json_decode((string) @file_get_contents(self::ZUGANGSDATEI), true);
            if (isset($alle[$z]) && is_array($alle[$z])) {
                $h = $alle[$z];
                foreach (['adresse', 'port', 'benutzer', 'realm', 'passwort', 'token'] as $f) {
                    if (isset($h[$f]) && $h[$f] !== '') { $cfg[$f] = $h[$f]; }
                }
            }
        }
        return new PxZugriff($cfg);
    }

    /** Wie der Knoten bei sich selbst heisst. Ohne ihn geht kein /nodes/<name>/... Pfad. */
    private function knotenname(PxZugriff $px): string
    {
        $kn = $px->daten('/nodes');
        return (is_array($kn) && count($kn)) ? (string) ($kn[0]['node'] ?? '') : '';
    }

    /**
     * Last, gescheiterte Auftraege, Zertifikatsrestlaufzeit. Das sind die Groessen, die
     * eine Stoerung melden - CPU und RAM beschreiben nur.
     */
    private function knotenDetails(PxZugriff $px, int $ziel, string $kname): void
    {
        $st = $px->daten('/nodes/' . rawurlencode($kname) . '/status');
        if (is_array($st)) {
            $la = $st['loadavg'] ?? [];
            $this->schreib($ziel, 'Last 1 min',  2, '',         (float) ($la[0] ?? 0));
            $this->schreib($ziel, 'Last 15 min', 2, '',         (float) ($la[2] ?? 0));
            $this->schreib($ziel, 'I/O Wait',    2, 'Prozent',  round(((float) ($st['wait'] ?? 0)) * 100, 2));
            $this->schreib($ziel, 'Kernel',      3, '',         (string) ($st['kversion']   ?? ''));
            $this->schreib($ziel, 'PVE Version', 3, '',         (string) ($st['pveversion'] ?? ''));

            // Auslagerungsspeicher und Prozessorangaben. DIE NAMEN SIND ABSICHT: genau so
            // hiessen sie in den alten Knotenskripten, und nur unter demselben Namen wird
            // die vorhandene Variable samt Historie weiterbenutzt - bei 'SWAP used' sind
            // das drei Jahre. Der Sammler hat diese Werte nie geschrieben; die Luecke
            // fiel nur nicht auf, weil die alten Skripte noch nachliefen.
            $sw = $st['swap'] ?? null;
            if (is_array($sw)) {
                $sg = max(1.0, (float) ($sw['total'] ?? 1));
                $this->schreib($ziel, 'SWAP',      2, 'GB',      round($sg / 1073741824, 2));
                $this->schreib($ziel, 'SWAP frei', 2, 'GB',      round(((float) ($sw['free'] ?? 0)) / 1073741824, 2));
                $this->schreib($ziel, 'SWAP used', 2, 'Prozent', round(((float) ($sw['used'] ?? 0)) / $sg * 100, 2));
            }
            $ci = $st['cpuinfo'] ?? null;
            if (is_array($ci)) {
                // cores und cpus sind NICHT dasselbe: 14 Kerne, 20 Threads bei einem
                // i9-13900H. 'CPU Cores' haengt an der TileVisu-Seite (vier Links) und
                // muss deshalb unter genau diesem Namen weitergeschrieben werden.
                $this->schreib($ziel, 'CPU Cores',   1, '', (int) ($ci['cores'] ?? 0));
                $this->schreib($ziel, 'CPU Threads', 1, '', (int) ($ci['cpus']  ?? 0));
                // Als Text mit Einheit - so stand es bisher im Baum und so lesen es die Seiten.
                $this->schreib($ziel, 'CPU MHz',     3, '', ((string) ($ci['mhz'] ?? '')) . ' MHz');
                $this->schreib($ziel, 'CPU Modell',  3, '', (string) ($ci['model'] ?? ''));
            }
        }
        // Gefiltert wird ueber die STARTZEIT. Ein Auftrag, der um 02:00 beginnt und um
        // 03:40 scheitert, faengt weit vor jedem kurzen Fenster an - deshalb ein
        // Tagesfenster und zaehlen, statt Ereignisse abgreifen zu wollen.
        // Gefragt wird ueber 30 TAGE und ohne 'errors=1'. Der Fehlerzaehler braucht nur
        // einen Tag, die Chronik aber die ganze Geschichte - und das Aufgabenprotokoll
        // ist die einzige Quelle im Aufbau, die rueckwirkend etwas ueber die letzten
        // Wochen weiss. Zweimal fragen waere zweimal Kontingent fuer dieselbe Liste.
        $tk = $px->daten('/nodes/' . rawurlencode($kname) . '/tasks?limit=400&since=' . (time() - 30 * 86400));
        if (is_array($tk)) {
            $fehler = 0; $letzter = ''; $grenze = time() - 86400;
            $zeilen = [['Zeit', 'Host', 'Aufgabe', 'Objekt', 'Dauer', 'Ergebnis', 'Start']];
            foreach ($tk as $t) {
                $st = (int) ($t['starttime'] ?? 0);
                if ($st <= 0) { continue; }
                $en = (int) ($t['endtime'] ?? 0);
                $s2 = trim((string) ($t['status'] ?? ''));
                // Eine laufende Aufgabe hat weder Ende noch Ergebnis. Sie als Fehler zu
                // zaehlen waere falsch, sie zu verschweigen aber auch - im Protokoll
                // steht sie als 'laeuft'.
                if ($s2 === '') { $s2 = $en > 0 ? 'OK' : 'läuft'; }
                if ($s2 !== 'OK' && $s2 !== 'läuft' && $st >= $grenze) {
                    $fehler++;
                    if ($letzter === '') { $letzter = ($t['type'] ?? '?') . ' ' . substr($s2, 0, 40); }
                }
                $zeilen[] = [
                    date('d.m. H:i', $st),
                    $kname,
                    (string) ($t['type'] ?? '?'),
                    mb_substr((string) ($t['id'] ?? '—'), 0, 40),
                    $en > 0 ? $this->laufzeit($en - $st) : '—',
                    mb_substr($s2, 0, 40),
                    $st,
                ];
            }
            $this->schreib($ziel, 'Aufgaben mit Fehler 24h', 1, '', $fehler);
            $this->schreib($ziel, 'Letzter Aufgabenfehler',  3, '', $letzter !== '' ? $letzter : '—');
            $this->schreib($ziel, 'Aufgabenliste',           3, '', json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        }
        $ze = $px->daten('/nodes/' . rawurlencode($kname) . '/certificates/info');
        if (is_array($ze)) {
            $min = null;
            foreach ($ze as $c) {
                $na = (int) ($c['notafter'] ?? 0);
                if ($na > 0) { $t2 = (int) floor(($na - time()) / 86400); $min = ($min === null) ? $t2 : min($min, $t2); }
            }
            if ($min !== null) { $this->schreib($ziel, 'Zertifikat Resttage', 1, '', $min); }
        }
    }

    /**
     * SMART ueber die API - und damit ohne das SSH-Skript, das ein root-Passwort im
     * Klartext mitfuehrte.
     */
    private function datentraeger(PxZugriff $px, int $ziel, string $kname): void
    {
        $dl = $px->daten('/nodes/' . rawurlencode($kname) . '/disks/list');
        if (!is_array($dl)) { return; }
        $dkat = $this->kindNachName($ziel, 'Datenträger');
        $schlecht = 0;
        foreach ($dl as $d) {
            $dev = basename((string) ($d['devpath'] ?? ''));
            if ($dev === '') { continue; }
            $dd = $this->kindNachName($dkat, $dev);
            // wearout meldet den VERBLEIBENDEN Anteil (100 = neu). Das alte SSH-Skript
            // speicherte 'Percentage Used' (0 = neu) - die andere Richtung. Wer das
            // uebersieht, haelt eine frische Platte fuer verbraucht.
            $w = $d['wearout'] ?? null;
            if (is_numeric($w)) { $this->schreib($dd, 'Abnutzung', 2, 'Prozent', round(100 - (float) $w, 1)); }
            $gesund = ((string) ($d['health'] ?? '')) === 'PASSED';
            $this->schreib($dd, 'SMART in Ordnung', 0, '~Alert.Reversed', $gesund);
            $this->schreib($dd, 'Modell',           3, '',                (string) ($d['model'] ?? ''));
            if (!$gesund) { $schlecht++; }
        }
        $this->schreib($ziel, 'Datenträger mit Befund', 1, '', $schlecht);
    }

    private function knotenSchreiben(int $ziel, array $n): void
    {
        $maxmem = max(1.0, (float) ($n['maxmem']  ?? 1));
        $maxdsk = max(1.0, (float) ($n['maxdisk'] ?? 1));
        $this->schreib($ziel, 'CPU',        2, 'Prozent', round(((float) ($n['cpu'] ?? 0)) * 100, 2));
        $this->schreib($ziel, 'RAM used',   2, 'Prozent', round(((float) ($n['mem'] ?? 0)) / $maxmem * 100, 2));
        $this->schreib($ziel, 'HDD used',   2, 'Prozent', round(((float) ($n['disk'] ?? 0)) / $maxdsk * 100, 2));
        $this->schreib($ziel, 'RAM',        2, 'GB',      round($maxmem / 1073741824, 2));
        $this->schreib($ziel, 'RAM frei',   2, 'GB',      round(($maxmem - (float) ($n['mem'] ?? 0)) / 1073741824, 2));
        $this->schreib($ziel, 'HDD',        2, 'GB',      round($maxdsk / 1073741824, 2));
        $this->schreib($ziel, 'HDD frei',   2, 'GB',      round(($maxdsk - (float) ($n['disk'] ?? 0)) / 1073741824, 2));
        // KEIN 'CPU Kerne': der Wert war maxcpu, also die Zahl der THREADS unter einem
        // Namen, der Kerne verspricht - gemessen 20 statt 14 bei einem i9-13900H. Die
        // echten Kerne stehen als 'CPU Cores' in knotenDetails(), und genau die zeigt
        // die TileVisu-Seite an. Doppelt gefuehrt war nicht die Zahl, sondern der Irrtum.
        $this->schreib($ziel, 'uptime',     2, 'RDays',   round(((float) ($n['uptime'] ?? 0)) / 86400, 2));
    }

    private function gaesteSchreiben(int $ziel, array $gaeste): void
    {
        $zaehler = $this->vollstaendig || ((int) date('i') % self::ZAEHLER_RASTER) === 0;
        $laufen  = 0;
        $stand    = json_decode($this->ReadAttributeString('Zaehlerstand'), true);
        if (!is_array($stand)) { $stand = []; }
        $vollTakt = max(60, $this->ReadPropertyInteger('DurchsatzTakt'));
        // WO EIN GAST LIEGT, entscheidet der Bestand - nicht dieses Modul.
        // Die alten Skripte haben je Gast eine Dummy-INSTANZ direkt unter dem Host
        // angelegt. Wer stattdessen stur eine Kategorie unter 'Gäste' anlegt, verdoppelt
        // den ganzen Bestand und haengt die Archivhistorie ab - gemessen waeren es 646
        // neue Variablen gewesen. Also: erst nachsehen, ob es den Gast unter dem Host
        // schon gibt (Instanz ODER Kategorie), und nur sonst unter 'Gäste' neu anlegen.
        $gkat = 0;
        foreach ($gaeste as $g) {
            $name = trim((string) ($g['name'] ?? ''));
            if ($name === '') { $name = 'vmid ' . ($g['vmid'] ?? '?'); }
            $ort = 0;
            foreach (IPS_GetChildrenIDs($ziel) as $c) {
                if (IPS_GetName($c) !== $name) { continue; }
                if (IPS_InstanceExists($c) || IPS_GetObject($c)['ObjectType'] == 0) { $ort = $c; break; }
            }
            if (!$ort) {
                if (!$gkat) { $gkat = $this->kindNachName($ziel, 'Gäste'); }
                $ort = $this->kindNachName($gkat, $name);
            }
            $an  = (($g['status'] ?? '') === 'running');
            if ($an) { $laufen++; }
            $mm = max(1.0, (float) ($g['maxmem']  ?? 1));
            $md = max(1.0, (float) ($g['maxdisk'] ?? 1));
            $this->schreib($ort, 'status',     0, '~Switch',  $an);
            $this->schreib($ort, 'cpu',        2, 'Prozent',  round(((float) ($g['cpu'] ?? 0)) * 100, 2));
            $this->schreib($ort, 'RAM %',      2, 'Prozent',  round(((float) ($g['mem'] ?? 0)) / $mm * 100, 2));
            $this->schreib($ort, 'RAM GB',     2, 'GB',       round(((float) ($g['mem'] ?? 0)) / 1073741824, 2));
            $this->schreib($ort, 'RAM max GB', 2, 'GB',       round($mm / 1073741824, 2));
            $this->schreib($ort, 'disk%',      2, 'Prozent',  round(((float) ($g['disk'] ?? 0)) / $md * 100, 2));
            // Absolutwerte. Sie standen in den Altskripten und fehlten hier - nach dem
            // Abschalten waeren 296 Variablen eingefroren gewesen, ohne dass etwas
            // ausgefallen waere. Dieselbe Falle wie bei 'speed in'/'speed out'.
            $this->schreib($ort, 'disk',       1, '',         (int) ($g['disk']    ?? 0));
            $this->schreib($ort, 'maxdisk',    1, '',         (int) ($g['maxdisk'] ?? 0));
            $this->schreib($ort, 'cpus',       1, '',         (int) ($g['maxcpu']  ?? 0));
            $this->schreib($ort, 'uptime',     2, 'RDays',    round(((float) ($g['uptime'] ?? 0)) / 86400, 3));
            $this->schreib($ort, 'id',         1, '',         (int) ($g['vmid'] ?? 0));
            // 'speed in'/'speed out' sind ABGELEITET: der Zuwachs des Zaehlers geteilt
            // durch die verstrichene Zeit, in kB/s. Die alten Skripte haben sie gerechnet,
            // Sammler und Module nicht - und weil die Variablen an LEBENDEN Gaesten hingen,
            // war das keine Altlast, sondern eine Luecke: 71 Variablen mit 32 Monaten und
            // rund 6 GB Historie standen still, ohne dass etwas ausgefallen waere. Deshalb
            // hier weiterrechnen statt die Reihe umzuziehen - dieselbe Variable, dieselbe
            // ID, dieselbe Geschichte.
            $this->rate($ort, (int) ($g['vmid'] ?? 0), $stand, $vollTakt,
                        (int) ($g['netin'] ?? 0), (int) ($g['netout'] ?? 0));
            if ($zaehler) {
                $this->schreib($ort, 'netin',     1, '', (int) ($g['netin']     ?? 0));
                $this->schreib($ort, 'netout',    1, '', (int) ($g['netout']    ?? 0));
                $this->schreib($ort, 'diskread',  1, '', (int) ($g['diskread']  ?? 0));
                $this->schreib($ort, 'diskwrite', 1, '', (int) ($g['diskwrite'] ?? 0));
            }
        }
        // Die vmids, die es JETZT gibt - als Text, damit PXB die Verwaisungspruefung
        // darauf stuetzen kann. Den Baum abzusuchen genuegt NICHT: die Dummy-Instanz
        // eines geloeschten Gastes bleibt mit ihrer alten id stehen, und der Gast gilt
        // dann faelschlich als lebendig. Gemessen: 8 statt 11 verwaiste Gruppen.
        $vmids = [];
        foreach ($gaeste as $g) { $v = (int) ($g['vmid'] ?? 0); if ($v > 0) { $vmids[] = $v; } }
        sort($vmids);
        $this->schreib($ziel, 'Gäste VMIDs', 3, '', implode(',', $vmids));
        $this->schreib($ziel, 'Gäste gesamt',   1, '', count($gaeste));
        $this->schreib($ziel, 'Gäste laufen',   1, '', $laufen);
        $this->schreib($ziel, 'Gäste gestoppt', 1, '', count($gaeste) - $laufen);
        $this->WriteAttributeString('Zaehlerstand', json_encode($stand));
        if ($zaehler) { $this->containerSwap($ziel, $gaeste); }
    }

    /**
     * Auslagerungsspeicher der CONTAINER. Er steht nicht in /cluster/resources, sondern
     * nur in der lxc-Liste des Knotens - deshalb ein eigener Aufruf, und nur im groben
     * Raster. Virtuelle Maschinen haben keinen Wert dafuer; sie werden uebersprungen,
     * statt eine Null einzutragen, die wie 'kein Auslagerungsspeicher belegt' aussaehe.
     */
    private function containerSwap(int $ziel, array $gaeste): void
    {
        $px = $this->zugriff();
        $kname = $this->knotenname($px);
        if ($kname === '') { return; }
        $lxc = $px->daten('/nodes/' . rawurlencode($kname) . '/lxc');
        if (!is_array($lxc)) { return; }
        $ort = [];
        foreach ($gaeste as $g) { $ort[(int) ($g['vmid'] ?? 0)] = trim((string) ($g['name'] ?? '')); }
        foreach ($lxc as $c) {
            if (!isset($c['swap'], $c['maxswap'])) { continue; }
            $name = $ort[(int) ($c['vmid'] ?? 0)] ?? trim((string) ($c['name'] ?? ''));
            if ($name === '') { continue; }
            $o = 0;
            foreach (IPS_GetChildrenIDs($ziel) as $x) {
                if (IPS_GetName($x) !== $name) { continue; }
                if (IPS_InstanceExists($x) || IPS_GetObject($x)['ObjectType'] == 0) { $o = $x; break; }
            }
            if (!$o) { continue; }
            $mx = max(1.0, (float) $c['maxswap']);
            $this->schreib($o, 'swap',    1, '',        (int) $c['swap']);
            $this->schreib($o, 'maxswap', 1, '',        (int) $c['maxswap']);
            $this->schreib($o, 'swap%',   2, 'Prozent', round(((float) $c['swap']) / $mx * 100, 2));
        }
    }

    /**
     * Durchsatz aus dem Zaehlerzuwachs, in kB/s.
     *
     * Der letzte Stand steht in einem Attribut, NICHT in der Zaehlervariablen. Nur so
     * koennen Durchsatz (fein) und Zaehler (grob) unabhaengig takten - laege der Bezug
     * in der Variablen, waere die Rate zwangslaeufig an deren Viertelstundenraster
     * gebunden und jede kurze Spitze verloren.
     *
     * Ein Zaehler, der KLEINER geworden ist, bedeutet einen Neustart des Gastes. Dann
     * gibt es keine sinnvolle Rate: der Stand wird uebernommen und nichts geschrieben,
     * statt einen absurden Wert in die Reihe zu setzen.
     */
    private function rate(int $ort, int $vmid, array &$stand, int $takt, int $ein, int $aus): void
    {
        $jetzt = time();
        $k     = (string) $vmid;
        $vor   = $stand[$k] ?? null;
        $stand[$k] = [$jetzt, $ein, $aus];
        if (!is_array($vor) || count($vor) < 3) { return; }      // erster Lauf: nur merken

        $dt = $jetzt - (int) $vor[0];
        if ($dt < $takt) { $stand[$k] = $vor; return; }          // noch nicht faellig
        if ($dt <= 0) { return; }
        if ($ein >= (int) $vor[1]) {
            $this->schreib($ort, 'speed in',  2, 'kBs', round(($ein - (int) $vor[1]) / 1024.0 / $dt, 3));
        }
        if ($aus >= (int) $vor[2]) {
            $this->schreib($ort, 'speed out', 2, 'kBs', round(($aus - (int) $vor[2]) / 1024.0 / $dt, 3));
        }
    }

    private function speicherSchreiben(int $ziel, array $speicher): void
    {
        $kat = $this->kindNachName($ziel, 'Speicher');
        foreach ($speicher as $s) {
            $name = trim((string) ($s['storage'] ?? ''));
            if ($name === '') { continue; }
            $ort = $this->kindNachName($kat, $name);
            $mx  = max(1.0, (float) ($s['maxdisk'] ?? 1));
            $this->schreib($ort, 'Belegung', 2, 'Prozent', round(((float) ($s['disk'] ?? 0)) / $mx * 100, 2));
            $this->schreib($ort, 'Frei',     2, 'GB',      round(($mx - (float) ($s['disk'] ?? 0)) / 1073741824, 1));
            // PVE meldet den Zustand mal als 1, mal als 'available'. Nur auf die Zahl zu
            // pruefen ergab bei ALLEN Speichern faelschlich 'nicht aktiv' - gemessen im
            // Vergleich gegen den Sammler.
            $this->schreib($ort, 'Aktiv',    0, '~Alert.Reversed',
                           ((int) ($s['status'] ?? 0)) === 1 || ($s['status'] ?? '') === 'available');
        }
    }

    /**
     * Kind mit diesem Namen finden oder als Kategorie anlegen.
     * Ueber den NAMEN, nicht ueber einen Ident: nur so werden die vorhandenen
     * Gast-Instanzen samt ihrer Archivhistorie weiterbenutzt statt verdoppelt.
     */
    private function kindNachName(int $eltern, string $name): int
    {
        foreach (IPS_GetChildrenIDs($eltern) as $c) {
            if (IPS_GetName($c) === $name) { return $c; }
        }
        $id = IPS_CreateCategory();
        IPS_SetName($id, $name);
        IPS_SetParent($id, $eltern);
        return $id;
    }

    /** Variable finden oder anlegen und schreiben. Profil nur setzen, wenn keines da ist. */
    /**
     * Laufzeit einer Aufgabe als m:ss bzw. h:mm - dieselbe Schreibweise wie in der
     * Proxmox-Oberflaeche, damit man beides nebeneinander lesen kann.
     */
    private function laufzeit(int $sek): string
    {
        if ($sek < 0) { $sek = 0; }
        if ($sek < 3600) { return sprintf('%d:%02d', intdiv($sek, 60), $sek % 60); }
        return sprintf('%d:%02d', intdiv($sek, 3600), intdiv($sek % 3600, 60));
    }

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
            // Genau hier fehlte es bisher. Die Archivierung war eine einmalige Handlung,
            // und was danach entstand, fiel durch: gemessen am 03.09.2026 hatte Proxplex
            // 59 Variablen und davon 0 archiviert. Jetzt entscheidet die Regel dort, wo
            // die Variable entsteht - ein neuer Knoten kommt nicht mehr stumm ins Haus.
            px_archiv_anwenden(px_archiv_instanz(), $id, $name, $typ);
        } elseif ($profil !== '') {
            $v = IPS_GetVariable($id);
            if ($v['VariableCustomProfile'] === '' && $v['VariableProfile'] === '') {
                @IPS_SetVariableCustomProfile($id, $profil);
            }
        }
        @SetValue($id, $wert);
    }
}
