# Growin Stock Sync

Hält den Warenbestand der Grow-In-Produkte in WooCommerce aktuell und führt nebenbei
einen vollständigen Katalog aller Growin-Artikelnummern.

Aufgebaut wie die bereits laufenden Plugins *Bloomtech Stock Sync* und
*Spider Farmer Stock Sync*: eigener Menüpunkt unter WooCommerce, Cron-Lauf,
Zuordnung per Artikelnummer, Protokoll.

## Installation

1. ZIP im Backend unter *Plugins → Installieren → Plugin hochladen* einspielen und aktivieren.
2. *WooCommerce → Growin Bestand → Einstellungen* öffnen.
3. Bezugsquelle wählen (siehe unten) und speichern.
4. Auf dem Reiter *Übersicht* einmal **Jetzt synchronisieren** drücken.

Beim Aktivieren wird die Tabelle `{prefix}growin_catalog` angelegt und der Cron
mit dem eingestellten Intervall geplant (Standard: alle 6 Stunden).

## Die zwei Wege zur Preisliste

### Manueller Upload (Standard)

Die Datei aus dem Growin-Kundenbereich (`https://www.growin.de/lists/pricelist/?lang=de`)
herunterladen und auf dem Reiter *Übersicht* hochladen. Der Abgleich startet sofort.
Die Datei wird unter `wp-content/uploads/growin-stock-sync/` abgelegt und ist gegen
direkten Zugriff geschützt.

Dieser Weg funktioniert immer und ist der empfohlene Start.

### Automatischer Abruf mit Login

Die Preisliste liegt hinter dem Login, deshalb meldet sich das Plugin selbst an:

1. Login-URL, Benutzername und Passwort eintragen, für growin.de ist das
   `https://www.growin.de/customer/account/`.
2. *Ziel des Anmeldeformulars* und die beiden Feldnamen **leer lassen**.
3. **Verbindung testen** drücken.

Das Plugin sucht auf der Login-Seite selbst das Formular, das ein Passwortfeld enthält,
und übernimmt daraus die Ziel-Adresse, die Namen von Benutzer- und Passwortfeld sowie
alle versteckten Felder wie CSRF-Token. Andere Formulare auf der Seite, etwa die Suche,
werden dabei übergangen.

Das ist nötig, weil growin.de auf Magento läuft: Die Felder heißen dort nicht
`username` und `password`, sondern `login[username]` und `login[password]`, und das
Formular wird nicht an die Login-Seite geschickt, sondern an
`/customer/account/loginPost/`. Beides erkennt das Plugin von allein.

Die drei Felder sind nur dazu da, die Erkennung zu überstimmen, falls sie einmal
danebenliegt. *Zusätzliche Formularfelder* bleiben im Normalfall leer; feste Zusätze
kommen zeilenweise als `feldname=wert` hinein.

Nach dem Login wird einer Weiterleitung einmal von Hand gefolgt, damit die
Sitzungs-Cookies vollständig eingesammelt werden. Kommt statt der CSV wieder eine Seite
mit Passwortfeld zurück, sagt der Test das ausdrücklich; dann stimmen entweder die
Zugangsdaten nicht oder der Shop verlangt beim Login eine zusätzliche Prüfung wie ein
Captcha. In dem Fall bleibt der manuelle Upload der verlässliche Weg.

Schlägt der Abruf fehl, greift das Plugin auf die zuletzt gespeicherte Datei zurück
und schreibt den Fehlschlag ins Protokoll. Der Bestand läuft also nie ins Leere.

### Das Passwortfeld

Das Eingabefeld ist **nach dem Speichern immer leer**. Das ist Absicht: Das Passwort
wird nie in den Seitenquelltext geschrieben. Ob eines hinterlegt ist, steht direkt
darunter, zusammen mit der Zeichenzahl und dem Datum der letzten Änderung. Zum Ändern
einfach ein neues eintragen und speichern, ein leeres Feld lässt das bisherige stehen.
Über *Gespeichertes Passwort löschen* wird es wieder entfernt.

Standardmäßig liegt das Passwort im Klartext in der Datenbank (`wp_options`), damit der
geplante Lauf ohne Eingriff funktioniert. Wer das nicht möchte, trägt es stattdessen in
die `wp-config.php` ein:

```php
define( 'GROWIN_SYNC_USER', 'kundennummer' );
define( 'GROWIN_SYNC_PASSWORD', 'geheim' );
```

Ist die Konstante gesetzt, hat sie Vorrang, das Feld im Backend verschwindet und in der
Datenbank wird nichts abgelegt. Wer gar keine Zugangsdaten hinterlegen will, bleibt beim
manuellen Upload.

## Vorrang: Bloomtech geht immer vor Growin

Produkte, die der Bloomtech-Abgleich betreut, fasst dieses Plugin nicht an. So können
beide Lieferanten-Syncs nebeneinander laufen, ohne sich gegenseitig zu überschreiben.

Erkannt wird die Bloomtech-Hoheit ausschließlich über die Artikelnummer: Die SKU des
Produkts steht im Artikelkatalog des Bloomtech-Plugins (Tabelle `…_bloomtech_articles`,
Spalte `artnr`). Die Tabelle wird automatisch gesucht, es muss nichts eingetragen werden.

Ist der Bloomtech-Abgleich für ein einzelnes Produkt ausdrücklich ausgesetzt
(`_bloomtech_exclude` = yes), darf Growin dieses Produkt übernehmen.

Auf dem Reiter *Einstellungen* steht unter *Vorrang*, was konkret erkannt wurde, etwa
die gefundene Katalogtabelle und die Zahl der vom Bloomtech-Abgleich ausgenommenen Produkte. Im
Protokoll erscheint nach jedem Lauf, wie viele Produkte deswegen übersprungen wurden.

## Zuordnung zu den Produkten

Ausschließlich über die **Artikelnummer gegen die SKU** (Produkte und Varianten). Wer
im Shop ein Präfix verwendet, trägt es in den Einstellungen ein.

Die EAN oder andere Felder werden **nie** zur Zuordnung verwendet. Ein Artikel, dessen
Nummer nicht als SKU im Shop steht, wird nicht angefasst.

Produkte mit dem Häkchen *Growin-Abgleich aussetzen* (Produkt → Lagerbestand) werden
nie verändert.

## Bestandslogik

Growin liefert keine Stückzahlen, sondern fünf Stufen. Die Zuordnung ist frei
einstellbar, voreingestellt ist:

| Growin-Stufe | Lagerstatus | Ersatzmenge | Lieferrückstand |
|---|---|---|---|
| 1 – lieferbar | auf Lager | 25 | nein |
| 2 – geringer Bestand | auf Lager | 5 | nein |
| 3 – im Zulauf | auf Bestellung | 0 | ja, mit Hinweis |
| 4 – nicht auf Lager | nicht vorrätig | 0 | nein |
| 5 – Bestellware | auf Bestellung | 0 | ja, mit Hinweis |

**Warum Ersatzmengen?** Sobald ein Produkt in WooCommerce die eigene Bestandsverwaltung
nutzt, leitet WooCommerce den Lagerstatus aus der Menge ab; ein direkt gesetzter Status
würde beim Speichern wieder überschrieben. Deshalb setzt das Plugin für solche Produkte
eine Ersatzmenge, die die Growin-Stufe abbildet. Wer das nicht will, schaltet
*Ersatzmengen setzen* ab: Dann bleiben Produkte mit eigener Bestandsverwaltung
unverändert und werden im Protokoll als übersprungen gemeldet.

Artikel mit dem Status *Auslaufend* können ignoriert, nur gemeldet oder automatisch auf
*nicht vorrätig* gesetzt werden.

## Verkaufspreise

Standardmäßig aus. Eingeschaltet zieht das Plugin bei jedem Lauf den Verkaufspreis mit.

**Netto oder brutto?** In der Growin-Liste ist der *Endkundenpreis* ein Bruttopreis
inklusive Mehrwertsteuer, der *Händlerpreis* dagegen netto. Die Liste führt den
Steuersatz in einer eigenen Spalte mit (durchgehend 19 Prozent). Wie der Shop Preise
speichert, liest das Plugin aus der WooCommerce-Einstellung *Preise inklusive Steuer
eingeben* aus und rechnet entsprechend um. Für hanfjack.de heißt das: Der Shop speichert
netto, der Endkundenpreis wird also durch 1,19 geteilt.

| Growin Endkundenpreis (brutto) | in den Shop (netto) |
|---|---|
| 6,50 € | 5,46 € |
| 9,90 € | 8,32 € |
| 19,90 € | 16,72 € |
| 2.915,50 € | 2.450,00 € |

Einstellbar sind:

- **Preisquelle:** Endkundenpreis oder Händlerpreis.
- **Schreibweise der Quelle:** brutto oder netto, passend zur gewählten Quelle.
- **Aufschlag in Prozent:** wird nach der Steuerumrechnung angewendet. Für den
  Händlerpreis als Quelle ist ein Aufschlag praktisch Pflicht.
- **Sicherheitsgrenze:** Weicht der neue Preis stärker als der eingestellte Prozentwert
  vom bisherigen ab, wird er nicht übernommen, sondern im Protokoll gemeldet.
  Voreingestellt sind 60 Prozent. Das fängt Fälle ab, in denen die Liste eine andere
  Verpackungseinheit meint als das Produkt im Shop.
- **Produkte im Angebot:** bleiben voreingestellt unangetastet. Angebotspreise werden
  nie überschrieben.

Vor dem ersten scharfen Lauf lohnt der Testlauf-Modus: Er rechnet alles durch und
protokolliert, schreibt aber nichts.

## Was das Plugin nicht anfasst

- Angebotspreise
- Versandgewicht, Maße, Versandklasse
- Produkttexte, Bilder, Kategorien
- alle übrigen Metafelder, insbesondere das Mindestalter von Germanized

Optional mitgeschrieben werden nur zwei reine Referenzfelder:
`_growin_price_dealer` (Händler-EK netto) und `_growin_weight` (Gewicht laut Liste).
Beide dienen der Kalkulation und wirken sich nirgends automatisch aus.

Mit *Testlauf* lässt sich ein kompletter Durchgang fahren, der alles auswertet und
protokolliert, aber nichts schreibt.

## Katalog

Jeder Lauf schreibt alle Artikel der Liste in die Tabelle `{prefix}growin_catalog` -
auch die, die es im Shop noch nicht gibt. Der Reiter *Katalog* ist durchsuchbar nach
Artikelnummer, Name, EAN und Marke, filterbar nach Bestandsstufe und danach, ob ein
Produkt verknüpft ist. Damit lässt sich sehen, was Growin führt, was davon schon
angelegt ist und welche Gewichte, EANs und Einkaufspreise hinterlegt sind.

## Betrieb

- **Intervall:** alle 2, 4 oder 6 Stunden, zweimal täglich, täglich oder nur von Hand.
- **Zeitbudget:** Ein Lauf bricht nach der eingestellten Sekundenzahl sauber ab, der
  Rest folgt beim nächsten Durchgang. So läuft auch eine Liste mit über 8.000 Zeilen
  nicht in einen PHP-Timeout.
- **Protokoll:** Reiter *Protokoll* mit gelesenen Zeilen, Zuordnungen, Änderungen,
  Übersprungenem und allen Meldungen. Optional eine E-Mail bei Fehlern, höchstens
  einmal pro Tag.

## Format der Preisliste

CSV, Semikolon getrennt, Windows-1252 kodiert, Dezimalkomma. Das Plugin erkennt die
Kodierung selbst und rechnet nach UTF-8 um; Trennzeichen und Spaltenreihenfolge werden
anhand der Kopfzeile erkannt. Erforderlich sind nur die Spalten *Artikel-Nr.* und
*Bestand*, alles Weitere wird genutzt, wenn es vorhanden ist.

Kommen mehrere Zeilen mit derselben Artikelnummer vor (in der Liste vom September 2026
sind das 21 exakte Dubletten), wird die letzte verwendet und die Zusammenfassung im
Protokoll vermerkt.

## Deinstallation

Beim Löschen des Plugins verschwinden Einstellungen, Protokoll, Cron und die
zwischengespeicherte CSV. Die Katalogtabelle bleibt bewusst erhalten. Wer sie
ebenfalls entfernen will:

```sql
DROP TABLE {prefix}growin_catalog;
```
