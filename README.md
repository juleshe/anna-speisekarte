# Anna Imbiss – Speisekarte

WordPress-Plugin für [annaimbiss.de](https://www.annaimbiss.de/speisekarte/).

- Liest die Speisekarte serverseitig aus einem Google Sheet (10 Minuten Cache, Fallback auf letzten guten Stand bzw. `data/speisekarte.csv`).
- Shortcode `[anna_speisekarte]`: HTML-Karte mit Tabs (lesbar am Handy, für Suchmaschinen indexierbar).
- Button „Speisekarte aufs Handy speichern“: erzeugt die Tafeln im TV-Look (1920×1080 JPG) im Browser.
- Schriften lokal im Plugin, keine Anfragen an Google aus dem Browser der Besucher.

## Sheet-Spalten

`Typ, Spalte, Nr, Name, Beschreibung, Preis 1, Preis 2, Zusatz`

| Typ | Bedeutung |
|---|---|
| Tafel | Neue Tafel/Tab. Name = Tab-Titel, Zusatz = optionale Hintergrundbild-URL |
| Kategorie | Badge. Preis 1/2 = Spaltenüberschriften (z. B. PREIS, 24er/28er) oder ein Preis direkt am Badge |
| Gericht | Nr, Name, Beschreibung, Preis 1, Preis 2, Zusatz = kleiner Text hinter dem Namen |
| Banner | Gelbes Banner. Zusatz = Bubble-Text, `\|` = Zeilenumbruch |
| Schrift | Handschrift-Spruch |
| Abstand | Leerraum, Name = Höhe in Pixeln |

## Updates

Neue Versionen werden als GitHub-Release mit dem Asset `anna-speisekarte.zip` veröffentlicht. Das Plugin prüft mit [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT) und installiert Updates automatisch.

Schriften: Gilda Display, Roboto Slab, Kaushan Script (SIL OFL 1.1), Roboto (Apache 2.0) – siehe `assets/fonts/LICENSES.txt`.
