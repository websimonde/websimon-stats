# websimon-stats

**Schlanke Besucherstatistik für PHP-Webseiten – ohne Cookies, ohne Datenbank, ohne Google.**

[English version](README.md) · [Live-Demo](https://websimon.de/demo/)

![Dashboard von websimon-stats](docs/screenshot.webp)

## Was es kann

- **Kein Cookie-Banner nötig:** keine Cookies, nichts wird auf den Geräten der Besucher gespeichert
- **Keine IP-Adressen:** Besucher werden über eine anonyme Kennung gezählt, die sich täglich ändert
- **Zählt auf dem Server:** funktioniert auch ohne JavaScript, Werbeblocker bremsen es nicht aus
- **Keine Datenbank:** Ordner hochladen, eine Zeile einfügen, fertig
- **Filtert Bots** von Suchmaschinen, Vorschau-Diensten und Skripten heraus
- **Auswertung:** Seitenaufrufe und Besucher, Verlauf nach Tagen, Wochen und Monaten, Herkunft, Browser, Betriebssysteme, Geräte, Sprachen, beliebteste Seiten
- **PDF-Bericht** für beliebige Zeiträume
- **Passwortgeschützt**, mit Sperre nach zu vielen Fehlversuchen
- **6 Sprachen** (Deutsch, Englisch, Spanisch, Französisch, Italienisch, Niederländisch), heller und dunkler Modus
- Alle Daten bleiben auf deinem eigenen Webspace

## Voraussetzungen

- Webspace mit **PHP 7.4 oder neuer** (getestet mit PHP 8.3)
- Apache, nginx, LiteSpeed oder IIS – es ist keine `.htaccess` und keine Server-Einstellung nötig
- Der Statistik-Ordner muss für PHP **beschreibbar** sein (bei den meisten Webspaces ist er das von Haus aus)

## Installation

1. **Herunterladen:** Oben auf dieser Seite auf **Code → Download ZIP** klicken und die ZIP-Datei entpacken.
2. **Hochladen:** Den Ordner **`stats`** per FTP (z. B. mit WinSCP oder FileZilla) in das Hauptverzeichnis deiner Webseite laden. Der Ordner darf auch anders heißen, z. B. `besucher`.
3. **Passwort festlegen:** Im Browser `https://deine-seite.de/stats/` aufrufen. Beim ersten Aufruf legst du ein Passwort fest (mindestens 8 Zeichen). Es wird nur verschlüsselt gespeichert.
4. **Zählung einbauen:** In der Statistik oben rechts auf das Zahnrad klicken. Unter **„Seitenaufrufe zählen“** steht die fertige Zeile für deine Seite, z. B.:

   ```php
   <?php if (is_file($_SERVER['DOCUMENT_ROOT'] . '/stats/track.php')) { include_once $_SERVER['DOCUMENT_ROOT'] . '/stats/track.php'; } ?>
   ```

   Diese Zeile kommt **ganz an den Anfang** jeder PHP-Seite, die gezählt werden soll – vor `<!DOCTYPE html>`. Nutzen alle Seiten eine gemeinsame Kopf-Datei, reicht es, sie dort einmal einzufügen.
5. **Testen:** Eine deiner Seiten aufrufen und danach die Statistik neu laden – der Aufruf sollte erscheinen.

> **Seiten ohne PHP (reines HTML)?** Dann kann alternativ ein unsichtbares Bild zählen:
> `<img src="/stats/track.php?img&page=/seitenname" alt="" width="1" height="1">`
> Die serverseitige PHP-Zeile ist aber genauer und funktioniert auch ohne Bilder.

## Einstellungen

Alles Wichtige stellst du direkt in der Statistik ein (Zahnrad oben rechts):

- **Darstellung:** hell, dunkel oder wie das System
- **Sprache:** wird sonst automatisch aus dem Browser übernommen
- **Daten aufbewahren:** 3, 6, 12 oder 24 Monate – ältere Aufrufe werden automatisch gelöscht
- **Bots herausfiltern:** Aufrufe ohne Browsersprache werden nicht gezählt (empfohlen)

**Eigenes Logo:** Lege eine Datei `logo.png` (etwa 260 × 64 Pixel) in den Statistik-Ordner. Für den dunklen Modus kann zusätzlich eine `logo-dark.png` dazukommen.

**Weitere Sprache:** Eine Datei aus `stats/lang/` kopieren, z. B. `en.php` → `pt.php`, und die Texte übersetzen. Die Sprache erscheint dann automatisch in der Auswahl.

## Datenschutz

Die Statistik setzt keine Cookies und liest nichts vom Gerät der Besucher aus. Sie wertet nur aus, was jeder Browser ohnehin mitschickt, und speichert keine IP-Adressen. Ein Cookie-Banner ist dafür in aller Regel nicht nötig.

**In deine Datenschutzerklärung gehört sie trotzdem.** Ein Textbeispiel zum Anpassen:

> **Besucherstatistik**
> Um zu erfahren, wie viele Besucher diese Website nutzt und welche Seiten aufgerufen werden, setze ich eine selbst gehostete Besucherstatistik ein. Sie wertet bei jedem Seitenaufruf auf dem Server folgende Angaben aus: aufgerufene Seite, Datum und Uhrzeit, Browser, Betriebssystem und Gerätetyp (abgeleitet aus der Browserkennung), Browsersprache sowie die Domain der zuvor besuchten Website (Referrer). Um mehrfache Aufrufe an einem Tag zusammenzufassen, wird aus der Browserkennung und dem Datum eine anonyme Kennung gebildet, die sich täglich ändert. IP-Adressen werden nicht gespeichert, es werden keine Cookies gesetzt und keine Informationen auf Ihrem Gerät abgelegt oder ausgelesen. Die Daten werden ausschließlich auf dem Webspace dieser Website gespeichert, nicht an Dritte weitergegeben und nach 12 Monaten automatisch gelöscht. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO; das berechtigte Interesse liegt in der Auswertung der Reichweite und der Verbesserung des Angebots.

Passe die Speicherdauer an deine Einstellung an. *Das ist keine Rechtsberatung – im Zweifel lass deine Datenschutzerklärung prüfen.*

## Update auf eine neue Version

Alle Dateien im Statistik-Ordner durch die neuen ersetzen – **außer** diesen, sie enthalten deine Daten:

- `config.php` (Passwort und Einstellungen)
- `visits.log.php` (die gezählten Besuche)
- `login-attempts.php`
- `logo.png` / `logo-dark.png`, falls vorhanden

## Support

websimon-stats ist kostenlos und wird so bereitgestellt, wie es ist – **ohne Support**. Fehlermeldungen lese ich gern, kann aber keine Antwort versprechen.

Du brauchst eine neue Webseite und willst dich um nichts kümmern? Bei Webseiten von [websimon.de](https://websimon.de) ist die Statistik inklusive und fertig eingerichtet.

Gefällt dir das Script?

<a href="https://ko-fi.com/G7N629I3TR"><img src="docs/coffee-de.png" alt="Spendier mir einen Kaffee" width="255"></a>

## Lizenz

[MIT](LICENSE) – du darfst das Script frei nutzen, verändern und weitergeben, auch für Kundenprojekte. Der Lizenzhinweis muss dabei erhalten bleiben.

© 2026 Ralf Simon, [websimon.de](https://websimon.de)
