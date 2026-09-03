<?php

declare(strict_types=1);

/**
 * Nur-Lese-Zugriff auf Proxmox VE und Proxmox Backup Server.
 *
 * Bewusst OHNE jede schreibende Methode. Was es nicht gibt, kann nicht versehentlich
 * aufgerufen werden - und die Anlage will beobachten, nicht steuern: die Schreibseite der
 * alten Bibliothek (start_vm, stop_vm, clone_vm ...) lag jahrelang unbenutzt.
 *
 * Die Zugangsdaten kommen als Feld herein, nicht aus einer Datei. Im Modul sind sie
 * Properties; ein Skript kann dieselbe Klasse mit einem Feld aus seiner Zugangsdatei
 * bedienen. Damit hat die Klasse keinen Ort, den sie kennen muss.
 */
final class PxZugriff
{
    /** Sekunden bis die Verbindung stehen muss. */
    private const VERBIND = 3;
    /** Sekunden fuer die ganze Anfrage. Gemessen brauchen die Aufrufe 15 bis 360 ms. */
    private const GESAMT = 8;

    private string $adresse;
    private int    $port;
    private string $typ;      // pve | pbs
    private string $token;
    private string $benutzer;
    private string $realm;
    private string $passwort;

    /** Ticket der laufenden Instanz. PVE-Tickets gelten zwei Stunden. */
    private ?array $ticket = null;

    public function __construct(array $cfg)
    {
        $this->adresse  = (string) ($cfg['adresse']  ?? '');
        $this->typ      = (string) ($cfg['typ']      ?? 'pve');
        $this->port     = (int)    ($cfg['port']     ?? ($this->typ === 'pbs' ? 8007 : 8006));
        $this->token    = (string) ($cfg['token']    ?? '');
        $this->benutzer = (string) ($cfg['benutzer'] ?? '');
        $this->realm    = (string) ($cfg['realm']    ?? 'pve');
        $this->passwort = (string) ($cfg['passwort'] ?? '');
    }

    /** Antwortet der Port? Kurz gefragt, damit ein toter Host nichts kostet. */
    public function erreichbar(float $sek = 2.0): bool
    {
        if ($this->adresse === '') { return false; }
        $s = @fsockopen($this->adresse, $this->port, $e1, $e2, $sek);
        if ($s === false) { return false; }
        fclose($s);
        return true;
    }

    /**
     * Ein GET. Liefert IMMER ein Feld, nie eine Ausnahme:
     *   ['ok'=>bool, 'code'=>int, 'data'=>mixed, 'fehler'=>string]
     * Der Aufrufer soll den Fehlerfall behandeln, nicht daran sterben.
     */
    public function get(string $pfad): array
    {
        if (!$this->erreichbar()) {
            return ['ok' => false, 'code' => 0, 'data' => null, 'fehler' => 'Port stumm'];
        }
        $kopf = $this->kopf();
        if ($kopf === null) {
            return ['ok' => false, 'code' => 401, 'data' => null, 'fehler' => 'Anmeldung fehlgeschlagen'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://' . $this->adresse . ':' . $this->port . '/api2/json' . $pfad,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => self::VERBIND,
            CURLOPT_TIMEOUT        => self::GESAMT,
        ]);
        $roh  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($roh === false) {
            return ['ok' => false, 'code' => $code, 'data' => null, 'fehler' => 'curl: ' . $cerr];
        }
        // Der HTTP-Code wird VOR json_decode geprueft: PBS antwortet bei falscher
        // Anmeldung mit Klartext, und ein blindes json_decode macht daraus null -
        // der Aufrufer haelt das dann faelschlich fuer "leer" statt fuer "abgelehnt".
        if ($code !== 200) {
            return ['ok' => false, 'code' => $code, 'data' => null,
                    'fehler' => 'HTTP ' . $code . ' ' . substr(trim((string) $roh), 0, 120)];
        }
        $j = json_decode((string) $roh, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'code' => $code, 'data' => null, 'fehler' => 'keine gueltige JSON-Antwort'];
        }
        return ['ok' => true, 'code' => $code, 'data' => $j['data'] ?? null, 'fehler' => ''];
    }

    /** Nur die Nutzdaten, sonst null. */
    public function daten(string $pfad)
    {
        $r = $this->get($pfad);
        return $r['ok'] ? $r['data'] : null;
    }

    /** Der Authentifizierungskopf. null heisst: Anmeldung nicht moeglich. */
    private function kopf(): ?array
    {
        if ($this->token !== '') {
            // PVE trennt Name und Geheimnis mit '=', PBS mit ' ' und ':'.
            // Wer den PVE-Code kopiert, bekommt bei PBS ein 401 und sucht lange.
            return [$this->typ === 'pbs'
                ? 'Authorization: PBSAPIToken ' . $this->token
                : 'Authorization: PVEAPIToken=' . $this->token];
        }
        if ($this->typ !== 'pve') { return null; }   // PBS ohne Token geht nicht
        $t = $this->pveTicket();
        return $t === null ? null : ['Cookie: PVEAuthCookie=' . $t];
    }

    /** Ticket holen und behalten. Erneuert nach 90 Minuten, gueltig waeren 120. */
    private function pveTicket(): ?string
    {
        if ($this->ticket !== null && (time() - $this->ticket['zeit']) < 5400) {
            return $this->ticket['ticket'];
        }
        if ($this->benutzer === '' || $this->passwort === '') { return null; }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://' . $this->adresse . ':' . $this->port . '/api2/json/access/ticket',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['username' => $this->benutzer,
                                                        'password' => $this->passwort,
                                                        'realm'    => $this->realm]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => self::VERBIND,
            CURLOPT_TIMEOUT        => self::GESAMT,
        ]);
        $roh  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) { return null; }
        $j = json_decode((string) $roh, true);
        if (!isset($j['data']['ticket'])) { return null; }
        $this->ticket = ['ticket' => $j['data']['ticket'], 'zeit' => time()];
        return $this->ticket['ticket'];
    }
}
