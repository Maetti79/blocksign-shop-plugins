# BlockSign eSign für PrestaShop

[Zur Projektübersicht](../../README.md)

## Installation und Betrieb

1. Erstelle die Modul-ZIP mit `scripts/build-prestashop.sh` oder lade das Artefakt aus GitHub Actions herunter. Installiere darin `blocksign-esign-prestashop.zip` über den Modulmanager eines **PrestaShop-9-Testshops**.
2. Lege ein einzelnes virtuelles Produkt mit gewünschtem Preis an. Hinterlege dafür weder einen PrestaShop-Download noch einen öffentlich erreichbaren Anhang. Wähle den Produktbereich in der Modulkonfiguration.
3. Lege `agreement.pdf` und `download.pdf` in einem privaten Verzeichnis außerhalb des Webroots ab. Setze `BLOCKSIGN_ESIGN_FILES` auf diesen absoluten Pfad, sowohl für den Web-PHP-Prozess als auch für den Worker. Wähle in der Modulkonfiguration außerdem Signaturniveau, PDF-Seite und Position.
4. Stelle dem Worker `BLOCKSIGN_ESIGN_API_KEY` über die Secret-Konfiguration des Servers bereit. Lasse jede Minute `php /pfad/zum/shop/modules/blocksignesign/worker.php` unter dem Shop-Benutzer laufen. Das CLI-PHP benötigt cURL.

PrestaShop zeigt den Statuslink auf der Bestellbestätigung und in der Bestellansicht. Gäste können den Link auch über die PrestaShop-Gastbestellverfolgung aufrufen. Ein Gastlink enthält einen vertraulichen, zufälligen Schlüssel pro Bestellung. Kunden mit Konto müssen zusätzlich angemeldet sein. Der Modul-Endpunkt prüft Bestellung, Schlüssel, Signaturstatus und Dateihash bei jedem Download. Wiederholte Bestellmeldungen behalten den vorhandenen Vorgang bei.

Der Worker verarbeitet bis zu 20 fällige Vorgänge pro Lauf. Er lädt die Erklärung hoch, sendet die BlockSign-Einladung und fragt den Status bis zu sieben Tage ab. Bei einem unklaren Ergebnis der Erstellung hält er den betroffenen Vorgang an und protokolliert den Fall. Ersetze die privaten Pilotdateien bei offenen oder weiterhin abrufbaren Bestellungen nur durch Dateien mit identischem Inhalt. Deaktivieren oder Deinstallieren des Moduls unterbricht den Zugriff. Beim Deinstallieren bleibt die Statustabelle für bestehende Nachweise erhalten.

## Produktzuordnung

Unter **Protected products** wählst du für welche Produkte die Einstellung gilt.

- **Single product** verwendet die bestehende Produkt-ID. Diese Auswahl gilt beim Update automatisch weiter. Mit Produkt-ID 0 werden in diesem Modus keine neuen Vorgänge angelegt.
- **Product category** verwendet die eingegebene Kategorie-ID. Berechtigt sind geeignete virtuelle Produkte, die direkt dieser Kategorie zugeordnet sind.
- **Products marked on their edit screen** verwendet die Checkbox **Require BlockSign eSign** unter **Optionen** des PrestaShop-Produkteditors. Wähle bei Multishop zuerst den einzelnen Shop im Backoffice aus.
- **All eligible virtual products** gilt für geeignete virtuelle Produkte im ganzen Shop. Produkte mit einem aktiven eigenen Shop-Download bleiben außerhalb dieser Auswahl.

Die Modulkonfiguration gilt für alle erfassten Produkte. Das erste Pflichtdokument und der private Download können je Produkt abweichen. Bestellungen können geschützte und ungeschützte Produkte kombinieren. Der Shop zeigt für jedes geschützte Produkt einen eigenen Downloadlink, sobald alle Pflichtdokumente der Bestellung bestätigt sind. Identische Dokumente mit identischen Signaturparametern werden pro Bestellung einmal angefordert. Änderungen der Zuordnung gelten für neue Bestellungen.

## Eigene PDFs je Produkt

Trage im Produkteditor bei **BlockSign primary agreement PDF** und **BlockSign protected download PDF** die jeweiligen Dateinamen ein. Die Dateien müssen im privaten BlockSign-Verzeichnis liegen. Leere Felder verwenden `agreement.pdf` und `download.pdf`. Die Signaturposition des ersten Dokuments und zusätzliche Pflichtdokumente stammen aus der Modulkonfiguration.

Neue Bestellungen speichern die Dateinamen und Dateihashes. Lass die zugehörigen Dateien für bestehende Bestellungen unverändert verfügbar. Für einen neuen Inhalt verwende einen neuen Dateinamen.

## Signaturniveau und Position

Das Modul bietet EES, FES und QES an. X und Y werden in PDF-Einheiten vom linken bzw. oberen Seitenrand gemessen. Standard ist EES auf Seite 1 bei X/Y = 50/50. Die Bestellung speichert die gewählten Werte. Ältere Bestellungen behalten ihre bisherigen Standardwerte. Welche Signaturstufen genutzt werden können, hängt vom BlockSign-Konto ab.

## Mehrere Pflichtdokumente

Standardmäßig verwendet das Modul `agreement.pdf` als erstes Pflichtdokument. Hinterlege weitere PDF-Dateien im selben privaten Verzeichnis und trage sie unter **Additional required PDFs** ein, eine pro Zeile.

```text
mandate.pdf | EES | 1 | 50 | 50
terms.pdf | FES | 2 | 12.5 | 40
```

Die Felder geben Dateiname, Signaturniveau, PDF-Seite und X/Y-Koordinaten an. Verwende eindeutige PDF-Dateinamen ohne Verzeichnispfad. `agreement.pdf` und `download.pdf` sind reserviert. Die Bestellung speichert die Dokumentliste, die Signaturparameter und die Dateihashes beim Kauf. Bestehende Bestellungen behalten ihre bisherigen Anforderungen.

Für zusätzliche Dokumente einer Produktkategorie nutze **Additional PDFs by product category**. Setze die Kategorie-ID an den Zeilenanfang, zum Beispiel `21 | category-terms.pdf | FES | 1 | 50 | 50`. Bei einem geschützten Produkt gelten die Dokumente seiner direkt zugeordneten Kategorien zusätzlich zu den shopweiten Dokumenten. Identische Dokumente mit identischen Einstellungen werden zusammengeführt.

Der Worker startet das nächste Dokument, nachdem BlockSign das vorherige bestätigt hat. Der Shop gibt den Download erst frei, wenn alle Pflichtdokumente unterschrieben sind. Ein abgebrochener oder abgelaufener Vorgang hält die Freigabe gesperrt.

## Signatur nach Zahlung

Hat die Bestellung einen positiven Gesamtbetrag, wartet der Shop auf die Zahlungsbestätigung. Bei 0 € bleibt der bestehende Ablauf erhalten. Verwende für bezahlte Produkte eine Zahlungsart, die erst nach Zahlungseingang einen bezahlten Status setzt. Die Standard-Nachnahme wird für diese Downloads nicht unterstützt und sollte für das Produkt im Shop deaktiviert sein.

PrestaShop verarbeitet eine bezahlte Bestellung ab dem nächsten Worker-Lauf, sobald der aktuelle Bestellstatus als bezahlt gilt. Stornierte und erstattete Bestellungen sowie Positionen mit erstatteter Stückzahl bleiben gesperrt.

## Elektronischer Zustellnachweis

Wähle **Proof of delivery** für das erste Pflichtdokument. Für ein zusätzliches PDF verwende die Kurzform `mitteilung.pdf | POD`. Bei einer Kategorie stellst du ihre ID voran, zum Beispiel `21 | mitteilung.pdf | POD`. BlockSign versendet das Dokument zur Empfangsbestätigung. Für diesen Vorgang sind keine Signaturposition oder Signaturstufe erforderlich.

Der Shop wartet auf die Bestätigung für genau dieses Dokument und diesen Empfänger. Eine versendete Einladung genügt für die Freigabe nicht. Ist die private Archivierung aktiviert, speichert das Modul das zugestellte PDF mit dem von BlockSign eingebetteten Nachweis als `<document-id>.delivery.pdf`. Der Ablauf ist mit simulierten API-Antworten geprüft. Der Test mit einem echten BlockSign-Konto steht noch aus.

## Signierte PDFs und Audit-Trails archivieren

Lege für die optionale Archivierung ein separates, privates Verzeichnis außerhalb aller Webroots an. Setze `BLOCKSIGN_ESIGN_ARCHIVE` in der Umgebung des CLI-Workers auf den absoluten Pfad. Der Worker-Benutzer benötigt dort Schreibzugriff.

Nach Bestätigung aller Pflichtsignaturen ruft der Hintergrundjob pro BlockSign-Vorgang das signierte PDF und den Audit-Trail ab. Er speichert sie im Unterordner `prestashop-<shop-id>-<bestell-id>` als `<request-id>.pdf` und `<request-id>.audit.json`. Das Limit für ein signiertes PDF beträgt 16 MiB. Die Dateien sind über den Server verfügbar. Ein Fehler bei der Archivierung wird protokolliert und lässt die normale Downloadfreigabe unberührt. Abgeschlossene Bestellungen ohne geplanten Hintergrundjob werden beim Aktivieren der Archivierung nicht rückwirkend verarbeitet.

## Prüfung vor einem Live-Einsatz

Im Testshop sind insbesondere Modulinstallation, normaler und Gast-Checkout, E-Mail-Einladung, Statuslink, Download-Sperre und anschließende Freigabe mit einem BlockSign-Testkonto zu prüfen. Die CI deckt Registrierung, Signaturablauf und Freigabeentscheidung mit simulierten Abhängigkeiten ab. Zusätzlich wurden Modulinstallation, Bestellregistrierung, Worker-Start und HTTP-Download vor und nach einer simulierten Signatur mit PrestaShop 9.0.0 und MariaDB 10.11 geprüft.

Teste mit einer verzögerten Zahlung und zwei Pflichtdokumenten. Vor der Zahlungsbestätigung darf keine Signaturanfrage starten. Nach der Zahlung bleibt das PDF bis zur zweiten Unterschrift gesperrt. Prüfe anschließend den Download und die erneute Sperre nach Stornierung oder Erstattung. Die neuen Zahlungsabläufe sind in der CI mit simulierten Shop- und BlockSign-Antworten geprüft.
