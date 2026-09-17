# Backlog

## P1 — Versionsstrategie: Patch-Updates beim Projektstart (v38)
**Ziel:** Ein neues Projekt startet mit den neuesten Patches innerhalb
der Versionsregel (ADR-11) und bleibt trotzdem lauffähig.
**Scope:** `make setup` bleibt beim geprüften Lock-Stand. Ein eigener
Schritt aktualisiert beide Apps innerhalb der Constraints (Patches ja,
ungetestete Minor-Versionen nein) und lässt `make verify` und die Tests
laufen. Eigenes Sulu-Lockfile als Rückfallstand (das Sulu-Skeleton
liefert keins mit). Versionsbericht über verfügbare, bewusst nicht
übernommene Minor- und Major-Versionen, geprüft gegen ADR-11.
**Nicht-Scope:** automatische Minor-/Major-Sprünge; Änderungen an
`.github/` (ADR-10).
**Akzeptanz:** Nach dem Update-Schritt sind Symfony und Sulu auf dem
neuesten Patch; ein absichtlich kaputtes Update lässt den Schritt
fehlschlagen, ohne den Lock-Stand zu verlieren.
**Status:** Grundsatz entschieden (v37), Detailplan gegen den Code steht
aus.

## P2 — CI-Pipeline
**Ziel:** `make setup`, `make phpstan`, `make test-all` laufen
automatisch, statt von Hand ausgeführt zu werden.
**Scope:** Eine Pipeline-Definition (GitHub Actions, GitLab CI, Jenkins).
**Nicht-Scope:** Deployment, Registry, Secrets-Management.
**Akzeptanz:** Fehlschlag bei gebrochenem Setup oder rotem Test;
Laufzeit dokumentiert.
**Tests:** Absichtlich gebrochener Commit lässt die Pipeline rot werden.
**Status:** Bewusst offen gelassen und dem übernehmenden Team überlassen,
weil die Plattformwahl an dessen Zielinfrastruktur hängt — siehe ADR-10.
Letzter Punkt der ursprünglichen Lückenliste.

### Befunde, die man sonst selbst erarbeiten muss

Gegen den tatsächlichen Code geprüft, nicht geschätzt:

1. **Jedes Test-Target braucht Docker.** `SULU` und `SYL` im Makefile
   sind `docker compose exec -T`, auch für `make test`. Ein schneller
   „nur Unit-Tests"-Job ist damit nicht möglich: `docker-build`,
   `install-apps`, `docker-start` und `deps` müssen vorher laufen.
   Realistische Laufzeit eines vollen Durchlaufs 20–30 min, nicht 2.
   Konsequenz für den Zuschnitt: eher nächtlich und manuell auslösbar
   als bei jedem Push.
2. **Sylius ist gelockt, Sulu wird beim Setup aufgelöst.** Weil
   `composer.json` mit `sylius/sylius` eingecheckt ist, überspringt
   `docker/scripts/install-apps.sh` das `create-project` für Sylius;
   `make deps` installiert exakt den Stand aus `composer.lock`
   (`SYLIUS_VERSION` greift nur ohne `composer.json`). Sulu dagegen
   entsteht bei jedem Setup per `create-project` mit `SULU_VERSION`,
   seit v37 Default `~3.0.9` (ADR-11): neueste 3.0.x-Patches, kein
   ungetestetes 3.1. Eine nächtliche Pipeline kann auf der Sulu-Seite
   also rot werden, ohne dass sich im Repo etwas geändert hat. Wer das
   nicht will, setzt `SULU_VERSION` im Pipeline-Environment auf eine
   exakte Version.
3. **`vendor/bin/phpstan analyse` ohne `-c` greift die falsche
   Konfiguration.** Ohne Argument gilt Sylius' `phpstan.dist.neon`:
   Level 9 auf `bin/ config/ public/ src/ tests/`. Nur `make phpstan`
   nimmt `phpstan-kickstarter.dist.neon` (Level 5, Begründung in
   ADR-08) und ruft vorher `sulu-theme` auf, damit die Sulu-Seite nicht
   alte Dateistände analysiert (FIXES.md Nr. 48).
4. **`make setup` verschluckt einen Fehler.** Im Target `deps` ist der
   Sulu-`cache:clear` als `… && printf … || printf …` verdrahtet. Der
   Exit-Code der Zeile ist der von `printf`, also immer 0: ein
   gescheiterter Cache-Bau meldet sich rot im Log, bricht `make setup`
   aber nicht ab. Wer `make setup` unverändert in eine Pipeline hängt,
   hat an dieser Stelle ein falsches Grün. Bewusst nicht mitbehoben, um
   das Verhalten von `make setup` hier nicht stillschweigend zu ändern —
   entweder im Makefile geradeziehen oder in der Pipeline einen eigenen
   Schritt für den Sulu-Cache vorsehen.
5. **Plattform ist unkritisch.** `docker-compose.yaml` pinnt bewusst
   kein `platform: linux/arm64` (Kommentar dort). x86_64-Runner laufen
   unverändert — und decken damit die Zielplattform Linux x86_64 ab,
   die bei lokaler Entwicklung auf Apple Silicon nie getestet wird.
6. **Falsches Grün bei null Tests ist abgedeckt.** Seit FIXES.md Nr. 50
   melden `test-integration` und `test-smoke` einen Lauf ohne
   ausgeführte Tests als Fehler (`--fail-on-skipped`). Ohne das hätte
   eine Pipeline bei fehlenden Containern grün geleuchtet.

### Unterentscheidung: geerbte Skeleton-Workflows — entschieden (v37)

**Entscheidung:** Option B, liegen lassen — `.github/` stammt aus dem
Sylius-Skeleton und bleibt unverändert (Nachtrag in ADR-10). Die
folgende Analyse bleibt als Grundlage für das übernehmende Team stehen.

`.github/` enthält unverändert das, was `composer create-project
sylius/sylius-standard` mitbringt: `workflows/build.yml`, `ci.yaml`,
`ci_js.yaml`, `ci_static-checks.yaml`, `auto-merge.yml`, `matrix.json`
sowie `dependabot.yml`, `autolabeler.yml`, `CODEOWNERS`. Nichts davon
wurde für dieses Projekt geschrieben oder angepasst. `build.yml`
triggert auf Push, Pull Request und nächtlich um 03:00 UTC und ruft die
übrigen Workflows auf. Sie führen unter anderem aus:

- `composer update --no-interaction --no-scripts`, also ohne Lock —
  gegen die bewusste Versionsfixierung, vor deren Aufweichung
  `make verify` sogar warnt
- `vendor/bin/phpstan analyse` ohne `-c`, siehe Befund 3 oben
- Behat- und JS-Suiten des Skeletons; `features/` ist in diesem
  Projekt leer
- `auto-merge.yml` erwartet ein Secret `DEPENDABOT_TOKEN` und würde
  Minor-Upgrades automatisch mergen

**Option A — entfernen.** Sauberer Ausgangspunkt, kein rotes
Actions-Tab, niemand debuggt fremde Workflows. Nachteil: die
Skeleton-Struktur als Vorlage ist weg und muss bei Bedarf aus einem
frischen `sylius-standard` geholt werden.
**Option B — liegen lassen.** Nichts geht verloren, aber die Läufe
scheitern vermutlich dauerhaft, und eine falsche Vorlage liegt genau
am Pfad, an dem man eine richtige erwartet. Das Muster hat in diesem
Projekt schon zweimal Zeit gekostet (FIXES.md Nr. 3 und Nr. 45).
**Option C — stilllegen.** Trigger auf `workflow_dispatch` reduzieren
und einen Kommentarkopf einsetzen. Kompromiss, erzeugt aber Dateien,
die aussehen wie gepflegt und es nicht sind.

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
