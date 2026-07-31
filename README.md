# Heavy Docs

By Weng (Weng Fei Fung)

For minds that face large technical documents, legal contracts, and financial pitches—and need a clear way to read them by need, see what each file provides, and hold keypoints or a map of content (MOC) with honest coverage.

![Last Commit](https://img.shields.io/github/last-commit/Siphon880gh/adhd-scattered-notes-harness/main)
[![GitHub](https://img.shields.io/badge/GitHub--blue?style=social&logo=GitHub)](https://github.com/Siphon880gh)
[![LinkedIn](https://img.shields.io/badge/LinkedIn-blue?style=flat&logo=linkedin&labelColor=blue)](https://www.linkedin.com/in/weng-fung/)
[![Youtube](https://img.shields.io/badge/Youtube-red?style=flat&logo=youtube&labelColor=red)](https://www.youtube.com/@WayneTeachesCode/)

A Cursor / Claude Code harness for turning a folder of heavy documents into navigable reading guides, per-file “what this provides,” keypoints / MOC, and coverage %.

The PHP app is an **artifact container**. The skill maps the pack in chat and writes artifacts next to your documents. You open `index.php` to **check the work and respond** on each card (saved for the next AI phase). Phase 2 finalizes in chat; the GUI shows the final pack.

## Roles

| Piece | Role |
|-------|------|
| `.agents/skills/heavy-docs` | Agent skill: list folders, ask which one, write artifacts, update config |
| `app.config.json` | Points the app at one `inputs/` folder and the active phase |
| `index.php` | Phase 1 review + respond; Phase 2 finalized navigation view |

There is **no folder switcher in the app**. The skill sets `app.config.json`; the app shows whatever that file points at.

## Setup

1. Create a batch folder: `inputs/whatname/`
2. Drop documents into that folder. Markdown and plain text work; so do **PDF, Word (`.doc`/`.docx`), Excel (`.xls`/`.xlsx`), CSV/TSV**, HTML, RTF, and similar. Excerpts or full files are fine. The skill extracts readable text from each format when mapping the pack.
3. In Cursor or Claude Code, run the skill at `.agents/skills/heavy-docs` — it **lists folders under `inputs/`** and asks which to use.
4. Serve the app and open it:

```bash
php -S localhost:8765
```

Then open `http://localhost:8765`.

## Input layout

```
app.config.json
inputs/
  whatname/
    contract.pdf
    model.xlsx
    pitch.docx
    notes.md
    phase1.json              # reading guides + per-doc coverage/keypoints + MOC
    phase1-responses.md      # optional: your Respond notes from the GUI
    phase2.json              # finalized pack after Phase 2 in chat
```

`app.config.json`:

```json
{
  "inputFolder": "whatname",
  "phase": 1
}
```

`phase` may be `1` or `2`.

## Two phases

### Phase 1 — map, then check in the GUI

The skill reads the documents and writes `phase1.json`:

- **Reading guides** — how to read the pack based on different needs
- **Per document** — what it provides, coverage %, coverage note, keypoints
- **MOC** — cross-document hubs when useful

**Open the GUI next.** Check each guide / document / keypoint / MOC node. Use **Respond** on anything that should change. Checks save into `phase1.json`; responses save into `phase1-responses.md` for the agent.

Use **Quiz** on a card or source file to open a copyable prompt for Cursor / Claude Code. The quiz runs in that chat thread — not in the PHP app.

### Phase 2 — finalize in AI chat

When Phase 1 looks good, return to chat and start Phase 2. The skill reads your GUI responses, writes `phase2.json`, and sets phase 2. Visit the GUI to see the final pack. You can always say **Revert to Phase 1** in chat, then refresh.

## Skill handoff

1. Skill lists `inputs/` folders and asks which one
2. Skill writes Phase 1 artifacts and sets `app.config.json`
3. You review and respond in `index.php`
4. You go back to chat to finalize Phase 2 (or remap / revert)

## Sample

`inputs/sample/` includes short excerpts: payments API design, mutual NDA, and a seed term sheet — enough to exercise technical, legal, and financial reading paths.
