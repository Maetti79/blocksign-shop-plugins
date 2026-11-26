# BlockSign eSign für Shops

Zwei Pakete teilen die Freigaberegeln. Das PHP-Paket enthält zusätzlich den ersten BlockSign-REST-Client. Die Piloten für WooCommerce und PrestaShop verarbeiten kostenlose Bestellungen für geschützte PDFs. Die gemeinsamen Testdaten prüfen auch die Entscheidung vor Bestellung, vor Zahlung und nach Zahlung. Die dazugehörigen bezahlten WooCommerce-Abläufe, mehrere Dokumente, Zustellnachweise und weitere Shop-Adapter sind zukünftige Ausbaustufen.

## WooCommerce-Pilot

1. Baue die Plugin-ZIP mit `scripts/build-woocommerce.sh`. Lade `build/blocksign-esign-woocommerce.zip` unter **Plugins → Installieren → Plugin hochladen** hoch und aktiviere das Plugin.
2. Lege ein einfaches **virtuelles** Produkt mit Preis **0 €** an. WooCommerces Standard-Downloadfunktion bleibt für dieses Produkt ausgeschaltet.
3. Kopiere die Erklärung als `agreement.pdf` und das Exposé als `download.pdf` in ein Verzeichnis außerhalb des Webroots, das der PHP-Prozess lesen kann und das der Webserver auch über Aliase nicht veröffentlicht. Die Erklärung braucht Platz für das Signaturfeld an der im nächsten Schritt konfigurierten Position.
4. Setze in `wp-config.php` die Konstanten `BLOCKSIGN_ESIGN_FILES` (absoluter Verzeichnispfad) und `BLOCKSIGN_ESIGN_API_KEY` (API-Key aus einer serverseitigen Secret-Konfiguration).
5. Trage die Produkt-ID unter **Einstellungen → BlockSign eSign** ein und wähle Signaturniveau, Seite und Position. Das Produkt wird einzeln und mit einer gültigen E-Mail-Adresse bestellt.

Der Job in WooCommerce Action Scheduler lädt die Erklärung hoch, bereitet die Signaturanfrage vor, sendet die BlockSign-Einladung und prüft anschließend den Status. Nach bestätigtem Abschluss erscheint der Link in der WooCommerce-Bestellansicht beim erneuten Laden. Die Downloadroute prüft Bestellberechtigung und Signaturstatus bei jedem Aufruf. Für Gastbestellungen ist der Bestellschlüssel der Zugangsschlüssel und entsprechend vertraulich zu behandeln.

Action Scheduler benötigt einen funktionierenden WordPress-Cron. Die Statusabfrage endet spätestens nach sieben Tagen. Wenn ein Upload oder das Anlegen einer Signaturanfrage mit unklarem Ergebnis fehlschlägt, hält der Job zur manuellen Prüfung an und hinterlegt eine Bestellnotiz. Die beiden PDF-Dateien müssen für bestehende Bestellungen unverändert verfügbar bleiben. Der Pilot prüft ihre SHA-256-Werte. Ein produktiver Wechsel von Dokumentversionen benötigt getrennte private Dateien pro Version.

### Prüfung vor einem Live-Einsatz

Führe in einem Testshop eine Gast- und eine Kontobestellung durch. Prüfe die Sperre vor Abschluss, die BlockSign-Einladung, das Erscheinen des Links nach Bestätigung, den Download und den verweigerten Zugriff mit ungültigem Bestellschlüssel. Prüfe klassischen Checkout und Checkout Blocks separat. In dieser Repository-Version wurde der Live-Ablauf mit WooCommerce und einem BlockSign-API-Key noch nicht verifiziert.

## PrestaShop-Pilot

1. Erstelle die Modul-ZIP mit `scripts/build-prestashop.sh` oder lade das Artefakt aus GitHub Actions herunter. Installiere darin `blocksign-esign-prestashop.zip` über den Modulmanager eines **PrestaShop-9-Testshops**.
2. Lege ein einzelnes virtuelles Produkt für **0 €** an. Hinterlege dafür weder einen PrestaShop-Download noch einen öffentlich erreichbaren Anhang. Trage die Produkt-ID in der Modulkonfiguration ein. Der Pilot verarbeitet eine kostenlose Bestellung mit genau einem Exemplar dieses Produkts.
3. Lege `agreement.pdf` und `download.pdf` in einem privaten Verzeichnis außerhalb des Webroots ab. Setze `BLOCKSIGN_ESIGN_FILES` auf diesen absoluten Pfad, sowohl für den Web-PHP-Prozess als auch für den Worker. Wähle in der Modulkonfiguration außerdem Signaturniveau, PDF-Seite und Position.
4. Stelle dem Worker `BLOCKSIGN_ESIGN_API_KEY` über die Secret-Konfiguration des Servers bereit. Lasse jede Minute `php /pfad/zum/shop/modules/blocksignesign/worker.php` unter dem Shop-Benutzer laufen. Das CLI-PHP benötigt cURL.

PrestaShop zeigt den Statuslink auf der Bestellbestätigung und in der Bestellansicht. Gäste können den Link auch über die PrestaShop-Gastbestellverfolgung aufrufen. Ein Gastlink enthält einen vertraulichen, zufälligen Schlüssel pro Bestellung. Kunden mit Konto müssen zusätzlich angemeldet sein. Der Modul-Endpunkt prüft Bestellung, Schlüssel, Signaturstatus und Dateihash bei jedem Download. Wiederholte Bestellmeldungen behalten den vorhandenen Vorgang bei.

Der Worker verarbeitet bis zu 20 fällige Vorgänge pro Lauf. Er lädt die Erklärung hoch, sendet die BlockSign-Einladung und fragt den Status bis zu sieben Tage ab. Bei einem unklaren Ergebnis der Erstellung hält er den betroffenen Vorgang an und protokolliert den Fall. Ersetze die privaten Pilotdateien bei offenen oder weiterhin abrufbaren Bestellungen nur durch Dateien mit identischem Inhalt. Deaktivieren oder Deinstallieren des Moduls unterbricht den Zugriff. Beim Deinstallieren bleibt die Statustabelle für bestehende Nachweise erhalten.

Im Testshop sind insbesondere Modulinstallation, normaler und Gast-Checkout, E-Mail-Einladung, Statuslink, Download-Sperre und anschließende Freigabe mit einem BlockSign-Testkonto zu prüfen. Die CI deckt Registrierung, Signaturablauf und Freigabeentscheidung mit simulierten Abhängigkeiten ab. Zusätzlich wurden Modulinstallation, Bestellregistrierung, Worker-Start und HTTP-Download vor und nach einer simulierten Signatur mit PrestaShop 9.0.0 und MariaDB 10.11 geprüft.

## Signaturniveau und Position

Beide Module bieten EES, FES und QES an. X und Y werden in PDF-Einheiten vom linken bzw. oberen Seitenrand gemessen. Standard ist EES auf Seite 1 bei X/Y = 50/50. Die Bestellung speichert die gewählten Werte. Ältere Bestellungen behalten ihre bisherigen Standardwerte. Welche Signaturstufen genutzt werden können, hängt vom BlockSign-Konto ab.

## Pakete testen

- PHP ab 8.1: `php tests/gate.php`
- Node.js 24: `npm --prefix packages/typescript ci` und `npm --prefix packages/typescript test`
- Gemeinsame Vertragstests: `tests/gate.json`

Lizenz: GPL-3.0-or-later.
