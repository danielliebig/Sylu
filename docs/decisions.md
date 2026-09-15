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
