# BlockSign eSign für Shops

Zwei Pakete teilen die Freigaberegeln. Das PHP-Paket enthält zusätzlich den ersten BlockSign-REST-Client. Der WooCommerce-Pilot verarbeitet eine kostenlose Bestellung für ein geschütztes PDF. Die gemeinsamen Testdaten prüfen auch die Entscheidung vor Bestellung, vor Zahlung und nach Zahlung. Die dazugehörigen bezahlten WooCommerce-Abläufe, mehrere Dokumente, Zustellnachweise und die übrigen Shop-Adapter sind zukünftige Ausbaustufen.

## WooCommerce-Pilot

1. Baue die Plugin-ZIP mit `scripts/build-woocommerce.sh`. Lade `build/blocksign-esign-woocommerce.zip` unter **Plugins → Installieren → Plugin hochladen** hoch und aktiviere das Plugin.
2. Lege ein einfaches **virtuelles** Produkt mit Preis **0 €** an. WooCommerces Standard-Downloadfunktion bleibt für dieses Produkt ausgeschaltet.
3. Kopiere die Erklärung als `agreement.pdf` und das Exposé als `download.pdf` in ein Verzeichnis außerhalb des Webroots, das der PHP-Prozess lesen kann und das der Webserver auch über Aliase nicht veröffentlicht. Die Erklärung braucht auf Seite 1 Platz für das Signaturfeld bei X/Y = 50/50 PDF-Einheiten. Das Pilot-Signaturniveau ist EES.
4. Setze in `wp-config.php` die Konstanten `BLOCKSIGN_ESIGN_FILES` (absoluter Verzeichnispfad) und `BLOCKSIGN_ESIGN_API_KEY` (API-Key aus einer serverseitigen Secret-Konfiguration).
5. Trage die Produkt-ID unter **Einstellungen → BlockSign eSign** ein. Das Produkt wird einzeln und mit einer gültigen E-Mail-Adresse bestellt.

Der Job in WooCommerce Action Scheduler lädt die Erklärung hoch, bereitet die Signaturanfrage vor, sendet die BlockSign-Einladung und prüft anschließend den Status. Nach bestätigtem Abschluss erscheint der Link in der WooCommerce-Bestellansicht beim erneuten Laden. Die Downloadroute prüft Bestellberechtigung und Signaturstatus bei jedem Aufruf. Für Gastbestellungen ist der Bestellschlüssel der Zugangsschlüssel und entsprechend vertraulich zu behandeln.

Action Scheduler benötigt einen funktionierenden WordPress-Cron. Die Statusabfrage endet spätestens nach sieben Tagen. Wenn ein Upload oder das Anlegen einer Signaturanfrage mit unklarem Ergebnis fehlschlägt, hält der Job zur manuellen Prüfung an und hinterlegt eine Bestellnotiz. Die beiden PDF-Dateien müssen für bestehende Bestellungen unverändert verfügbar bleiben. Der Pilot prüft ihre SHA-256-Werte. Ein produktiver Wechsel von Dokumentversionen benötigt getrennte private Dateien pro Version.

### Prüfung vor einem Live-Einsatz

Führe in einem Testshop eine Gast- und eine Kontobestellung durch. Prüfe die Sperre vor Abschluss, die BlockSign-Einladung, das Erscheinen des Links nach Bestätigung, den Download und den verweigerten Zugriff mit ungültigem Bestellschlüssel. Prüfe klassischen Checkout und Checkout Blocks separat. In dieser Repository-Version wurde der Live-Ablauf mit WooCommerce und einem BlockSign-API-Key noch nicht verifiziert.

## Pakete testen

- PHP ab 8.1: `php tests/gate.php`
- Node.js 24: `npm --prefix packages/typescript ci` und `npm --prefix packages/typescript test`
- Gemeinsame Vertragstests: `tests/gate.json`

Lizenz: GPL-3.0-or-later.
