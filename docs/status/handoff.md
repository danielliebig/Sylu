# Handoff

## Was das ist
Kickstarter für Sulu-CMS-plus-Sylius-Shop-Projekte, headless, DACH.
Stand v34. Für Agenturteams gedacht, nicht als Wegwerf-Demo.

## Erster Start
```
tar xzf sulu-sylius-kickstarter-v34.tar.gz
chmod +x docker/scripts/*.sh
cp .env.docker.example .env.docker
make setup
```
15–25 Minuten. Danach **ein** manueller Schritt: Startseite unter
`http://localhost/admin/` veröffentlichen.

## Zugänge
| Was | URL | Login |
|---|---|---|
| Storefront | http://localhost/ | – |
| Sulu-Admin | http://localhost/admin/ | admin / admin |
| Sylius-Admin | http://localhost:8082/admin/ | admin@demo.de / admin |
| Mailpit | http://localhost/mail/ | – |
| phpMyAdmin | http://localhost:8081/ | root / root |

## Wichtigste Befehle
```
make verify            Prüft 16 Bereiche, läuft vor den Fixtures
make test              35 Unit-Tests, ohne Container
make test-integration  10 gegen die Shop-API
make test-smoke        15 durchs Frontend via Caddy
make test-all          alle 60
make phpstan           statische Analyse
make payments-demo     Demo-Zahlungsarten
make payments-live     Adyen (verlangt Zugangsdaten)
make doctor            Schnelldiagnose
```

## Dokumente im Projektpaket
- `FIXES.md` — 49 dokumentierte Fehler und Entscheidungen mit Begründung.
  Vor jeder größeren Änderung querlesen; vieles dort ist mühsam
  erarbeitetes Wissen über Versionseigenheiten. Wird 180-mal aus Code,
  Konfiguration und Skripten heraus referenziert — nicht verschieben.
- `CHANGELOG.md` — Version für Version, knapp.
- `README.md` — Installation, Architektur, Entwicklerhinweise.
- `QUICKSTART.md` — Kurzfassung des Starts.
- `CLAUDE.md` — Kontext für KI-gestützte Weiterarbeit, inkl. Risikotabelle.
- `docs/arc42.md`, `docs/decisions.md` — Architekturdokumentation und
  ADR-01 bis ADR-09. Dauerhaft, für übernehmende Teams gedacht.
- `docs/status/` — Arbeitsstand dieser Datei, `current-state.md` und
  `backlog.md`. Ändert sich pro Version, richtet sich an die
  Weiterentwicklung.

Nicht von uns: `CONFLICTS.md` stammt aus Sylius-Standard und
dokumentiert `composer.json`-Konflikte.

## Fallen, die Zeit kosten
1. **Ordnerwechsel ohne `down -v`.** Alle Versionsordner teilen dasselbe
   Docker-Volume. Ein „frischer" Ordner startet sonst auf alten Daten.
2. **`make setup` bricht ab, trotzdem committen.** Ergibt einen
   unvollständigen Commit. Erst die Erfolgsmeldung abwarten.
3. **Bundle-Overrides in der falschen App.** Sylius-Overrides gehören
   nach `templates/bundles/`, nicht ins Sulu-Overlay. `make verify`
   Abschnitt 16 prüft das inzwischen.
4. **Eigene Klassen sind `final`.** PHPUnit kann sie nicht mocken; in
   Tests echte Instanzen mit `MockHttpClient` bauen.
5. **Sulu-Inhalte brauchen manuelles Veröffentlichen.** Änderungen am
   Seiteninhalt landen zunächst nur im Entwurf.
6. **`.kickstarter-overlay/` und `.sylius-original/` nicht committen.**
   Beide erzeugt `install-apps.sh`: Schritt 1 legt einen Abzug der
   eigenen Dateien an, bevor der Sylius-Installer sie überschreibt,
   Schritt 3 holt sie zurück; Schritt 0 schiebt Sylius' eigene
   `compose.yml` beiseite, damit `docker compose` ihr nicht Vorrang
   gibt. Schritt 1 wird übersprungen, sobald Sylius installiert ist —
   ein committeter Abzug friert damit ein und weicht still vom
   Projektstand ab. Beide stehen in `.gitignore`.

## Ungetestet
Adyen. Backend und Widget sind gebaut und gegen Plugin-Code verifiziert,
der Zahlungsvorgang lief aber nie — es fehlen Sandbox-Zugangsdaten.
Standardmäßig deaktiviert.

## Nächste Schritte
Siehe `docs/status/backlog.md`. Höchste Priorität: CI-Pipeline,
Adyen-Verifizierung, zweiter Anlauf für die Entfernbarkeit des
Demo-Brandings.
