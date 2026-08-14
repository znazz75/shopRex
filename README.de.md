# shopRex

[English](README.md) | **Deutsch**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB%2010.3%2B-4479a1)
![No build step](https://img.shields.io/badge/dependencies-zero%20required-brightgreen)

> Diese Übersetzung wird manuell parallel zur englischen README.md gepflegt.
> Bei Abweichungen ist die englische Version maßgeblich.

Ein einfaches PHP + MySQL Online-Shop-Framework — reines PHP (PDO, keine
Composer-Abhängigkeiten zum Ausführen nötig), prozedural/leicht-objektorientiert,
gedacht als Ausgangspunkt zum Weiterbauen, nicht als fertiges Produkt. Das
Frontend läuft auf Bootstrap 5 (+ jQuery/jQuery UI für ein paar interaktive
Admin-Tools, Quill.js für Rich-Text, Cropper.js für den Bildzuschnitt — alle
von öffentlichen CDNs geladen, siehe [Externe Bibliotheken](#externe-bibliotheken)).
Die gesamte Seite (Shop und Backend) ist von Haus aus dreisprachig (Englisch/
Deutsch/Französisch), einzelne Sprachen lassen sich aktivieren/deaktivieren
(bis hin zu nur einer, wodurch die Sprachumschaltung komplett entfällt) oder
weitere hinzufügen, die MwSt. ist vollständig konfigurierbar, und die Kasse
erzeugt eine echte PDF-Rechnung — siehe die Funktionsliste unten.

## Funktionen

**Shop**
- Modernes **Bootstrap-5-Layout**, **umschaltbar im Backend** (Admin → Einstellungen → 3 integrierte Themes: Default, Midnight/Dunkel, Ocean) — siehe [Frontend-Theme](#frontend-theme)
- **Dreisprachig (Englisch/Deutsch/Französisch) ab Werk, erweiterbar auf jede Sprache, und pro Sprache im Backend umschaltbar** — eine Sprachauswahl ist im Header verfügbar, sobald mehr als eine Sprache aktiviert ist; siehe [Sprachen](#sprachen)
- Produktliste mit Sortierung (neueste, Preis, Name), Suche und **konfigurierbarer Paginierung** (20/50/200/alle, pro Besucher gemerkt) — siehe [Paginierung](#paginierung)
- Kategorien mit **unbegrenzter Verschachtelung** (Kategorie > Unterkategorie > Unterkategorie > ...), mit Breadcrumbs und Dropdown-Navigation
- **Seitensuche** über Produkte und Kategorien hinweg ([search.php](search.php))
- Produktdetailseite mit Optionsauswahl (z. B. Größe, Farbe), bestandsbewusstem „In den Warenkorb", einer **Mehrbild-Galerie** (Bootstrap-Carousel + Vorschaubilder) mit Bildunterschrift je Bild, und **zeitlich begrenzten Rabatt-Badges** mit angezeigtem Datumsbereich — siehe [Rabatte & Verfügbarkeitszeiträume](#rabatte--verfügbarkeitszeiträume)
- **Konfigurierbare MwSt.** — Bruttopreise (inkl. Steuer) werden überall im Shop angezeigt; Warenkorb/Kasse weisen Nettopreis und Steuer getrennt aus — siehe [MwSt.](#mwst)
- Sitzungsbasierter Warenkorb (hinzufügen/ändern/entfernen, Live-Bestandsprüfung) mit einem **„Weiter einkaufen"-Button**, der zum zuletzt angesehenen Produkt zurückführt
- Kasse mit **PayPal**, **Kreditkarte** (Stripe Checkout) oder **Vorkasse per Überweisung**
- Eine **PDF-Rechnung** wird an der Kasse erzeugt, in der Sprache des Kunden, mit MwSt.-Aufschlüsselung, als E-Mail-Anhang versendet und im Kundenkonto gespeichert — siehe [Rechnungen](#rechnungen)
- Bestellbestätigungsseite + editierbare HTML-E-Mails für Registrierung, Passwort-Reset und jedes Bestellereignis — siehe [E-Mail-Vorlagen](#e-mail-vorlagen)
- Kundenkonten (Registrierung/Login/Bestellhistorie) mit **Selbstbedienungs-Datenexport und Kontolöschung** — siehe [Datenschutz (DSGVO)](#datenschutz-dsgvo)
- **Editierbare CMS-Seiten** — Impressum, Datenschutzerklärung, Über uns, Copyright kommen als editierbare Startinhalte mit, plus eigene Seiten lassen sich ergänzen ([page.php](page.php))
- **Editierbares Hauptmenü und Footer-Untermenü**, beide verschachtelbar, beide im Backend verwaltbar ([Menüs](#menüs))

**Backend** (`/admin`)
- **Installationsgeführte Einrichtung** — kein manueller SQL-Import nötig, siehe unten
- **Mehrere Admin-Konten mit Rollen** — Super Admin (voller Zugriff) und Manager (nur Produkte/Kategorien/Lager/Inhalt); siehe [Admin-Rollen](#admin-rollen)
- **Testbenutzerkonten** für Testläufe — Bestellungen unter einem Testkonto nutzen eine simulierte Zahlung, rühren nie den echten Lagerbestand an und sind aus jedem Finanzbericht ausgeschlossen; siehe [Testbenutzer](#testbenutzer)
- Produktverwaltung (Anlegen/Bearbeiten/Löschen, Optionen/Varianten, **Rabatt + Verfügbarkeitsplanung je Artikel**, **Netto-/Brutto-Preiseingabe mit Live-Umrechnung**) plus ein eigener **Bildmanager**: mehrere Bilder je Produkt hochladen, jedes beschriften, per Drag & Drop neu anordnen, das Hauptbild festlegen, und **mit Cropper.js zuschneiden** (Zuschneidebereich und Ausgabebreite/-höhe festlegen; ein zugeschnittenes Derivat wird serverseitig mit GD erzeugt) — siehe [admin/product_images.php](admin/product_images.php) und [admin/image_crop.php](admin/image_crop.php)
- Kategorieverwaltung (unbegrenzte Verschachtelung, eingerückte Auswahl der übergeordneten Kategorie, Zyklus-Vermeidung)
- Lagerverwaltung (Bestandsmengen, manuelle Anpassungen, Bewegungsprotokoll mit klarer Kennzeichnung von Testbestellungen, Niedrigbestandswarnungen)
- **Steuersätze** — mehrere MwSt.-Sätze mit einem als Standard markiert; MwSt. komplett ein-/ausschaltbar — siehe [MwSt.](#mwst)
- **Seiten** — Rich-Text-Editor (Quill.js) für die oben genannten CMS-Seiten
- **Menüs** — Drag-&-Drop-Verwaltung (jQuery UI Sortable) des Hauptmenüs und des Footer-Untermenüs, mit Linktypen für benutzerdefinierte URL/Kategorie/Seite und Verschachtelung für Dropdowns
- **E-Mail-Vorlagen** — gemeinsamen Header/Footer und Betreff/Text jeder E-Mail bearbeiten, pro Sprache, mit Referenz der verfügbaren Platzhalter — siehe [E-Mail-Vorlagen](#e-mail-vorlagen)
- **Einstellungen** — Shop-Daten, Bankdaten für Überweisungen, Standardanzahl Artikel pro Seite, Standardsprache, MwSt.-Umschalter, Datenaufbewahrungsdauer und Frontend-Theme-Umschalter
- Finanzverwaltung (Umsatz-Dashboard, Umsatz nach Monat/Zahlungsart, Transaktionsjournal — nur echte Bestellungen) — nur Super Admin
- Kundenverwaltung (Liste, Bestellhistorie, sperren/entsperren, Testbenutzer anlegen, **DSGVO-Datenexport/-löschung**) — nur Super Admin
- Bestellverwaltung (Status- und Zahlungsstatus-Updates, Kundenbenachrichtigungen, Rechnungsdownload, Testbestellungs-Badges/Filter) — nur Super Admin

## Voraussetzungen

- PHP 8.0+ mit den Erweiterungen `pdo_mysql`, `curl`, `gd` (Bildzuschnitt), `iconv` und `mbstring` (Zeichenkodierung für PDF-Rechnungen) - bei den meisten PHP-Installationen standardmäßig gebündelt/aktiviert
- MySQL 5.7+ / MariaDB 10.3+
- Ein lokaler Mail-Transport, damit `mail()` tatsächlich E-Mails zustellt (oder SMTP einsetzen — siehe unten)
- Internetzugang aus dem Browser des Besuchers, um Bootstrap/jQuery/Quill/Cropper.js von deren CDNs zu laden (siehe [Externe Bibliotheken](#externe-bibliotheken), um sie stattdessen selbst zu hosten)
- Apache mit `mod_authz_core`/`mod_headers`, damit die mitgelieferten `.htaccess`-Dateien wirksam werden (siehe [Sicherheit](#sicherheit)) - unter nginx die Regeln stattdessen in den Server-Block übertragen, da nginx keine `.htaccess` liest

## Installation

1. **Lokal ausführen** mit dem eingebauten PHP-Server aus dem Projektstamm:
   ```bash
   php -S localhost:8000
   ```
   Für echtes Hosting das Document Root des Webservers auf diesen Ordner
   **oder ein Unterverzeichnis davon** zeigen lassen (z. B. `https://example.com/shopRex/`)
   - beides funktioniert ohne Codeänderungen, siehe [Betrieb in einem Unterverzeichnis](#betrieb-in-einem-unterverzeichnis).
   Stellen Sie sicher, dass `config/`, `includes/` und `sql/` **nicht** über
   den Browser erreichbar sind (oder fügen Sie eine `.htaccess`-/nginx-Regel
   hinzu, die dies blockiert) — alles, was der Browser braucht, liegt im
   Projektstamm, in `assets/`, `admin/` und `uploads/`.

2. **Die Seite im Browser öffnen.** Jede Seite (Shop oder Backend) leitet
   beim ersten Aufruf zum Installer weiter, [install.php](install.php):
   1. **Voraussetzungsprüfung** — PHP-Version, Erweiterungen `pdo_mysql`/`curl`, sowie ob `config/` und `uploads/products/` beschreibbar sind.
   2. **Seite & Datenbank einrichten** — das Feld **Site-URL** wird durch automatische Erkennung von Schema+Host+Unterverzeichnis aus genau dieser Anfrage vorausgefüllt (korrigieren Sie es, falls falsch, z. B. hinter einem Reverse-Proxy - siehe [Betrieb in einem Unterverzeichnis](#betrieb-in-einem-unterverzeichnis)); geben Sie Datenbank-Host, -Port, -Name, -Benutzer und -Passwort ein. Die Datenbank wird angelegt, falls sie noch nicht existiert, und das Schema wird automatisch importiert (mit optionaler Checkbox für Demo-Inhalte). Dies schreibt `config/installed.php` — **nicht ins Git-Repository übernommen**, da es Ihr DB-Passwort enthält.
   3. **Administratorkonto** — Benutzername, E-Mail und Passwort für den ersten Admin eingeben. Dieses Konto erhält die Rolle **Super Admin**.

   Sobald ein Admin-Konto existiert, verweigert `install.php` dauerhaft die
   erneute Ausführung (auch bei direktem Aufruf), damit es keinen laufenden
   Shop überschreiben kann. Falls Sie die Datenbankverbindung jemals von
   Hand neu konfigurieren müssen, bearbeiten Sie `config/installed.php`
   direkt (oder ändern Sie die Site-URL unter **Admin → Einstellungen**,
   ohne die Datenbankverbindung anzufassen).

   Shop: http://localhost:8000/index.php
   Admin: http://localhost:8000/admin/login.php

   *(Fortgeschrittene/unbeaufsichtigte Einrichtung: Sie können den Installer
   komplett überspringen, indem Sie selbst
   `mysql -u root -p your_db < sql/schema.sql` ausführen und die
   Umgebungsvariablen `SHOPREX_DB_*`/`SHOPREX_SITE_URL` setzen — siehe
   `config/config.php`. In diesem Fall müssen Sie selbst eine Zeile in
   `admin_users` anlegen.)*

## Betrieb in einem Unterverzeichnis

Jeder interne Link, jede Weiterleitung, Asset-URL und E-Mail, die die
Anwendung erzeugt, basiert auf der Konstante `SITE_URL`
(`rtrim(SITE_URL, '/') . '/index.php'` usw.) - nirgendwo wird angenommen,
dass die Seite im Wurzelverzeichnis der Domain liegt. `SITE_URL` selbst
stammt, in dieser Reihenfolge, aus: `config/installed.php` (einmalig vom
Installer gesetzt, später editierbar unter **Admin → Einstellungen →
Site-URL**), der Umgebungsvariable `SHOPREX_SITE_URL`, oder - bevor eines
von beidem existiert - einer automatischen Schätzung (`detectSiteUrl()`
in `config/config.php`), die den tatsächlichen Dateisystempfad des
Projekts mit dem Document Root des Webservers vergleicht, sodass ein
Unterverzeichnis wie `https://localhost/shopRex` korrekt ohne jede
Konfiguration erkannt wird. Falls diese Schätzung einmal falsch liegt
(ungewöhnliche Serverkonfigurationen, manche Reverse-Proxys), korrigieren
Sie sie einfach im Installer oder unter **Admin → Einstellungen** - sonst
muss nichts weiter geändert werden. Wird `https://` als Schema der
Site-URL verwendet, leitet zudem jede reine HTTP-Anfrage automatisch auf
HTTPS um (siehe [Sicherheit](#sicherheit)).

## Sicherheit

- **CSRF**: jedes zustandsändernde Formular/jeder AJAX-Aufruf ist durch ein
  sitzungsgebundenes Token geschützt (`csrfField()`/`requireCsrf()` in
  `includes/functions.php`). `verifyCsrf()` verlangt, dass *beide* - das
  übermittelte und das Sitzungs-Token - vor dem Vergleich nicht leer sind -
  `hash_equals('', '')` liefert in PHP `true`, ohne diese Prüfung könnte
  also eine gefälschte Anfrage ohne Token-Feld durchgehen, solange die
  Sitzung des Opfers noch keines erzeugt hatte. Das Session-Cookie selbst
  ist `HttpOnly`, `SameSite=Lax` und über HTTPS `Secure`
  (`config/config.php`), und Login/Registrierung erzeugen die Session-ID
  neu (`regenerateSession()`), um Session-Fixation zu verhindern.
- **`.htaccess`-Härtung** (nur Apache, siehe [Voraussetzungen](#voraussetzungen)):
  im Projektstamm werden `X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy` und ein `Permissions-Policy`-Header gesetzt sowie
  Dotfiles blockiert; `config/`, `includes/`, `sql/` und `admin/cron/` sind
  vom direkten Webzugriff vollständig ausgeschlossen; `uploads/` blockiert
  die Ausführung von PHP (zusätzliche Absicherung gegen einen bösartigen
  Datei-Upload); `uploads/invoices/` ist komplett gesperrt (Rechnungen
  werden ausschließlich über das auth-geprüfte `invoice_download.php`
  ausgeliefert).
- **HTTPS erzwingen**: sobald Ihre konfigurierte Site-URL (**Admin →
  Einstellungen**) `https://` verwendet, wird jede reine HTTP-Anfrage mit
  301 auf ihr HTTPS-Äquivalent umgeleitet (`config/config.php`, erkennt
  `X-Forwarded-Proto` hinter einem Reverse-Proxy). An das Schema der
  Site-URL selbst gebunden statt immer aktiv, sodass eine `http://`-Site-URL
  - lokale Entwicklung, eine noch nicht auf HTTPS umgestellte Staging-Seite
  - nie erzwungen wird und nie in eine Umleitungsschleife gerät. Eine
  äquivalente, optionale serverseitige Variante (standardmäßig
  auskommentiert) befindet sich in der root-`.htaccess` für alle, die das
  lieber Apache erledigen lassen, bevor PHP überhaupt läuft.
- Passwörter werden mit `password_hash()`/`password_verify()` (bcrypt)
  gehasht; durchgängig werden Prepared Statements verwendet.

Es gab noch kein vollständiges professionelles Sicherheitsaudit - vor dem
Produktiveinsatz prüfen, insbesondere die Datei-Upload-Verarbeitung und die
Zugriffskontrolle des Admin-Bereichs.

## Admin-Rollen

Definiert in [admin/includes/roles.php](admin/includes/roles.php):

| Rolle | Zugriff |
|---|---|
| **Super Admin** | Alles: Produkte, Kategorien, Lager, Seiten, Menüs, Bestellungen, Finanzen, Kunden, Einstellungen, Versand, und Verwaltung anderer Admin-Konten |
| **Manager** | Nur Produkte, Kategorien, Lager und Seiten/Menüs ("Artikel-/Inhaltsverwaltung") — kein Zugriff auf Bestellungen, Finanzen, Kunden, Einstellungen, Versand oder Admin-Konten |

Admin-Konten verwalten unter **Admin → Admin-Konten** (nur Super Admin,
[admin/admins.php](admin/admins.php)): zusätzliche Konten anlegen, Rolle
zuweisen, deaktivieren/reaktivieren, Passwörter zurücksetzen oder löschen.
Das System behält immer mindestens einen aktiven Super Admin - der letzte
kann nicht gelöscht, herabgestuft oder deaktiviert werden.

Um eine neue Rolle hinzuzufügen: eine Bezeichnung zu `ADMIN_ROLES`
hinzufügen und in `ADMIN_CAPABILITIES` in derselben Datei auflisten,
welche Bereiche sie freischaltet; das `ENUM` der Spalte `role` in
`sql/schema.sql` entsprechend anpassen (oder bei einer bestehenden
Datenbank `ALTER TABLE admin_users MODIFY role ENUM(...)`).

## Frontend-Theme

**Admin → Einstellungen** hat zwei unabhängige Regler, beide wirken sofort
auf jeden Besucher:

### Layout (Theme-Pakete)

Ein Layout ist eine komplett andere Seitenstruktur, nicht nur andere
Farben - das mitgelieferte Paket **Sidebar Filters** verschiebt z. B. den
Kategoriebaum in eine dauerhafte linke Seitenleiste statt der
Standard-Breadcrumb-/Chip-Zeile oben. Pakete liegen in `themes/<key>/` und
werden auf dieselbe Art automatisch erkannt wie `includes/lang/*.php`-Sprachen
- **um eines hinzuzufügen**, eine neue `themes/<key>/theme.json`
(`{"name": "...", "description": "..."}`) anlegen, sie erscheint dann
automatisch unter Admin → Einstellungen → Layout, ohne Codeänderungen.
Ein Paket kann jeden der drei Template-Slots überschreiben, indem es eine
Datei mit passendem Namen ablegt:

| Datei | Überschreibt | Fällt zurück auf |
|---|---|---|
| `header.php` | Alles von `<html>` bis zum Beginn von `<main>` | `includes/header.php` |
| `footer.php` | Alles vom Ende von `<main>` an | `includes/footer.php` |
| `home.php` | Den Produktlisten-Inhalt auf `index.php` (Startseite, Kategorie- und Suche-innerhalb-einer-Kategorie-Ansichten) | `includes/home.php` |

Ein Paket muss nur die Dateien bereitstellen, die es tatsächlich ändert -
das eingebaute Paket **Default** liefert gar keine (Beweis, dass der
Fallback transparent funktioniert), und **Sidebar Filters** überschreibt
nur `home.php` sowie sein eigenes `style.css` (geladen nach
`assets/css/style.css`, damit es Layout-CSS ergänzen oder weiter
überschreiben kann, ohne das Kern-Stylesheet anzufassen). Der Resolver ist
`themeTemplatePath()` in `includes/functions.php`; jede
Shop-Root-Seite ruft ihn auf, statt `includes/header.php`/`footer.php`
direkt einzubinden. Bewusst **nicht** enthalten ist ein
Im-Browser-„Theme-.zip hochladen"-Ablauf - das würde jedem mit
Admin-Zugriff erlauben, beliebigen PHP-Code auf dem Server hochzuladen und
auszuführen; das später hinzuzufügen wäre eine eigene, sicherheitsgeprüfte
Änderung.

### Akzentfarbe

Färbt Buttons, Links, Badges und die Navigationsleiste innerhalb des
jeweils aktiven Layouts ein. Drei sind ab Werk dabei
(`includes/functions.php`, Konstante `THEMES`): **Default** (hell),
**Midnight** (dunkel, mit Bootstrap 5.3s nativem
`data-bs-theme="dark"`-Farbmodus) und **Ocean** (hell, Türkis-Akzent).
Bootstrap wird von einem CDN geladen statt aus Sass gebaut, daher sind die
Komponentenfarben in feste Werte kompiliert statt als Laufzeit-CSS-Variablen
verfügbar — jedes Theme setzt eine benutzerdefinierte Eigenschaft
`--shop-accent`, die `assets/css/style.css` nutzt, um genau die
Bootstrap-Klassen einzufärben, die dieses Projekt tatsächlich verwendet
(Buttons, Links, Badges, Formular-Checkboxen usw.). Zum Hinzufügen: einen
Eintrag zu `THEMES` mit `bs_theme` (`light`/`dark`), einer `accent`-Hexfarbe
und einer `navbar_bg`-Hexfarbe hinzufügen — keine CSS-Änderungen nötig,
außer Sie möchten etwas über die bereits in `style.css` gelisteten Elemente
hinaus einfärben.

## Sprachen

Ist von Haus aus dreisprachig (Englisch + Deutsch + Französisch), sowohl
im **Shop** als auch im **Backend**, und darauf ausgelegt, weitere Sprachen
ohne Codeänderungen auf jeder Seite aufzunehmen:

- `includes/lang/en.php`, `de.php` und `fr.php` geben jeweils ein flaches
  Array `'namespace.key' => 'string'` zurück (640 Schlüssel, über alle drei
  synchron gehalten); `__('key', ['token' => $value])`
  (`includes/i18n.php`) schlägt die aktuelle Sprache nach, fällt bei
  fehlenden Einträgen auf Englisch zurück und ersetzt `{token}`-Platzhalter.
- **Eine Sprache hinzufügen**, indem eine neue `includes/lang/xx.php`-Datei
  mit denselben Schlüsseln abgelegt wird (ein Eintrag `_meta_name` legt den
  Anzeigenamen fest, z. B. `'Español'`) - sie wird überall dort automatisch
  erkannt, wo eine Sprachauswahl erscheint, keine weiteren Änderungen
  nötig. **Admin → Einstellungen → Sprachen** zeigt diese Anleitung auch
  direkt im Backend.
- **Einzelne Sprachen aktivieren/deaktivieren** unter **Admin →
  Einstellungen → Sprachen**, ohne die zugrunde liegende Datei zu löschen -
  eine Sprache bleibt erkannt (z. B. weiterhin nutzbar, um eine bestehende
  Bestellung/einen bestehenden Kunden zu formatieren, der sie erfasst
  hatte, bevor sie deaktiviert wurde), verschwindet aber aus jeder
  Sprachauswahl, aus `?lang=` und aus den Sprach-Tabs in
  Seiten/E-Mail-Vorlagen/Kategorien/Produkten, sobald sie abgewählt wird
  (`getEnabledLanguages()` in `includes/i18n.php`, im Unterschied zu
  `getAvailableLanguages()` für „jede vorhandene Datei"). **Wird nur eine
  Sprache aktiviert, entfällt die Sprachumschaltung vollständig** - die
  Auswahl wird nur angezeigt, wenn mehr als eine Sprache aktiviert ist.
  Mindestens eine Sprache bleibt immer aktiviert; wird ohne Auswahl
  gespeichert, werden stattdessen wieder alle aktiviert, statt den Shop
  ganz ohne Sprache dastehen zu lassen.
- Besucher und Admins können **jederzeit die Sprache wechseln** über die
  Auswahl in der Navigationsleiste (Shop) bzw. der Seitenleiste (Admin) -
  `?lang=xx` wird in `$_SESSION['language']` für den Rest des Besuchs
  gemerkt (`getCurrentLanguage()`/`languageSwitchUrl()`). Bis eine Wahl
  getroffen wurde, legt **Admin → Einstellungen → Sprache** die
  Standardsprache fest (`default_language`), die selbst eine aktivierte
  Sprache sein muss.
- Auch Daten werden pro Sprache formatiert (`formatLocalDate()`: `Aug 25, 2026` /
  `25.08.2026` / `25 août 2026`) - PHPs `date()` ist nicht locale-fähig,
  daher braucht eine Sprache mit ausgeschriebenen (nicht-englischen)
  Monatsnamen eine von Hand hinterlegte Namensliste, ebenso wie Französisch;
  ein numerischer Stil wie beim Deutschen braucht keine solche Liste.
  Wichtig zu wissen, falls Sie eine eigene Sprache hinzufügen und deren
  Datumsangaben englisch aussehen.
- **Umfangshinweis**: Dies deckt die UI-Umgebung (Navigation, Beschriftungen,
  Schaltflächen, Meldungen, E-Mails, Rechnungen) in allen aktivierten
  Sprachen ab. **Produktname, Kurzbeschreibung, Beschreibung sowie
  Options-/Wertebezeichnungen** (z. B. „Größe" / „S, M, L") sind ebenfalls
  übersetzbar - **Admin → Produkte → bearbeiten** hat einen Sprach-Tab je
  verfügbarer Sprache; eine leer gelassene Sprache fällt je Feld auf den
  Basis-/Standardsprachtext zurück. Technisch gesehen behalten die Zeilen
  in `products`/`product_options`/`product_option_values` weiterhin nur den
  Text der Standardsprache wie bisher; jede weitere Sprache liegt in einer
  eigenen Zeile in `product_translations` /
  `product_option_translations` / `product_option_value_translations`
  (`applyProductTranslation()`/`applyOptionTranslations()` in
  `includes/functions.php` legen die Sprache des Besuchers bei der
  Anzeige darüber). Shop-Suche und Namenssortierung auf
  `index.php`/`search.php` durchsuchen/sortieren beim Browsen in einer
  Nicht-Standardsprache ebenfalls den übersetzten Text.
  **Kategorienamen** sind das einzige verbliebene einsprachige
  Katalog-Element (nur der `intro_text` einer Kategorie ist pro Sprache) -
  sie genauso wie Produkte zu übersetzen wäre eine sinnvolle Erweiterung,
  falls benötigt. CMS-Seiten (Tabelle `pages`) sind wieder anders
  aufgebaut: eine komplette Zeile pro Sprache - siehe
  [admin/pages.php](admin/pages.php).

## Menüs

**Admin → Menüs** verwaltet zwei unabhängige, verschachtelbare Menüs:
- **Hauptmenü** — als Navigationsleiste in `includes/header.php` gerendert, mit Dropdowns für jeden Eintrag mit Untereinträgen.
- **Footer-Menü** — als Linkliste in `includes/footer.php` gerendert.

Jeder Eintrag ist eine **benutzerdefinierte URL**, eine **Kategorie**
(verlinkt zu `index.php?category=`, inklusive der Produkte aus deren
Unterkategorien) oder eine **Seite** (verlinkt zu `page.php?slug=`).
Einträge per Drag am &#10021;-Griff neu anordnen (jQuery UI Sortable,
gespeichert über `admin/menu_reorder.php`) — Ziehen ordnet nur innerhalb
derselben Geschwisterebene neu, ein Eintrag kann dabei nie versehentlich
unter ein anderes übergeordnetes Element verschoben werden.
Verschachtelungstiefe ist unbegrenzt, genau wie bei Kategorien.

## Testbenutzer

**Admin → Kunden** hat ein Formular „Testbenutzer anlegen"
(Benutzername/E-Mail/Passwort frei wählbar), das ein Kundenkonto mit
`is_test_account = 1` erzeugt. Wer sich im Shop mit diesem Konto anmeldet,
sieht ein dauerhaftes **TEST-MODUS**-Banner, und jede Bestellung, die
dabei aufgegeben wird:

- nutzt das `TestGateway` aus
  [includes/PaymentGateway.php](includes/PaymentGateway.php), das **keinen
  Netzwerkaufruf zu PayPal/Stripe/irgendwohin** macht und die Bestellung
  sofort als bezahlt markiert (simuliert) - unabhängig davon, welche
  Zahlungsart an der Kasse gewählt wurde, bewegt sich nie echtes Geld;
- wird weiterhin in `inventory_log` erfasst (der Testlauf ist also unter
  **Admin → Lager** sichtbar und nachvollziehbar), verringert aber **nie
  den echten Bestand** - das entsprechende
  `UPDATE products SET stock_quantity = ...` wird komplett übersprungen;
- wird mit `orders.is_test_order = 1` markiert und ist dadurch **aus jeder
  Finanzkennzahl ausgeschlossen**: den Umsatz-/Erstattungs-/
  Durchschnittsbestellwert-Summen sowie den Monats-/Zahlungsart-Aufschlüsselungen
  in `admin/finance.php`, den Umsatz- und Bestellzahl-Kacheln des
  Dashboards, und dem Transaktionsjournal (für Testbestellungen wird von
  vornherein keine Zeile in `transactions` angelegt).

Testbestellungen erscheinen weiterhin unter **Admin → Bestellungen** und
in den „Letzte Bestellungen" des Dashboards (beide filterbar, beide mit
einem `TEST`-Badge markiert), sodass sich der Testlauf tatsächlich
überprüfen lässt - sie sind lediglich aus den Zahlen ausgeschlossen, die
das echte Geschäft abbilden. Ein Testkonto löschen (und nicht mehr
verwenden) unter **Admin → Kunden → [Konto] → Testbenutzer löschen**.

## Paginierung

Das Produktraster im Frontend (`index.php`, sowie die Produktergebnisse
auf `search.php`) bietet **20 / 50 / 200 / Alle anzeigen** Artikel pro
Seite an. Sobald ein Besucher eine Wahl trifft, wird sie in
`$_SESSION['per_page']` gespeichert und gilt für den Rest des Besuchs für
jede Liste - keine Notwendigkeit, sie in der URL zu wiederholen. Bis eine
Wahl getroffen wurde, nutzt die Seite den unter **Admin → Einstellungen →
Produktlisten** konfigurierten Standard (`items_per_page_default`, ab
Werk `20`). Bootstrap-Paginierungssteuerelemente (`renderPagination()` in
`includes/functions.php`) erscheinen, sobald es mehr als eine Seite gibt.

## Rabatte & Verfügbarkeitszeiträume

Jedes Produkt (**Admin → Produkte → bearbeiten → Bereiche Rabatt /
Verfügbarkeitszeitraum**) kann unabhängig voneinander haben:

- **Einen Rabatt** - prozentual oder als Festbetrag, optional begrenzt
  durch ein Start- und/oder Enddatum/-uhrzeit. Solange aktiv, wird er als
  Badge (z. B. „20% Rabatt" oder „3,00 € sparen") neben dem Preis sowohl im
  Produktraster als auch auf der Produktseite angezeigt, mit einer Zeile
  „Angebot gültig ..." / „Angebot endet ...", sobald eine Datumsgrenze
  gesetzt ist (`formatDiscountDateRange()` in `includes/functions.php`).
  Die Sortierung nach Preis im Produktraster nutzt den aktuell
  rabattierten Preis.
- **Einen Verfügbarkeitszeitraum** - `available_from`/`available_until`.
  Außerhalb dieses Zeitraums ist das Produkt vollständig verborgen: weder
  in Listen/Suche vorhanden, *noch* liefert seine direkte URL etwas
  anderes als einen 404-Fehler, genau als würde es nicht existieren
  (`isProductCurrentlyAvailable()`). Damit lässt sich ein Produkt zu einem
  bestimmten Datum live schalten oder ablaufen lassen, ohne dessen
  `status` anzufassen.

## MwSt.

**Admin → Einstellungen → MwSt.** schaltet die Steuer für den gesamten
Shop ein/aus (`vat_enabled`). **Admin → Steuersätze** verwaltet beliebig
viele Sätze (z. B. „Standard 19%", „Ermäßigt 7%"), einer davon als
Standard für neue Produkte markiert.

- **Produktpreise** (Admin → Produkte → bearbeiten → Preis & MwSt.): einen
  Steuersatz wählen und den Preis **entweder netto oder brutto** eingeben -
  ein Live-JS-Hinweis zeigt beim Tippen den jeweils anderen Wert an, und
  der Server berechnet und speichert unabhängig davon den maßgeblichen
  **Netto**-Preis (vertraut nie der clientseitigen Umrechnung).
  `products.price_entry_mode` merkt sich, welches Feld zuletzt genutzt
  wurde, damit das Formular es beim nächsten Mal wieder so anzeigt.
- **Anzeige im Shop**: Produktlisten und die Produktseite zeigen immer den
  **Brutto**-Preis (inkl. Steuer) (`getGrossPrice()` in
  `includes/functions.php`), mit dem Hinweis „Preise inkl. MwSt.".
- **Warenkorb/Kasse**: zeigt den **Netto**-Preis plus eine nach Satz
  aufgeschlüsselte MwSt.-Zeile (ein Warenkorb mit Artikeln zu zwei
  unterschiedlichen Sätzen zeigt zwei MwSt.-Zeilen) -
  `tax_total`/`tax_breakdown` von `Cart::getItems()`. Netto + Steuer
  ergeben immer denselben Gesamtbetrag wie die im Shop gezeigten
  Bruttopreise.
- Jede Zeile in `order_items` erfasst ihren `tax_rate_percent`/`tax_amount`
  zum Zeitpunkt der Bestellung, sodass historische Bestellungen/Rechnungen
  auch dann korrekt bleiben, wenn Sie später einen Steuersatz ändern oder
  löschen.
- MwSt. ausschalten: Preise werden überall genau so angezeigt und
  berechnet, wie eingegeben, ohne jegliche hinzugefügte oder
  ausgewiesene Steuer.

## Zahlungen

Die Anbindungspunkte der Zahlungsanbieter liegen in
[includes/PaymentGateway.php](includes/PaymentGateway.php):

- **PayPal** — echte Orders-v2-REST-Aufrufe (standardmäßig Sandbox).
  `PAYPAL_CLIENT_ID`/`PAYPAL_CLIENT_SECRET`/`PAYPAL_MODE` entweder unter
  **Admin → Einstellungen → Zahlung** konfigurieren (hat Vorrang, in der
  Tabelle `settings` gespeichert) oder als Konstanten in
  `config/config.php` / Umgebungsvariablen `SHOPREX_PAYPAL_*` (der
  Fallback-Standard für eine unkonfigurierte Installation). Ohne gültige
  Zugangsdaten wird die Bestellung trotzdem angelegt und als „pending"
  markiert, damit der übrige Ablauf für lokale Tests funktionsfähig bleibt.
- **Kreditkarte** — Stripe Checkout Sessions (standardmäßig Testmodus).
  Dasselbe Muster „Admin-Einstellung überschreibt Konstante" für
  `STRIPE_SECRET_KEY`/`STRIPE_PUBLISHABLE_KEY`. Derselbe
  Pending-Bestellung-Fallback gilt ohne echte Schlüssel.
- **Überweisung** — keine externe API. Die Bestellung wird als „pending"
  angelegt, dem Kunden werden Ihre Bankdaten per E-Mail geschickt
  (**Admin → Einstellungen → Shop-Details**), und ein Admin markiert die
  Bestellung unter **Admin → Bestellungen** als „bezahlt", sobald die
  Überweisung eingeht.

Vor dem Livegang: PayPal-Webhook-/Stripe-Webhook-Verarbeitung für
asynchrone Bestätigung ergänzen (der aktuelle Ablauf verlässt sich auf die
Browser-Weiterleitung zurück zu `checkout_process.php`, was für ein
einfaches Framework ausreicht, aber nicht wasserdicht gegen abgebrochene
Weiterleitungen ist).

## E-Mail-Vorlagen

[includes/Mailer.php](includes/Mailer.php) nutzt standardmäßig PHPs
eingebautes `mail()`, damit das Framework ohne Pflichtabhängigkeiten
auskommt. Jeder Sendeversuch wird in der Tabelle `email_log` protokolliert.
Für den Produktivbetrieb [PHPMailer](https://github.com/PHPMailer/PHPMailer)
per Composer installieren und den Transport in `Mailer::deliver()` gegen
einen SMTP-basierten Versand austauschen, unter Nutzung der bereits in
`config/config.php` definierten Konstanten
`SMTP_HOST`/`SMTP_PORT`/`SMTP_USER`/`SMTP_PASS`.

Jede E-Mail besteht aus `{{_header}}` + dem Textkörper einer Vorlage +
`{{_footer}}`, alle pro Sprache editierbar unter **Admin →
E-Mail-Vorlagen** ([admin/email_templates.php](admin/email_templates.php))
mit einer Referenz der `{{Platzhalter}}` beim Bearbeiten jeder Vorlage:

| Vorlage | Wird gesendet, wenn |
|---|---|
| `_header` / `_footer` | Umschließt jede der unten genannten E-Mails (gemeinsames Branding) |
| `order_confirmation` | Eine Bestellung aufgegeben wird (Kasse) - enthält die automatisch erzeugte, nicht editierbare Artikeltabelle sowie die PDF-Rechnung als Anhang |
| `order_status_update` | Admin → Bestellungen → ein Admin aktiviert „Kunden per E-Mail informieren" bei einer Statusänderung |
| `registration_welcome` | Ein Kunde sich registriert |
| `password_reset` | Anfrage „Passwort vergessen" ([forgot_password.php](forgot_password.php) / [reset_password.php](reset_password.php)) |
| `account_deletion_warning` | Die DSGVO-Inaktivitätsbereinigung, 3 Monate bevor ein ruhendes Konto gelöscht wird - siehe [Datenschutz (DSGVO)](#datenschutz-dsgvo) |

Eine Sprache ohne eigene Fassung eines Schlüssels fällt auf die englische
Version zurück (`Mailer::getTemplate()`), sodass nur übersetzt werden
muss, was tatsächlich angepasst wurde.

## Rechnungen

Eine PDF-Rechnung wird an der Kasse erzeugt
(`InvoiceGenerator::generateForOrder()`, aufgerufen aus
`checkout_process.php` direkt nach dem Anlegen der Bestellung), in der
Sprache der Bestellung, und:

- wird **per E-Mail** als Anhang an die Bestellbestätigung angehängt (eine
  von Hand gebaute `multipart/mixed`-MIME-Nachricht in
  `Mailer::deliver()` - keine Bibliothek nötig);
- wird unter `uploads/invoices/` **gespeichert** (nie direkt über den
  Browser erreichbar - siehe [Sicherheit](#sicherheit)) und in der Tabelle
  `invoices` erfasst;
- ist **herunterladbar** vom Kunden aus seiner Bestellhistorie
  ([account.php](account.php)) und von jedem Admin aus
  [admin/order_view.php](admin/order_view.php), beide über
  [invoice_download.php](invoice_download.php), das prüft, ob der Anfragende
  der Besitzer der Bestellung oder ein Admin ist, bevor die Datei
  ausgeliefert wird.

Die Rechnung selbst - Shop-Name, Rechnungs-/Bestellnummer, Rechnungsadresse,
eine Artikeltabelle und eine nach Satz gruppierte MwSt.-Aufschlüsselung,
sofern zutreffend - wird mit [includes/SimplePdf.php](includes/SimplePdf.php)
gerendert, einem kleinen, abhängigkeitsfreien PDF-Writer, der eigens für
dieses Projekt gebaut wurde (Kern-Helvetica-Schriften über
WinAnsiEncoding, das deutsche Umlaute/ß und anderen Latin-1-Text abdeckt;
keine Bilder, keine eigenen Schriften, mehrseitige Unterstützung über
eine einfache Umbruchprüfung). Es ist keine allgemeine PDF-Bibliothek -
wer über einfache Rechnungen hinaus etwas braucht, sollte stattdessen ein
Composer-Paket wie `dompdf/dompdf` einsetzen.

## Datenschutz (DSGVO)

- **Export**: jeder Kunde kann alles, was shopRex über ihn gespeichert hat
  (Profil, Adressen, vollständige Bestellhistorie), als JSON unter **Mein
  Konto → Meine Daten exportieren** herunterladen
  ([account_export.php](account_export.php)); ein Admin kann dasselbe für
  jeden Kunden unter **Admin → Kunden → [Kunde] → Daten exportieren**
  ([admin/customer_export.php](admin/customer_export.php)) tun. Beide
  rufen dieselbe Funktion `GdprTools::exportData()` auf.
- **Löschung ("Recht auf Vergessenwerden")**: Kunden können ihr eigenes
  Konto löschen (erneute Passworteingabe erforderlich,
  [account_delete.php](account_delete.php)); Admins können das jedes
  Kunden unter **Admin → Kunden → [Kunde] → Konto löschen (DSGVO)** tun.
  Beide rufen `GdprTools::deleteCustomer()` auf, das die Zeile in
  `customers` unwiderruflich löscht (und deren Adressen kaskadierend
  mitlöscht), aber **die Bestellungen behält**, mit bereinigtem
  `shipping_name`/Adresse/Notizen - eine Auslegung von Art. 17 Abs. 3 lit.
  b DSGVO, der Daten ausnimmt, die für eine gesetzliche
  Aufbewahrungspflicht (Buchhaltungs-/Steuerunterlagen) benötigt werden.
  Bereits erzeugte Rechnungs-PDFs werden **nicht** rückwirkend geschwärzt
  (passen Sie `GdprTools::deleteCustomer()` an, falls die
  Aufbewahrungsregeln in Ihrer Rechtsordnung von diesem Standard
  abweichen).
- **Automatisierte Inaktivitätslöschung**: **Admin → Einstellungen →
  Datenaufbewahrung** legt fest, nach wie vielen Monaten Inaktivität die
  Löschung ausgelöst wird (Standard 24). 3 Monate vor Erreichen dieser
  Schwelle erhält ein Kunde eine Warn-E-Mail (Vorlage
  `account_deletion_warning`) - jede erneute Anmeldung storniert die
  Löschung. `runGdprInactivityCleanup()` in
  [includes/GdprCleanup.php](includes/GdprCleanup.php) erledigt beide
  Schritte; täglich über einen echten System-Cron ausführen:
  ```bash
  0 3 * * * php /path/to/shopRex/admin/cron/gdpr_cleanup.php
  ```
  [admin/cron/gdpr_cleanup.php](admin/cron/gdpr_cleanup.php) verweigert
  die Ausführung außerhalb der CLI (ein echter Cron-Job hat keine
  Admin-Sitzung zu prüfen, ein Aufruf per HTTP würde also jedem erlauben,
  Löschungen auszulösen) - zusätzlich abgesichert durch
  `admin/cron/.htaccess` als zweite Ebene. Admins können den Lauf auch bei
  Bedarf über **Admin → Einstellungen → Bereinigung jetzt ausführen**
  anstoßen. Testkonten (`is_test_account`) sind davon nie betroffen.

## Externe Bibliotheken

Alle werden von öffentlichen CDNs geladen (jsdelivr / code.jquery.com) -
kein Composer-/npm-Build-Schritt nötig. Um sie stattdessen selbst zu
hosten (z. B. für eine Offline-/abgeschottete Bereitstellung oder eine
strengere CSP), jede einzeln nach `assets/vendor/` herunterladen und die
`<link>`-/`<script>`-Tags in `includes/header.php`, `includes/footer.php`,
`admin/pages.php`, `admin/menus.php`, `admin/product_images.php` und
`admin/image_crop.php` anpassen.

| Bibliothek | Verwendet für | Wo |
|---|---|---|
| Bootstrap 5.3 | Layout/Komponenten im Shop | `includes/header.php`, `includes/footer.php` |
| Bootstrap Icons 1.11 | Icons (Warenkorb, Suche, Zahlungsarten, ...) | `includes/header.php` |
| jQuery 3.7 | Kleine Interaktionen im Shop; von jQuery UI benötigt | `includes/footer.php`, `assets/js/main.js` |
| jQuery UI 1.13 (Sortable) | Drag-&-Drop-Neuordnung von Menüs und Produktbildern | `admin/menus.php`, `admin/product_images.php` |
| Quill.js 2.0 | Rich-Text-Editor für CMS-Seiten | `admin/pages.php` |
| Cropper.js 1.6 | Interaktiver Bildzuschnitt | `admin/image_crop.php` |

## Projektstruktur

```
install.php           Ersteinrichtungsassistent (siehe Installation oben)
config/                DB- und Site-Konfiguration; installed.php wird generiert, nicht eingecheckt
includes/              Gemeinsames PHP: bootstrap, Cart, Mailer, PaymentGateway, ImageProcessor (GD-
                       Zuschnitt), SimplePdf + InvoiceGenerator, GdprTools + GdprCleanup, i18n
                       (__()/Sprachdateien), Kategorie-/Menübaum- + Theme-Hilfsfunktionen
includes/lang/en.php, de.php, fr.php   Übersetzungsstrings (Sprache hinzufügen: neue xx.php hier ablegen)
assets/                Shop-CSS/JS/Bilder
themes/                Installierbare Layout-Pakete (siehe Frontend-Theme oben) - themes/default/,
                       themes/sidebar/ und alle, die Sie hinzufügen; jedes ist nur eine theme.json
                       plus optionale Overrides von header.php/footer.php/home.php/style.css
uploads/products/      Hochgeladene Produktbilder (Originale + erzeugte zugeschnittene Derivate)
uploads/invoices/      Erzeugte Rechnungs-PDFs (nie über den Browser erreichbar, siehe Sicherheit)
sql/schema.sql         Datenbankstruktur + Standardwerte für Einstellungen/Seiten/Menü/Steuersätze/
                       E-Mail-Vorlagen/eine Standard-Versandmethode
sql/seed_demo.sql      Optionale Demo-Kategorien/-Produkte/-Menülinks (Checkbox im Installer)
index.php, product.php, cart.php, checkout.php, page.php, search.php, ...   Shop-Seiten
forgot_password.php, reset_password.php   Passwort-Wiederherstellung
account_export.php, account_delete.php    Selbstbedienungs-DSGVO-Export/-Löschung für Kunden
invoice_download.php   Auth-geprüfte Auslieferung der Rechnungs-PDFs (Besitzer der Bestellung oder Admin)
admin/                 Backend (eigener Header/Footer/Auth/Rollen/CSS)
admin/includes/roles.php    Rolle-zu-Berechtigung-Zuordnung (siehe Admin-Rollen oben)
admin/admins.php            Admin-Kontenverwaltung (nur Super Admin)
admin/categories.php        Kategoriebaum-Verwaltung (unbegrenzte Verschachtelung)
admin/pages.php              CMS-Seitenverwaltung (Quill.js-Editor, pro Sprache)
admin/menus.php              Verwaltung von Haupt-/Footer-Menü (jQuery UI Sortable)
admin/menu_reorder.php       AJAX-Endpunkt für die Drag-&-Drop-Neuordnung der Menüs
admin/product_images.php     Bildmanager je Produkt (Upload, Beschriftung, Neuordnung, Hauptbild)
admin/product_image_reorder.php   AJAX-Endpunkt für die Drag-&-Drop-Neuordnung der Bilder
admin/image_crop.php         Cropper.js-Zuschnitt-UI + löst ImageProcessor (GD) aus
admin/tax_rates.php          MwSt.-Satz-Verwaltung
admin/shipping.php           Gewichtsstaffel-Versandmethoden + Regeln für Gratisversand
admin/email_templates.php    Editierbarer E-Mail-Header/-Footer/-Text, pro Sprache
admin/customer_export.php    Admin-ausgelöster DSGVO-Datenexport
admin/cron/gdpr_cleanup.php  Nur-CLI-Einstiegspunkt für die Inaktivitätsbereinigung (siehe Datenschutz)
admin/settings.php           Shop-/Zahlungs-/Bankdaten, Layout-/Theme-/Sprach-/MwSt.-/Aufbewahrungseinstellungen
```

## Hinweise / bekannte Vereinfachungen

- CSRF-Schutz, Passwort-Hashing (`password_hash`/`password_verify`) und
  Prepared Statements werden durchgängig verwendet, aber es gab noch kein
  vollständiges Sicherheitsaudit — vor dem Produktiveinsatz prüfen,
  insbesondere die Datei-Upload-Verarbeitung, die Zugriffskontrolle des
  Admin-Bereichs, und `install.php` absichern (es sperrt sich selbst,
  sobald ein Admin existiert, aber erwägen Sie, es nach der Einrichtung
  ganz zu entfernen).
- CMS-Seiteninhalte (`page.php`) und deren Darstellung im Shop gelten als
  **vertrauenswürdiges HTML**, unescaped — dasselbe Modell wie bei den
  meisten CMS (WordPress-Beitrags-/Seiteninhalte usw.). Wer Seiten
  bearbeiten kann (Super Admin oder Manager), kann beliebiges
  Markup/Skripte in den Shop einschleusen; behandeln Sie „wer darf Seiten
  bearbeiten" als gleichbedeutend mit „wer darf den Code der Seite
  bearbeiten".
- Der Bildzuschnitt (`includes/ImageProcessor.php`) benötigt die
  PHP-Erweiterung `gd`; ohne sie funktionieren Uploads weiterhin, aber der
  Zuschneiden-Button zeigt einen Fehler an, statt ein zugeschnittenes
  Derivat zu erzeugen.
- „Zuschneiden" erzeugt ein Derivat pro Bild (ersetzt beim erneuten
  Zuschneiden das vorherige) — es gibt keine responsive
  `srcset`-/Mehrgrößen-Erzeugung.
- Die Erkennung von Testbestellungen setzt voraus, dass man **als
  Testkonto angemeldet** ist - es gibt kein „Test-Gastbestellung"-Konzept
  (ein Konto ist das, was Admin → Kunden anlegt, und `is_test_account`
  lebt auf dieser Zeile).
- Die Formel für den rabattierten Preis ist bewusst zweimal vorhanden:
  einmal in SQL (`index.php`/`search.php`, für Sortierung/Filterung) und
  einmal in PHP (`getActiveDiscount()` in `includes/functions.php`, für
  die Anzeige und den maßgeblichen Preis beim Hinzufügen zum
  Warenkorb/an der Kasse) - bei Änderungen an der Rabattlogik müssen
  beide aktualisiert werden.
- **Sprachabdeckung**: die UI-Umgebung im Shop/Backend und alle E-Mails
  sind vollständig ins Englische/Deutsche/Französische übersetzt,
  CMS-Seiten unterstützen eine Zeile pro Sprache, und
  Produktname/Kurzbeschreibung/Beschreibung/Optionsbezeichnungen sind
  ebenfalls pro Sprache (Admin → Produkte → bearbeiten) - siehe
  [Sprachen](#sprachen). **Kategorienamen** bleiben einsprachig (nur der
  `intro_text` einer Kategorie ist übersetzbar) - siehe
  [Sprachen](#sprachen) für die Gründe und wie sich das bei Bedarf
  erweitern lässt.
- **SimplePdf** (`includes/SimplePdf.php`) unterstützt nur die
  Kern-Helvetica-Schriften über WinAnsiEncoding (~Latin-1/Windows-1252) -
  das deckt Englisch, Deutsch und Französisch (sowie die meisten
  westeuropäischen Sprachen) ab, aber z. B. nicht Kyrillisch, Griechisch
  oder CJK-Schriftzeichen; nicht unterstützte Zeichen werden durch die
  `iconv(...//TRANSLIT)`-Umwandlung transliteriert oder verworfen. Zudem
  wird die Textbreite für den Zeilenumbruch geschätzt statt exakter
  Zeichenmetriken. Für alles über einfache Rechnungen hinaus eine
  Composer-PDF-Bibliothek einsetzen.
- Die DSGVO-Löschung behält Bestell-/Finanzdatensätze (anonymisiert), statt
  sie ganz zu löschen, und bearbeitet bereits erzeugte Rechnungs-PDFs nicht
  rückwirkend - siehe [Datenschutz (DSGVO)](#datenschutz-dsgvo) für die
  Begründung und wo das anzupassen ist, falls Ihre
  Aufbewahrungspflichten abweichen.
- Es ist keine automatisierte Testsuite enthalten.

## Mitwirken

Fehlermeldungen, Feature-Wünsche und Pull Requests sind willkommen - siehe
[CONTRIBUTING.md](CONTRIBUTING.md) für die Entwicklungsumgebung,
Coding-Richtlinien und wie ein Sicherheitsproblem vertraulich gemeldet
wird.

## Lizenz

[MIT](LICENSE) - nutzen Sie es, wie Sie möchten, auch kommerziell; behalten
Sie nur den Copyright-Hinweis bei.
