# SymconProxmox

Drei Module fuer die Beobachtung der Proxmox-Umgebung. Rein lesend.

| Modul | Prefix | Aufgabe |
|---|---|---|
| ProxmoxNode | PXV | Ein Proxmox-VE-Knoten: Knotenwerte, Gaeste, Speicher, SMART, Zertifikat, Auftragsfehler |
| ProxmoxBackup | PXB | Ein Proxmox Backup Server: Datastores, Sicherungsgruppen, Pruef- und Abgleichauftraege |
| ProxmoxLagebild | PXL | Liest quer: Befundtabelle, Lage, Knotenvergleich, Archivpflege |


## Voraussetzungen

- IP-Symcon ab Kernel 7.1, PHP 8
- Ein erreichbarer Proxmox-Knoten (VE oder Backup Server) mit einem
  **API-Token**. Lesende Rechte genuegen - die Module schreiben nichts.

## Installation

Konsole → *Kern-Instanzen* → **Modules** → Hinzufuegen:

```
https://github.com/hoep/SymconProxmox
```

Danach je beobachtetem Knoten eine Instanz anlegen und das Token eintragen. Die
Abschnitte *Die Zugangsdaten* und *Takte* weiter unten sagen, was wohin gehoert.

## Lizenz

MIT - siehe `LICENSE`.

## Warum Module und nicht weiter Skripte

Nicht wegen der Abfrage - die kann ein Skript genauso. Wegen des **Timers**. Am
15.02.2026 wurden fuenf Zeitereignisse abgehakt, und ein halbes Jahr lang fiel niemandem
auf, dass Majestix und Asterix keine Werte mehr lieferten: rund 1.050 Variablen zeigten
Februarstaende, teils verlinkt in einer Ansicht. Ein Modultimer ist ein verstecktes
Objekt - er erscheint in der Baumansicht gar nicht und kann deshalb nicht versehentlich
weggeklickt werden.

Der zweite Grund ist PXL: die Frage *was ist gerade zu tun?* ist keine Frage an einen
Host, sondern an die Anlage. Sie braucht einen Ort, der quer liest - und genau einen.

## Rein lesend

Es gibt keine `RequestAction`, kein Starten, kein Stoppen, kein Klonen. Die Schreibseite
der alten Bibliothek (`start_vm`, `stop_vm`, `clone_vm` ...) lag jahrelang unbenutzt. Was
es nicht gibt, kann nicht versehentlich aufgerufen werden; wer es will, ruestet es bewusst nach.

## Spiegelbetrieb: das Ziel-Objekt

Jede Instanz hat eine Eigenschaft **Ziel**. Zeigt sie auf eine bestehende Kategorie, wird
per `SetValue` in die **vorhandenen Objekt-IDs** geschrieben. Damit bleiben Archivreihen
und alle Verknuepfungen der Visualisierung erhalten - die Seiten muessen nicht neu
gebunden werden. Leer oder 0 heisst: unter der Instanz selbst.

Gesucht wird ueber den **Namen**, nicht ueber einen Ident. Nur so werden die vorhandenen
Gast-Kategorien weiterbenutzt statt verdoppelt.

## Die Zugangsdaten

Das Geheimnis gehoert **nicht** in die Properties: die stehen in `settings.json` mit 0644
im Klartext. Stattdessen traegt man in **Zugang** den Namen aus
`scripts/proxmox.zugang.json` ein (0600, root - Symcon laeuft als root und darf sie
lesen). Adresse und Port kommen dann ebenfalls von dort, damit ein Umzug an EINER Stelle
gepflegt wird.

Die Felder Token/Benutzer/Passwort bleiben als Rueckfallweg bestehen; ein Name in
`Zugang` gewinnt.

### Token-Schreibweisen

PVE und PBS trennen unterschiedlich. Wer den PVE-Code kopiert, bekommt bei PBS ein 401
und sucht lange:

```
PVE:  Authorization: PVEAPIToken=benutzer@realm!name=geheimnis
PBS:  Authorization: PBSAPIToken benutzer@realm!name:geheimnis
```

### Rechte

Ein PBS-Token ohne ACL-Eintrag bekommt **kein 403**, sondern `200` mit einer **leeren
Liste**. Die Ueberwachung meldet dann *null Probleme* und ist blind. Deshalb zaehlt PXB
die sichtbaren Datastores mit und setzt Status 203, wenn es null sind; PXL macht daraus
einen Befund. Gemessen am 03.09.2026 traf das vier von vier PBS.

Noetig ist auf PBS die Rolle **Audit** auf Pfad `/` (mit Propagate), auf PVE genuegt
**PVEAuditor**.

## Archivierung

`libs/px-archiv.php` entscheidet je Variable, ob sie ins Archiv gehoert und mit welcher
Aggregation. Zaehler (`netin`, `netout`, `diskread`, `diskwrite`) bekommen
Aggregationstyp 1 - mit Standardaggregation waere der Tageswert der Mittelwert des
Zaehlerstandes, eine Zahl ohne Bedeutung. Unveraenderliches (Kernzahl, Kapazitaeten,
Verwaltungszeitstempel) und Text bleiben draussen.

Die Regel wird dort angewendet, wo die Variable **entsteht**. Vorher war die
Archivierung eine einmalige Handlung, und alles Spaetere fiel durch: gemessen am
03.09.2026 hatte Proxplex 59 Variablen und davon 0 archiviert, Falbala und Gutemine je
11 und davon 0. Ein neuer Knoten kam stumm ins Haus.

**Es wird nur eingeschaltet, nie ausgeschaltet.** `AC_SetLoggingStatus(..., false)`
loescht die aufgezeichnete Reihe - unwiderruflich, ohne Rueckfrage. Eine Regel, die sich
irrt, wuerde damit Jahre an Historie vernichten. Was faelschlich archiviert ist, meldet
PXL ins Log und laesst es liegen.

## Takte

Nicht alles kostet gleich viel, also fragt nicht alles gleich oft:

| Was | Takt | Warum |
|---|---|---|
| `/cluster/resources` | 60 s (min. 30) | eine Abfrage fuer Knoten, Gaeste und Speicher |
| Zaehler je Gast | 15 min | aendern sich sekuendlich; ein Minutenwert sagt nicht mehr |
| Last, Auftragsfehler, Zertifikat | 5 min | melden Stoerungen, liegen an eigenen Pfaden |
| SMART | 6 h | Platten altern nicht im Minutentakt, und die Abfrage weckt jede Platte |
| PBS-Gruppen, Pruef-/Abgleichauftraege | 15 min | teuerste Abfrage; eine Sicherung entsteht nicht im Minutentakt |
| Archivpflege | 1 h | |

Getaktet wird ueber eigene Zeitstempel (`RegisterAttributeInteger`), nicht ueber
`date('i') % n`: bei einem 30-Sekunden-Takt loest die Uhrzeitrechnerei jede Abfrage
doppelt aus.

## Ein Knoten im Neustart ist kein Fehler

Er wird vermerkt (`Erreichbar` = false), nicht gemeldet. Die alte Bibliothek gab
*Login to Proxmox Host failed.* aus - im 15-Sekunden-Takt, ueber die Dauer eines
Neustarts hinweg hunderte Logzeilen. Genau die Groesse `Erreichbar` fehlte beim Ausfall
ab Februar.

## Verwaiste Sicherungsgruppen

Eine Gruppe, deren Gast es nicht mehr gibt, altert ewig weiter. Als *kritisch* gemeldet
stuende die Seite dauerhaft rot, ohne dass jemand etwas tun koennte ausser die Gruppe zu
loeschen - deshalb *niedrig*, und getrennt gezaehlt.

Erkannt wird das ueber die VMIDs, die es auf dem zugehoerigen Knoten wirklich noch gibt.
Der Datastore heisst nach seinem Knoten (`Backup_Majestix`), und VMIDs sind nur **je
Cluster** eindeutig: eine globale Liste haette `vm/100` auf Obelix als Beleg dafuer
genommen, dass `vm/100` auf Majestix noch lebt. PXB liest die Liste aus dem Baum - die
Module reden nicht miteinander, der Baum ist die gemeinsame Sprache. Findet sich der
Knoten nicht, unterbleibt die Pruefung: lieber nicht pruefen als jede Gruppe fuer
verwaist erklaeren.

## Instanzen

Neun Hosts, also neun Instanzen plus eine PXL:

| Instanz | Typ | Zugang | Ziel |
|---|---|---|---|
| Majestix, Asterix, Obelix, VerleihNix, Proxplex | PXV | `majestix` ... | die bestehende Host-Kategorie |
| Zechine, Galantine, Falbala, Gutemine | PXB | `zechine` ... | die bestehende Host-Kategorie |
| Lagebild | PXL | — | `Hardware\Proxmox` |
