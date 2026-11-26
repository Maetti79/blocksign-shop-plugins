# BlockSign eSign für JTL-Shop

[Projektübersicht und BlockSign-Konto](../../README.md#blocksign-konto-api-key-und-abrechnung)

Der Adapter registriert geschützte Bestellungen in **JTL-Shop 5.5 oder neuer**. Bei 0 € startet BlockSign nach der Bestellabgabe. Bei einem positiven Bestellwert startet der Worker nach dem in JTL-Shop hinterlegten Zahlungsdatum. Die Kunden laden die PDFs nach der letzten Pflichtbestätigung über ihr angemeldetes Konto herunter.

## Installation und Konfiguration

1. Installiere `blocksign-esign-jtl-shop.zip` im **Plugin-Manager** eines JTL-Shop-Testshops. Das ZIP wird von GitHub Actions oder mit `scripts/build-jtl-shop.sh` gebaut.
2. Kopiere `config.example.json` in ein privates Serververzeichnis. Setze `BLOCKSIGN_JTL_CONFIG` im Web-PHP und im CLI-PHP auf den absoluten Pfad zu dieser Datei. Lege die PDFs im Verzeichnis `files` außerhalb des Webroots ab.
3. Stelle dem CLI-Prozess `BLOCKSIGN_ESIGN_API_KEY` bereit. Plane mit dem Benutzer des Shops jede Minute `php cli blocksign_esign:process` aus dem Shop-Verzeichnis ein. CLI-PHP benötigt cURL.
4. Verlinke die vom Plugin angelegte Seite **BlockSign-Status** im Kundenbereich. Im Checkout kann das Template den Statuslink auf der Bestellbestätigung ausgeben. Die Seite zeigt nur Bestellungen des angemeldeten Kontos.

## Produkte und Dokumente

`products` und `categories` verwenden numerische JTL-IDs. `default` kann gemeinsame Regeln für alle Produkte festlegen. Kategorieregeln greifen in aufsteigender ID-Reihenfolge. Eine Produktregel überschreibt die geerbten Werte. Setze `enabled` auf `false`, um ein Produkt aus einer geerbten Regel auszunehmen. PDF-Dateinamen bestehen aus ASCII-Buchstaben, Ziffern, Punkten, Bindestrichen und Unterstrichen.

Wähle je Dokument EES (`aes`), FES (`fes`), QES (`qes`) oder Zustellnachweis (`pod`). Für zusätzliche Dokumente enthält `documents` je Zeile den PDF-Namen und die Signaturdetails, etwa `mandat.pdf | FES | 1 | 50 | 60` oder `empfang.pdf | POD`. Die Verfahren entsprechen den anderen PHP-Plugins. Ein optionaler privater `archive`-Pfad nimmt signierte PDFs, Audit-Trails und Zustellnachweise auf.

## Status, Download und Abnahme

Der Download prüft bei jedem Aufruf das Konto, Bestellpositionen, die aktuell in JTL-Shop gespeicherte Zahlung, Stornierung und negative Zahlungseingänge, sämtliche Pflichtdokumente und den Datei-Hash. Rückerstattungen müssen aus dem Zahlungsprozess im JTL-Shop als negative Zahlung oder Stornierung vorliegen, damit der Shop sie berücksichtigen kann. Halte den geschützten Download außerhalb der allgemeinen JTL-Medien und anderer Auslieferungswege bereit.

Der erste Adapter deckt Bestellungen mit Kundenkonto ab. Vorab-Signatur im Checkout, Sperrung eines Wawi-Versandexports und Zuweisung von Kundengruppen stehen noch aus. Die automatisierten Tests verwenden simulierte JTL-Daten und eine simulierte BlockSign-API. Installation, Bestellbestätigungslink, Zahlungs- und Stornodaten aus Wawi, Cron und Downloads sind noch in einem echten JTL-Shop-Testsystem abzunehmen.
