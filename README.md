# BlockSign eSign für Shops

BlockSign eSign verknüpft Shop-Bestellungen mit elektronischen Unterschriften. Shopbetreiber hinterlegen PDF-Vorlagen und legen fest, welche Produkte die Dokumente erfordern. Ein geschützter PDF-Download wird freigegeben, sobald alle Pflichtsignaturen und bei bezahlten Bestellungen die Zahlungsbestätigung vorliegen.

## Shop-Module

| Shop | Unterstützte Abläufe | Einrichtung |
| --- | --- | --- |
| WooCommerce | Ohne Zahlung, nach Zahlung, vor Zahlung und vor Bestellabgabe im klassischen Checkout und in Checkout Blocks | [WooCommerce-README](plugins/woocommerce/README.md) |
| PrestaShop 9 | Ohne Zahlung, nach Zahlung und vor Bestellabgabe im Standard-Checkout mit 0‑€‑Bestellung, Vorkasse oder Scheck | [PrestaShop-README](plugins/prestashop/README.md) |
| Shopware 6.7 | Ohne Zahlung, nach Zahlung und vor Bestellabgabe und Zahlung im Standard-Checkout | [Shopware-README](plugins/shopware/README.md) |

GitHub Actions baut die Installationspakete. [Workflow und ZIP-Artefakte](https://github.com/Maetti79/blocksign-shop-plugins/actions/workflows/check.yml)

## Gemeinsame Funktionen

- **Produktzuordnung** für ein einzelnes Produkt, markierte Produkte, eine Kategorie oder alle geeigneten virtuellen Produkte.
- **Ein oder mehrere Pflichtdokumente**, auch je Produktkategorie, mit EES, FES oder QES sowie PDF-Seite und X/Y-Position je Dokument.
- **Elektronische Zustellnachweise** als Alternative zur Signatur für einzelne Pflichtdokumente.
- **Private Ablage von Nachweisen** für signierte PDFs, Audit-Trails und zugestellte PDFs.
- **Versandfreigabe in WooCommerce und PrestaShop** für markierte physische Produkte nach Bestätigung der Pflichtdokumente.
- **Automatische Zugangsfreigabe** zu privaten Seiten in WooCommerce und per Kundengruppe in PrestaShop und Shopware.
- **Geschützter Download** mit einer Freigabeprüfung anhand des Bestell-, Zahlungs- und Signaturstatus.

Die Module unterstützen gemischte Warenkörbe und mehrere geschützte PDF-Produkte pro Bestellung. Für jedes Produkt lassen sich das erste Pflichtdokument und der geschützte PDF-Download einzeln festlegen. Weitere Shop-Adapter sind geplante Ausbaustufen.

## Anwendungsfälle und Rechtsgrundlagen

Der Shop kann die Bestätigung vor der Bestellabgabe, vor der Zahlung, nach der Zahlung oder bei einer Bestellung ohne Zahlung einholen. Welche Abläufe sich mit welchen Zahlungsarten umsetzen lassen, steht in der jeweiligen Shop-README. Die Beispiele beziehen sich auf Deutschland und das EU-Recht.

| Anwendungsfall | Bestätigung und Freigabe | Rechtsgrundlage |
| --- | --- | --- |
| **Immobilienmakler, kostenloses Exposé** | Käufer oder Interessenten laden das Exposé nach der erforderlichen Erklärung herunter. Bei einem Fernabsatz-Maklervertrag kann der Makler die ausdrückliche Zustimmung zum vorzeitigen Tätigwerden und die Kenntnisbestätigung zum Erlöschen des Widerrufsrechts einholen. | Bei entgeltlichen Dienstleistungen erlischt das Widerrufsrecht unter den gesetzlichen Voraussetzungen mit vollständiger Leistung. Der Exposé-Download für 0 € allein entscheidet weder über das Bestehen eines Maklervertrags noch über dessen vollständige Erfüllung. [§ 356 Abs. 4 BGB](https://www.gesetze-im-internet.de/bgb/__356.html) |
| **Sofortdownload digitaler Inhalte** | Der Shop erfasst vor dem Abruf die ausdrückliche Zustimmung zum vorzeitigen Beginn und die Kenntnisbestätigung zum Verlust des Widerrufsrechts. | Bei entgeltlichen digitalen Inhalten gelten zusätzlich die Voraussetzungen zur Vertragsbestätigung, bei Verträgen ohne Zahlungspflicht gelten eigene Regeln. [§ 356 Abs. 5 BGB](https://www.gesetze-im-internet.de/bgb/__356.html), [§ 312f BGB](https://www.gesetze-im-internet.de/bgb/__312f.html) |
| **Importshop für Ersatzteile** | Vor der Freigabe für den Versand kann der Shop bei gebrauchten Ersatzteilen eine gesonderte Vereinbarung zur verkürzten Verjährungsfrist erfassen. | Im Verbrauchsgüterkauf gilt für gebrauchte Ware eine Mindestfrist von einem Jahr. Die Verkürzung setzt eine eigene Information vor der Vertragserklärung und eine ausdrückliche, gesonderte Vereinbarung voraus. Eine freiwillige Garantie ist davon getrennt. [§ 476 Abs. 2 BGB](https://www.gesetze-im-internet.de/bgb/__476.html), [§ 443 BGB](https://www.gesetze-im-internet.de/bgb/__443.html) |
| **Vereinsbeitritt mit SEPA-Lastschrift** | Beitrittsantrag und SEPA-Mandat können als getrennte Pflichtdokumente hinterlegt werden. Der Shop kann anschließend den Mitgliederzugang freischalten. | Die Satzung regelt die Aufnahme. Für das Mandat gelten das gewählte SEPA-Regelwerk und die Anforderungen des Zahlungsdienstleisters. [§§ 25](https://www.gesetze-im-internet.de/bgb/__25.html), [58 BGB](https://www.gesetze-im-internet.de/bgb/__58.html), [EPC-Regelwerk zur SEPA-Lastschrift](https://www.europeanpaymentscouncil.eu/what-we-do/sepa-direct-debit) |
| **Maßanfertigung mit Entwurfsfreigabe** | Der Kunde bestätigt Maße, Material und Entwurf. Der Betrieb kann die bestätigte Fassung als Auftragsgrundlage verwenden. | Bei eindeutig personalisierten Waren kann die gesetzliche Ausnahme vom Widerrufsrecht greifen. Entscheidend sind die Eigenschaften der Ware. [§ 312g Abs. 2 Nr. 1 BGB](https://www.gesetze-im-internet.de/bgb/__312g.html) |
| **B2B-Preisliste, Datenraum oder Händlerportal** | Der Käufer bestätigt eine Vertraulichkeitsvereinbarung. Der Shop schaltet den Zugang für das Kundenkonto frei, auch bei einer 0‑€‑Bestellung. | Eine Vertraulichkeitsvereinbarung kann Teil der angemessenen Geheimhaltungsmaßnahmen sein. [§ 2 Nr. 1 GeschGehG](https://www.gesetze-im-internet.de/geschgehg/__2.html) |
| **Abnahme eines Werks** | Nach erbrachter Leistung bestätigt der Auftraggeber ein Abnahmeprotokoll. Der Auftragnehmer kann das signierte Protokoll archivieren. | Bei einem Werkvertrag regelt das BGB die Abnahme und ihre Bedeutung für die Fälligkeit der Vergütung. [§§ 640](https://www.gesetze-im-internet.de/bgb/__640.html), [641 BGB](https://www.gesetze-im-internet.de/bgb/__641.html) |
| **Versand einer Vertragsmitteilung** | Eine Vertragsmitteilung wird per elektronischem Zustellnachweis übermittelt. Der Shop archiviert den Nachweis. | Für elektronische Zustelldienste gilt Art. 43 eIDAS. Die dort genannten gesetzlichen Vermutungen gelten für qualifizierte Dienste. [Art. 43 eIDAS](https://eur-lex.europa.eu/eli/reg/2014/910/oj) |

Das erforderliche Signaturniveau hängt vom Dokument und den geltenden Formvorschriften ab. Eine qualifizierte elektronische Signatur hat nach eIDAS die Rechtswirkung einer handschriftlichen Unterschrift. Für die Ersetzung der gesetzlichen Schriftform im deutschen Recht gilt § 126a BGB, soweit elektronische Form zulässig ist. [Art. 25 eIDAS](https://eur-lex.europa.eu/eli/reg/2014/910/oj), [§ 126a BGB](https://www.gesetze-im-internet.de/bgb/__126a.html)

## Entwicklung

Der [PHP-Kern](packages/php) enthält den BlockSign-REST-Client und die Freigabelogik der Shop-Module. Das [TypeScript-Paket](packages/typescript) enthält dieselben Freigaberegeln und verwendet dieselben Testdaten. [BlockSign-API-Dokumentation](https://blocksign.io/api/docs)

- PHP ab 8.1 mit `php tests/gate.php`
- Node.js 24 mit `npm --prefix packages/typescript ci` und `npm --prefix packages/typescript test`
- [Gemeinsame Vertragstests](tests/gate.json)

Lizenz [GPL-3.0-or-later](LICENSE).
