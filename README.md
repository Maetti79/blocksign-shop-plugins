# BlockSign eSign für Shops

BlockSign eSign verknüpft Shop-Bestellungen mit elektronischen Unterschriften. Shopbetreiber hinterlegen PDF-Vorlagen und legen fest, welche Produkte die Dokumente erfordern. Ein geschützter PDF-Download wird freigegeben, sobald alle Pflichtsignaturen und bei bezahlten Bestellungen die Zahlungsbestätigung vorliegen.

## Shop-Module

| Shop | Unterstützte Abläufe | Einrichtung |
| --- | --- | --- |
| WooCommerce | Ohne Zahlung, nach Zahlung und vor Zahlung im klassischen Checkout | [WooCommerce-README](plugins/woocommerce/README.md) |
| PrestaShop 9 | Ohne Zahlung und nach Zahlung | [PrestaShop-README](plugins/prestashop/README.md) |

GitHub Actions baut die Installationspakete. [Workflow und ZIP-Artefakte](https://github.com/Maetti79/blocksign-shop-plugins/actions/workflows/check.yml)

## Gemeinsame Funktionen

- **Produktzuordnung** für ein einzelnes Produkt, markierte Produkte, eine Kategorie oder alle geeigneten virtuellen Produkte.
- **Ein oder mehrere Pflichtdokumente** mit EES, FES oder QES sowie PDF-Seite und X/Y-Position je Dokument.
- **Private Ablage von Nachweisen** für signierte PDFs und Audit-Trails.
- **Geschützter Download** mit einer Freigabeprüfung anhand des Bestell-, Zahlungs- und Signaturstatus.

Die Module verarbeiten derzeit ein Exemplar eines geschützten Produkts je Bestellung. Alle vom Modul erfassten Produkte teilen sich die Dokument- und Downloadkonfiguration. Eine Sperre vor verbindlicher Bestellung, Zustellnachweise und weitere Shop-Adapter sind geplante Ausbaustufen.

## Entwicklung

Der [PHP-Kern](packages/php) enthält den BlockSign-REST-Client und die Freigabelogik der Shop-Module. Das [TypeScript-Paket](packages/typescript) enthält dieselben Freigaberegeln und verwendet dieselben Testdaten. [BlockSign-API-Dokumentation](https://blocksign.io/api/docs)

- PHP ab 8.1 mit `php tests/gate.php`
- Node.js 24 mit `npm --prefix packages/typescript ci` und `npm --prefix packages/typescript test`
- [Gemeinsame Vertragstests](tests/gate.json)

Lizenz [GPL-3.0-or-later](LICENSE).
