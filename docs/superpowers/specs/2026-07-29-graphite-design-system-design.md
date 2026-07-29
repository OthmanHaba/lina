# "Graphite" Design System — Design

**Date:** 2026-07-29
**Status:** Approved
**Scope:** Replace the default shadcn look with a custom dark-only design system ("Graphite & Electric"): new tokens, fonts, and component skins across every page. Radix primitives and component APIs stay; only presentation changes — plus one intentional behavioral removal: light mode and the appearance setting.

Out of scope: layout changes, new components, replacing Radix, marketing/branding work beyond the in-app wordmark treatment.

## Decisions made

| Decision | Choice |
|---|---|
| Direction | C — "Graphite & Electric" (chosen visually over Terminal Ops and Warm Editorial) |
| Modes | Dark only. Light mode and the appearance setting are deleted, not hidden |
| Depth | Reskin: keep Radix primitives + component APIs; redo tokens and skins |
| Fonts | Space Grotesk (UI/headings), JetBrains Mono (logs/data/numbers), self-hosted via @fontsource packages — no font CDN |
| Accent | Electric chartreuse `#c8f542`, used sparingly with a subtle glow |

## Token system

Single token set in `resources/css/app.css` `:root` — the `.dark` block, the light values, and all `dark:` variant classes are removed.

- **Surfaces:** page `#111318` with a faint `linear-gradient(160deg, #111318 60%, #151a24)` body background; card `#161a22`; raised (popovers, dropdowns, active nav) `#1d222c`.
- **Borders:** `#232833` default, `#2e3542` emphasis/hover. **Radii:** 9px cards/dialogs, 7px controls/badges.
- **Text:** `#dde3ee` primary, `#9aa4b5` secondary, `#6b7484` muted. Headings use tight letter-spacing (`-0.02em`).
- **Accent:** `#c8f542`; text on accent is near-black `#111318`. `--glow-accent: 0 0 8px rgba(200,245,66,.35)` for live indicators, primary CTAs, chart peaks. Focus rings are chartreuse.
- **Status:** danger `#ff6b6b`, warn `#ffa94d`, ok `#3dd68c`. Level/status badges are tinted-transparent chips (e.g. `rgba(255,107,107,.15)` bg with the status color as text), replacing solid Badge variants where levels/statuses appear.
- **Charts:** `CHART_COLORS` in `resources/js/lib/chart.ts` becomes `['#c8f542', '#5eead4', '#74c0fc', '#ffa94d', '#ff6b6b', '#e599f7', '#3dd68c', '#b197fc']`.

All values map onto the existing shadcn semantic token names (`--background`, `--card`, `--primary`, `--muted-foreground`, etc.) so component classes keep working; new additions are the gradient, `--glow-accent`, and status tokens.

## Typography

- `@fontsource-variable/space-grotesk` and `@fontsource-variable/jetbrains-mono` npm packages, imported in `app.tsx`/`app.css`; Tailwind `--font-sans` → Space Grotesk stack, `--font-mono` → JetBrains Mono stack.
- Space Grotesk: all UI text, headings (700, tight tracking), buttons, nav.
- JetBrains Mono: log lines, timestamps, stat numbers (tabular), tokens/IDs, code-ish content.

## Dark-only removal

- Delete `resources/js/pages/settings/appearance.tsx`, `resources/js/components/appearance-tabs.tsx`, the `use-appearance` hook and its call sites (including the inline appearance script in the Blade layout), the settings-nav link, and the appearance route in `routes/settings.php`.
- Remove/adjust any tests covering the appearance route; document the removal in the plan.
- Strip all `dark:` variant classes from components/pages as they're touched (mechanical sweep; final grep gate: zero `dark:` occurrences in resources/js).
- The `<html>` element keeps no theme class logic; tokens live on `:root` alone.

## Component skins (Radix/APIs unchanged)

- **Buttons:** primary = chartreuse bg, near-black text, glow on hover; secondary/outline = graphite surfaces with `#2e3542` borders; ghost = text-only; destructive = danger-tinted.
- **Cards/dialogs/popovers/dropdowns:** surface system + 9px radius; dropdown/popover on raised surface.
- **Inputs/selects/textareas:** `#161a22` bg, `#232833` border, chartreuse focus ring; mono font where content is data (tokens, LogQL, search).
- **Sidebar:** flat near-black (`rgba(255,255,255,.02)` over page), `lina.` wordmark (chartreuse period), active item on raised surface with chartreuse text/dot, muted inactive items.
- **Badges:** tinted-transparent chip treatment described above.
- **Tables/lists, skeletons, toasts (sonner), tooltips:** re-tokened to the surface/text system.
- **Auth + welcome pages:** brought into the system (dark, wordmark, chartreuse accents) so the first impression matches.
- Dashboard widgets/charts pick up tokens + new CHART_COLORS; the live-tail pulse and auto-refresh dots use accent + glow.

## Rollout order (each stage: `npm run build` + full Pest suite green)

1. Fonts + token layer + chart palette (visual foundation; most components inherit immediately).
2. `components/ui/*` primitive skins.
3. Layout: sidebar, header, nav, breadcrumbs.
4. Pages sweep: dashboard, logs, servers, alerts, teams, settings, auth/welcome + `dark:` strip + appearance removal.
5. Deploy to server B; visual E2E click-through of every page.

## Testing

- Existing Pest suite stays green throughout except appearance-route tests, which are removed with the feature.
- Final gates: zero `dark:` classes in resources/js; zero Google Fonts/CDN references; build clean.
- Visual verification via the live E2E on B (every page, plus dialogs/dropdowns/toasts).
