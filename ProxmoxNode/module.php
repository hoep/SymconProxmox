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
    /** Kuerzeste erlaubte Abfrage. Darunter belastet man den pvedaemon ohne Gewinn:
     *  pvestatd frischt seinen Zwischenspeicher ohnehin nur alle 10 Sekunden auf. */
    private const TAKT_MIN = 30;

    /** Zaehler werden nur im Viertelstundenraster geschrieben - sie aendern sich
     *  sekuendlich, und ein Minutenwert sagt nicht mehr als ein Viertelstundenwert. */
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

    /** Ein Abfragelauf. Oeffentlich, damit der Timer und ein Skript ihn aufrufen koennen. */
    public function Abfragen(): void
    {
        $px = $this->zugriff();
        $r  = $px->get('/cluster/resources');

        if (!$r['ok']) {
            // Ein Knoten im Neustart ist KEIN Fehler. Er wird vermerkt, nicht gemeldet -
            // genau diese Groesse fehlte beim Ausfall ab Februar.
            $this->SetValue('Erreichbar', false);
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
        }
        // Gefiltert wird ueber die STARTZEIT. Ein Auftrag, der um 02:00 beginnt und um
        // 03:40 scheitert, faengt weit vor jedem kurzen Fenster an - deshalb ein
        // Tagesfenster und zaehlen, statt Ereignisse abgreifen zu wollen.
        $tk = $px->daten('/nodes/' . rawurlencode($kname) . '/tasks?limit=200&errors=1&since=' . (time() - 86400));
        if (is_array($tk)) {
            $fehler = 0; $letzter = '';
            foreach ($tk as $t) {
                $s2 = (string) ($t['status'] ?? '');
                if ($s2 !== '' && $s2 !== 'OK') {
                    $fehler++;
                    if ($letzter === '') { $letzter = ($t['type'] ?? '?') . ' ' . substr($s2, 0, 40); }
                }
            }
            $this->schreib($ziel, 'Aufgaben mit Fehler 24h', 1, '', $fehler);
            $this->schreib($ziel, 'Letzter Aufgabenfehler',  3, '', $letzter !== '' ? $letzter : '—');
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
        $this->schreib($ziel, 'CPU Kerne',  1, '',        (int) ($n['maxcpu'] ?? 0));
        $this->schreib($ziel, 'uptime',     2, 'RDays',   round(((float) ($n['uptime'] ?? 0)) / 86400, 2));
    }

    private function gaesteSchreiben(int $ziel, array $gaeste): void
    {
        $zaehler = ((int) date('i') % self::ZAEHLER_RASTER) === 0;
        $laufen  = 0;
        foreach ($gaeste as $g) {
            $name = trim((string) ($g['name'] ?? ''));
            if ($name === '') { $name = 'vmid ' . ($g['vmid'] ?? '?'); }
            $ort = $this->kindNachName($ziel, $name);
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
            $this->schreib($ort, 'uptime',     2, 'RDays',    round(((float) ($g['uptime'] ?? 0)) / 86400, 3));
            $this->schreib($ort, 'id',         1, '',         (int) ($g['vmid'] ?? 0));
            if ($zaehler) {
                $this->schreib($ort, 'netin',     1, '', (int) ($g['netin']     ?? 0));
                $this->schreib($ort, 'netout',    1, '', (int) ($g['netout']    ?? 0));
                $this->schreib($ort, 'diskread',  1, '', (int) ($g['diskread']  ?? 0));
                $this->schreib($ort, 'diskwrite', 1, '', (int) ($g['diskwrite'] ?? 0));
            }
        }
        $this->schreib($ziel, 'Gäste gesamt',   1, '', count($gaeste));
        $this->schreib($ziel, 'Gäste laufen',   1, '', $laufen);
        $this->schreib($ziel, 'Gäste gestoppt', 1, '', count($gaeste) - $laufen);
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
            $this->schreib($ort, 'Aktiv',    0, '~Alert.Reversed', ((int) ($s['status'] ?? 0)) === 1);
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
