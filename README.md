# BlockSign eSign für Shops

BlockSign eSign verknüpft Shop-Bestellungen mit elektronischen Unterschriften. Shopbetreiber hinterlegen PDF-Vorlagen und legen fest, welche Produkte die Dokumente erfordern. Ein geschützter PDF-Download wird freigegeben, sobald alle Pflichtsignaturen und bei bezahlten Bestellungen die Zahlungsbestätigung vorliegen.

## Funktionsumfang und Status

**Ja** steht für eine implementierte Funktion. **Teilweise** nennt die verfügbare Variante. **Offen** kennzeichnet ausstehende Funktionen oder Prüfungen.

| Funktion | WooCommerce | PrestaShop | Shopware | Shopify |
| --- | --- | --- | --- | --- |
| API-Key und privater PDF-Pfad in der Shop-Oberfläche | Ja | Ja | Ja | Teilweise · Serverkonfiguration |
| Regeln für ein Produkt oder alle geeigneten Produkte | Ja | Ja | Ja | Ja · auch je Variante |
| Produktkategorien oder -gruppen | Ja · Kategorie | Ja · Kategorie | Ja · Kategorie | Ja · Produkt-Tags |
| Markierung am Produkt | Ja · Checkbox | Ja · Checkbox | Ja · Zusatzfeld | Ja · Produkt-Tag |
| Mehrere Pflichtdokumente je Bestellung | Ja | Ja | Ja | Ja |
| EES, FES und QES | Ja | Ja | Ja | Ja |
| PDF-Seite und X/Y-Position je Dokument | Ja | Ja | Ja | Ja |
| Elektronischer Zustellnachweis | Ja | Ja | Ja | Ja |
| Bestätigung ohne Zahlung bei 0 € | Ja | Ja | Ja | Ja |
| Bestätigung nach Zahlungseingang | Ja | Ja | Ja | Ja |
| Bestätigung vor Zahlung | Ja · Bestellzahlungsseite oder vor Bestellabgabe | Teilweise · Vorkasse und Scheck | Ja · vor Bestellabgabe im Standard-Checkout | Ja · eigener Kaufablauf mit Shopify-Rechnungslink |
| Bestätigung vor verbindlicher Bestellabgabe im regulären Checkout | Ja · klassischer Checkout und Checkout Blocks | Teilweise · 0 €, Vorkasse und Scheck | Ja · Standard-Checkout | Offen |
| Zeitpunkt pro Produkt oder Kategorie | Offen · Einstellung pro Shop | Offen · Einstellung pro Shop | Ja · Produkt- und Kategorieregeln | Teilweise · Regeln je Variante, Produkt und Tag. Vor Zahlung je Variante über Bestellentwurf |
| Früh und spät zu bestätigende Dokumente im selben Warenkorb | Offen · gemeinsamer Zeitpunkt | Offen · gemeinsamer Zeitpunkt | Ja | Teilweise · nach Zahlung im regulären Warenkorb. Vor Zahlung als Einzelvariante |
| Geschützter PDF-Download nach Bestätigung | Ja | Ja | Ja | Ja · im Käuferkonto |
| Versand im Shop bis zur Bestätigung sperren | Ja · Bestellstatus | Ja · Versandstatus | Ja · Lieferstatus | Teilweise · Freigabe-Tag für Versand-Workflows |
| Automatischer Mitglieder- oder Kundenzugang | Ja · private Seite | Ja · Kundengruppe | Ja · Kundengruppe | Teilweise · private Mitglieder-PDFs |
| Privates Archiv für signierte PDFs, Audit-Trails und Zustellnachweise | Ja | Ja | Ja | Ja |
| Automatisierte Paket- und Funktionstests | Bestanden | Bestanden | Bestanden | Bestanden |
| End-to-End-Abnahme mit BlockSign und echtem Zahlungsanbieter im Testmodus | Offen | Offen | Offen | Offen |

Für die Vorab-Bestätigung mit Kreditkarte, PayPal oder SEPA-Lastschrift in PrestaShop steht die Online-Zahlungsanbindung aus. In Shopify legt der Shop die verfügbaren Zahlungsmethoden für den Rechnungslink fest.

Die Einrichtung steht in den Shop-READMEs. [WooCommerce](plugins/woocommerce/README.md) · [PrestaShop 9](plugins/prestashop/README.md) · [Shopware 6.7](plugins/shopware/README.md) · [Shopify](plugins/shopify/README.md)

GitHub Actions baut alle vier Pakete. [Workflow und ZIP-Artefakte](https://github.com/Maetti79/blocksign-shop-plugins/actions/workflows/check.yml)

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

Der [PHP-Kern](packages/php) enthält den BlockSign-REST-Client und die Freigabelogik der Shop-Module. Das [TypeScript-Paket](packages/typescript) enthält dieselben Freigaberegeln und den BlockSign-Client für Shopify. [BlockSign-API-Dokumentation](https://blocksign.io/api/docs)

- PHP ab 8.1 mit `php tests/gate.php`
- Node.js 24 mit `npm --prefix packages/typescript ci` und `npm --prefix packages/typescript test`
- [Gemeinsame Vertragstests](tests/gate.json)

Lizenz [GPL-3.0-or-later](LICENSE).
