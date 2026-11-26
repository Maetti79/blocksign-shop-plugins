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

## Bestellabgabe und Zahlung erst nach Bestätigung

Aktiviere **Require confirmation before order and payment** in der Modulkonfiguration. Im Standard-Checkout startet der Kunde die Bestätigung über die BlockSign-Schaltfläche im Zahlungsschritt. Der CLI-Worker sendet die Einladungen. Nach der letzten Bestätigung lädt der Kunde den Checkout erneut und kann die Bestellung aufgeben. Die Freigabe gilt höchstens sieben Tage für den konkreten Warenkorb, den Kunden, den Betrag und die hinterlegten Dokumente. Sie lässt sich einmal verwenden.

Der Modus unterstützt 0‑€‑Bestellungen sowie PrestaShops native Module für Vorkasse per Überweisung (`ps_wirepayment`) und Scheck (`ps_checkpayment`). Zahlungsanweisungen erscheinen dadurch erst nach der Bestätigung und Bestellabgabe. Deaktiviere alle anderen Zahlungswege für die geschützten Produkte. Der serverseitige Hook kennt den aktiven Browser-Warenkorb. Externe Zahlungsanbieter können Zahlungen außerhalb dieses Hooks auslösen. Ein Theme muss im Zahlungsschritt `displayPaymentTop` ausgeben. Aktualisiere das Modul im Modulmanager, damit die Tabelle und die Hooks angelegt werden.

## Versandfreigabe für physische Produkte

Aktualisiere das Modul im Modulmanager. Aktiviere anschließend **Require BlockSign before shipping** unter **Optionen** für ein physisches Produkt ohne Varianten. Das Modul verwendet die für dieses Produkt hinterlegten Pflichtdokumente. Eine Download-Datei ist für diese Position nicht erforderlich.

Nach Zahlungsbestätigung verarbeitet der Worker die Pflichtdokumente und setzt die Bestellung nach Abschluss auf **BlockSign: Versandbereit**. PrestaShop blockiert vorher reguläre Statuswechsel auf Versand oder Lieferung. Hat der Kunde die Pflichtdokumente bereits im Checkout bestätigt, wartet die Versandfreigabe nur noch auf die Zahlung.

Versand- und ERP-Integrationen müssen bei geschützten Bestellungen den Status **BlockSign: Versandbereit** abwarten. Ein Export direkt beim Zahlungseingang braucht eine eigene Sperre. Prüfe die Kombination aus Zahlungsmodul, PrestaShop und Versandsoftware im Testshop.

## Zugang über eine Kundengruppe freigeben

Lege eine eigene PrestaShop-Kundengruppe an und ordne ihr die vorgesehenen Zugriffsrechte zu. Trage ihre ID bei einem virtuellen Produkt ohne Varianten, nativen Download oder Anhang unter **Optionen → BlockSign customer access group ID** ein. Für dieses Produkt verwenden die Pflichtdokumente denselben Signaturablauf wie andere BlockSign-Produkte. Eine private Download-Datei ist dafür nicht erforderlich.

Sobald Zahlung und Pflichtdokumente bestätigt sind, fügt der Worker das aktive Kundenkonto der Gruppe hinzu. Die Bestellung legt Konto und Gruppe fest. Die Konto-E-Mail muss mit der für die Bestätigung verwendeten Adresse übereinstimmen. Bei einem Gastkonto wartet der Worker bis zu sieben Tage auf die Umwandlung zum regulären Konto. Bestehende Gruppen und die Standardgruppe des Kontos bleiben erhalten.

Die Freigabe ist eine einmalige Gruppenzuweisung. Preise und Zugriffsrechte werden nach den PrestaShop-Regeln der Gruppe angewendet. Eine spätere Erstattung oder ein Austritt entfernt die Gruppenzugehörigkeit nicht automatisch. Entziehe den Zugang in diesem Fall im Backoffice.

## Elektronischer Zustellnachweis

Wähle **Proof of delivery** für das erste Pflichtdokument. Für ein zusätzliches PDF verwende die Kurzform `mitteilung.pdf | POD`. Bei einer Kategorie stellst du ihre ID voran, zum Beispiel `21 | mitteilung.pdf | POD`. BlockSign versendet das Dokument zur Empfangsbestätigung. Für diesen Vorgang sind keine Signaturposition oder Signaturstufe erforderlich.

Der Shop wartet auf die Bestätigung für genau dieses Dokument und diesen Empfänger. Eine versendete Einladung genügt für die Freigabe nicht. Ist die private Archivierung aktiviert, speichert das Modul das zugestellte PDF mit dem von BlockSign eingebetteten Nachweis als `<document-id>.delivery.pdf`. Der Ablauf ist mit simulierten API-Antworten geprüft. Der Test mit einem echten BlockSign-Konto steht noch aus.

## Signierte PDFs und Audit-Trails archivieren

Lege für die optionale Archivierung ein separates, privates Verzeichnis außerhalb aller Webroots an. Setze `BLOCKSIGN_ESIGN_ARCHIVE` in der Umgebung des CLI-Workers auf den absoluten Pfad. Der Worker-Benutzer benötigt dort Schreibzugriff.

Nach Bestätigung aller Pflichtsignaturen ruft der Hintergrundjob pro BlockSign-Vorgang das signierte PDF und den Audit-Trail ab. Er speichert sie im Unterordner `prestashop-<shop-id>-<bestell-id>` als `<request-id>.pdf` und `<request-id>.audit.json`. Das Limit für ein signiertes PDF beträgt 16 MiB. Die Dateien sind über den Server verfügbar. Ein Fehler bei der Archivierung wird protokolliert und lässt die normale Downloadfreigabe unberührt. Abgeschlossene Bestellungen ohne geplanten Hintergrundjob werden beim Aktivieren der Archivierung nicht rückwirkend verarbeitet.

## Prüfung vor einem Live-Einsatz

Im Testshop sind insbesondere Modulinstallation, normaler und Gast-Checkout, E-Mail-Einladung, Statuslink, Download-Sperre und anschließende Freigabe mit einem BlockSign-Testkonto zu prüfen. Die CI deckt Registrierung, Signaturablauf und Freigabeentscheidung mit simulierten Abhängigkeiten ab. Zusätzlich wurden Modulinstallation, Bestellregistrierung, Worker-Start und HTTP-Download vor und nach einer simulierten Signatur mit PrestaShop 9.0.0 und MariaDB 10.11 geprüft.

Teste mit einer verzögerten Zahlung und zwei Pflichtdokumenten. Vor der Zahlungsbestätigung darf keine Signaturanfrage starten. Nach der Zahlung bleibt das PDF bis zur zweiten Unterschrift gesperrt. Prüfe anschließend den Download und die erneute Sperre nach Stornierung oder Erstattung. Die neuen Zahlungsabläufe sind in der CI mit simulierten Shop- und BlockSign-Antworten geprüft.
