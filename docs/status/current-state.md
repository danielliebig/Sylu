# Aktueller Stand — v34

## Funktionsfähig und bestätigt
- Sulu-Startseite mit Live-Produktdaten aus Sylius
- Katalog: Kategorien, Kategorieseiten, Produktdetails
- Warenkorb: hinzufügen, Menge ändern, entfernen
- Checkout: Adresse → Versand → Zahlung → Übersicht → Bestätigung,
  alle vier Demo-Zahlungsarten frei wählbar
- Bestellbestätigungsmail in Mailpit, eigener Absender, ohne toten Link
- Sylius-Admin auf `:8082`, Sulu-Admin auf `/admin/`
- 60 Tests grün (35 Unit, 10 Integration, 15 Smoke)
- `make phpstan` sauber (Sylius Level 5, Sulu `max` inkl. Tests)

## Umgesetzt, aber ungetestet
**Adyen-Zahlungsabwicklung.** Backend (Fixture, Routing,
`make payments-demo`/`payments-live`) und Drop-in-Widget im Checkout
sind gebaut; der Erfolgs-Redirect führt zur eigenen Bestätigungsseite.
Jeder Architekturbaustein ist gegen echten Plugin-Code verifiziert —
**der Zahlungsvorgang selbst lief nie**, da keine Sandbox-Zugangsdaten
vorliegen. Adyen ist per Default deaktiviert.

## Bekannte Einschränkungen
- Frontend nutzt hartkodiert den Channel `germany`; AT/CH sind
  konfiguriert und seit v33 datenseitig korrekt, aber nicht angebunden.
- Kein CI.
- Demo-Branding („Rockband") in 18 Dateien verteilt.
- Smoke-Tests können JS-gerenderte Teile nicht prüfen.

## Betrieb
- `make setup` ≈ 15–25 Min. Danach **ein** manueller Schritt:
  Startseite im Sulu-Admin veröffentlichen.
- Beim Ordnerwechsel zwingend `docker compose down -v` im alten Ordner.

## Offene Kleinigkeit aus der letzten Runde
`make test-smoke` und `make test-integration` geben ihre
Voraussetzungs-Hinweise **immer** aus, auch wenn alles läuft — gelb
formatiert, wirkt wie eine Warnung. Die Tests überspringen sich im
Fehlerfall ohnehin selbst mit klarer Meldung samt Abhilfe
(`make fixtures` bzw. `make setup`), die Zeilen sind also redundant.
Entfernt, Testlauf steht noch aus.

Nebenbefund: Die Hinweiszeile in `test-smoke` nannte eine
veröffentlichte Startseite als Voraussetzung. Die 15 Smoke-Tests rufen
`/` nicht auf — nur Controller-Routen sowie Admin- und Mailpit-Routing.
Die Angabe war falsch und ist in arc42 Kapitel 10 korrigiert.
