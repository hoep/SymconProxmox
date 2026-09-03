<?php

declare(strict_types=1);

/**
 * Welche Proxmox-Variable ins Archiv gehoert - und mit welcher Aggregation.
 *
 * WARUM ES DIESE DATEI GIBT: die Archivierung war vorher eine einmalige Handlung. Ein
 * Durchlauf hat den damaligen Bestand eingerichtet, und alles, was danach entstand, fiel
 * durch. Gemessen am 03.09.2026: Proxplex 59 Variablen, davon 0 archiviert; Falbala und
 * Gutemine je 11, davon 0. Ein neuer Knoten kam also stumm ins Haus. Deshalb steht die
 * Entscheidung jetzt dort, wo die Variable ENTSTEHT, und nicht in einem Skript, das
 * jemand daran denken muesste erneut zu starten.
 *
 * NUR EINSCHALTEN, NIE AUSSCHALTEN. AC_SetLoggingStatus($ac, $id, false) loescht die
 * aufgezeichnete Reihe - unwiderruflich, ohne Rueckfrage. Eine Regel, die sich irrt,
 * wuerde damit Jahre an Historie vernichten. Also schaltet diese Datei ausschliesslich
 * ein; was faelschlich archiviert ist, wird GEMELDET und bleibt liegen, bis ein Mensch
 * entscheidet.
 */

if (!function_exists('px_archiv_regel')) {

    /**
     * @return array{0: bool, 1: int}  [archivieren?, Aggregationstyp 0=Standard 1=Zaehler]
     */
    function px_archiv_regel(string $name, int $typ): array
    {
        // Text laesst sich nicht sinnvoll aggregieren.
        if ($typ === 3) { return [false, 0]; }

        // Zaehler laufen monoton hoch. Mit Standardaggregation waere der Tageswert der
        // Mittelwert des Zaehlerstandes - eine Zahl ohne Bedeutung. Als Zaehler gerechnet
        // ist es der Zuwachs, also der tatsaechliche Verkehr.
        static $zaehler = ['netin', 'netout', 'diskread', 'diskwrite'];
        if (in_array($name, $zaehler, true)) { return [true, 1]; }

        // Unveraenderliches und Verwaltungskram. Eine Reihe aus immer demselben Wert
        // kostet Platz und sagt nichts; die Kapazitaet steckt ohnehin in der Prozentreihe.
        static $niemals = [
            'CPU Kerne', 'CPU Cores', 'CPU Threads', 'CPU MHz', 'CPU Modell',
            'id', 'Port', 'Aktiv', 'Zugang hinterlegt', 'Gäste VMIDs',
            'RAM GB', 'RAM max GB', 'RAM', 'HDD', 'SWAP', 'Gesamt', 'maxdisk', 'maxswap',
            'Letzte Abfrage', 'Letzte Sicherung', 'Token-Name', 'Geheimnis eintragen',
        ];
        if (in_array($name, $niemals, true)) { return [false, 0]; }

        // Alles Uebrige ist eine Groesse, die sich bewegt: Auslastungen, Fuellstaende,
        // Stueckzahlen, Alter, Zustaende. Genau daraus entsteht der Verlauf, den die
        // Seiten zeigen - und der die Frage 'seit wann eigentlich?' beantwortet.
        return [true, 0];
    }

    /**
     * Die Regel auf eine Variable anwenden. Gibt zurueck, was geschehen ist:
     * '' nichts noetig, 'ein' eingeschaltet, 'agg' Aggregation berichtigt,
     * 'ungefragt archiviert' die Variable ist archiviert, obwohl die Regel es nicht
     * vorsieht - bewusst NICHT abgeschaltet, siehe Kopf.
     */
    function px_archiv_anwenden(int $ac, int $vid, string $name, int $typ): string
    {
        if ($ac <= 0 || $vid <= 0) { return ''; }
        [$soll, $agg] = px_archiv_regel($name, $typ);
        $ist = @AC_GetLoggingStatus($ac, $vid);

        if (!$soll) {
            return $ist ? 'ungefragt archiviert' : '';
        }
        if (!$ist) {
            @AC_SetLoggingStatus($ac, $vid, true);
            @AC_SetAggregationType($ac, $vid, $agg);
            @AC_ReAggregateVariable($ac, $vid);
            return 'ein';
        }
        if ((int) @AC_GetAggregationType($ac, $vid) !== $agg) {
            // Der Typ laesst sich umstellen, ohne die Rohwerte anzutasten; die
            // Aggregate werden neu gerechnet. Die Historie bleibt also erhalten.
            @AC_SetAggregationType($ac, $vid, $agg);
            @AC_ReAggregateVariable($ac, $vid);
            return 'agg';
        }
        return '';
    }

    /** Die Archivinstanz, oder 0 wenn es keine gibt. */
    function px_archiv_instanz(): int
    {
        $l = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return $l ? (int) $l[0] : 0;
    }
}
