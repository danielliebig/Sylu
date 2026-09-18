# Handoff

## Was das ist
Kickstarter für Sulu-CMS-plus-Sylius-Shop-Projekte, headless, DACH.
Stand v38. Für Agenturteams gedacht, nicht als Wegwerf-Demo.

## Erster Start
```
tar xzf sulu-sylius-kickstarter-v36.tar.gz
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
   nach `sylius-overlay/templates/bundles/`, nicht ins Sulu-Overlay.
   `make verify` Abschnitt 16 prüft das inzwischen.
4. **Eigene Klassen sind `final`.** PHPUnit kann sie nicht mocken; in
   Tests echte Instanzen mit `MockHttpClient` bauen.
5. **Sulu-Inhalte brauchen manuelles Veröffentlichen.** Änderungen am
   Seiteninhalt landen zunächst nur im Entwurf.
6. **Änderungen direkt in `sylius/` oder `sulu/` gehen verloren.** Beide
   Ordner werden erzeugt und sind ignoriert; Quelle ist das jeweilige
   Overlay. `make verify` Abschnitt 19 meldet eine Overlay-Datei, die
   nicht in der App angekommen ist. Umgekehrt gilt: Wer eine Datei nur
   in der App anlegt, verliert sie beim nächsten `make sylius-theme`.
7. **Es gibt keine CI-Konfiguration mehr.** Das geerbte `.github/` liegt
   seit v38 im generierten `sylius/` und damit außerhalb des Repos. Die
   Workflows stammten unverändert aus `sylius/sylius-standard` und
   wurden für dieses
   Projekt nie angepasst: `composer update` ohne Lock, PHPStan mit
   Sylius' Level-9-Konfiguration statt der eigenen, Behat gegen ein
   leeres `features/`, Auto-Merge mit einem Secret, das es hier nicht
   gibt. Wer CI aufsetzt, fängt besser bei den Make-Targets an — siehe
   ADR-10 und Backlog „P2 — CI-Pipeline".
8. **„Keine Container nötig" bei `make test` heißt „keine Fixtures".**
   Auch die Unit-Tests laufen über `docker compose exec -T sulu`. Ohne
   laufenden Stack gibt es keinen Testlauf, nur eine Docker-Fehlermeldung.

## Ungetestet
Adyen. Backend und Widget sind gebaut und gegen Plugin-Code verifiziert,
der Zahlungsvorgang lief aber nie — es fehlen Sandbox-Zugangsdaten.
Standardmäßig deaktiviert.

## Nächste Schritte
Siehe `docs/status/backlog.md`. Höchste Priorität: Adyen-Verifizierung
und zweiter Anlauf für die Entfernbarkeit des Demo-Brandings. Die
CI-Pipeline steht dort ebenfalls, ist aber kein offener Auftrag an euch,
sondern eine offen gelassene Entscheidung (ADR-10) — Befunde und
Fallstricke sind im Backlog gesammelt.
