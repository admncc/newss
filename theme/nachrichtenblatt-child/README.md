# Nachrichtenblatt — Child Theme

Redesign-Child-Theme für [das-nachrichtenblatt.de](https://das-nachrichtenblatt.de/).
Basiert auf dem **Newspaper**-Theme (tagDiv) und überschreibt Design-Tokens,
Typografie, Karten-Layout, Single-Post-Lesbarkeit, Header/Footer und ergänzt
Accessibility- und UX-Funktionen.

## Was es ändert

- **Design-System** mit CSS-Variablen (Farben, Typo, Spacing) — leicht zu twea­ken
- **Typografie**: PT Serif für Headlines, Inter für Body, Work Sans für Meta — modulare Skala 1.25
- **Marken-Rot** verfeinert von `#ec3535` → `#d81e1e` (etwas tiefer, bessere AA-Kontrastwerte gegen Weiß)
- **Karten** mit sanftem Hover-Zoom auf Bilder, klarere Meta-Hierarchie, Kategorien-Pille als Kicker
- **Single-Post**: 720 px Satzspiegel, 1.75 Line-Height, Drop-Cap, redaktionelle Blockquotes, Captions mit Trennlinie
- **Lesefortschrittsbalken** oben (an/aus per Customizer)
- **Lesezeit-Pille** (200 WPM, gecached in Postmeta `_nbc_reading_time`)
- **Dark-Mode** via `prefers-color-scheme` + Toggle-Button, Persistenz in `localStorage`
- **Schema.org NewsArticle** JSON-LD (deaktiviert sich, wenn Yoast/Rank Math aktiv)
- **Newss-Plugin-Integration**: KI-generierte Posts bekommen ein "KI"-Badge in Listen + eine pflichtkonforme KI-Disclosure-Box am Artikelende (EU AI Act Art. 50)
- **Accessibility**: Skip-Link, sichtbare Fokus-Ringe, `prefers-reduced-motion` respektiert, AA-Kontraste
- **Print-Stylesheet**: Header/Footer/Toggle/Disclosure ausgeblendet, Links mit URL-Anhang

## Datei-Layout

```
nachrichtenblatt-child/
├── style.css                 ← WP-Theme-Header (Template: Newspaper)
├── functions.php             ← Bootstrap, lädt inc/-Dateien
├── theme.json                ← Block-Editor-Tokens (Farben/Typo/Layout)
├── screenshot.png            ← Vorschau im WP-Admin
├── README.md
├── assets/
│   ├── css/redesign.css      ← Haupt-Stylesheet (lädt nach Parent)
│   ├── css/editor.css        ← Gutenberg-Editor-Styles
│   └── js/redesign.js        ← Vanilla, defer — Reading-Progress, Dark-Toggle, Smooth-Scroll
├── inc/
│   ├── enqueue.php           ← Asset-Enqueues + Skip-Link + Critical-CSS
│   ├── template-functions.php← Reading-Time, KI-Disclosure, JSON-LD, Body-Class
│   └── customizer.php        ← Akzentfarbe, Color-Mode, Progress-Bar-Switch
└── languages/                ← .po/.mo (text-domain: nachrichtenblatt-child)
```

## Installation

1. Verzeichnis `nachrichtenblatt-child/` nach `wp-content/themes/` kopieren.
2. Sicherstellen, dass das Parent-Theme **Newspaper** (tagDiv) installiert ist.
3. Im Backend: **Design → Themes → Nachrichtenblatt Child → Aktivieren**.
4. Optional: **Design → Customizer → Nachrichtenblatt — Design**:
   - Akzentfarbe wählen
   - Standard-Farbmodus (System/Hell/Dunkel)
   - Lesefortschrittsbalken an/aus

## Entwicklung

Keine Build-Tools nötig — pure CSS/JS/PHP. Falls langfristig SCSS gewünscht:
- `assets/css/redesign.css` ist der Output-Punkt
- Kompilieren mit z. B. `sass src/redesign.scss assets/css/redesign.css`

### Override-Strategie

Newspaper / tagDiv Cloud Library schreibt sehr viele Inline-Styles und nutzt
extrem spezifische Selektoren wie `.tdb-block-inner.td-fix-index .tdb-block-title`.
Wir kommen damit auf zwei Wegen aus:

1. **Variablen statt Werte ändern** — `--nbc-brand` etc. greifen überall, wo wir
   sie ins CSS einsetzen.
2. **!important sparsam** — nur wo Inline-Styles dominieren (Logo-Farbe,
   Menü-Links, Excerpt-Farben). Die meisten anderen Regeln gewinnen über
   Lade-Reihenfolge (Child-CSS nach Parent).

### Kompatibilität

- Newspaper 12.7+ (getestet gegen 12.7.5)
- WordPress 6.4+
- PHP 8.1+
- Optional: Newss-Plugin (für KI-Disclosure-Auto-Inject)

## Lizenz

Proprietär — interne Nutzung für Nachrichtenblatt.
