# UI style guide

How the MATA dashboard should look and feel, and the reusable building blocks to
get there. Applies to everything rendered in the browser: Twig templates,
`public/assets/css/main.css`, Tabulator tables, and modal content.

## The aesthetic in one sentence

**Sleek, modern, minimalistic dark UI with a restrained cyberpunk accent —
professional first, neon second.**

Dark is the default and the reference design. The light theme, switched with
the sun/moon button in the topbar, keeps every role: same accent, same
severity meanings, light surfaces. Hex values in this guide are the dark ones
unless a table lists both.

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

`:root` in `public/assets/css/main.css` holds the dark values and
`[data-theme=light]` right below it redefines them. These blocks are the only
place colors are written down.

!!! warning "New colors must be tokens"
    Rules use `var(--…)`, never a hex, `rgb()`, `hsl()` or color keyword. A new
    color needs a semantic token with a value in **both** blocks; reuse an
    existing token when the role matches instead of adding a near-duplicate.
    JavaScript follows the same rule: inline styles use `var(--…)`, canvas code
    (Chart.js) reads tokens with `window.mataThemeColor('--…')`.

Check for strays with
`grep -nE '#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(' public/assets/css/main.css`:
it should only hit the two token blocks.

Tokens whose dark values match can still differ in light. For example
`--text-heading` and `--text-on-fill` are both white in dark, but headings
turn near-black in light while text on solid severity fills stays white. Pick
the token by role, not by its dark value.

### Surfaces & borders

| Name | Token | Dark | Light | Use |
|---|---|---|---|---|
| Page | Bulma `--bulma-body-background-color` | `#14161a` (Bulma) | `#eef1f5` | Page background behind cards |
| Surface, card | `--surface-card` | `#000` | `#fff` | Server, app and database cards, fleet summary, login card |
| Surface, deepest | `--surface-base` | `#0b0d12` | `#fff` | Table body / component base background |
| Surface, panel | `--surface-panel` | `#0e1015` | `#f5f7fa` | Headers, footers, filter bars, panel rows |
| Surface, raised | `--surface-raised` | `#12151c` | `#fff` | Floating cards (dropdowns, popovers) |
| Surface, control | `--surface-control` / `--surface-control-alt` | `#10131a` / `#14171d` | `#fff` / `#f1f3f7` | Inputs, secondary buttons |
| Surface, chip | `--surface-chip` | `#161a22` | `#eceff4` | Neutral audit chips |
| Surface, zebra | `--surface-zebra` | `#0f1218` | `#f8f9fb` | Even table rows |
| Surface, hover | `--surface-hover` / `--surface-hover-alt` / `--surface-hover-header` | `#131c26` / `#182029` / `#131a22` | `#e8f3f6` / `#e1eff3` / `#e9eff4` | Row, button, and sortable-header hover fills |
| Surface, muted | `--surface-muted` | `#232323` | `#eceff4` | README headings and notices |
| Surface, icon button | `--surface-icon-button` | `#23272f` | `#f1f3f7` | Bell trigger, theme toggle |
| Overlay | `--surface-overlay` | `rgba(0,0,0,.5)` | `rgba(16,22,32,.35)` | Modal backdrops |
| Border, hairline | `--border-hairline` | `#151a22` | `#e7eaf0` | Row separators inside a component |
| Border, default | `--border-default` | `#1f242e` | `#d9dee6` | Component outlines, section dividers |
| Border, control | `--border-control` | `#262c38` | `#c8cfda` | Input/button/card borders |
| Border, strong | `--border-strong` / `--border-strong-alt` | `#2a3040` / `#3a4150` | `#bcc4d0` / `#a9b2c0` | Outlined pills, bell trigger |
| Border, control hover | `--border-control-hover` | `#333c4d` | `#96a0b1` | Input hover border |

### Cyan accent

| Name | Token | Dark | Light | Use |
|---|---|---|---|---|
| Accent border | `--accent-border` | `#3f93a6` | `#2a8296` | Hover/focus border, active-state border |
| Accent edge | `--accent-edge` | `#2f6f7d` | `#5aaabb` | Structural accent lines (header underline, filter bar left edge) |
| Accent fill | `--accent-fill` / `--accent-fill-hover` | `#10262b` / `#153238` | `#e1f2f6` / `#cfeaf0` | Active/selected background |
| Accent text | `--accent-text` / `--accent-text-alt` / `--accent-text-bright` | `#9fe8f2` / `#7fe3f0` / `#c8f4fa` | `#0e6273` / `#0f6b7e` / `#094e5c` | Highlighted and hover text |
| Focus ring | `--accent-focus-ring` | `rgba(63,147,166,.2)` | `rgba(42,130,150,.25)` | `box-shadow: 0 0 0 2px …` on focus |

### Text

In light, every text token reaches WCAG AA (4.5:1) on white, `--surface-panel`
and the page background.

| Name | Token | Dark | Light | Use |
|---|---|---|---|---|
| Text, heading | `--text-heading` | `#fff` | `#11141b` | Section headings, server names, card text |
| Text, bright | `--text-bright` | `#e8eaf0` | `#161a22` | Headings inside components, input values |
| Text, body | `--text-body` | `#c9cfda` | `#2c3340` | Table cells, content text |
| Text, label | `--text-label` | `#c5cad6` | `#343b49` | Labels and floating-card rows |
| Text, soft | `--text-soft` | `#aeb7c5` | `#464e5e` | Icon buttons, chart stat labels |
| Text, muted | `--text-muted` | `#8b93a5` | `#586173` | Column headers, meta labels, secondary links |
| Text, faint | `--text-faint` | `#5a6172` | `#5f6777` | Placeholders, counters, empty states |
| Text on fill | `--text-on-fill` | `#fff` | `#fff` | Text on solid severity, env and alarm fills |

### Severity scale

Solid badge/pill fills, their 1px light borders, and the light text tints used
when a count sits on a dark surface (e.g. dropdown rows):

| Level | Fill (both themes) | Border dark / light | Tint dark / light |
|---|---|---|---|
| critical | `--sev-critical-fill` `#c22a35` | `--sev-critical-border` `#e05561` / `#a3212b` | `--sev-critical-tint` `#f09595` / `#b02430` |
| high / error | `--sev-high-fill` `#c05a1d` | `--sev-high-border` `#e0854a` / `#9e4a16` | `--sev-high-tint` `#f0b27b` / `#a24c17` |
| medium / warning | `--sev-medium-fill` `#9c7c14` | `--sev-medium-border` `#c4a53a` / `#7f650e` | `--sev-medium-tint` `#e3ce7a` / `#765e0b` |
| low / ok | `--sev-low-fill` `#2e7d43` | `--sev-low-border` `#4da767` / `#256638` | `--sev-low-tint` `#7bd88f` / `#226c38` |
| unknown | `--sev-unknown-fill` `#4a5060` | `--sev-unknown-border` `#6b7280` | `--sev-unknown-tint` `#9a9a9a` / `#5b6271` |
| abandoned / inactive | `--sev-abandoned-fill` `#343a47` / light `#e4e7ec` | `--sev-abandoned-border` `#6b7280` dashed | `--sev-abandoned-tint` `#c5cad6` / `#464e5e` |

Solid severity fills always carry `--text-on-fill` (white) bold text and keep
their color in light. Borders darken the fill in light, so they also work as
dot colors. Tints are text on the theme's surfaces: light in dark, dark in light.

### Other token groups

These carry their own light values in `main.css`; see the blocks for numbers.

| Group | Tokens | Light behaviour |
|---|---|---|
| Status text | `--signal-critical`, `-high`, `-medium`, `-error`, `-warn`, `-ok`, `-info`, `-neutral` | Darker variants with AA contrast (server card stats, log levels, Tabulator level formatter) |
| Alarm fills | `--alarm-fill`, `--alarm-fill-focus`, `--alarm-border`, `--alarm-border-focus` | Unchanged |
| Solid fills | `--env-*`, `--fill-warn`, `--fill-error`, `--ping-*`, `--logo-*`, `--readme-heading-fill`, `--notification-*` | Unchanged; they carry their own text color |
| Icons | `--icon-navbar`, `--icon-title`, `--icon-stat`, `--icon-loading`, `--icon-default` | Dark greys |
| Chrome | `--navbar-background`, `--navbar-border`, `--navbar-badge-ring`, `--progress-bar` | Frosted white bar, cyan progress bar |
| Notices | `--notice-*` | Unchanged (white card with dark code box in both) |
| Charts | `--chart-line`, `--chart-fill`, `--chart-point`, `--chart-text`, `--chart-grid` | Read by `theme.js`; open charts update on `mata:themechange` |
| Tables | `--table-placeholder` | Overrides the Tabulator midnight theme's placeholder color |
| Native widgets | `--color-scheme` | `color-scheme` of selects and date inputs |

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
color-scheme: var(--color-scheme); /* native select/date widgets follow the theme */
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
(`var(--navbar-badge-ring)` on the navbar) so it appears punched out.

### Icon button (`.notification-bell-trigger`, `.theme-toggle`)

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
  `fonts.css → tabulator_midnight.min.css → bulma.css → main.css`. The
  Tabulator theme is dark; `main.css` overrides its colors with tokens, which
  is what makes tables follow the light theme. A Tabulator feature that is not
  styled in `main.css` yet keeps midnight colors. `main.css`
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
- **Themes**: the server renders `<html data-theme="dark|light">` and the
  `color-scheme` meta from the `mata_theme` cookie (`UiTheme`), so there is no
  flash and no inline script. `src/main.scss` puts Bulma's dark theme on
  `:root` and its light theme on `[data-theme=light]`; `prefers-color-scheme`
  is ignored on purpose, so don't add `@use "bulma/sass/themes"` back.
  `theme.js` flips the attribute, writes the cookie and dispatches
  `mata:themechange` on `document`.
- **Charts**: give Chart.js datasets `themeColors: { borderColor: '--chart-line' }`
  for colors that should follow the theme; `theme.js` re-reads them and calls
  `chart.update()` when the theme changes. Colors from `servers.json` stay as
  configured.
- **Topbar**: keeps Bulma's `is-dark` for the dark theme; `[data-theme=light]
  .navbar.is-dark` resets its Bulma navbar variables, and the topbar logo is
  inverted (`filter: invert(1) hue-rotate(180deg)`), since it is drawn for a
  dark bar.
- **Out of scope**: alarm emails, webhook templates and advisory reports stay
  dark with their own inline colors; the theme does not reach them.

## Do / don't

| Do | Don't |
|---|---|
| Layer the three surface tones + hairlines for depth | Use pure `#000`/`#fff`, grey `#252525`-era tones, or heavy box-shadows on inline elements |
| Use cyan for *interaction and structure* only | Introduce a second accent hue (purple, magenta, green highlights) |
| Reuse the severity table exactly, muted fill + light border + white text | Use saturated `red`/`orange`/`gold` keywords or invent new severity shades |
| Keep labels tiny/uppercase/muted and content readable | Uppercase body text or brighten labels to white |
| Small radii (4–8px), 10px only on floating cards | Pill-shaped buttons, large rounded panels |
| Let color mean severity/state/interactivity | Decorate neutral elements with color |
