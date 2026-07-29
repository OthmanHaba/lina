# Graphite Design System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the shadcn look with the dark-only "Graphite & Electric" design system (graphite surfaces, chartreuse accent, Space Grotesk + JetBrains Mono) across every page, deleting light mode and the appearance setting.

**Architecture:** Token-first rollout: remove the appearance machinery, then flip the CSS token layer to a single dark set (components inherit instantly), then reskin ui/ primitives, then layout, then a page sweep that strips every `dark:` class. Radix primitives and all component APIs stay.

**Tech Stack:** Tailwind v4 (@theme tokens), @fontsource-variable packages, existing shadcn/Radix components, recharts.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-29-graphite-design-system-design.md` — its token values are exact and binding.
- PRESENTATION ONLY except the one sanctioned behavioral change: complete removal of light mode + the appearance setting (Task 1). No other route/prop/endpoint/logic changes.
- Fonts self-hosted via `@fontsource-variable/space-grotesk` and `@fontsource-variable/jetbrains-mono` — these two npm packages are the ONLY new dependencies. Zero font-CDN references.
- Accent `#c8f542` is used sparingly: primary buttons, focus rings, live indicators, active nav, chart lead color. Never for large fills or body text.
- `@custom-variant dark` stays in app.css until Task 6 removes it together with the last `dark:` classes (they're inert after Task 1 removes the class-toggling machinery, but the variant must exist for the build to keep their meaning stable).
- Final gates (Task 6): `grep -rn "dark:" resources/js --include="*.tsx"` → zero matches; `grep -rn "fonts.googleapis\|fonts.bunny" resources` → zero; `npm run build` clean; full `php artisan test --compact` green.
- After each task: `npm run build` (zero TS errors), `npx prettier --write` on touched files, `npm run lint` clean, `php artisan test --compact` green. After PHP changes also `vendor/bin/pint --dirty --format agent`.
- Commit signing uses 1Password — if signing fails, report BLOCKED.

---

### Task 1: Remove light mode + appearance machinery

**Files:**
- Delete: `resources/js/pages/settings/appearance.tsx`, `resources/js/components/appearance-tabs.tsx`, `resources/js/hooks/use-appearance.tsx`, `app/Http/Middleware/HandleAppearance.php`
- Modify: `routes/settings.php:30` (remove appearance route), `bootstrap/app.php` (remove HandleAppearance registration AND the `'appearance'` entry in `encryptCookies(except: [...])`, keep `'sidebar_state'`), `resources/views/app.blade.php` (remove the `@class(['dark' => ...])` from `<html>` and the entire inline `$appearance` script block), `resources/js/app.tsx` (remove `initializeTheme` import + call), `resources/js/components/ui/sonner.tsx` (drop `useAppearance`, hardcode `theme="dark"`), settings navigation (grep `appearance` in `resources/js/layouts` and `resources/js/pages/settings` — remove the nav item linking to appearance.edit)
- Test: `tests/Feature/Settings/` (add one)

**Interfaces:**
- Produces: no `.dark` class is ever applied; `$appearance` no longer shared to views; `appearance.edit` route gone. Later tasks rely on `dark:` classes being inert from here on.

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Settings/AppearanceRemovedTest.php`:

```php
<?php

use App\Models\User;

it('no longer exposes an appearance settings page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/appearance')
        ->assertNotFound();
});
```

- [ ] **Step 2: Run it** — `php artisan test --compact --filter=AppearanceRemovedTest` — Expected: FAIL (route currently 200s).
- [ ] **Step 3: Delete/modify per the file list.** In bootstrap/app.php the middleware is registered in the web group — find `HandleAppearance` and remove that line plus the import. In `app.blade.php`, `<html>` keeps `lang` only. Grep for any leftover references: `grep -rn "appearance\|Appearance" resources/js app routes bootstrap resources/views --include="*.php" --include="*.tsx" --include="*.blade.php"` — the only acceptable survivors are unrelated words (none expected).
- [ ] **Step 4: Gates.** Filtered test passes; full `php artisan test --compact` green (no existing tests reference appearance — verified during planning); `npm run build` clean; pint.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: remove light mode and appearance setting (dark-only)"`.

---

### Task 2: Fonts + Graphite token layer + chart palette

**Files:**
- Modify: `resources/css/app.css` (full token replacement below), `resources/js/lib/chart.ts`, `package.json` (via npm install)

**Interfaces:**
- Produces: the semantic tokens every component already consumes (`--background`, `--card`, `--primary`, …) now hold Graphite values; new utilities `--color-success`/`--color-warning` (usable as `text-success`, `bg-warning/15`, etc.); `--shadow-glow` (usable as `shadow-glow`); fonts `--font-sans` = Space Grotesk Variable, `--font-mono` = JetBrains Mono Variable. `CHART_COLORS[0]` is the accent chartreuse.

- [ ] **Step 1: Install fonts** — `npm install @fontsource-variable/space-grotesk @fontsource-variable/jetbrains-mono`
- [ ] **Step 2: Replace `resources/css/app.css`** with (keep the two `@source` lines and `@custom-variant dark` line exactly as they are today; everything else replaced):

```css
@import 'tailwindcss';

@import 'tw-animate-css';
@import '@fontsource-variable/space-grotesk';
@import '@fontsource-variable/jetbrains-mono';

@source '../views';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';

@custom-variant dark (&:is(.dark *));

@theme {
    --font-sans:
        'Space Grotesk Variable', ui-sans-serif, system-ui, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
        'Noto Color Emoji';
    --font-mono:
        'JetBrains Mono Variable', ui-monospace, SFMono-Regular, Menlo,
        monospace;

    --radius-lg: var(--radius);
    --radius-md: calc(var(--radius) - 2px);
    --radius-sm: calc(var(--radius) - 4px);

    --color-background: var(--background);
    --color-foreground: var(--foreground);
    --color-card: var(--card);
    --color-card-foreground: var(--card-foreground);
    --color-popover: var(--popover);
    --color-popover-foreground: var(--popover-foreground);
    --color-primary: var(--primary);
    --color-primary-foreground: var(--primary-foreground);
    --color-secondary: var(--secondary);
    --color-secondary-foreground: var(--secondary-foreground);
    --color-muted: var(--muted);
    --color-muted-foreground: var(--muted-foreground);
    --color-accent: var(--accent);
    --color-accent-foreground: var(--accent-foreground);
    --color-destructive: var(--destructive);
    --color-destructive-foreground: var(--destructive-foreground);
    --color-success: var(--success);
    --color-warning: var(--warning);
    --color-border: var(--border);
    --color-input: var(--input);
    --color-ring: var(--ring);
    --color-chart-1: var(--chart-1);
    --color-chart-2: var(--chart-2);
    --color-chart-3: var(--chart-3);
    --color-chart-4: var(--chart-4);
    --color-chart-5: var(--chart-5);
    --color-sidebar: var(--sidebar);
    --color-sidebar-foreground: var(--sidebar-foreground);
    --color-sidebar-primary: var(--sidebar-primary);
    --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
    --color-sidebar-accent: var(--sidebar-accent);
    --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
    --color-sidebar-border: var(--sidebar-border);
    --color-sidebar-ring: var(--sidebar-ring);

    --shadow-glow: 0 0 8px rgba(200, 245, 66, 0.35);
}

:root {
    --background: #111318;
    --foreground: #dde3ee;
    --card: #161a22;
    --card-foreground: #dde3ee;
    --popover: #1d222c;
    --popover-foreground: #dde3ee;
    --primary: #c8f542;
    --primary-foreground: #111318;
    --secondary: #1d222c;
    --secondary-foreground: #dde3ee;
    --muted: #1d222c;
    --muted-foreground: #6b7484;
    --accent: #1d222c;
    --accent-foreground: #dde3ee;
    --destructive: #ff6b6b;
    --destructive-foreground: #ff6b6b;
    --success: #3dd68c;
    --warning: #ffa94d;
    --border: #232833;
    --input: #232833;
    --ring: #c8f542;
    --chart-1: #c8f542;
    --chart-2: #5eead4;
    --chart-3: #74c0fc;
    --chart-4: #ffa94d;
    --chart-5: #ff6b6b;
    --radius: 0.5625rem; /* 9px cards; controls use --radius-md (7px) */
    --sidebar: #0e1014;
    --sidebar-foreground: #9aa4b5;
    --sidebar-primary: #c8f542;
    --sidebar-primary-foreground: #111318;
    --sidebar-accent: #1d222c;
    --sidebar-accent-foreground: #dde3ee;
    --sidebar-border: #1c212b;
    --sidebar-ring: #c8f542;
}

@layer base {
    * {
        @apply border-border;
    }

    body {
        @apply text-foreground;
        background: linear-gradient(160deg, #111318 60%, #151a24) fixed #111318;
    }

    h1,
    h2,
    h3 {
        letter-spacing: -0.02em;
    }
}
```

Note: the `.dark { … }` block is deleted entirely. `--secondary-foreground` on text over `#1d222c` etc. — sanity: secondary text is `#9aa4b5` where components use `text-muted-foreground`; the muted-foreground token is the darker `#6b7484` and secondary text should read `#9aa4b5`. Where the current UI reads too dim after the flip (checked visually in later tasks), prefer changing the component to `text-foreground/70` rather than re-tuning tokens.

- [ ] **Step 3: Chart palette** — `resources/js/lib/chart.ts`:

```ts
export const CHART_COLORS = [
    '#c8f542',
    '#5eead4',
    '#74c0fc',
    '#ffa94d',
    '#ff6b6b',
    '#e599f7',
    '#3dd68c',
    '#b197fc',
];
```

- [ ] **Step 4: Gates.** `npm run build`; open the app locally (Herd) and confirm it renders dark everywhere with Space Grotesk (spot-check headings and a log row for mono); `php artisan test --compact` green.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Graphite token layer, fonts, chart palette"`.

---

### Task 3: ui/ primitive skins

**Files:**
- Modify: `resources/js/components/ui/button.tsx`, `badge.tsx`, `card.tsx`, `input.tsx`, `select.tsx`, `dialog.tsx`, `dropdown-menu.tsx`, `tooltip.tsx`, `skeleton.tsx`, `checkbox.tsx`, `separator.tsx`, `sheet.tsx` — class-level restyling only, no API changes (props/variants keep their names; two badge variants ADDED).

**Interfaces:**
- Produces: `Badge` gains `success` and `warning` variants (tinted chips). All other component APIs unchanged. Later tasks rely on: `variant="success"`, `variant="warning"` on Badge; primary Button = chartreuse with `hover:shadow-glow`.

Treatments (apply by editing each component's cva/class strings — read each file first; these are the deltas, not full rewrites):

- **button.tsx:** default variant → `bg-primary text-primary-foreground hover:bg-primary/90 hover:shadow-glow font-medium`; outline/secondary → surfaces `bg-secondary`/`border-border hover:border-[#2e3542] hover:bg-secondary`; all variants `rounded-md` (7px via --radius-md). Focus-visible ring already uses `ring-ring` → now chartreuse, keep.
- **badge.tsx:** default stays token-driven; ADD variants: `success: 'border-transparent bg-success/15 text-success'`, `warning: 'border-transparent bg-warning/15 text-warning'`; change `destructive` to the chip treatment `border-transparent bg-destructive/15 text-destructive`; `rounded-md`.
- **card.tsx:** `rounded-lg border bg-card` (9px), remove any shadow → `shadow-none`.
- **input.tsx / select.tsx / textarea usages:** `bg-card border-input rounded-md`, focus ring chartreuse (token already), `placeholder:text-muted-foreground`.
- **dialog.tsx / sheet.tsx:** content on `bg-popover` `rounded-lg` `border-border`, overlay `bg-black/60 backdrop-blur-[2px]`.
- **dropdown-menu.tsx / tooltip.tsx / popover-like:** `bg-popover rounded-md border-border`, item focus `focus:bg-accent`.
- **skeleton.tsx:** `bg-muted/60`.
- **separator.tsx:** token-driven already — verify only.
- Strip `dark:` classes from every ui/ file you touch (they're inert; deleting here shrinks Task 6).

- [ ] **Step 1:** Apply the treatments file by file, reading each before editing.
- [ ] **Step 2: Gates.** `npm run build`; visual spot-check (buttons, a dialog, a dropdown, badges on the alerts page); `php artisan test --compact`; prettier + lint.
- [ ] **Step 3: Commit** — `git add -A && git commit -m "feat: Graphite skins for ui primitives"`.

---

### Task 4: Layout — sidebar, header, nav

**Files:**
- Modify: `resources/js/components/app-logo.tsx` (wordmark), `app-sidebar.tsx`, `nav-main.tsx`, `nav-user.tsx`, `nav-footer.tsx`, `app-sidebar-header.tsx`, `breadcrumbs.tsx`, `team-switcher.tsx` — presentation only.

**Interfaces:**
- Consumes: sidebar tokens from Task 2 (`--sidebar*`), Badge variants from Task 3.

- [ ] **Step 1: Wordmark.** `app-logo.tsx` renders the text wordmark `lina` + chartreuse period instead of the icon+text lockup:

```tsx
<span className="text-lg font-bold tracking-tight text-white">
    lina<span className="text-primary">.</span>
</span>
```

(Keep `app-logo-icon.tsx` for auth pages/favicons if referenced; check usages before deleting anything.)
- [ ] **Step 2: Sidebar.** Flat near-black comes from the `--sidebar` token already; adjust nav items: inactive `text-sidebar-foreground`, active item on `bg-sidebar-accent text-primary` with a small leading dot `<span className="size-1.5 rounded-full bg-primary shadow-glow" />` (only on the active item); section labels `text-[11px] uppercase tracking-[0.1em] text-muted-foreground`.
- [ ] **Step 3: Header/breadcrumbs/team-switcher:** align to tokens (borders `border-sidebar-border`, text secondary); strip `dark:` classes from all touched layout files.
- [ ] **Step 4: Gates.** Build, visual check of sidebar active/inactive/hover + mobile sheet sidebar, suite, prettier/lint.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: Graphite sidebar, wordmark, layout chrome"`.

---

### Task 5: Pages sweep A — dashboard + logs

**Files:**
- Modify: `resources/js/pages/dashboard.tsx`, `resources/js/components/dashboard/*.tsx`, `resources/js/components/dashboard/widgets/*.tsx`, `resources/js/pages/logs/index.tsx`, `resources/js/components/charts/daily-bar-chart.tsx`

Checklist per the spec (presentation only):
- Stat numbers, log lines, timestamps → `font-mono` (mostly already; verify stat-widget number is mono + `tabular-nums`).
- Level badges (log-stream widget + logs page) → Badge `destructive`/`warning` chip variants from Task 3 (replace the ad-hoc amber-tinted secondary for warn).
- Live indicators: logs live-tail pulse dot and dashboard auto-refresh dot → `bg-primary shadow-glow` (replace emerald).
- Timeseries/daily charts: grid strokes `stroke-border`, tooltip `contentStyle` → `{ fontSize: 12, background: '#1d222c', border: '1px solid #232833', borderRadius: 9, color: '#dde3ee' }`.
- Server-health dot for `online` → keep `bg-success` (emerald token) — status semantics stay green; chartreuse is for accent, not status.
- Strip all `dark:` classes in these files.

- [ ] **Step 1:** Apply checklist; read each file first.
- [ ] **Step 2: Gates.** Build; visual check dashboard + logs (widgets, dialog, drag, live tail); suite; prettier/lint.
- [ ] **Step 3: Commit** — `git add -A && git commit -m "feat: Graphite dashboard and logs pages"`.

---

### Task 6: Pages sweep B — servers, alerts, teams, settings, auth, welcome + dark:-strip finale

**Files:**
- Modify: `resources/js/pages/servers/*.tsx`, `alerts/index.tsx`, `teams/*.tsx`, `settings/profile.tsx`, `settings/security.tsx`, `auth/*.tsx`, `welcome.tsx`, `resources/js/layouts/**` (auth + settings layouts), remaining `resources/js/components/*.tsx` with `dark:` classes
- Modify: `resources/css/app.css` (remove the `@custom-variant dark` line — last step)

Checklist:
- Server tokens, install commands, LogQL/textarea content → `font-mono`.
- Status/state badges across servers + alerts → Task 3 chip variants (`success`/`warning`/`destructive`).
- Auth pages + welcome: dark surfaces, `lina.` wordmark, chartreuse primary buttons — first-impression parity per the spec.
- Remove every remaining `dark:` class: `grep -rln "dark:" resources/js --include="*.tsx"` and fix each file (the variant is inert — deletion must not change rendering; where a `dark:` class was the *intended* dark styling of a token-less color, fold it into the base class).
- Then delete `@custom-variant dark (&:is(.dark *));` from app.css.

- [ ] **Step 1:** Apply per page; read each file first.
- [ ] **Step 2: Final gates.**

```bash
grep -rn "dark:" resources/js --include="*.tsx" | wc -l   # must be 0
grep -rn "fonts.googleapis\|fonts.bunny" resources | wc -l # must be 0
npm run build && npm run lint
php artisan test --compact
```

- [ ] **Step 3: Commit** — `git add -A && git commit -m "feat: Graphite sweep — remaining pages, dark-variant removal"`.

---

### Task 7: Deploy to server B + visual E2E

**Files:** none in-repo (deployment; run post-merge on `main`).

- [ ] **Step 1:** Local: full suite green; `npm run build`; `tar -czf /tmp/lina-build.tar.gz -C public build`; `git bundle create /tmp/lina-main.bundle main`.
- [ ] **Step 2:** Deploy to B (established flow): scp bundle+tarball → `sudo git checkout -- .` → fetch/merge --ff-only → extract build → `php artisan migrate --force --no-interaction` (no new migrations expected; harmless) → `view:clear && config:cache && route:cache` → `chown -R ubuntu:www-data`.
- [ ] **Step 3:** Visual E2E at http://18.159.103.136: every page (dashboard incl. widget dialog/drag, logs live-tail, servers index/show, alerts, teams, settings profile/security, login/register, welcome); confirm fonts load locally (no CDN requests in the network panel), `settings/appearance` 404s, dark rendering everywhere including dialogs/dropdowns/toasts.
- [ ] **Step 4:** Record results in `.superpowers/sdd/progress.md`; fix-deploy loop for anything found.

---

## Self-Review Notes

- Spec coverage: dark-only removal (T1), tokens/fonts/gradient/glow/chart palette (T2), component skins (T3), sidebar/wordmark/layout (T4), pages incl. widgets + live-glow (T5), remaining pages + auth/welcome + dark-strip + custom-variant removal (T6), deploy + visual E2E (T7). Status chips: variants in T3, applied in T5/T6.
- Type consistency: Badge `success`/`warning` defined T3, consumed T5/T6; `shadow-glow` defined T2, consumed T3–T5; sidebar tokens T2 → T4.
- Deliberate judgment areas for implementers: exact current class strings in each ui/ file (read-first deltas, not verbatim rewrites); dimness re-tuning rule (component-level `text-foreground/70`, never token changes).
