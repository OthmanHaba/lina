# Container Source In-UI Guidance — Design

**Date:** 2026-07-29
**Status:** Approved
**Scope:** Make the `container` log source self-explanatory in the UI so a user knows exactly what to do — in their terminal, on their server — the moment they choose the type, and why the terminal step is needed at all. Plus harden the in-container path input so the common `/*.log` mistake can't be saved. Follow-up to the container-file-source feature.

Out of scope: auto-applying the mount, agent-side container discovery, changing the underlying tail mechanism.

## Problem

The current form labels the path field and shows a bare compose/Dokploy line only after saving. Users don't learn (a) that a container source needs a host-side bind mount, (b) the exact terminal steps, (c) *why* the terminal is involved, and the path field silently accepts a `/*.log` glob that produces a broken mount. Reported live: a user entered `/var/www/html/storage/logs/*.log` and had no idea a bind mount + container recreate was required.

## Decisions

| Decision | Choice |
|---|---|
| Guidance placement | Both: a form-time explainer when `container` is selected, and a full copy-paste runbook on the saved source |
| Instruction style | Terminal-first (docker compose + docker run); one-line "managed platform" aside, no Dokploy-first wording |
| Copy source of truth | Backend `ContainerMountInstructions` service (tested), consumed by the UI |
| Path input | Reject glob (`*`) and trailing slash server-side; live preview of the resulting mount + tailed path in the form |

## Form-time explainer (create/edit, `type === 'container'`)

When the user selects "File inside a container", below the two fields show a short explainer box:

- **What/why (one or two sentences):** "Lina's agent runs on your host as a non-root user, so it can't read files inside a container. You'll expose the log directory to the host with a one-time bind mount — after you save, Lina gives you the exact commands."
- **Live preview** under the path field: as the user types the directory, show what Lina will tail — `<in_container_path>/*.log` — and a note that the exact host mount path (`/var/log/lina/<id>`) appears after saving (the host path is derived from the source id, which doesn't exist pre-save). Helper text: "Directory only — no `/*.log`."

## Post-save runbook (source panel)

Replace the current bare snippet with a numbered runbook driven by the service:

1. **Prep the host folder** — `sudo mkdir -p /var/log/lina/<id> && sudo chmod 777 /var/log/lina/<id>` — note: "so your container can write logs here and the agent can read them."
2. **Add the mount and recreate the container** — show both:
   - compose: the `volumes:` line + `docker compose up -d`
   - docker run: `-v /var/log/lina/<id>:<path>` (with "stop, rm, and re-run to add a mount")
   - one-line aside: "On a managed platform, add the same bind mount in its UI and redeploy."
3. **Verify** — the existing "Check for logs" button.

Each command in a copyable `font-mono` block.

## Backend — `ContainerMountInstructions`

Extend the returned array (single tested source of truth for all copy):
- existing: `hostPath`, `containerPath`, `compose`, `dockerRun`
- add: `prepare` — `sudo mkdir -p {hostPath} && sudo chmod 777 {hostPath}`
- add: `recreate` — `docker compose up -d`
- add: `tailGlob` — `{hostPath}/*.log` (what the agent tails; used in the preview/verify copy)
- drop `dokploy` (replaced by the generic managed-platform aside rendered in the UI). Update its test.

## Path input hardening

`SaveLogSourceRequest` `container` arm: keep `required`, `string`, `max:1024`, `starts_with:/`, and add:
- reject a glob: `not_regex:/\*/`
- reject a trailing slash: `not_regex:/\/$/`

with a clear message ("Enter the directory only — no `*` glob or trailing slash"). Relative paths already rejected by `starts_with:/`.

## Testing

Pest:
- `ContainerMountInstructions` returns `prepare`, `recreate`, `tailGlob` with correct interpolation; no `dokploy` key.
- Validation rejects `/var/www/html/storage/logs/*.log` (glob) and `/var/www/html/storage/logs/` (trailing slash); accepts `/var/www/html/storage/logs`.
- Renderer unchanged (still `/var/log/lina/<id>/*.log`).

Frontend verified by `npm run build` + the live E2E on server B (select the type → explainer + preview render; save → runbook shows exact commands; a glob path is rejected with the message).
