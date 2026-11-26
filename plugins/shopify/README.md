# BlockSign eSign für Shopify

[Zur Projektübersicht](../../README.md)

Die App verbindet Shopify mit BlockSign. Bei regulären Bestellungen startet sie die Bestätigung nach Zahlung oder bei einem Bestellwert von 0 €. Für ausgewählte Varianten kann die App die Signatur vor der Zahlung einholen und anschließend einen Shopify-Rechnungslink erstellen.

## Aufsetzen

1. Lege im Shopify Dev Dashboard eine App für einen Testshop deiner Organisation an. Die App ist auf die Installation in einem eigenen Shopify-Shop ausgelegt. Sie verwendet den Client-Credentials-Grant und benötigt Shopify-Zugriff auf Bestellungen, E-Mail-Adressen von Kunden und Bestellentwürfe. Für geschützte Kundendaten gelten die Freigaben in Shopify. [Shopify Client-Credentials-Grant](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/client-credentials-grant)
2. Kopiere `shopify.app.toml.example` nach `shopify.app.toml`, trage die Client-ID und die öffentlich erreichbare HTTPS-Adresse der App ein und veröffentliche die App-Konfiguration mit Shopify CLI. Sie registriert den App-Proxy unter `/apps/blocksign` und die Bestell- und Rückerstattungs-Webhooks unter `/webhooks`. [Shopify-App-Konfiguration](https://shopify.dev/docs/apps/build/cli-for-apps/app-configuration)
3. Kopiere die JSON-Beispieldatei in ein privates Serververzeichnis. Passe Shop, Datenbank, PDF-Verzeichnis und Regeln an. Lege die privaten Verzeichnisse an, bevor du die App startest. Der optionale Archivpfad bleibt privat und muss beschreibbar sein.
4. Stelle die Umgebungsvariablen `SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET`, `BLOCKSIGN_ESIGN_API_KEY` und `BLOCKSIGN_SHOPIFY_CONFIG` bereit. Die letzte Variable enthält den absoluten Pfad zur JSON-Datei. `PORT` kann optional gesetzt werden und lautet standardmäßig `3000`.
5. Starte die App mit Node.js 24 und `npm start` im Verzeichnis `plugins/shopify`. Die Shopify-Variante läuft als Server-App mit einem integrierten Hintergrundprozess. Verwende eine dauerhafte, private SQLite-Datendatei und einen App-Prozess pro Installation. Stelle `/health` für die Verfügbarkeitsprüfung bereit.

Das Installationspaket enthält den App-Quellcode und den TypeScript-Kern. Alternativ kann das ZIP mit `scripts/build-shopify.sh` im Repository gebaut werden.

## Regeln und Bestätigungen

- Regeln können eine Variante, ein Produkt oder einen Produkt-Tag auswählen. Eine Regel ohne Auswahl gilt als Standard. Am konkretesten sind Variantenregeln, gefolgt von Produktregeln, Tags und Standard. Tags eignen sich auch, um Produkte zu gruppieren.
- Je Regel sind mehrere Pflichtdokumente möglich. Pro PDF lassen sich Seite, X/Y und EES (`aes`), FES (`fes`), QES (`qes`) oder Zustellnachweis (`pod`) hinterlegen. Die PDF-Dateien bleiben bis zur Abwicklung der zugehörigen Vorgänge unter ihrem ursprünglichen Namen und mit unverändertem Inhalt verfügbar.
- `download` und `membership` stellen einen privaten PDF-Download für das Käuferkonto bereit, etwa ein Exposé oder Mitgliederunterlagen. Für diese Varianten bleibt die Datei aus öffentlichen Shopify-Medien und aus allgemeinen Download-Apps heraus.
- `shipment` kennzeichnet die vollständig bezahlte und bestätigte Bestellung mit `blocksign-ready`. Verwende dafür manuelle Auslieferung oder einen eigenen Workflow, der dieses Freigabesignal auswertet. Der Shopify-Standardversand wird durch die Kennzeichnung allein nicht gesperrt.
- Das optionale Nachweisarchiv speichert bestätigte PDFs, Audit-Trails und Zustellnachweise lokal. Shopify liefert Kundendateien nur aus, wenn der App-Proxy den Käufer identifiziert und der aktuelle Admin-API-Status die Freigabe erlaubt. Eine teilweise oder vollständige Erstattung sowie eine Stornierung sperren den Abruf.

## Vor Zahlung per Shopify-Bestellentwurf

Verwende für `before_payment` eine aktive Variante mit eindeutiger URL-Kennung (`slug`). Halte das Produkt aus Vertriebskanälen heraus, über die es direkt gekauft werden könnte. Verlinke auf `/apps/blocksign/purchase/<slug>`. Dort fordert der angemeldete Kunde die BlockSign-Bestätigung an. Nach Abschluss aller Pflichtdokumente erstellt die App einen Bestellentwurf mit einer Einheit der Variante und zeigt den von Shopify ausgestellten Zahlungslink an. Shopify bestimmt Steuern, Versandkosten und die verfügbaren Zahlungsmethoden. [Shopify-Bestellentwurf](https://shopify.dev/docs/api/admin-graphql/latest/mutations/draftOrderCreate)

Dieser Ablauf ist für einzeln angebotene Varianten vorgesehen. Eine in den regulären Shopify-Warenkorb integrierte Vorab-Sperre oder eine Shopify Checkout Function ist in diesem Adapter nicht enthalten.

## Nach Zahlung oder ohne Zahlung

Linke im Shop auf `/apps/blocksign`. Angemeldete Kunden sehen ihre BlockSign-Bestellungen. Bei 0 € genügt eine gültige Bestellung. Bei einem positiven Betrag prüft die App den vollständigen Zahlungseingang anhand der Admin API. Die Standardberechtigung von Shopify begrenzt den Abruf älterer Bestellungen. Dauerhafte Downloads über dieses Zeitfenster hinaus benötigen die passende Freigabe von Shopify. [Shopify Admin API für Bestellungen](https://shopify.dev/docs/api/admin-graphql/latest/objects/Order)

## Prüfung im Testshop

Prüfe Bestellung, Zahlungsstatus und Rückerstattung sowie den Zugriff aus dem Käuferkonto. Kontrolliere beim Bestellentwurfsablauf, dass der Link erst nach der letzten Bestätigung entsteht und dass die Variante außerhalb dieses Ablaufs nicht kaufbar ist. Shopify-App-Proxy und Webhooks sind zusätzlich mit lokalen HTTP-Tests und simulierten Diensten geprüft. Die Abnahme mit einem echten Shopify-Testshop, BlockSign und einem Zahlungsanbieter steht aus.
