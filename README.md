# Heavy Docs

By Weng (Weng Fei Fung)

![Last Commit](https://img.shields.io/github/last-commit/Siphon880gh/adhd-heavy-docs/main)
[![GitHub](https://img.shields.io/badge/GitHub--blue?style=social&logo=GitHub)](https://github.com/Siphon880gh)
[![LinkedIn](https://img.shields.io/badge/LinkedIn-blue?style=flat&logo=linkedin&labelColor=blue)](https://www.linkedin.com/in/weng-fung/)
[![Youtube](https://img.shields.io/badge/Youtube-red?style=flat&logo=youtube&labelColor=red)](https://www.youtube.com/@WayneTeachesCode/)

For minds that face large technical documents, legal contracts, and financial pitches—and need a clear way to read them by need, see what each file provides, hold keypoints or a map of content (MOC) with honest coverage, and quiz your understanding.

A Cursor / Claude Code harness for turning a folder of heavy documents into navigable reading guides, per-file “what this provides,” keypoints / MOC, and coverage %. Drop a pack under `inputs/`, ask in chat, and the **heavy-docs** skill walks Phase 1 → GUI review → Phase 2 until you have a final readable guide.

The PHP app is an artifact container. The skill does the heavy organizing in Cursor or Claude Code and writes artifacts next to your notes. You open `index.php` to review them, make light adjustments in the browser (checks / Respond notes), then return to the chat for Phase 2 merges, fidelity fixes, and the rest.

## Quick start

1. Put your documents in a folder under `inputs/`, for example:

```
inputs/business-plan-acme/
  deck.pdf
  model.xlsx
  nda.docx
  notes.md
```

`inputs/business-plan-acme/` is already set up for you (local only; `business-plan-*` folders are gitignored). Add your files there, or create another folder name under `inputs/`.

2. In Cursor or Claude Code, prompt something like:

```text
Help me read business-plan-acme
```

If that folder exists under `inputs/`, the agent loads `.agents/skills/heavy-docs` and runs the phased workflow for you. You can also say **“start phase 1”**, **“start phase 2”**, or **“let’s continue”**.

3. Serve the PHP app when you want to review artifacts in the browser:

```bash
php -S localhost:8765
```

Open `http://localhost:8765`.

## What you get

| Piece | Role |
|-------|------|
| `.agents/skills/heavy-docs` | Agent skill: pick an `inputs/` folder, write artifacts, update config |
| `app.config.json` | Points the app at one `inputs/` folder and the active phase |
| `index.php` | Artifact container: Phase 1 review + respond; Phase 2 finalized navigation view |

Each folder under `inputs/` is a separate project. Use **Projects** in the app header to switch between them. That writes `app.config.json` and restores the phase that project was last on. The skill can also point the file at a folder.

## Supported inputs

Markdown and plain text work; so do **PDF, Word (`.doc`/`.docx`), Excel (`.xls`/`.xlsx`), CSV/TSV**, HTML, RTF, PowerPoint, and similar. Excerpts or full files are fine. The skill extracts readable text from each format when mapping the pack.

```
app.config.json
inputs/
  business-plan-acme/        # your pack (example name)
    deck.pdf
    model.xlsx
    nda.docx
    notes.md
    phase1.json              # reading guides + per-doc coverage/keypoints + MOC
    phase1-responses.md      # optional: your Respond notes from the GUI
    phase2.json              # finalized pack after Phase 2 in chat
```

`app.config.json`:

```json
{
  "inputFolder": "business-plan-acme",
  "phase": 1
}
```

`phase` may be `1` or `2`. It is the phase of `inputFolder`. Empty `inputFolder` means no project selected yet. The app also keeps a `projects` map (for example `"projects": { "business-plan-acme": { "phase": 1 } }`) so switching projects doesn't reset each one's phase. When you edit the file by hand, keep `projects` and set `projects[inputFolder].phase` to the phase you write.

## Workflow (automatic via the skill)

### Phase 1 — map, then check in the GUI

Ask in chat (e.g. *Help me read business-plan-acme*). The skill reads the documents and writes `phase1.json`:

- **Reading guides** — how to read the pack based on different needs
- **Per document** — what it provides, coverage %, coverage note, keypoints
- **MOC** — cross-document hubs when useful

**Open the GUI next.** Check each guide / document / keypoint / MOC node. Use **Respond** on anything that should change. Checks save into `phase1.json`; responses save into `phase1-responses.md` for the agent.

Use **Quiz** on a card or source file to open a copyable prompt for Cursor / Claude Code. The quiz runs in that chat thread — not in the PHP app.

### Phase 2 — finalize in AI chat

When Phase 1 looks good, return to chat and start Phase 2 (or say **“let’s continue”**). The skill reads your GUI responses, writes `phase2.json`, and sets phase 2. Visit the GUI to see the final pack. You can always say **Revert to Phase 1** in chat, then refresh.

### Handoff checklist

1. Drop docs into `inputs/{folder}/`
2. Prompt in chat — skill lists folders if needed, or uses the name you gave
3. Skill writes Phase 1 artifacts and sets `app.config.json`
4. You review and respond in `index.php`
5. Back to chat for Phase 2 finalization (or remap / revert)

## Try the sample

`inputs/sample/` includes short excerpts: payments API design, mutual NDA, and a seed term sheet — enough to exercise technical, legal, and financial reading paths.

```text
Help me read sample
```
