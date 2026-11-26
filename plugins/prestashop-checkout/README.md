# BlockSign Checkout für PrestaShop

BlockSign Checkout ist ein optionales Zahlungsmodul für PrestaShop 9 und benötigt **BlockSign eSign ab 0.15.0**. Es ergänzt den Ablauf für Warenkörbe mit Pflichtdokumenten vor der Bestellabgabe. Nach der Bestätigung legt PrestaShop eine unbezahlte Bestellung an und öffnet Stripe Checkout. Der bestätigte Warenkorb, der Gesamtbetrag, die Währung und das Käuferkonto werden mit der Bestellung abgeglichen.

## Einrichtung im Testshop

1. Installiere die beiden ZIPs `blocksign-esign-prestashop.zip` und `blocksign-checkout-prestashop.zip` im PrestaShop-Modulmanager. Richte BlockSign eSign ein und aktiviere für das Produkt die Phase **vor Bestellabgabe**. [PrestaShop-eSign-README](../prestashop/README.md)
2. Setze im serverseitigen Web-PHP `BLOCKSIGN_STRIPE_SECRET_KEY` und `BLOCKSIGN_STRIPE_WEBHOOK_SECRET`. Verwende für die Abnahme den Testmodus. `BLOCKSIGN_STRIPE_SHOP_ID` kann den vorgesehenen PrestaShop-Shop angeben. Standard ist der Hauptshop.
3. Aktiviere Kreditkarte, PayPal und SEPA-Lastschrift im Stripe-Konto. PayPal und SEPA-Lastschrift hängen von der Händlerregion und der Freischaltung bei Stripe ab. Mit `BLOCKSIGN_STRIPE_METHODS` kannst du eine kommagetrennte Teilmenge von `card,paypal,sepa_debit` auswählen. [Stripe PayPal](https://docs.stripe.com/payments/paypal/accept-a-payment) · [Stripe SEPA-Lastschrift](https://docs.stripe.com/payments/sepa-debit/accept-a-payment)
4. Trage in Stripe einen HTTPS-Webhook für den PrestaShop-Controller `blocksigncheckout/webhook` ein. Der typische Pfad lautet `/module/blocksigncheckout/webhook`. Ohne sprechende URLs verwende `/index.php?fc=module&module=blocksigncheckout&controller=webhook`. Abonniere `checkout.session.completed` und `checkout.session.async_payment_succeeded`. [Stripe Checkout Webhooks](https://docs.stripe.com/payments/checkout/fulfill-orders)
5. Erlaube **BlockSign Checkout** für die vorgesehenen Länder, Kundengruppen und Währungen in PrestaShop. Der Adapter nimmt eine Bestellung je Warenkorb in EUR ab 0,50 € an. Warenkörbe, die PrestaShop auf mehrere Bestellungen aufteilt, verwenden einen anderen freigegebenen Zahlungsweg.

## Zahlungsablauf

Der Shop bietet Stripe Checkout nach allen Pflichtbestätigungen der Vorab-Phase an. Dokumente mit dem Zeitpunkt **nach Zahlung** bleiben bis zur bestätigten Zahlung in der Warteschlange. Aus der Bestellansicht kann der Käufer eine noch offene Stripe-Sitzung wieder aufnehmen. Eine abgelaufene und unbezahlte Sitzung kann der Käufer neu starten.

Die Rückkehrseite zeigt den Bestellstatus. Die Zahlungsbuchung erfolgt über einen signierten Stripe-Webhook und einen Abruf der Checkout-Sitzung bei Stripe. SEPA-Lastschrift bleibt bis zur bestätigten Gutschrift offen. Mehrfache Ereignisse führen zu einer Buchung. Kann ein Zahlungsversuch nach einer länger dauernden Störung nicht zugeordnet werden oder meldet Stripe eine abgeschlossene Sitzung ohne erfolgreiche Zahlung, prüfe den Status im Stripe-Dashboard und kläre den bestehenden Versuch, bevor du für den Kunden eine neue Bestellung anlegst.

Beim Storno einer unbezahlten Bestellung beendet das Modul zuerst eine noch offene Stripe-Sitzung. Bei einer bereits eingeleiteten oder bestätigten Zahlung muss der Shop den Zahlungsstatus klären. Erfasse Erstattungen oder Stornierungen in PrestaShop, damit das eSign-Modul die Downloads anhand des PrestaShop-Bestellstatus erneut sperrt. Die Rückabwicklung einer gebuchten Zahlung erfolgt über den Erstattungsprozess des Shops.

## Status der Abnahme

Getestet mit simulierten Stripe-Antworten und PrestaShop-Bestelldaten. Die Abnahme mit einem echten PrestaShop-Testshop, einem BlockSign-Testkonto und den freigeschalteten Zahlungsarten steht aus. Prüfe dabei auch die Bestellrückkehr im Browser, eine verzögerte SEPA-Bestätigung, die Stornierung einer offenen Bestellung und die Bestellansicht nach Erstattung.
