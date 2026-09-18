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

**Nachtrag (v38):** Mit der neuen Struktur liegt das geerbte `.github/`
im generierten `sylius/` und damit außerhalb des Repos. Es gibt also
keine CI-Konfiguration mehr, auch keine unpassende — Dependabot
(`directory: "/"`) und die Sylius-Workflows sind damit weg. Wer eine
Pipeline aufsetzt, beginnt bei null, was der Absicht dieser Entscheidung
entspricht.

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
`make setup` installiert weiterhin den geprüften Lock-Stand. Seit v38
werden die drei Versionen nicht mehr von Hand gepflegt, sondern von
`docker/scripts/resolve-versions.sh` aus der Upstream-CI abgeleitet
(ADR-12). Die Quellen dafür: Sylius `.github/workflows/matrix.json`,
wobei `minimal` und `full` beide zählen — PHP 8.5 mit MySQL steht nur in
`minimal`; Sulu `.github/workflows/test-application.yaml` samt
`tests/docker/docker-compose.mysql-*.yml`, weil der Matrixname
(`mysql-80`) nicht die Version ist. Node.js lässt sich bei Sylius nicht
ableiten (`engines.node: >=20`, nach oben offen), die Zahl kommt also
allein aus Sulus Matrix. Der MySQL Community Server steht unter GPLv2 — für
gehostete Shops unkritisch; wird MySQL in ausgelieferte Software
eingebettet oder mitgebündelt, ist das juristisch zu prüfen.

## ADR-12 Kickstarter im Root, Anwendungen in eigenen Ordnern
**Status:** umgesetzt (v38)
**Entscheidung:** Der Repository-Root ist der Kickstarter und enthält
keinen Anwendungscode. Sylius und Sulu werden beim Setup in `./sylius`
und `./sulu` erzeugt, beide Ordner sind ignoriert. Was uns gehört, liegt
in `sylius-overlay/` und `sulu-overlay/`, jeweils samt `composer.json`
und `composer.lock`. Die Versionsabsicht steht in `kickstarter.yaml`,
zwei Composer-Constraints; PHP, MySQL und Node.js werden daraus in
`versions.env` abgeleitet (`make versions`).
**Begründung:** Bis v37 lag Sylius im Root, vermischt mit den eigenen
Dateien: 213 von 269 eingecheckten Dateien stammten aus dem
Sylius-Skeleton. Der Installer musste unsere Dateien deshalb vor dem
`create-project` sichern und danach zurückspielen, eigene Konfigurationen
brauchten abweichende Namen, und ein Update des Skeletons wäre nicht von
eigenen Änderungen zu trennen gewesen. Mit getrennten Ordnern kollidiert
nichts, und das Overlay wird einfach darüber kopiert.
**Verworfene Alternativen:**
- *Nur räumlich trennen*, also die eingecheckte Sylius-App nach `./sylius`
  verschieben. Hätte die Asymmetrie zur generierten Sulu-Seite behalten
  und die Skeleton-Dateien weiter im Repo geführt.
- *Root-`composer.json` als Manifest.* Composer versteht eine
  `composer.json` als etwas Installierbares: ein `composer install` im
  Root hätte Sylius und Sulu in einen gemeinsamen `vendor/` auflösen
  wollen, obwohl die Seiten heute auf verschiedenen DBAL-Majors liegen
  (Sylius 3.10, Sulu 4). Daher eine eigene Datei mit eigenem Namen, die
  niemand versehentlich installiert.
**Konsequenzen:** Eingecheckt sind rund 60 statt 269 Dateien. Das geerbte
`.github/` liegt jetzt im generierten `sylius/` und ist damit außerhalb
des Repos — es gibt also keine CI-Konfiguration mehr, was zu ADR-10 passt
und dort als Nachtrag festgehalten ist. Die Skeleton-Constraint
wird aus dem Manifest auf die Minor-Serie reduziert (`~2.2.9` → `^2.2`),
weil `sylius/sylius-standard` unabhängig vom Framework versioniert ist und
bei 2.2.4 endet. Neue Host-Abhängigkeit: `python3` für die Ableitung.
`verify.sh` prüft die Struktur in Abschnitt 19 und die abgeleiteten
Versionen in Abschnitt 18.

## ADR-13 Kein Cache- und Session-Dienst im Kickstarter
**Status:** umgesetzt (v39)
**Entscheidung:** Der Stack enthält keinen Redis- oder Valkey-Dienst.
Symfony-Cache und Sessions liegen im Dateisystem, `cache.yaml` bleibt in
beiden Apps das unveränderte Rezept. Wo Cache und Sessions im Betrieb
landen, entscheidet das übernehmende Team; das Rezept dafür steht in
`docs/status/handoff.md`.
**Begründung:** Bis v38 lief ein `redis:7-alpine` mit, den keine der
beiden Anwendungen benutzte. `REDIS_URL` war gesetzt, aber nichts las
sie — in v37 war das bewusst so belassen und nur die Doku korrigiert
worden. Ein Dienst, der nichts tut, kostet beim ersten Lesen Vertrauen
und beim zweiten eine Stunde Suche. Dateisystem-Sessions tragen bei
mehreren Instanzen nicht, aber Mehr-Instanz-Betrieb ist eine
Deployment-Frage, und die hängt an der Zielinfrastruktur — dieselbe
Klasse Entscheidung, die bei der CI-Pipeline schon dem übernehmenden Team
überlassen wurde (ADR-10).
**Verworfene Alternativen:**
- *Anbinden statt entfernen.* Hätte die Wahl des Backends vorweggenommen,
  die der Kickstarter nicht treffen kann, und ihn um einen Dienst
  erweitert, den die lokale Entwicklung nicht braucht.
- *Nur den Cache anbinden, Sessions im Dateisystem lassen.* Halber
  Schritt: der eigentliche Grund für einen solchen Dienst sind die
  Sessions.
- *Redis stehen lassen und nur die Doku ehrlich halten* — der Stand aus
  v37. Hält den Widerspruch am Leben, statt ihn aufzulösen.
**Lizenznotiz:** Redis 7.4 stand unter RSALv2/SSPLv1 und war damit nicht
OSI-konform. Seit Redis 8 (Mai 2025) gibt es eine Tri-Lizenz, die AGPLv3
einschließt. Wer anbindet, hat also drei Wege: Redis 8 unter AGPLv3,
Valkey unter BSD (Fork der Linux Foundation) oder Cache und Sessions in
MySQL, ganz ohne zusätzlichen Dienst.
**Konsequenzen:** Die PHP-Extension `redis` bleibt im Image
(`docker/php/Dockerfile`), damit das Anbinden später keinen Image-Neubau
erfordert — sie ist kein laufender Dienst und widerspricht der
Entscheidung nicht. `verify.sh` prüft in Abschnitt 20 generisch, dass
kein Host in einer DSN auf einen Dienst zeigt, den `docker-compose.yaml`
nicht deklariert: genau der Fehler, der mit einer stehengebliebenen
`REDIS_URL` entstanden wäre, und derselbe, der beim Kopieren eines alten
`docker-compose.yaml` in einen neuen Versionsordner entsteht.
