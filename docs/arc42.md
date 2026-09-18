# arc42-Dokumentation

Nur belegte Inhalte. Leere Kapitel sind als solche gekennzeichnet.

## 1 Einführung und Ziele
Kickstarter für Kundenprojekte einer Digitalagentur: redaktionelle
Inhalte über Sulu, Shop über Sylius, eine öffentliche Herkunft.
Zielgruppe: erfahrene Entwickler, die das Gerüst übernehmen und pflegen.

**Qualitätsziele:**
| Ziel | Ausprägung im Projekt |
|---|---|
| Nachvollziehbarkeit | 51 dokumentierte Befunde mit Begründung (FIXES.md) |
| Installierbarkeit | ein Befehl, Prüfung vorab, Tests nicht blockierend |
| Robustheit | API-Ausfall führt zu leerer Liste, nie zu 500 |
| Wartbarkeit | kein Vendor-Eingriff, Overlay-Prinzip |

## 2 Randbedingungen
- Getestete Versionen (v38): Sulu 3.0.9, Sylius 2.2.9, Symfony 7.4.18,
  PHP 8.5.10, MySQL 8.4.11, Node.js 24.21.0 — beide Apps über den
  `composer.lock` im jeweiligen Overlay festgelegt; PHP, MySQL und
  Node.js abgeleitet aus `kickstarter.yaml` nach ADR-11 und ADR-12
- Multi-Arch (Apple Silicon und x86_64), kein hartkodiertes Platform-Flag
- Sulu 3 statt 2.6 (ProxyManager-Endlosschleife mit Symfony 7.4)
- FrankenPHP ohne Worker-Mode
- Entwicklungsbetrieb auf `localhost`; Serverbetrieb nicht verifiziert
- Dokumentation und Code-Kommentare Englisch, Shop-Inhalte Deutsch

## 3 Kontextabgrenzung
**Fachlich:** Besucher nutzt Storefront; Redakteur pflegt Seiten in
Sulu; Shop-Betreiber verwaltet Produkte und Bestellungen in Sylius.

**Technisch:**
| Nachbar | Richtung | Zweck |
|---|---|---|
| Browser | eingehend | HTTP über Caddy |
| Adyen | ausgehend + eingehend | Zahlung, Webhook (lokal nicht nutzbar) |
| Mailpit | ausgehend | abgefangene Mails, kein echter Versand |

## 4 Lösungsstrategie
- Headless: Sylius als Datenlieferant, Sulu als einzige sichtbare Herkunft
- Proxy statt Browser-Zugriff (Option A) — Cart-Token bleibt serverseitig
- Ein Router für alles, damit nur eine Webserver-Technologie im Spiel ist
- Fehlertoleranz vor Vollständigkeit: fehlschlagende API-Aufrufe
  degradieren, statt die Seite zu brechen

## 5 Bausteinsicht

### Verzeichnisse
```
/                       Kickstarter (kein Anwendungscode)
├── kickstarter.yaml    Versionen von Sylius und Sulu (Absicht)
├── versions.env        PHP, MySQL, Node (abgeleitet, `make versions`)
├── sylius-overlay/     wird per `make sylius-theme` nach ./sylius kopiert
│   ├── composer.json, composer.lock   geprüfter Abhängigkeitsstand
│   ├── config/packages/  dach_demo.yaml, dach_products.yaml,
│   │                     sylius_shipping_payment.yaml
│   ├── src/Fixture/      RockbandProductsFixture
│   ├── src/Command/      CreateTestOrdersCommand
│   ├── templates/bundles/ Sylius-Bundle-Template-Overrides
│   └── var/demo-images/  eigene Produktfotos (optional)
├── sulu-overlay/       wird per `make sulu-theme` nach ./sulu kopiert
│   ├── composer.json, composer.lock   geprüfter Abhängigkeitsstand
│   ├── config/         webspaces/website.xml, templates/pages/
│   ├── src/            Controller, Services, Twig-Extension, Command
│   ├── templates/      Storefront-Templates
│   ├── tests/          Unit-, Integration-, Smoke-Tests
│   └── public/css/
├── docker/             Caddyfile, install-apps.sh, resolve-versions.sh,
│                       verify.sh
├── sylius/             generierte Sylius-App (nicht im Paket)
└── sulu/               generierte Sulu-App (nicht im Paket)
```

### Ebene 1
Router (Caddy) · Sulu-App · Sylius-App · MySQL · Redis · Mailpit

### Ebene 2 — eigene Bausteine, Sulu-App
| Baustein | Aufgabe |
|---|---|
| `SyliusShopClient` | alle API-Aufrufe, Host-Header-Channelwahl, Fehlerkapselung |
| `CartManager` | Cart-Token in der Session, Lebenszyklus |
| `CatalogController` | `/produkte/*` |
| `CartController` | `/warenkorb/*` |
| `CheckoutController` | `/checkout/*`, lineare Guards |
| `ShopExtension` | Twig: Produkte, Warenkorb-Badge, Preisformat |
| `SeedHomepageCommand` | füllt den Startseiten-Entwurf |

### Ebene 2 — eigene Bausteine, Sylius-App
`RockbandProductsFixture` (Produkte inkl. generierter GD-Icons),
`CreateTestOrdersCommand`, zwei Bundle-Template-Overrides.

## 6 Laufzeitsicht
**Produktseite:** Browser → Caddy → Sulu-Controller → Shop-API
(intern, Host-Header) → Template.

**Warenkorb:** Token aus Session; fehlt er oder ist die Bestellung nicht
mehr im Zustand `cart`, wird ein neuer angelegt.

**Checkout:** Adresse (`PUT`) → Versand (`PATCH`) → Zahlung (`PATCH`) →
Abschluss (`PATCH .../complete`). Jeder Schritt prüft den Zustand und
leitet sonst zurück.

**Adyen (gebaut, nie gelaufen):** Widget im Browser spricht direkt mit
Sylius; Antwort enthält entweder eine weitere Aktion (3-D Secure) oder
eine Weiterleitung. Der eigene `completeCheckout()` wird dabei nicht
aufgerufen.

## 7 Verteilungssicht
Ein Docker-Compose-Verbund mit festem Projektnamen — daher teilen sich
alle Versionsordner dieselben Volumes. Offen nach außen: Port 80
(Storefront), 8082 (Sylius-Admin), 8081 (phpMyAdmin), 3307 (MySQL).

### Routing (Caddy)
| Pfad | Ziel |
|---|---|
| `/` und alles Übrige | Sulu (Catch-All) |
| `/produkte/*`, `/warenkorb/*`, `/checkout/*` | Sulu-Controller |
| `/media/*`, `/bundles/sylius*` | Sylius (Assets) |
| `/{de_DE,de_AT,de_CH}/payment/adyen/*` | Sylius (Widget) |
| `/payment/adyen/*` | Sylius (Webhook) |
| `/api/v2/shop/payment/adyen/*` | Sylius |
| `/build/app/shop/*` | Sylius (Widget-Bundle) |
| `/{de_DE,de_AT,de_CH}/order/thank-you` | Sylius |
| `/shop/*` | Redirect → `/produkte/` |
| `/shop/admin*` | Redirect → `{host}:8082/admin/` |
| `/mail/*` | Mailpit |
| Port 8082 | Sylius-Admin (eigener Server-Block) |

Die Shop-API ist nie öffentlich geroutet; Sulu ruft sie
container-intern auf. Locales sind explizit aufgeführt, weil Caddys
Wildcard-Kombination (Anfang plus Mitte) fehlerhaft ist.

## 8 Querschnittliche Konzepte

### Erweiterungsmechanismen
- **Overlay:** eigene Dateien liegen getrennt, werden kopiert;
  Vendor-Code bleibt unberührt
- **Override:** Symfonys Bundle-Template-Mechanismus, zwingend in der
  App, die rendert
- **Konfiguration:** eigene Dateien bewusst anders benannt als die von
  Sylius/Sulu; Zugangsdaten nur über Umgebungsvariablen

### Verifizierte API-Eigenheiten
Mühsam erarbeitet, in FIXES.md belegt, durch Integrationstests gesichert:
- Channel-Wahl über Host-Header (Verbindung zu `http://sylius`,
  Host = Channel-Hostname)
- Taxon-Filter: `productTaxons.taxon.code` — ein falscher Parameter wird
  **stillschweigend ignoriert**, nicht abgewiesen
- Produkte nur per `code` adressierbar, nicht per `slug`
- Preis (in Cent) und Lagerstatus eingebettet in `defaultVariantData`
- Sammlungen unter `hydra:member` **oder** `member`
- POST braucht `application/ld+json`, PATCH `application/merge-patch+json`

### Fehlerbehandlung und Typsicherheit
API-Fehler führen zu leerem Ergebnis, protokolliert statt geworfen.
API-Werte werden einmal zentral verengt, statt an jeder Lesestelle
gecastet.

### Prüfung vor Installation
`make verify` mit 20 Abschnitten (0 bis 19, einzeln aufrufbar), inklusive Querverweis-Prüfungen
zwischen Dateien (Template-Verweise, Route-Namen, Override-Ablageort)
und der Konsistenz von Datenbank-Image und `serverVersion`.

## 9 Architekturentscheidungen
Siehe `decisions.md` (ADR-01 bis ADR-11).

## 10 Qualitätsanforderungen

### Testebenen
| Befehl | Umfang | Voraussetzung |
|---|---|---|
| `make test` | 35 Unit-Tests | keine |
| `make test-integration` | 10 gegen Shop-API | Container + Fixtures |
| `make test-smoke` | 15 durchs Frontend via Caddy | Container + Fixtures |
| `make test-all` | alle 60 | wie oben |

Die Smoke-Tests prüfen ausschließlich Controller-Routen (`/produkte/*`,
`/warenkorb/*`, `/checkout/*`) sowie Admin- und Mailpit-Routing. Die
redaktionelle Startseite rufen sie nicht auf; ihr Veröffentlichen ist
daher für `make test-smoke` **keine** Voraussetzung, sondern nur für den
manuellen Blick auf `/`.

### Szenarien
| Szenario | Erwartung | Abgedeckt durch |
|---|---|---|
| Sylius nicht erreichbar | Storefront bleibt bedienbar | Unit-Tests |
| Sylius-Update ändert API | wird sichtbar | Integrationstests |
| Routing-Regel gebrochen | wird sichtbar | Smoke-Tests |
| Sulu-Update verschiebt Templates | Warnung | `make verify` 13 |
| Override in falscher App | Fehler | `make verify` 16 |

Statische Analyse: Sylius Level 5 (bewusst, siehe ADR-08),
Sulu Level `max` inklusive `tests`.

## 11 Risiken und technische Schulden
| Risiko | Schwere | Stand |
|---|---|---|
| Adyen nie real getestet | hoch | offen, Zugangsdaten fehlen |
| Kein CI | mittel | bewusst offen, ADR-10 |
| Demo-Branding in 18 Dateien | mittel | offen, ADR-09 |
| Bundle-Overrides brechen bei Sylius-Update still | mittel | teilweise geprüft |
| Serverbetrieb unverifiziert | mittel | offen |
| Frontend hartkodiert auf Channel `germany` | niedrig | AT/CH datenseitig korrekt, nicht angebunden |
| Widget-Bundle 5,88 MB | niedrig | bewusst akzeptiert |
| JS-Teile nicht testbar (Adyen-Widget, Sulu-Admin) | niedrig | dokumentiert |

## 12 Glossar
| Begriff | Bedeutung |
|---|---|
| Overlay | `sulu-overlay/`, wird nach `./sulu` kopiert |
| Channel | Sylius-Verkaufskanal (germany/austria/switzerland) |
| Taxon | Sylius-Produktkategorie |
| Drop-in | Adyens JS-Zahlungskomponente |
| Smoke-Test | HTTP-Prüfung des Frontends über Caddy |
