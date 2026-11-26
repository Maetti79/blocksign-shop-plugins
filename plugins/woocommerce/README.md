# BlockSign eSign für WooCommerce

[Zur Projektübersicht](../../README.md)

## Installation und Betrieb

1. Baue die Plugin-ZIP mit `scripts/build-woocommerce.sh`. Lade `build/blocksign-esign-woocommerce.zip` unter **Plugins → Installieren → Plugin hochladen** hoch und aktiviere das Plugin.
2. Lege ein einfaches **virtuelles** Produkt mit gewünschtem Preis an. WooCommerces Standard-Downloadfunktion bleibt für dieses Produkt ausgeschaltet.
3. Kopiere die Erklärung als `agreement.pdf` und das Exposé als `download.pdf` in ein Verzeichnis außerhalb des Webroots, das der PHP-Prozess lesen kann und das der Webserver auch über Aliase nicht veröffentlicht. Die Erklärung braucht Platz für das Signaturfeld an der im nächsten Schritt konfigurierten Position.
4. Setze in `wp-config.php` die Konstanten `BLOCKSIGN_ESIGN_FILES` (absoluter Verzeichnispfad) und `BLOCKSIGN_ESIGN_API_KEY` (API-Key aus einer serverseitigen Secret-Konfiguration).
5. Wähle unter **Einstellungen → BlockSign eSign** den Produktbereich sowie Signaturniveau, Seite und Position. Für die Signaturanfrage ist eine gültige E-Mail-Adresse erforderlich.

Der Job in WooCommerce Action Scheduler lädt die Erklärung hoch, bereitet die Signaturanfrage vor, sendet die BlockSign-Einladung und prüft anschließend den Status. Nach bestätigtem Abschluss erscheint der Link in der WooCommerce-Bestellansicht beim erneuten Laden. Die Downloadroute prüft Bestellberechtigung und Signaturstatus bei jedem Aufruf. Für Gastbestellungen ist der Bestellschlüssel der Zugangsschlüssel und entsprechend vertraulich zu behandeln.

Action Scheduler benötigt einen funktionierenden WordPress-Cron. Die Statusabfrage endet spätestens nach sieben Tagen. Wenn ein Upload oder das Anlegen einer Signaturanfrage mit unklarem Ergebnis fehlschlägt, hält der Job zur manuellen Prüfung an und hinterlegt eine Bestellnotiz. Die beiden PDF-Dateien müssen für bestehende Bestellungen unverändert verfügbar bleiben. Der Pilot prüft ihre SHA-256-Werte. Ein produktiver Wechsel von Dokumentversionen benötigt getrennte private Dateien pro Version.


## Produktzuordnung

Unter **Protected products** wählst du für welche Produkte die Einstellung gilt.

- **Single product** verwendet die bestehende Produkt-ID. Diese Auswahl gilt beim Update automatisch weiter. Mit Produkt-ID 0 werden in diesem Modus keine neuen Vorgänge angelegt.
- **Product category** verwendet die eingegebene Kategorie-ID. Berechtigt sind geeignete virtuelle Produkte, die direkt dieser Kategorie zugeordnet sind.
- **Products marked on their edit screen** verwendet die Checkbox **Require BlockSign eSign** unter **Produktdaten → Allgemein** des klassischen WooCommerce-Produkteditors.
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

WooCommerce reagiert auf Zahlungsbestätigung und Statuswechsel. Der Vorgang verlangt einen bezahlten Status und ein Zahlungsdatum. Auch wenn nur ein Teilbetrag erstattet wurde, bleibt der Download gesperrt.

## Signatur vor Zahlung

Wähle unter **Einstellungen → BlockSign eSign** bei **Signature timing** die Option **Before payment**. Die Einstellung gilt für neue bezahlte Bestellungen des konfigurierten PDF-Produkts. Der klassische Checkout (Shortcode) und Checkout Blocks legen eine offene Bestellung an und leiten zur Bestellansicht weiter, während BlockSign die Einladungen versendet. Nach der letzten bestätigten Signatur erscheint dort **Continue to payment**. Gäste können die Bestellansicht nach dem Signieren erneut öffnen. WooCommerce prüft die Signaturen auch bei einem direkten Aufruf des Bestellzahlungsformulars.

Diese Variante setzt einen Zahlungsanbieter voraus, der WooCommerces standardmäßige Seite zur Zahlung offener Bestellungen unterstützt. Verwende für das geschützte Produkt den normalen Checkout ohne Express-Zahlungsbuttons. Der Store-API-Checkout gibt während der Signatur einen ausstehenden Zahlungsstatus und den Link zur Bestellansicht zurück. Im Block-Template der Bestellbestätigung zeigt der Block **Zusätzliche Informationen** die Signaturhinweise und den Zahlungslink. Die Bestellung wird vor der Signatur angelegt. Wenn die Unterschrift vor einer verbindlichen Bestellung vorliegen muss, brauchst du eine Sperre vor der verbindlichen Bestellabgabe.

## Bestellabgabe erst nach Signatur

Wähle unter **Signature timing** die Option **Before order**. Der erste Checkout-Versuch prüft die Angaben und plant die BlockSign-Einladungen im Hintergrund. Der Kunde bleibt auf der Checkout-Seite. Nach der Bestätigung aller Pflichtdokumente sendet er das Formular erneut ab. Erst dann legt WooCommerce die Bestellung an und startet bei einem zahlungspflichtigen Warenkorb die Zahlung. Die Auswahl gilt auch für 0‑€‑Bestellungen.

Die Bestätigung gilt bis zu sieben Tage für die Checkout-Sitzung, den Warenkorb, die E-Mail-Adresse, den Gesamtbetrag und die Dokumentversionen. Eine Bestellung verbraucht die Bestätigung. Bei geänderten Angaben startet ein neuer Vorgang. Wenn die Bestellerstellung nach dem Verbrauch fehlschlägt, muss der Kunde erneut bestätigen. Der Modus gilt im klassischen Checkout und in Checkout Blocks ohne Express-Zahlungsbuttons. Checkout Blocks kann einen internen Bestellentwurf speichern. Er bleibt bis zur Bestätigung ein Entwurf, und der Zahlungsanbieter wird in dieser Phase nicht aufgerufen.

## Versandfreigabe für physische Produkte

Aktiviere **Require BlockSign before shipping** unter **Produktdaten → Allgemein** für ein einfaches, physisches Produkt. Das Produkt verwendet seine eigenen oder die shopweiten Pflichtdokumente. Für die Versandposition ist keine private Download-Datei erforderlich. Enthält ein Warenkorb eine solche Position, gilt die Versandfreigabe für die gesamte Bestellung.

Nach der Zahlung führt WooCommerce eine noch offene Bestellung unter **Awaiting BlockSign confirmation**. Der Bestand wird mit der regulären WooCommerce-Logik berücksichtigt. Sind die Pflichtdokumente bestätigt, setzt der Worker die Bestellung auf **Processing**, damit die Versandbearbeitung beginnen kann. Bestätigen Kunden die Dokumente bereits vor der Bestellabgabe oder Zahlung, geht die Bestellung nach erfolgter Zahlung direkt in die reguläre Bearbeitung.

Versand- und ERP-Integrationen müssen den WooCommerce-Status **Processing** abwarten. Eine Integration, die schon auf das Zahlungsevent versendet, braucht eine eigene Sperre. Prüfe den Ablauf mit deinem Zahlungsanbieter und deiner Versandsoftware im Testshop. Nachnahme wird für geschützte Bestellungen nicht unterstützt.

## Elektronischer Zustellnachweis

Wähle **Proof of delivery** für das erste Pflichtdokument. Für ein zusätzliches PDF verwende die Kurzform `mitteilung.pdf | POD`. Bei einer Kategorie stellst du ihre ID voran, zum Beispiel `21 | mitteilung.pdf | POD`. BlockSign versendet das Dokument zur Empfangsbestätigung. Für diesen Vorgang sind keine Signaturposition oder Signaturstufe erforderlich.

Der Shop wartet auf die Bestätigung für genau dieses Dokument und diesen Empfänger. Eine versendete Einladung genügt für die Freigabe nicht. Ist die private Archivierung aktiviert, speichert das Modul das zugestellte PDF mit dem von BlockSign eingebetteten Nachweis als `<document-id>.delivery.pdf`. Der Ablauf ist mit simulierten API-Antworten geprüft. Der Test mit einem echten BlockSign-Konto steht noch aus.

## Signierte PDFs und Audit-Trails archivieren

Lege für die optionale Archivierung ein separates, privates Verzeichnis außerhalb aller Webroots an. Definiere in `wp-config.php` zusätzlich die Konstante `BLOCKSIGN_ESIGN_ARCHIVE` mit dem absoluten Pfad. Der WordPress-Cron-Benutzer benötigt dort Schreibzugriff.

Nach Bestätigung aller Pflichtsignaturen ruft der Hintergrundjob pro BlockSign-Vorgang das signierte PDF und den Audit-Trail ab. Er speichert sie im Unterordner `woocommerce-<site-id>-<bestell-id>` als `<request-id>.pdf` und `<request-id>.audit.json`. Das Limit für ein signiertes PDF beträgt 16 MiB. Die Dateien sind über den Server verfügbar. Ein Fehler bei der Archivierung wird protokolliert und lässt die normale Downloadfreigabe unberührt. Abgeschlossene Bestellungen ohne geplanten Hintergrundjob werden beim Aktivieren der Archivierung nicht rückwirkend verarbeitet.

## Prüfung vor einem Live-Einsatz

Führe in einem Testshop eine Gast- und eine Kontobestellung durch. Prüfe die Sperre vor Abschluss, die BlockSign-Einladung, das Erscheinen des Links nach Bestätigung, den Download und den verweigerten Zugriff mit ungültigem Bestellschlüssel. Prüfe klassischen Checkout und Checkout Blocks separat. In dieser Repository-Version wurde der Live-Ablauf mit WooCommerce und einem BlockSign-API-Key noch nicht verifiziert.

Teste mit einer verzögerten Zahlung und zwei Pflichtdokumenten. Vor der Zahlungsbestätigung darf keine Signaturanfrage starten. Nach der Zahlung bleibt das PDF bis zur zweiten Unterschrift gesperrt. Prüfe anschließend den Download und die erneute Sperre nach Stornierung oder Erstattung. Die neuen Zahlungsabläufe sind in der CI mit simulierten Shop- und BlockSign-Antworten geprüft.
