# Aktueller Stand — v39

## Neu in v39
- Der Redis-Dienst ist entfernt. Er lief seit jeher mit, ohne dass ihn
  eine der beiden Apps benutzte; Cache und Sessions liegen im
  Dateisystem. Welches Backend im Betrieb genutzt wird, entscheidet das
  übernehmende Team (ADR-13), das Rezept steht in `handoff.md`
- `verify.sh` Abschnitt 20: jeder Host aus einer DSN in
  `docker-compose.yaml` muss dort auch als Dienst deklariert sein —
  fängt stehengebliebene Variablen und aus alten Versionsordnern
  kopierte Compose-Dateien
- Die PHP-Extension `redis` bleibt im Image, damit ein späteres Anbinden
  keinen Image-Neubau braucht

## Neu in v38
- Kickstarter im Root, `sylius/` und `sulu/` werden erzeugt und sind
  ignoriert; Quellen liegen in `sylius-overlay/` und `sulu-overlay/`
  (ADR-12). Eingecheckt sind rund 60 statt 269 Dateien
- `kickstarter.yaml` hält nur die Versionen von Sylius und Sulu;
  PHP 8.5, MySQL 8.4 und Node 24 werden per `make versions` aus der
  Upstream-CI beider Projekte abgeleitet
- `make freeze-locks` schreibt den geprüften Abhängigkeitsstand beider
  Apps in die Overlays; die Sulu-Seite hat damit erstmals ein Lockfile
- `make verify` hat 21 Abschnitte (0 bis 20) und ist einzeln aufrufbar:
  `make verify SECTIONS="18 19"`
- Bestätigt: eigene Produktfotos landen jetzt im Container
  (FIXES.md Nr. 52); der Checkout-Patch lief seit Version 1 ins Leere
  (Nr. 53)

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
- Kein CI — bewusst offen, siehe ADR-10.
- Demo-Branding („Rockband") in 18 Dateien verteilt.
- Smoke-Tests können JS-gerenderte Teile nicht prüfen.

## Betrieb
- `make setup` ≈ 15–25 Min. Danach **ein** manueller Schritt:
  Startseite im Sulu-Admin veröffentlichen.
- Beim Ordnerwechsel zwingend `docker compose down -v` im alten Ordner.

## Erledigt in dieser Runde
`make test-integration` und `make test-smoke` meldeten bei
abgeschaltetem Stack Erfolg, obwohl null Tests liefen — PHPUnit endet
mit Exitcode 0, wenn alles übersprungen wurde. Behoben mit
`--fail-on-skipped`; der Voraussetzungs-Hinweis steht jetzt im
Fehlerzweig, ein erfolgreicher Lauf ist damit gelbfrei. Details in
FIXES.md Nr. 50.

Nebenbefund: Die Hinweiszeile in `test-smoke` nannte eine
veröffentlichte Startseite als Voraussetzung. Die 15 Smoke-Tests rufen
`/` nicht auf — nur Controller-Routen sowie Admin- und Mailpit-Routing.
In arc42 Kapitel 10 korrigiert.
