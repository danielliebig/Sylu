# Backlog

## P2 — CI-Pipeline
**Ziel:** `make setup`, `make phpstan`, `make test-all` laufen
automatisch bei jedem Commit.
**Scope:** Eine Pipeline-Definition (GitHub Actions oder GitLab CI).
**Nicht-Scope:** Deployment, Registry, Secrets-Management.
**Akzeptanz:** Fehlschlag bei gebrochenem Setup oder rotem Test;
Laufzeit dokumentiert.
**Tests:** Absichtlich gebrochener Commit lässt die Pipeline rot werden.
**Hinweis:** Letzter Punkt der ursprünglichen Lückenliste. Seit
FIXES.md Nr. 50 melden `test-integration` und `test-smoke` einen Lauf
ohne ausgeführte Tests als Fehler — ohne das hätte die Pipeline bei
fehlenden Containern grün geleuchtet.

## P2 — Adyen gegen echte Sandbox testen
**Ziel:** Den nie ausgeführten Zahlungsvorgang verifizieren.
**Scope:** Zugangsdaten in `.env.docker`, Container neu erzeugen,
`make payments-live`, kompletter Checkout mit Testkarte.
**Nicht-Scope:** Webhooks (brauchen öffentliche URL), Express-Checkout.
**Akzeptanz:** Widget rendert, Testzahlung schließt ab, Weiterleitung
landet auf der eigenen Bestätigungsseite.
**Tests:** Manuell im Browser; Smoke-Tests können das JS-Widget nicht
prüfen.
**Blockiert durch:** fehlende Sandbox-Zugangsdaten.

## P2 — Agentur-Tauglichkeit, zweiter Anlauf
**Ziel:** Demo-Branding beim Projektstart entfernbar machen.
**Scope:** Zuerst das Problem aus ADR-09 lösen — Sulu-Inhalte brauchen
manuelles Veröffentlichen, wodurch Namensänderungen unsichtbar bleiben.
Danach: neutrale Strukturnamen, optionale eigene Produktdaten.
**Nicht-Scope:** Interaktiver Prompt in `make setup` (bricht CI).
**Akzeptanz:** Ein Entwickler kann ohne Suchen-und-Ersetzen in 18
Dateien starten.
**Tests:** `make setup` bleibt vollautomatisch; `make test-all` grün.

## P3 — `localhost`-Annahmen für Serverbetrieb prüfen
**Ziel:** Deployment außerhalb von localhost ermöglichen.
**Scope:** Audit auf hartkodierte Hostnamen; `APP_ENV`, offene Ports,
TLS, Secrets bewerten.
**Nicht-Scope:** Tatsächliches Deployment.
**Akzeptanz:** Liste der nötigen Änderungen liegt vor.
**Hinweis:** Der Caddy-Redirect nutzt bereits `{host}`.

## P3 — Echte Produktfotos
**Ziel:** Generierte Icons ersetzen.
**Scope:** Sechs Fotos nach `var/demo-images/<code>.jpg`.
**Akzeptanz:** Katalog zeigt Fotos statt Icons.
**Blockiert durch:** Claude kann keine Bilder beschaffen
(Netzwerkbeschränkung).

## P3 — Mehrsprachigkeit über DACH hinaus
**Ziel:** Channel-Wahl nicht mehr hartkodiert.
**Scope:** `CHANNEL = 'germany'` in vier Controllern auflösen.
**Akzeptanz:** AT/CH-Storefront erreichbar.
**Hinweis:** Datenseitig seit v33 korrekt (Übersetzungen vorhanden).

## P3 — Mail-Template-Link
**Ziel:** Kein Verweis auf abgeschaltete Sylius-Seiten.
**Status:** Der tote Link wurde entfernt. Ob ein Ersatzlink auf die
eigene Bestätigungsseite sinnvoll ist, wurde bewusst verneint
(Seite ist nicht für späteren Aufruf gedacht).
