# webtrees-share

[English](README.md) · **Deutsch**

Ein Modul für [webtrees](https://webtrees.net/), mit dem man eine einzelne Person per Link mit jemandem teilen
kann, der **kein** webtrees-Konto hat – zum Beispiel einer Tante, die bestimmt weiß, wann ihr Vater geboren
wurde, aber selbst nie ein Konto anlegen würde. Ein gewöhnliches Zusatzmodul: Es liegt in `modules_v4/`,
der webtrees-Kern wird nicht verändert. Unabhängig von anderen Modulen wie „webtreesand-api“ – keins der beiden
liest die Tabellen oder Klassen des anderen.

| | |
| - | - |
| webtrees | 2.2.x |
| PHP | 8.3 oder neuer |
| Lizenz | GPL-3.0 |

## Wie es funktioniert

1. **Anfragen:** Ein angemeldeter Bearbeiter wählt eine Person aus und lässt einen Link erzeugen. Dabei wird
   sofort eine Momentaufnahme der wichtigsten Angaben zu dieser Person gespeichert (Geburt/Tod, Datum und Ort).
2. **Der Link** führt zu einer schlichten, für Handys geeigneten webtrees-Seite – kein Login nötig. Die
   angefragte Person sieht genau das, was zum Zeitpunkt der Anfrage bekannt war (nicht den aktuellen Stand
   des Stammbaums), kann die Angaben ergänzen oder korrigieren, ein Foto hinzufügen und dazu eine freie
   Notiz hinterlassen.
3. **Die Antwort** geht an die Person zurück, die die Anfrage gestellt hat – als Benachrichtigung (Icon in
   webtrees, optional in einer begleitenden App) und per E-Mail. Es gibt in webtrees keine private
   Nachrichtenfunktion, deshalb speichert dieses Modul Anfrage und Antwort selbst, in einer einzigen Tabelle.
4. **Übernehmen:** Wer die Anfrage gestellt hat, sieht alte und vorgeschlagene Werte nebeneinander, wählt aus,
   was übernommen werden soll, und speichert. Das läuft als ganz normale Bearbeitung über das eigene Konto –
   genau so, als hätte man die Angaben am Telefon bekommen und selbst eingetragen. Bestehende Regeln
   (Moderation, „Änderungen automatisch annehmen“ …) gelten dabei unverändert.
5. **Weiterfragen:** Nach dem Absenden schlägt die Seite vor, auch Eltern oder Kinder der Person zu ergänzen,
   sortiert danach, wie viel bei ihnen noch fehlt – ohne dass dafür ein neuer Link erzeugt werden muss.

Ein Link ist **2 Tage** gültig.

## Installation

1. Diesen Ordner nach `modules_v4/webtrees-share` kopieren, sodass `modules_v4/webtrees-share/module.php`
   entsteht.
2. Fertig. Das Modul legt beim ersten Aufruf seine eine Tabelle an (`webtreesshare_request`) und ist danach
   aktiv, sichtbar unter *Verwaltung → Module → Alle Module*.

Aktualisieren: Ordner ersetzen. Entfernen: Ordner löschen (die Tabelle bleibt bestehen und kann bei Bedarf von
Hand entfernt werden).

## Datenschutz und Rechte

- Eine Anfrage kann nur erzeugen, wer die betreffende Person ohnehin bearbeiten darf.
- Wer den Link öffnet, sieht nur die eingefrorene Momentaufnahme, nie den aktuellen, unter Umständen
  privateren Stand des Stammbaums.
- Die Antwort wird **nicht automatisch** in den Stammbaum übernommen – erst wenn die anfragende Person sie
  Feld für Feld bestätigt, entsteht eine echte Bearbeitung, mit denselben Regeln wie jede andere Bearbeitung
  auch.

## Für Entwickler

Einstieg in den Quelltext ist der Kopf von `WebtreesShareModule.php`. Die wichtigsten Endpunkte
(`/module/webtrees-share/<Aktion>[/<Baum>]`, wie bei jedem webtrees-Modul ohne eigene Routen):

| Aktion | Methode | Angemeldet? | Zweck |
| - | - | - | - |
| `Info` | GET | nein | `{active, version}` – z. B. für eine App, um zu prüfen, ob das Modul aktiv ist |
| `CreateRequest` | POST | ja, Bearbeiter | Anfrage anlegen, liefert `{url, expires}` |
| `RequestEmailTemplate` | GET | ja | Vorschau des E-Mail-Textes zum Personalisieren |
| `SendRequestEmail` | POST | ja | verschickt die E-Mail serverseitig |
| `Request` | GET/POST | nein | die Formularseite für die angefragte Person |
| `RequestContinue` | POST | nein | legt aus der Weiterfragen-Liste eine neue Anfrage an |
| `RequestReview` | GET/POST | ja, Ersteller | Liste bzw. Vergleichs-/Übernahme-Seite |
| `RequestPhoto` | GET | ja, Ersteller | liefert ein noch nicht geprüftes Foto für die Vergleichsseite |
| `RequestNotifications` | GET | ja | `{unread}` für ein Benachrichtigungssymbol |

Ein eingereichtes Foto liegt zunächst im eigenen „data“-Ordner von webtrees, nicht im Medienarchiv des
Baums – erst wenn die anfragende Person es auf der Vergleichsseite annimmt, wird daraus ein echtes
Medienobjekt.
