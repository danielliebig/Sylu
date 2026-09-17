# Architekturentscheidungen

## ADR-01 Caddy statt nginx als Router
**Status:** umgesetzt
**Entscheidung:** Caddy übernimmt das Routing.
**Begründung:** FrankenPHP *ist* Caddy. nginx ersetzt
`proxy_set_header` pro `location`-Block statt zu vererben — ein Block
mit einem eigenen Header verlor dadurch den Host-Header und brach die
Channel-Erkennung.
**Konsequenzen:** Eine Webserver-Technologie im Stack. Caddys
Wildcard-Kombination (Anfang + Mitte) ist fehlerhaft, daher Locales
explizit auflisten.

## ADR-02 Headless: Sulu-Backend als Proxy
**Status:** umgesetzt
**Entscheidung:** Option A — Sulus PHP-Backend ruft die Shop-API auf,
nicht der Browser.
**Begründung:** Cart-Token bleibt serverseitig; nur eine öffentliche
Herkunft.
**Konsequenzen:** Sylius-Frontend abgeschaltet. Ausnahme: das
Adyen-Widget muss aus PCI-Gründen direkt mit Sylius sprechen.

## ADR-03 Sylius-Admin auf eigenem Port
**Status:** umgesetzt
**Entscheidung:** Port 8082, unpräfixiert.
**Begründung:** Sulu und Sylius bauen Admin-Assets unter identischem
Pfad `/build/admin/` — per Pfad nicht auflösbar.
**Konsequenzen:** Admin unter `:8082/admin/`. Alter Pfad leitet weiter.

## ADR-04 Adyen als Zahlungsanbieter
**Status:** Backend + Widget umgesetzt, nie gegen echte Sandbox getestet
**Entscheidung:** Adyen (europäische Datensouveränität), **eine**
Zahlungsart statt drei.
**Begründung:** Adyens Drop-in bündelt PayPal/Klarna/Karte selbst.
**Konsequenzen:** Widget lädt Sylius' Shop-Bundle (5,88 MB), nur wenn
Adyen gewählt. Webhooks brauchen öffentliche URL — lokal außen vor.

## ADR-05 Bildstrategie zweistufig
**Status:** umgesetzt
**Entscheidung:** lokale Datei → generiertes GD-Icon. Pixabay-Download
komplett entfernt.
**Begründung:** Pixabay liefert dauerhaft 403.
**Konsequenzen:** Eigene Fotos über `var/demo-images/<code>.jpg`.
Icons wurden visuell (Python/PIL) geprüft, nicht nur berechnet.

## ADR-06 Sulu-Content-Locale auf `de`
**Status:** umgesetzt
**Entscheidung:** Webspace-Overlay mit `language="de"`.
**Begründung:** Skeleton liefert nur Englisch; DACH-Shop mit
„en"-Sprachumschalter ist falsch.
**Konsequenzen:** Erfordert frischen Aufbau (`down -v`) — Inhalte sind
pro Locale gespeichert. Admin-UI-Sprache bleibt davon unberührt.

## ADR-07 Teststrategie dreistufig
**Status:** umgesetzt
**Entscheidung:** Unit / Integration / Smoke als getrennte Gruppen.
**Begründung:** Ein Testbefehl, der ohne Docker fehlschlägt, wird nicht
mehr ausgeführt. Mocks können Sylius-Änderungen prinzipiell nicht
erkennen — dafür Integrationstests.
**Konsequenzen:** Smoke-Tests laufen über Caddy und prüfen damit auch
das Routing. Grenze: curl statt Browser, JS-gerenderte Teile
(Adyen-Widget, Sulu-Admin) nur oberflächlich prüfbar.

## ADR-08 PHPStan Level 5 auf Sylius-Seite
**Status:** umgesetzt
**Entscheidung:** Eigene Konfiguration mit Level 5 statt Sylius' Level 9.
**Begründung:** Gemessen 3 Befunde bei 5, 52 bei 9 — überwiegend aus
absichtlich untypisierten `object`-Parametern, die selbst ein Fix gegen
Sylius' Namespace-Verschiebungen sind.
**Konsequenzen:** Sulu-Seite läuft auf `max`, inklusive `tests`.

## ADR-09 v27 verworfen (Agentur-Tauglichkeit)
**Status:** verworfen, Bedürfnis offen
**Entscheidung:** Neutrale Namen, `make init-project`, eigene
Produktdaten wurden gebaut und wieder entfernt.
**Begründung:** `make init-project` änderte Konfigurationsdateien, aber
der sichtbare Homepage-Text blieb — Sulu speichert Inhalte als Entwurf
und braucht manuelles Veröffentlichen.
**Konsequenzen:** „Rockband" steckt weiterhin in 18 Dateien.
Bei erneutem Anlauf zuerst das Veröffentlichen-Problem lösen.

## ADR-10 CI dem übernehmenden Team überlassen
**Status:** bewusst offen
**Entscheidung:** Keine Pipeline-Definition im Projektpaket.
**Begründung:** Die Plattformwahl (GitHub Actions, GitLab CI, Jenkins)
hängt an der Zielinfrastruktur des übernehmenden Teams. Eine
mitgelieferte Definition wäre eine Vorentscheidung, die dort
wahrscheinlich ohnehin ersetzt würde.
**Konsequenzen:** `.github/` enthält weiterhin das unveränderte
Sylius-Standard-Skeleton, das für dieses Projekt nicht gilt. Die
geprüften Voraussetzungen und Fallstricke für einen späteren Anlauf
stehen im Backlog unter „P2 — CI-Pipeline", damit sie nicht erneut
erarbeitet werden müssen.
**Nachtrag v37:** Die Unterentscheidung zu `.github/` ist getroffen —
es bleibt unverändert, weil es aus dem Sylius-Skeleton stammt
(Backlog, Option B). Dependabot und die Skeleton-Workflows laufen damit
weiter. Wer die Läufe stoppen will, ohne das Repo zu ändern, deaktiviert
GitHub Actions in den Repository-Einstellungen.

## ADR-11 Versionsregel für PHP, Datenbank und Node.js
**Status:** umgesetzt (v37)
**Entscheidung:** Maßgeblich ist die Upstream-CI, also die öffentliche
Testkonfiguration von Sylius **und** Sulu — nicht deren Dokumentation.
Gewählt wird die höchste PHP-Version, die beide testen, dazu die höchste
MySQL-Version, die beide **zusammen mit dieser PHP-Version** testen.
Node.js folgt derselben Regel. Stand v37: PHP 8.5, MySQL 8.4, Node.js 24
(geprüft gegen Sylius 2.2.9 und Sulu 3.0.9).
**Begründung:** Die Dokumentation reicht nicht: Sylius nennt nach oben
offene Mindestversionen, Sulu nennt gar keine Datenbankversion. Und die
Kombination zählt, weil Sulu nur feste Paare testet — MariaDB 11.4 und
PHP 8.5 jeweils einzeln, aber nicht zusammen. Gemeinsam getestet ist mit
PHP 8.5 nur MySQL 8.4. Eigene Tests (`make verify`, `make test-all`)
bleiben zusätzlich Pflicht, ersetzen die Upstream-Absicherung aber nicht.
**Konsequenzen:** MariaDB wurde verworfen. Neuere Versionen (PHP 8.6,
MySQL 9.7) kommen erst, wenn beide Projekte sie in ihrer CI testen.
Sulu ist auf `~3.0.9` begrenzt, damit kein ungetestetes 3.1 einzieht.
`make setup` installiert weiterhin den geprüften Lock-Stand;
Patch-Updates beim Projektstart sollen als eigener Schritt folgen
(geplant für v38). Der MySQL Community Server steht unter GPLv2 — für
gehostete Shops unkritisch; wird MySQL in ausgelieferte Software
eingebettet oder mitgebündelt, ist das juristisch zu prüfen.
