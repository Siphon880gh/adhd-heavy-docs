---
name: heavy-docs
description: >-
  Navigate a folder of large documents under inputs/ (technical docs, legal
  contracts, financial pitches — PDF, Word, Excel, CSV, markdown, and more)
  into Phase 1 reading guides / per-file info / keypoints-or-MOC with coverage %,
  GUI review responses, and Phase 2 finalization in chat. Use when the user wants
  to map a document pack, run the heavy-docs harness, or advance phase1/phase2
  artifacts for index.php.
---

# Heavy Docs Harness

This skill runs in **Cursor or Claude Code**. The PHP app (`index.php`) is an artifact container: write artifacts here, tell the user to open the GUI, they check work and respond per item in the browser, then continue in chat. Each folder under `inputs/` is its own project. The app’s **Projects** menu switches the live folder and restores that project’s phase by writing `app.config.json`. You may also switch by writing that file.

## Product framing (verbatim)

For minds that face large technical documents, legal contracts, and financial pitches—and need a clear way to read them by need, see what each file provides, and hold keypoints or a map of content (MOC) with honest coverage.

This is primarily used under Cursor or Claude Code but we open the php file as a container of artifacts. We look at artifacts to know how to prompt subsequently.

## When to use

- User has documents in `inputs/{folder}/` (markdown, text, PDF, Word, Excel, CSV, and similar)
- User asks to map, navigate, digest, or continue a heavy-docs batch
- User says **“start phase 1”**, **“start phase 2”**, or **“let’s continue”**
- User confirms Phase 1 GUI review is done and wants Phase 2 finalization
- User says **“Revert to Phase 1”** while in Phase 2

## File contracts

Per batch folder `inputs/{folder}/`:

| File | Role |
|------|------|
| Source docs | PDF, Word (`.doc`/`.docx`), Excel (`.xls`/`.xlsx`), CSV/TSV, `.md`/`.txt`, HTML, RTF, and similar — excerpts or full files |
| `phase1.json` | Reading guides, per-doc provides/coverage/keypoints, MOC |
| `phase1-responses.md` | User responses from the GUI — **read before Phase 2** |
| `phase2.json` | Finalized navigation pack after applying responses |

Project root:

| File | Role |
|------|------|
| `app.config.json` | `{ "inputFolder": "{folder}" \| "", "phase": 1 \| 2, "projects": { "{folder}": { "phase": 1 \| 2 } } }` — empty `inputFolder` means no project selected. The app’s Projects menu and this skill both write it. Whenever you write it, keep the existing `projects` object and set `projects["{inputFolder}"].phase` to the phase you write; never drop other folders’ phases. On every start, read it again, since the user may have switched projects in the browser. |

**Source extensions (treat as documents):** `.md`, `.txt`, `.csv`, `.tsv`, `.pdf`, `.docx`, `.doc`, `.xlsx`, `.xls`, `.pptx`, `.ppt`, `.rtf`, `.html`, `.htm`, `.pages`, `.numbers`, `.key` when present. Include other office/data files in the folder if they are clearly part of the pack.

Skip when reading sources: `phase1.json`, `phase2.json`, `phase1-responses.md`, and junk/system files (`.DS_Store`, `Thumbs.db`, etc.).

### Reading source documents (required)

**Do not invent content from filenames alone.** Extract readable text (or structured table text) from every source file before writing `phase1.json`. Prefer the first tool that works on the machine:

| Kind | How to extract |
|------|----------------|
| `.md`, `.txt`, `.csv`, `.tsv`, `.html`, `.htm`, `.rtf` | Read directly (Read tool / `cat`). For CSV/TSV, keep headers and skim representative rows; note row/column scale in `provides` / `coverageNote`. |
| `.pdf` | `pdftotext -layout "{path}" -` when available; else Python (`pypdf` / `pdfminer.six` / `pymupdf`) if installed. |
| `.docx` | `pandoc -t plain "{path}"`, or macOS `textutil -convert txt -stdout "{path}"`, or unzip and read `word/document.xml` (strip tags). |
| `.doc` | macOS `textutil -convert txt -stdout "{path}"`, or `antiword` / `pandoc` if present. |
| `.xlsx` / `.xls` | Python (`openpyxl` / `xlrd` / `pandas`) when available; else `ssconvert` (gnumeric); for `.xlsx`, unzip and read sheet XML + `sharedStrings.xml` as a last resort. Summarize sheet names, headers, and notable figures — do not dump huge grids wholesale into keypoints. Then follow **Spreadsheets: guide the human reader** below. |
| `.pptx` / `.ppt` | `pandoc -t plain` when available; else unzip `.pptx` and read `ppt/slides/slide*.xml`. |

**Spreadsheets: guide the human reader.** A workbook is not read top to bottom, so tell the person exactly where to look:

- `provides` lists every tab (sheet) by its exact name with one line on what it holds, and marks tabs that are safe to skip (raw data dumps, lookup tables, hidden helper sheets, chart-only tabs).
- Start the keypoints with a **"Read these tabs, in this order"** keypoint: the tab names in reading order and why each matters for this pack.
- For each tab worth reading, add a keypoint that says what to read as a human: which rows, columns, totals, or named cells carry the answer (e.g. "Row 12 *Net burn* across Jan–Dec; the FY total is in column N"), what assumptions drive the numbers, and what to sanity-check.
- Cite tabs as `sources` in the form `{file}:{Tab name}` (e.g. `model.xlsx:Summary`). In the GUI that source opens the workbook preview on that tab.
- Reading guides that include a workbook should name the tab(s) to open in `howToRead`, not just the file.

If extraction fails after trying available tools: still list the file under `documents` with a low `coveragePercent`, set `coverageNote` to the failure reason, and ask the user for a text/CSV export or to install a local extractor — then continue with the files you could read.

### `phase1.json`

This schema is a **working shape**, not the end-all-be-all structure. Prefer the fields below so the GUI can render, but adapt depth and emphasis to the pack: lean harder on reading guides for multi-path diligence, keypoints for dense single docs, MOC for cross-file hubs — or keep a section sparse/`[]` when it does not help. Do not force a full textbook outline into every batch.

```json
{
  "kind": "mixed",
  "title": "Short pack title",
  "readingGuides": [
    {
      "id": "rg1",
      "need": "Legal risk before signing",
      "howToRead": "Start with NDA scope, then MSA liability, then term sheet economics.",
      "order": ["nda.md", "msa-excerpt.md", "term-sheet.md"],
      "checked": false
    }
  ],
  "moc": [
    {
      "id": "moc1",
      "title": "Money & liability",
      "summary": "Where economics and caps live across the pack.",
      "links": [
        { "file": "msa-excerpt.md", "note": "§8 liability cap" },
        { "file": "term-sheet.md", "note": "liquidation preference" }
      ],
      "checked": false
    }
  ],
  "documents": {
    "nda.md": {
      "provides": "Mutual confidentiality, term, exclusions, remedies.",
      "coveragePercent": 85,
      "coverageNote": "Strong on definitions; thin on residuals / affiliates nuance.",
      "keypoints": [
        {
          "id": "nda-k1",
          "title": "Survival period",
          "body": "Confidentiality survives 3 years after termination.",
          "sources": ["nda.md:40-48"]
        }
      ],
      "checked": false
    }
  }
}
```

Rules:

- `kind`: `technical` | `legal` | `financial` | `mixed`
- Include **every** source document under `documents` (all supported extensions in the folder — not only markdown/text)
- `coveragePercent` is 0–100: honest estimate of how much of that document the GUI map (provides + keypoints + links) actually covers — not a quality score
- `coverageNote` explains what is covered vs thin/missing
- `readingGuides`: different **needs** (due diligence, engineer onboarding, investor skim, etc.) each with a how-to-read path — count and granularity flex with the pack
- `moc`: map of content nodes linking files/topics; use when cross-doc structure helps; may be `[]` if a pure keypoint list is enough
- Every document gets `provides`, `coveragePercent`, `coverageNote`, and `keypoints` (may be empty only if the file is truly noise — prefer at least one keypoint; keypoint count is not fixed)
- Preserve `checked` booleans when rewriting after GUI edits

### `phase1-responses.md`

User-written feedback saved by the app **during Phase 1** (batch folder). May be missing — treat as empty. **Always read before Phase 2.**

```markdown
# Phase 1 responses

## Guide: rg1

Optional notes about this reading guide (wrong order, missing need, unclear how-to-read).

## Doc: nda.md

Optional notes about the whole document card (provides wrong, coverage too high/low, missing theme).

## Keypoint: nda-k1

Optional notes about one keypoint (wrong claim, missing detail, merge/split).

## Moc: moc1

Optional notes about one MOC node (bad links, missing hub, retitle).
```

- Match ids from `phase1.json` (`rg1`, `nda-k1`, `moc1`, …) and document filenames for `## Doc:`
- Do not invent responses; only use what the user wrote
- The app POSTs to `index.php?action=save-responses`. Do not wipe this file when rewriting `phase1.json` / `phase2.json`

### `phase2.json`

Same shape as `phase1.json` (refined). Written only in Phase 2 after applying `phase1-responses.md` and any `checked` state from disk. This is the **final** navigation pack the GUI shows in phase 2.

## Strict workflow

### 0. Session check — list folders, ask which one

On **every start**: **read `app.config.json` first**, list directories under `inputs/` (ignore files like `.gitkeep`), announce state, then **ask which folder** (offer the options below).

#### Detect session state

| State | When |
|-------|------|
| **No folder pointed** | `inputFolder` is `""`, or the folder under `inputs/` does not exist |
| **Mid-session** | `inputFolder` points at an existing folder and `phase` is `1` |
| **Final session** | `inputFolder` points at an existing folder and `phase` is `2` |

Announce briefly (e.g. “Active: `inputs/sample` · phase 1” or “No folder pointed”).

#### Options by state

**A. No folder pointed** — offer:

1. **Reset sample and select it** — wipe/recreate `inputs/sample/`, set active, run Phase 1.
2. **See the app with no folder selected** — recreate sample on disk, leave `inputFolder` empty (empty-state preview only).
3. **Use folder `{name}`** — one option per non-sample directory under `inputs/`.
4. **None / use my docs** — tell them to create `inputs/{name}/`, drop documents (PDF, Word, Excel, CSV, markdown, etc.), then re-run.

**B. Mid-session (phase 1)** — offer:

1. **Continue `{folder}` at phase 1** — keep config; remind them to **open the GUI**, check each guide/doc/keypoint/MOC, respond in the GUI (saves to `phase1-responses.md` / checked flags in `phase1.json`), then return to chat when ready for Phase 2.
2. **Reset sample and select it**
3. **See the app with no folder selected**
4. **Switch to folder `{name}`** — other non-sample dirs → phase 1
5. **None / use my docs**

**C. Final session (phase 2)** — offer:

1. **Continue `{folder}` (phase 2, finalized)** — keep config; tell them to **visit the GUI** to see the final navigation pack. Mention they can always **“Revert to Phase 1”** in chat, then refresh.
2. **Revert to Phase 1** — set `"phase": 1`; keep artifacts; refresh GUI to unlock responses again.
3. **Start a new batch** — then offer A’s folder picks.
4. **Reset sample and select it**
5. **See the app with no folder selected**
6. **None / use my docs**

Selecting sample always runs the reset below.

- **If they choose “Reset sample and select it”:**
  1. Delete `inputs/sample/` if it exists.
  2. Recreate `inputs/sample/` with only the three document files below (exact contents). Do **not** write phase JSON.
  3. Write `app.config.json` with `"inputFolder": "sample"` and `"phase": 1`.
  4. Continue with Phase 1 on `sample`.

- **If they choose “See the app with no folder selected”:**
  1. Same recreate of sample docs only.
  2. Write `app.config.json` with `"inputFolder": ""` and `"phase": 1`.
  3. Tell them to open `index.php` for the empty state. Stop; re-offer folder options.

- **If they choose “Continue …”:** do not change folder/phase unless advancing; resume at current phase.

- **If they choose “Revert to Phase 1”:** set `"phase": 1`; keep files; tell them to refresh the GUI.

- **If they choose another / switch folder:** set that folder and `"phase": 1`, then Phase 1.

#### Sample document templates (recreate exactly; no phase JSON)

`inputs/sample/api-design.md`:

```markdown
# Payments API — Internal Design (excerpt)

## Goals
- Idempotent charge creation for web + mobile
- Webhook delivery with at-least-once semantics
- PCI scope limited to tokenized PANs via Stripe

## Auth
All server-to-server calls use HMAC-SHA256 over `timestamp.method.path.body`.
Keys rotate every 90 days. Clock skew tolerance: 5 minutes.

## Charge flow
1. Client collects card → Stripe.js → `payment_method` id
2. Backend `POST /v1/charges` with `Idempotency-Key`
3. On success, enqueue `charge.succeeded` outbox row
4. Worker delivers webhooks; receiver must be idempotent

## Open questions
- Do we support partial captures in v1?
- Retry budget for webhook worker (current draft: 8 attempts, expo backoff)
```

`inputs/sample/nda-excerpt.md`:

```markdown
# Mutual NDA (excerpt)

## 1. Purpose
Parties may share Confidential Information to evaluate a possible business relationship.

## 2. Definition
"Confidential Information" means non-public information disclosed by either party, in any form, that is marked confidential or would reasonably be understood as confidential.

## 3. Exclusions
Obligations do not apply to information that is (a) public through no fault of Recipient, (b) already known without duty, (c) independently developed, or (d) required by law (with notice if legally permitted).

## 4. Term
Agreement term: 2 years. Confidentiality survives for 3 years after termination; trade secrets survive while they remain trade secrets.

## 5. Residuals
No residuals clause in this draft.
```

`inputs/sample/term-sheet.md`:

```markdown
# Series Seed Term Sheet (excerpt)

Issuer: Acme Analytics, Inc.
Amount: $2.5M on $10M post-money SAFE (valuation cap)
Lead: Northbridge Capital

## Economics
- 1x non-participating liquidation preference
- Pro-rata rights for major investors (≥$250k)
- MFN on subsequent SAFEs for 120 days

## Governance
- Board: 2 founders, 1 lead investor
- Protective provisions: sale, new preferred, debt >$100k

## Timing
Target close: 45 days from signature of this sheet (non-binding except confidentiality / exclusivity).
Exclusivity: 30 days.
```

### 1. Phase 1 — map the pack, then open the GUI

After a folder is selected:

1. List all source documents in the folder (see supported extensions). **Extract readable text** from each (PDF / Word / Excel / CSV / etc. — use the reading table above). Do not map from filenames alone.
2. Infer pack `kind` and `title`.
3. Write `phase1.json` with:
   - **readingGuides** — how to read based on different needs
   - **documents.\*** — what each file provides, coverage %, coverage note, keypoints
   - **moc** — cross-doc map when useful
4. Set `app.config.json` `"phase": 1`.
5. **Remind the user to open the GUI** (`php -S localhost:8765` → `http://localhost:8765`). Tell them the next step is **in the GUI**: check the work and respond to each guide / document / keypoint / MOC node. The GUI saves checked state into `phase1.json` and responses into `phase1-responses.md` for the next phase.
6. Stop and wait. Do not advance to Phase 2 until they confirm Phase 1 review is done (or explicitly insist).

Phase 1 may loop if they ask for a remap in chat before reviewing — rewrite `phase1.json`, preserve responses file, they refresh the GUI.

### 2. Phase 2 — finalize in AI chat

Only after the user confirms Phase 1 GUI review is done:

1. **Re-read** `phase1-responses.md` (if present) and `phase1.json` (for `checked` and latest map).
2. Apply responses: fix provides, coverage %, keypoints, guides, MOC links/titles as requested.
3. Write `phase2.json` (final pack).
4. Set `app.config.json` `"phase": 2`.
5. Tell the user finalization is done in chat, they can **always revert** by saying **“Revert to Phase 1”**, and they should **visit the GUI** to see the final navigation pack.

## Agent checklist

1. On every start: read `app.config.json`, **list `inputs/` folders**, detect state, announce, ask which folder / option.
2. Mid-session: offer Continue first; remind them to open the GUI and respond there.
3. Final: offer Continue (finalized) first; mention Revert + visit GUI for the final view.
4. Reset sample: recreate the three sample docs only; phase 1; run Phase 1.
5. Never treat phase JSON / responses md as source documents.
6. Extract text from every source type (PDF, Word, Excel, CSV, …) before mapping; every source file appears under `documents` with coverage %.
7. After Phase 1 write: **remind to open the GUI**; next phase of work for the user is check + respond in the GUI.
8. Before Phase 2: read `phase1-responses.md` + `phase1.json`.
9. Do not advance to Phase 2 without explicit user confirmation.
10. After Phase 2: finalize message in chat; mention Revert; tell them to visit the GUI for the final view.
11. On **“Revert to Phase 1”**: set `"phase": 1`; keep artifacts; refresh GUI.
12. When the user pastes a GUI **Quiz** prompt: run the quiz in this chat (never in the PHP app); follow the question-type protocol above.

## Quiz for understanding (chat-only)

The GUI has a **Quiz** button on guides, documents, keypoints, MOC nodes, and source files. It opens a modal with a copyable prompt and reminds the user to paste it into **Cursor or Claude Code**. The quiz does **not** run in `index.php` — only in this chat thread.

When the user pastes a Heavy Docs quiz prompt (or says “quiz me” on a pack item):

1. Stay in chat. Do not send them back to the GUI to take the quiz.
2. Use the material in the prompt; re-read pack source file(s) if you need more fidelity.
3. Run a short quiz (~4–8 questions), one at a time (or a short batch if they ask).
4. Mix these question styles when useful:
   - **Multiple choice** — one correct answer; any number of choices. User answers e.g. `a` or `2`.
   - **Fill in the blank** — same as multiple choice, with blanks in the stem.
   - **True/False** — multiple choice with only True / False.
   - **SATA** — select all that apply; enabled by comma-separated correct indices (e.g. `1,2,4`), not a special type name.
   - **Ranking / ordering** — label **Question Type Ranked**; user answers ordered indices (e.g. `4,3,5`).
   - **Mix and match** — label **Question Type Mix and match**; pair items (optional distractors); user answers e.g. `1e, 2a`.
5. After each answer: grade briefly, then continue. Keep questions grounded in the material — no outside trivia.

## Return-to-chat prompts (for the user)

- **“start phase 1”** / **“start phase 2”** / **“let’s continue”**
- **“Revert to Phase 1”** — from Phase 2: unlock GUI responses again
- Paste a **Quiz** prompt from the GUI — get quizzed in this chat

After Phase 1:

- “Phase 1 looks good — start Phase 2”
- “Remap the legal reading guide to put term sheet first”

After Phase 2:

- **“Revert to Phase 1”**
- “Raise coverage on nda-excerpt — we missed affiliates”
