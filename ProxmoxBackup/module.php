<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/px-zugriff.php';
require_once __DIR__ . '/../libs/px-archiv.php';

/**
 * ProxmoxBackup (PXB) — ein Proxmox Backup Server.
 *
 * Die vier PBS dieser Anlage waren bisher nur zur Haelfte angebunden: zechine und galantine
 * ueber vier Skripte, die ihren Token per shell_exec('curl -H ...') durchreichten - dort
 * stand er fuer die Dauer des Aufrufs in der Prozessliste. falbala und gutemine waren
 * ueberhaupt nicht erfasst.
 *
 * Die eigentliche Aufgabe ist nicht der Platz, sondern die STILLE: ein Sicherungsauftrag,
 * der nicht mehr laeuft, meldet nichts. Deshalb fuehrt die Instanz je Datastore das Alter
 * der juengsten Sicherung - die einzige Groesse, die auffliegen laesst, dass seit Tagen
 * nichts mehr gesichert wurde.
 *
 * REIN LESEND. Kein Ausloesen von Jobs, kein Loeschen von Snapshots.
 */
class ProxmoxBackup extends IPSModule
{
    private const TAKT_MIN = 60;
    /** Snapshots sind der teuerste Endpunkt (eine Zeile je Sicherung). Nur stuendlich. */
    private const FRISCHE_RASTER = 60;

    /** Gruppen, Pruef- und Abgleichauftraege: alle 15 Minuten. Die Gruppenliste ist die
     *  teuerste Abfrage des Moduls, und eine Sicherung entsteht nicht im Minutentakt. */
    private const TIEF_TAKT = 900;

    /** Die geschuetzte Zugangsdatei. 0600 root - Symcon laeuft als root und darf sie lesen. */
    private const ZUGANGSDATEI = '/var/lib/symcon/scripts/proxmox.zugang.json';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Adresse', '');
        // 8007 ist der Normalfall. Hier laufen alle vier ueber einen Proxy auf 443 -
        // gemessen: 8007 ist von aussen auf keinem der vier offen.
        $this->RegisterPropertyInteger('Port', 8007);
        $this->RegisterPropertyString('Token', '');
        // Wie beim Knoten: das Geheimnis bleibt in der geschuetzten Datei (0600 root),
        // nicht in den Properties - die stehen in settings.json mit 0644 im Klartext.
        $this->RegisterPropertyString('Zugang', '');
        $this->RegisterPropertyInteger('Takt', 300);
        $this->RegisterPropertyInteger('Ziel', 0);
        $this->RegisterPropertyInteger('WarnAlterStunden', 48);

        $this->RegisterAttributeInteger('LetzteTiefe', 0);

        $this->RegisterTimer('Abfrage', 0, 'PXB_Abfragen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Erreichbar', 'Erreichbar', '~Alert.Reversed', 1);
        $this->RegisterVariableInteger('LetzteAbfrage', 'Letzte Abfrage', '~UnixTimestamp', 2);
        $this->RegisterVariableInteger('Datastores', 'Datastores', '', 3);
        $this->RegisterVariableBoolean('SicherungFrisch', 'Sicherungen frisch', '~Alert.Reversed', 4);

        // Ein Name in der Zugangsdatei genuegt - Adresse und Token kommen dann von dort.
        // Ein Abgleich nur gegen die Properties liesse den Timer sonst auf 0 stehen.
        $takt = max(self::TAKT_MIN, $this->ReadPropertyInteger('Takt'));
        $an   = trim($this->ReadPropertyString('Zugang')) !== ''
                || ($this->ReadPropertyString('Adresse') !== '' && $this->ReadPropertyString('Token') !== '');
        $this->SetTimerInterval('Abfrage', $an ? $takt * 1000 : 0);
        $this->SetStatus($an ? 102 : 104);
    }

    /** Alles in einem Lauf, ohne Ruecksicht auf den 15-Minuten-Takt der Gruppenabfrage. */
    public function Vollabfrage(): void
    {
        $this->WriteAttributeInteger('LetzteTiefe', 0);
        $this->Abfragen();
    }

    public function Abfragen(): void
    {
        $px = $this->zugriff();
        $r  = $px->get('/status/datastore-usage');

        if (!$r['ok']) {
            $this->SetValue('Erreichbar', false);
            $z = $this->ReadPropertyInteger('Ziel');
            if ($z > 0 && IPS_ObjectExists($z)) {
                $this->schreib($z, 'Erreichbar', 0, '~Alert.Reversed', false);
            }
            // 403 heisst hier fast immer: der Token hat keinen ACL-Eintrag. Das ist der
            // gefaehrlichste Fall, weil PBS dann LEERE Listen liefert statt eines Fehlers -
            // die Ueberwachung meldete 'null Probleme' und waere blind.
            $this->SetStatus($r['code'] === 401 || $r['code'] === 403 ? 201 : 202);
            return;
        }
        $this->SetValue('Erreichbar', true);
        $this->SetValue('LetzteAbfrage', time());
        $this->SetStatus(102);

        $ziel = $this->ReadPropertyInteger('Ziel');
        if ($ziel <= 0 || !IPS_ObjectExists($ziel)) { $ziel = $this->InstanceID; }

        if ($ziel !== $this->InstanceID) {
            $this->schreib($ziel, 'Erreichbar',     0, '~Alert.Reversed', true);
            $this->schreib($ziel, 'Letzte Abfrage', 1, '~UnixTimestamp',  time());
        }
        $stores = (array) $r['data'];
        $this->SetValue('Datastores', count($stores));
        // Auch im Ziel: die Befundtabelle liest die Zahl dort und macht aus einer Null
        // den Befund 'Token ohne Rechte'.
        if ($ziel !== $this->InstanceID) {
            $this->schreib($ziel, 'Datastores', 1, '', count($stores));
        }
        // Kein einziger Datastore trotz gueltiger Antwort ist der Riegel gegen den
        // blinden Zugang: ein Token ohne Rechte bekommt kein 403, sondern nichts.
        if (count($stores) === 0) { $this->SetStatus(203); }

        $tief = (time() - $this->ReadAttributeInteger('LetzteTiefe')) >= self::TIEF_TAKT;
        if ($tief) { $this->WriteAttributeInteger('LetzteTiefe', time()); }

        $aeltesteStd = 0.0; $ohneSicherung = 0;

        foreach ($stores as $d) {
            $name = trim((string) ($d['store'] ?? ''));
            if ($name === '') { continue; }
            $ort = $this->kindNachName($ziel, $name);
            $ges = max(1.0, (float) ($d['total'] ?? 1));
            $this->schreib($ort, 'Belegung', 2, 'Prozent', round(((float) ($d['used'] ?? 0)) / $ges * 100, 2));
            $this->schreib($ort, 'Frei',     2, 'GB',      round(((float) ($d['avail'] ?? 0)) / 1073741824, 1));
            $this->schreib($ort, 'Gesamt',   2, 'GB',      round($ges / 1073741824, 1));

            if (!$tief) { continue; }
            $this->gruppen($px, $ort, $name, $aeltesteStd, $ohneSicherung);
            $this->dedup($px, $ort, $name);
        }

        if ($tief) {
            $this->auftraege($px, $ziel);
            $this->schreib($ziel, 'Ältester Gast ohne Sicherung', 2, 'RDays', round($aeltesteStd / 24, 2));
            $this->schreib($ziel, 'Gruppen ohne Sicherung',       1, '',      $ohneSicherung);
            $frisch = ($aeltesteStd * 3600) <= $this->ReadPropertyInteger('WarnAlterStunden') * 3600;
            $this->SetValue('SicherungFrisch', $frisch);
            if ($ziel !== $this->InstanceID) {
                $this->schreib($ziel, 'Sicherungen frisch', 0, '~Alert.Reversed', $frisch);
            }
        }
    }

    /**
     * Die Gruppen eines Datastores: Anzahl, verwaiste, juengste und aelteste Sicherung.
     *
     * Gefragt wird nach GRUPPEN, nicht nach Snapshots: die Gruppenliste traegt
     * 'last-backup' und ist eine Zeile je Gast statt einer je Sicherung - gemessen 243
     * Snapshot-Zeilen gegen ein Dutzend Gruppen.
     */
    private function gruppen(PxZugriff $px, int $ort, string $store, float &$aeltesteStd, int &$ohneSicherung): void
    {
        $gr = $px->daten('/admin/datastore/' . rawurlencode($store) . '/groups');
        if (!is_array($gr)) { return; }

        // Der Datastore heisst nach seinem Knoten (Backup_Majestix ...). VMIDs sind nur JE
        // CLUSTER eindeutig - eine globale Liste haette vm/100 auf Obelix als Beleg dafuer
        // genommen, dass vm/100 auf Majestix noch lebt.
        $lebt = $this->lebendigeGaeste((string) preg_replace('/^Backup[_-]?/i', '', $store));

        $juengste = 0; $aelteste = 0; $anz = 0; $ohne = 0; $verwaist = 0;
        foreach ($gr as $g) {
            $anz++;
            $lb   = (int) ($g['last-backup'] ?? 0);
            $vmid = (int) ($g['backup-id']   ?? 0);
            // Eine Gruppe ohne Gast altert ewig weiter und faerbt die Lage dauerhaft rot,
            // ohne dass jemand etwas tun koennte ausser die Gruppe zu loeschen. Gemessen
            // waren es vm/9000, vm/9001, vm/9002 und vm/300 - letzte Sicherung 2023/2024.
            if ($vmid > 0 && count($lebt) && !isset($lebt[$vmid])) { $verwaist++; continue; }
            if ($lb <= 0) { $ohne++; continue; }
            if ($lb > $juengste) { $juengste = $lb; }
            $alt = time() - $lb;
            if ($alt > $aelteste) { $aelteste = $alt; }
        }
        $this->schreib($ort, 'Gruppen',           1, '', $anz);
        $this->schreib($ort, 'Verwaiste Gruppen', 1, '', $verwaist);
        if ($juengste > 0) {
            $this->schreib($ort, 'Letzte Sicherung',        1, '~UnixTimestamp', $juengste);
            $this->schreib($ort, 'Alter jüngste Sicherung', 2, 'RDays', round((time() - $juengste) / 86400, 2));
        }
        $std = round($aelteste / 3600, 2);
        $this->schreib($ort, 'Ältester Gast ohne Sicherung', 2, 'RDays', round($std / 24, 2));
        if ($std > $aeltesteStd) { $aeltesteStd = $std; }
        $ohneSicherung += $ohne;
    }

    /**
     * Welche VMIDs es auf einem Knoten wirklich noch gibt - gelesen aus dem, was die
     * PXV-Instanzen in den Baum geschrieben haben. Die Module reden nicht miteinander;
     * der Baum ist die gemeinsame Sprache. Findet sich der Knoten nicht, bleibt die
     * Liste leer und die Verwaisungspruefung unterbleibt - lieber nicht pruefen als
     * jede Gruppe fuer verwaist erklaeren.
     */
    private function lebendigeGaeste(string $knoten): array
    {
        $ids = [];
        foreach (IPS_GetInstanceListByModuleID('{2C9E5A47-8B31-4E62-9D0F-A47B15C3E8D2}') as $iid) {
            $ziel = (int) @IPS_GetProperty($iid, 'Ziel');
            if ($ziel <= 0 || !IPS_ObjectExists($ziel)) { $ziel = $iid; }
            if (mb_strtolower(IPS_GetName($ziel)) !== mb_strtolower($knoten)) { continue; }
            // PXV legt die Liste der vmids ab, die es im letzten Lauf WIRKLICH gab.
            // Den Baum abzusuchen waere naheliegend und falsch: die Dummy-Instanz eines
            // geloeschten Gastes bleibt mit ihrer alten id stehen: gemessen 8 statt 11
            // verwaisten Gruppen, weil drei geloeschte Gaeste als lebendig galten.
            foreach (IPS_GetChildrenIDs($ziel) as $c) {
                if (!IPS_VariableExists($c) || IPS_GetName($c) !== 'Gäste VMIDs') { continue; }
                foreach (explode(',', (string) GetValue($c)) as $st) {
                    $n = (int) trim($st);
                    if ($n > 0) { $ids[$n] = true; }
                }
            }
        }
        return $ids;
    }

    /** Der Deduplikationsfaktor - die Zahl, die zeigt, was der Speicher wirklich leistet. */
    private function dedup(PxZugriff $px, int $ort, string $store): void
    {
        $st = $px->daten('/admin/datastore/' . rawurlencode($store) . '/status');
        if (is_array($st) && isset($st['gc-status']['index-data-bytes'], $st['gc-status']['disk-bytes'])) {
            $db = max(1.0, (float) $st['gc-status']['disk-bytes']);
            $this->schreib($ort, 'Dedup-Faktor', 2, '',
                           round(((float) $st['gc-status']['index-data-bytes']) / $db, 2));
        }
    }

    /**
     * Pruef- und Abgleichauftraege. Die Zahl steht ausdruecklich da, auch wenn sie null
     * ist: null sichtbare Auftraege ist eine Aussage - entweder gibt es keine, oder der
     * Token sieht sie nicht -, kein leeres Feld.
     */
    private function auftraege(PxZugriff $px, int $ziel): void
    {
        $vf = $px->daten('/admin/verify');
        if (is_array($vf)) {
            $fehler = 0;
            foreach ($vf as $j) {
                $z = (string) ($j['last-run-state'] ?? '');
                if ($z !== '' && $z !== 'OK') { $fehler++; }
            }
            $this->schreib($ziel, 'Prüfaufträge',            1, '', count($vf));
            $this->schreib($ziel, 'Prüfaufträge im Fehler',  1, '', $fehler);
            $this->schreib($ziel, 'Prüfaufträge in Ordnung', 0, '~Alert.Reversed', $fehler === 0);
        }
        $sy = $px->daten('/admin/sync');
        if (is_array($sy)) {
            $fehler = 0;
            foreach ($sy as $j) { if (((string) ($j['last-run-state'] ?? 'OK')) !== 'OK') { $fehler++; } }
            $this->schreib($ziel, 'Abgleichaufträge',           1, '', count($sy));
            $this->schreib($ziel, 'Abgleichaufträge im Fehler', 1, '', $fehler);
        }
    }

    // ---------------------------------------------------------------- privat

    private function zugriff(): PxZugriff
    {
        $cfg = [
            'typ'     => 'pbs',
            'adresse' => $this->ReadPropertyString('Adresse'),
            'port'    => $this->ReadPropertyInteger('Port'),
            'token'   => $this->ReadPropertyString('Token'),
        ];
        $z = trim($this->ReadPropertyString('Zugang'));
        if ($z !== '' && is_readable(self::ZUGANGSDATEI)) {
            $alle = json_decode((string) @file_get_contents(self::ZUGANGSDATEI), true);
            if (isset($alle[$z]) && is_array($alle[$z])) {
                foreach (['adresse', 'port', 'token'] as $f) {
                    if (isset($alle[$z][$f]) && $alle[$z][$f] !== '') { $cfg[$f] = $alle[$z][$f]; }
                }
            }
        }
        return new PxZugriff($cfg);
    }


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
}
