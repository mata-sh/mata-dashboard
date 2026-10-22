# UI Styleguide

How the MATA dashboard should look and feel, and the reusable building blocks to
get there. Applies to everything rendered in the browser: Twig templates,
`public/assets/css/main.css`, Tabulator tables, and modal content.

## The aesthetic in one sentence

**Sleek, modern, minimalistic dark UI with a restrained cyberpunk accent —
professional first, neon second.**

What that means in practice:

- **Near-black, blue-tinted surfaces.** Backgrounds are not pure `#000` grey —
  they lean slightly blue (`#0b0d12`, `#0e1015`, `#12151c`). Depth comes from
  layering these tones plus 1px hairline borders, not from heavy shadows or
  gradients.
- **Cyan is the single accent color.** Interactive/highlighted things glow
  teal-cyan (`#3f93a6` borders, `#9fe8f2`/`#7fe3f0` text, `#10262b` fills).
  Cyan is used sparingly — a 1–2px border edge, a hover state, an active page
  button — never as a large fill.
- **Severity colors are muted, not neon.** Red/orange/amber/green are dimmed
  to sit comfortably on dark surfaces (`#c22a35`, not `#ff0000`). Brightness is
  reserved for text tints and hover states.
- **Quiet by default, loud on signal.** Chrome (headers, labels, pagination,
  meta text) is desaturated grey-blue. Color always *means* something:
  severity, state, or interactivity. If an element carries no signal, it stays
  grey.
- **Small, dense, uppercase labels.** Structural labels (table headers, server
  names, pill text) are ~0.72rem, bold, uppercase, letter-spaced. Content text
  stays normal-case and readable.
- **Tight geometry.** Radii are small (4–8px on components, 10px on floating
  cards). Padding is compact. Hairlines separate rows instead of gaps.

## Design tokens

`:root` in `public/assets/css/main.css` is the canonical token source. New UI
styles must use these variables. Introducing a new raw hex requires adding a
semantic token first; legacy blocks retain their existing literals until their
visual pass.

### Surfaces & borders

| Name | Token | Value | Use |
|---|---|---|---|
| Surface, deepest | `--surface-base` | `#0b0d12` | Table body / component base background |
| Surface, panel | `--surface-panel` | `#0e1015` | Headers, footers, filter bars, panel rows |
| Surface, raised | `--surface-raised` | `#12151c` | Floating cards (dropdowns, popovers) |
| Surface, control | `--surface-control` / `--surface-control-alt` | `#10131a` / `#14171d` | Inputs, secondary buttons |
| Surface, chip | `--surface-chip` | `#161a22` | Neutral audit chips |
| Surface, zebra | `--surface-zebra` | `#0f1218` | Even table rows |
| Surface, hover | `--surface-hover` / `--surface-hover-alt` / `--surface-hover-header` | `#131c26` / `#182029` / `#131a22` | Row, button, and sortable-header hover fills |
| Border, hairline | `--border-hairline` | `#151a22` | Row separators inside a component |
| Border, default | `--border-default` | `#1f242e` | Component outlines, section dividers |
| Border, control | `--border-control` | `#262c38` | Input/button/card borders |
| Border, strong | `--border-strong` / `--border-strong-alt` | `#2a3040` / `#3a4150` | Outlined pills, bell trigger |
| Border, control hover | `--border-control-hover` | `#333c4d` | Input hover border |

### Cyan accent

| Name | Token | Value | Use |
|---|---|---|---|
| Accent border | `--accent-border` | `#3f93a6` | Hover/focus border, active-state border |
| Accent edge | `--accent-edge` | `#2f6f7d` | Structural accent lines (header underline, filter bar left edge) |
| Accent fill | `--accent-fill` / `--accent-fill-hover` | `#10262b` / `#153238` | Active/selected background (dark teal) |
| Accent text | `--accent-text` / `--accent-text-alt` / `--accent-text-bright` | `#9fe8f2` / `#7fe3f0` / `#c8f4fa` | Highlighted and hover text |
| Focus ring | `--accent-focus-ring` | `rgba(63, 147, 166, .2)` | `box-shadow: 0 0 0 2px …` on focus |

### Text

| Name | Token | Value | Use |
|---|---|---|---|
| Text, bright | `--text-bright` | `#e8eaf0` | Headings inside components, input values |
| Text, body | `--text-body` | `#c9cfda` | Table cells, content text |
| Text, label | `--text-label` | `#c5cad6` | Labels and floating-card rows |
| Text, muted | `--text-muted` | `#8b93a5` | Column headers, meta labels, secondary links |
| Text, faint | `--text-faint` | `#5a6172` | Placeholders, counters, empty states |

### Severity scale

Solid badge/pill fills, their 1px light borders, and the light text tints used
when a count sits on a dark surface (e.g. dropdown rows):

| Level | Fill token / value | Border token / value | Text token / value |
|---|---|---|---|
| critical | `--sev-critical-fill` / `#c22a35` | `--sev-critical-border` / `#e05561` | `--sev-critical-tint` / `#f09595` |
| high / error | `--sev-high-fill` / `#c05a1d` | `--sev-high-border` / `#e0854a` | `--sev-high-tint` / `#f0b27b` |
| medium / warning | `--sev-medium-fill` / `#9c7c14` | `--sev-medium-border` / `#c4a53a` | `--sev-medium-tint` / `#e3ce7a` |
| low / ok | `--sev-low-fill` / `#2e7d43` | `--sev-low-border` / `#4da767` | `--sev-low-tint` / `#7bd88f` |
| unknown | `--sev-unknown-fill` / `#4a5060` | `--sev-unknown-border` / `#6b7280` | `--sev-unknown-tint` / `#9a9a9a` |
| abandoned / inactive | `--sev-abandoned-fill` / `#343a47` | `--sev-abandoned-border` / `#6b7280` dashed | `--sev-abandoned-tint` / `#c5cad6` |

Solid severity fills always carry white (`#fff`) bold text.

## Typography rules

- Section headings (`h3`) are Raleway, bold, uppercase.
- **Structural micro-labels** — table column headers, server labels, pill
  text: `font-size: .72rem` (`.78rem` for Bulma tables); `font-weight: 700`;
  `text-transform: uppercase`; `letter-spacing: .07em`; color `#8b93a5`.
- Body/content inside components: `.9–.95rem`, normal case.
- Floating card text (dropdown menus): 13px rows, 12px meta, 11px badges.
- Monospace (ui-monospace stack) only for machine-ish values: progress
  counters, CVE ids, code.

## Reusable components

These already exist in `main.css` — reuse the classes instead of restyling.

### Panel / section row (`.audit-server-row`, `.filter-menu`)

The signature MATA container: panel surface, default border, **2px cyan left
edge** as the accent.

```css
background: #0e1015;
border: 1px solid #1f242e;
border-left: 2px solid #2f6f7d;
border-radius: 4–6px;
```

### Data tables (`.tabulator`)

Global — every Tabulator table inherits it. Rounded 6px shell with
`overflow: hidden`, panel-colored header with a **1px `#2f6f7d` bottom
border**, uppercase muted column titles, zebra rows (`#0f1218`), hairline row
separators, hover `#131c26`, no vertical cell borders. Footer pagination:
ghost buttons; the active page gets the cyan trio (fill `#10262b`, border
`#2f6f7d`, text `#9fe8f2`).

### Inputs & secondary buttons (`.filter-menu` fields)

```css
background: #10131a;             /* buttons: #14171d */
border: 1px solid #262c38;
border-radius: 4px;
color: #e8eaf0;
color-scheme: dark;              /* dark native select/date widgets */
/* hover  */ border-color: #333c4d;         /* buttons: #3f93a6 + #9fe8f2 text */
/* focus  */ border-color: #3f93a6; box-shadow: 0 0 0 2px rgba(63,147,166,.2);
```

Primary/action variant (`.audit-run-button`): accent fill `#10262b`, border
`#2f6f7d`, text `#9fe8f2`.

### Severity badges & pills (`.cve-badge`, `.audit-pill`, `.audit-chip`)

Solid severity fill + 1px lighter border of the same hue + white bold text,
radius 4–5px. Zero/meta variants go transparent with `#2a3040` border and
muted text — color only when there is something to signal.

### Count badge (`.notification-bell-badge`)

Circular count that overlaps a corner: `min-width: 18px; height: 18px;
border-radius: 9999px` (stadium — stays round at 2+ digits), solid `#c22a35`,
white 11px bold text, and a **2px ring in the parent's background color**
(`var(--bulma-dark)` on the navbar) so it appears punched out.

### Icon button (`.notification-bell-trigger`)

36×36 rounded square (8px), `#23272f` fill, `#3a4150` border, muted icon.
Hover/focus: cyan border + brightened icon. The pattern for any navbar-level
icon action.

### Floating card / dropdown (`.notification-bell-menu`)

`width: ~260–280px; background: #12151c; border: 1px solid #262c38;
border-radius: 10px; overflow: hidden; box-shadow: 0 12px 28px
rgba(0,0,0,.45)`. Structure: header row (13px semibold title + 12px muted
action link) → hairline-separated content rows → footer strip on `#0e1015`
holding one full-width bordered button.

### Status dot (`.notification-dot`, `.node-health-status-dot`)

8px circle in the severity fill color, paired with a normal-case label and,
optionally, a right-aligned count in the severity *text tint*.

### Toggle switch (`.toggle`)

Track `#262c38` / border `#3a4150`, grey thumb. Checked: track `#10262b`,
border `#3f93a6`, thumb `#7fe3f0`. Markup: hidden checkbox +
`.toggle-track` + `.toggle-text`.

## Interaction rules

- **Hover** = cyan border (`#3f93a6`) and/or brightened text — never a big
  background swap. Rows may deepen to `#131c26`.
- **Focus** on inputs: cyan border + soft `rgba(63,147,166,.2)` ring, never
  the default outline alone.
- **Active/selected** = the cyan trio: fill `#10262b`, border `#2f6f7d`, text
  `#9fe8f2`.
- **Transitions**: `.1–.15s ease` on `border-color`, `color`, `box-shadow`,
  `opacity`. Nothing bouncy; no transitions on layout properties.
- **Reveal on intent**: secondary actions (refresh/info buttons) sit at
  `opacity: 0` and appear on container hover.
- **De-emphasize, don't hide**: filtered-out/zero items drop to
  `opacity: .35` + grayscale rather than disappearing.

## Codebase gotchas (read before styling)

- **CSS load order** (`templates/partials/structure/head.html.twig`):
  `fonts.css → tabulator_midnight.min.css → bulma.css → main.css`. `main.css`
  wins equal-specificity conflicts by coming last — you rarely need
  `!important` to override the Tabulator theme or Bulma.
- **`main.css` has no cache-bust parameter.** After CSS changes, a hard
  refresh (Ctrl+Shift+R) is required to see them.
- **No global `border-box` reset** — elements resolve to `content-box`.
  Any fixed-size element with padding/border needs its own
  `box-sizing: border-box` or it will render larger than specified.
- **Global SVG rule**: `svg { width: 12px; height: 12px; stroke: #7b7b7b;
  stroke-width: 2.5 }`, plus `.navbar-item svg { stroke: white; …
  margin-right: -6px }`. Component icons must override size and set
  `stroke: currentColor` (and reset the navbar margin) or they inherit these.
- **Perfect circles**: use `border-radius: 9999px`, not `50%`, on count
  badges so they become stadiums instead of ovals when content widens.
- **Dark native widgets**: set `color-scheme: dark` on inputs/selects so the
  browser draws dark dropdowns and date pickers.
- **Bulma variables**: when a color must match Bulma chrome (e.g. the badge
  ring against the navbar), reference the CSS variable
  (`var(--bulma-dark, #2e333d)`) instead of hardcoding.

## Do / don't

| Do | Don't |
|---|---|
| Layer the three surface tones + hairlines for depth | Use pure `#000`/`#fff`, grey `#252525`-era tones, or heavy box-shadows on inline elements |
| Use cyan for *interaction and structure* only | Introduce a second accent hue (purple, magenta, green highlights) |
| Reuse the severity table exactly, muted fill + light border + white text | Use saturated `red`/`orange`/`gold` keywords or invent new severity shades |
| Keep labels tiny/uppercase/muted and content readable | Uppercase body text or brighten labels to white |
| Small radii (4–8px), 10px only on floating cards | Pill-shaped buttons, large rounded panels |
| Let color mean severity/state/interactivity | Decorate neutral elements with color |
