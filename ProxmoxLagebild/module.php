<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/px-archiv.php';

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

        $tab = array_merge([['Stufe', 'Host', 'Objekt', 'Befund', 'seit']], $zeilen);
        $this->SetValue('Befunde',      json_encode($tab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->SetValue('BefundeOffen', count($zeilen));
        $this->SetValue('Lage',         $lage);
        $this->SetValue('Knotenvergleich', $this->knotenvergleich($wurzel));

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
            $this->LogMessage('Archivpflege: ' . count($b['ungefragt'])
                              . ' Variablen sind archiviert, obwohl die Regel es nicht vorsieht — '
                              . 'NICHT abgeschaltet, das würde die Reihe löschen: '
                              . implode(', ', array_slice($b['ungefragt'], 0, 10)), KL_WARNING);
        }
        return $b;
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
