# BlockSign eSign für Shopware

[Zur Projektübersicht](../../README.md)

## Installation und Betrieb

1. Installiere `blocksign-esign-shopware.zip` in einem **Shopware-6.7-Testshop** unter **Erweiterungen → Meine Erweiterungen**. Das ZIP lässt sich mit `scripts/build-shopware.sh` bauen.
2. Hinterlege in der Erweiterungskonfiguration den API-Key und einen absoluten Pfad zu einem privaten PDF-Verzeichnis außerhalb von `public`. Alternativ verwaltet der Server die Werte über `BLOCKSIGN_ESIGN_API_KEY` und `BLOCKSIGN_ESIGN_FILES`. Serverwerte haben Vorrang. Gib die Dateien auch über Webserver-Aliase nicht öffentlich frei.
3. Lege `agreement.pdf` und für Download-Produkte `download.pdf` im privaten Verzeichnis ab. Verwende für geschützte Downloads weder Shopwares öffentlich erreichbare Medien-URLs noch Shopwares nativ ausgelieferte Download-Dateien.
4. Lege den Verkaufskanal, das Signaturniveau und den Zeitpunkt fest. Aktiviere die Erweiterung für den betreffenden Verkaufskanal.
5. Starte jede Minute `php bin/console blocksign:process` unter dem Shop-Benutzer. Der Prozess lädt Pflichtdokumente hoch, sendet Einladungen, prüft Bestätigungen und verarbeitet Freigaben.

## Produkte, Kategorien und Dokumente

Aktiviere am Produkt unter **Zusatzfelder → BlockSign eSign** das Feld **BlockSign-Bestätigung erforderlich**. Alternativ verwende die Regeln für Produkt- und Kategorie-IDs oder das globale Profil für alle Produkte. Ohne eigene Regel liefert ein geschütztes Produkt `download.pdf` aus.

Trage zusätzliche Pflichtdokumente zeilenweise ein, etwa `mandate.pdf | FES | 2 | 40 | 50` oder `mitteilung.pdf | POD`. Beim ersten Dokument lässt sich EES, FES, QES oder Zustellnachweis auswählen. Für Signaturen werden PDF-Seite sowie X/Y-Position konfiguriert. Die Shop-Zuordnung verwendet Shopware-UUIDs.

```json
{
  "categories": {
    "966ea0a6a44f4980a842feff1725b8ab": {
      "kind": "shipping",
      "timing": "before_order",
      "agreement": "liefervereinbarung.pdf"
    }
  },
  "products": {
    "d636453dab924c6d9050bb84f6eaca89": {
      "kind": "download",
      "timing": "after_payment",
      "agreement": "exposee-erklaerung.pdf",
      "download": "exposee.pdf",
      "documents": "mandate.pdf | EES | 1 | 50 | 50"
    }
  }
}
```

Verwende für PDF-Namen ASCII-Buchstaben, Ziffern, Punkte, Bindestriche und Unterstriche. Kategorie-Regeln werden in der angegebenen Reihenfolge angewandt. Produktregeln überschreiben die geerbten Werte. `kind` kann `download`, `shipping` oder `membership` sein. Identische Pflichtdokumente mit identischen Einstellungen werden pro Warenkorb zusammengefasst.

## Freigabeverhalten

- **Ohne Zahlung.** Bei 0 € wird keine bestätigte Zahlung erwartet. Abhängig von der Regel startet die Bestätigung vor oder nach der Bestellung.
- **Vor Zahlung.** `before_order` und `before_payment` verlangen die Bestätigung bereits auf der letzten Checkout-Seite, bevor Shopware Bestellung und Zahlungsvalidierung ausführt. Der Worker versendet die Einladungen. Warenkorb, Konto, Betrag, Währung und Dokumente werden an die Bestätigung gebunden. Die Bestätigung gilt höchstens sieben Tage und kann einmal für eine Bestellung verwendet werden.
- **Nach Zahlung.** Pflichtdokumente dieser Phase starten erst bei vollständig bezahlter Bestellung. In gemischten Warenkörben können weitere Dokumente bereits vor der Bestellung erforderlich sein.
- **Downloads.** Die Shopware-Bestellansicht verlinkt den Status und die freigegebenen Dateien. Jeder Abruf prüft Konto, Bestellung, Zahlungsstatus, Bestätigung und Dateihash.
- **Versand.** `shipping` sperrt Shopwares Versandstatus bis zur bestätigten Zahlung und den vollständigen Pflichtdokumenten. ERP und externe Logistik müssen diese Freigabe berücksichtigen.
- **Mitgliedschaft.** `membership` benötigt die UUID der Zielgruppe im Feld `group`. Der Worker weist diese Shopware-Kundengruppe nach der Freigabe dem Käuferkonto zu. Eine Shopware-Kundengruppe kann Preise und Berechtigungen ändern. Für Austritt oder Rückerstattung wird die Gruppe im Backoffice geändert.

## Nachweise archivieren

Hinterlege ein beschreibbares, privates Archivverzeichnis in der Erweiterungskonfiguration oder über `BLOCKSIGN_ESIGN_ARCHIVE`. Nach vollständiger Bestätigung archiviert der Worker signierte PDFs, Audit-Trails und elektronische Zustellnachweise im Bestellordner. Halte PDF-Dateien für bestehende Bestellungen unter ihren ursprünglichen Namen und mit unverändertem Inhalt verfügbar.

## Prüfung vor dem Live-Einsatz

Verwende einen Shopware-6.7-Testshop mit BlockSign-Testkonto und einem Zahlungsanbieter im Testmodus. Prüfe den Standard-Storefront-Checkout und die Statusseite im Kundenkonto. Prüfe zudem die Sperre nach einer Änderung am Warenkorb und die Freigabe nach der letzten Zahlung beziehungsweise Signatur. Zahlungs-Erweiterungen mit einem eigenen Express-Checkout benötigen eine gesonderte Integration und Prüfung, da sie vor Shopwares zentraler Zahlungsvalidierung eigene Anbieter-Sitzungen starten können.
