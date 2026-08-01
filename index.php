<?php
declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    try {
        require_once $autoload;
    } catch (Throwable $e) {
        // Spreadsheet preview unavailable (e.g. PHP too old for vendor platform check).
    }
}
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/spreadsheet.php';

$config = load_config();
$folder = $config['inputFolder'];
$phase = $config['phase'];
$dir = $folder !== '' ? input_dir($folder) : '';
$folderExists = $folder !== '' && is_dir($dir);

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && (string) $_GET['action'] === 'sheet-preview'
) {
    header('Content-Type: application/json; charset=utf-8');
    $name = isset($_GET['file']) ? (string) $_GET['file'] : '';
    $name = basename(str_replace(["\0", '\\'], '', $name));
    $docFiles = $folderExists ? list_doc_files($dir) : [];
    if (
        !$folderExists
        || $name === ''
        || !in_array($name, $docFiles, true)
        || !is_spreadsheet_previewable($name)
    ) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Spreadsheet not found.']);
        exit;
    }
    if (!spreadsheet_library_available()) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'PhpSpreadsheet is not installed. Run composer install.']);
        exit;
    }
    $built = build_spreadsheet_preview_html($dir . '/' . $name);
    if (!$built['ok']) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $built['error']]);
        exit;
    }
    echo json_encode(['ok' => true, 'html' => $built['html']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && (string) $_GET['action'] === 'raw-file'
) {
    $name = isset($_GET['file']) ? (string) $_GET['file'] : '';
    $name = basename(str_replace(["\0", '\\'], '', $name));
    $docFiles = $folderExists ? list_doc_files($dir) : [];
    if (!$folderExists || $name === '' || !in_array($name, $docFiles, true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found.';
        exit;
    }
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found.';
        exit;
    }
    header('Content-Type: ' . mime_for_source_file($name));
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    readfile($path);
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_GET['action'])
    && (string) $_GET['action'] === 'save-checked'
) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$folderExists || $phase !== 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Checked state is only editable in Phase 1.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Expected JSON body with checked.']);
        exit;
    }

    $phase1Path = $dir . '/phase1.json';
    $existing = load_json_file($phase1Path) ?? [];
    $updated = apply_nav_checked($existing, $payload);
    if (!save_json_file($phase1Path, $updated)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not write phase1.json.']);
        exit;
    }

    echo json_encode(['ok' => true, 'pack' => $updated]);
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_GET['action'])
    && (string) $_GET['action'] === 'save-responses'
) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$folderExists || $phase !== 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Responses are only available in Phase 1.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Expected JSON body with responses.']);
        exit;
    }

    $responses = normalize_phase1_responses($payload);
    if (!save_phase1_responses($dir, $responses)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not write phase1-responses.md.']);
        exit;
    }

    echo json_encode(['ok' => true, 'responses' => $responses]);
    exit;
}

$docFiles = $folderExists ? list_doc_files($dir) : [];
$activeFile = isset($_GET['file']) ? (string) $_GET['file'] : '';
if ($activeFile === '' || !in_array($activeFile, $docFiles, true)) {
    $activeFile = $docFiles[0] ?? '';
}

$phase1 = $folderExists ? load_json_file($dir . '/phase1.json') : null;
$phase2 = $folderExists ? load_json_file($dir . '/phase2.json') : null;
$responses = $folderExists
    ? load_phase1_responses($dir)
    : normalize_phase1_responses(null);

$navSource = $phase === 2 && is_array($phase2) ? $phase2 : $phase1;
$pack = normalize_nav_pack(is_array($navSource) ? $navSource : null);
$reviewLocked = $phase === 2;
$showEmpty = !$folderExists;

/**
 * @param list<array{label: string, count: int}> $items
 */
function render_count_bar(array $items, string $ariaLabel): void
{
    echo '<div class="bucket-counts" aria-label="' . e($ariaLabel) . '">';
    $first = true;
    foreach ($items as $item) {
        if (!$first) {
            echo '<span class="bucket-counts__divider" aria-hidden="true"></span>';
        }
        $first = false;
        echo '<span class="bucket-counts__item">';
        echo e((string) ($item['label'] ?? '')) . ' (<span>' . (int) ($item['count'] ?? 0) . '</span>)';
        echo '</span>';
    }
    echo '</div>';
}

function render_check_button(bool $checked, bool $locked, string $aria): void
{
    if ($locked) {
        if ($checked) {
            echo '<span class="item-card__check item-card__check--static" title="Reviewed" aria-hidden="true">';
            echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>';
            echo '</span>';
        }
        return;
    }
    echo '<button type="button" class="item-card__check" data-check aria-pressed="' . ($checked ? 'true' : 'false') . '" title="' . e($aria) . '" aria-label="' . e($aria) . '">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>';
    echo '</button>';
}

/**
 * @param array<string, string> $bucket
 */
function render_response_box(string $kind, string $id, array $bucket, bool $locked, string $placeholder): void
{
    $text = $bucket[$id] ?? '';
    $has = $text !== '';
    if ($locked && !$has) {
        return;
    }
    $fieldId = 'resp-' . $kind . '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $id);
    echo '<div class="item-card__suggest' . ($has ? ' has-response' : '') . '" data-response-wrap>';
    if ($locked) {
        echo '<div class="item-card__suggest-panel">';
        echo '<p class="item-card__suggest-caption">Your response</p>';
        echo '<p class="response-readonly">' . e($text) . '</p>';
        echo '</div>';
    } else {
        echo '<button type="button" class="item-card__suggest-toggle" data-response-toggle aria-expanded="' . ($has ? 'true' : 'false') . '" title="Respond for AI" aria-label="Respond for AI">';
        echo '<svg class="suggest-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 18-5v12L3 13v-2z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>';
        echo '<span class="item-card__suggest-label">Respond</span>';
        echo '</button>';
        echo '<div class="item-card__suggest-panel" data-response-panel' . ($has ? '' : ' hidden') . '>';
        echo '<label class="item-card__suggest-caption" for="' . e($fieldId) . '">Tell the AI what to fix before finalizing</label>';
        echo '<textarea class="item-card__suggest-input" data-response-input data-response-kind="' . e($kind) . '" data-response-id="' . e($id) . '" id="' . e($fieldId) . '" rows="3" maxlength="4000" placeholder="' . e($placeholder) . '">' . e($text) . '</textarea>';
        echo '</div>';
    }
    echo '</div>';
}

function render_coverage_meter(int $percent): void
{
    $pct = max(0, min(100, $percent));
    echo '<div class="coverage" aria-label="Coverage ' . $pct . ' percent">';
    echo '<div class="coverage__label"><span>Coverage</span><strong>' . $pct . '%</strong></div>';
    echo '<div class="coverage__track"><div class="coverage__fill" style="width:' . $pct . '%"></div></div>';
    echo '</div>';
}

function build_quiz_prompt(string $folder, string $scope, string $title, string $material): string
{
    $folderLabel = $folder !== '' ? 'inputs/' . $folder : '(no folder)';
    $material = trim($material);
    if ($material === '') {
        $material = '(No extra material on this card — re-read the related source file(s) in the pack folder.)';
    }

    return <<<PROMPT
Quiz me for understanding on this Heavy Docs artifact.

IMPORTANT: Run the quiz in this Cursor / Claude Code chat thread — not in the PHP visual artifact container. I copied this prompt from the GUI and returned here to be quizzed.

Pack folder: {$folderLabel}
Scope: {$scope}
Title: {$title}

Material to quiz me on:
---
{$material}
---

Give me a short quiz (about 4–8 questions) grounded in the material above. Re-read source file(s) from the pack if you need more fidelity. Mix question types when useful:

- Multiple choice — standard one-answer; any number of choices. I respond with a letter or index (e.g. `a` or `2`).
- Fill in the blank — same as multiple choice, with blanks in the question text.
- True/False — multiple choice with only True / False.
- SATA (select all that apply) — enabled by comma-separated correct indices (e.g. `1,2,4`); not a special type name.
- Ranking / ordering — label as **Question Type Ranked**. I respond with ordered indices (e.g. `4,3,5`).
- Mix and match — label as **Question Type Mix and match**; pair items (optionally with distractors). I respond like `1e, 2a`.

Ask one question at a time (or a short batch if I ask), wait for my answer, grade briefly, then continue. No trivia outside this material.
PROMPT;
}

function render_quiz_button(string $modalTitle, string $prompt): void
{
    echo '<button type="button" class="quiz-btn" data-quiz-open';
    echo ' data-quiz-title="' . e($modalTitle) . '"';
    echo ' data-quiz-prompt="' . e($prompt) . '"';
    echo ' title="Copy quiz prompt for Cursor / Claude Code"';
    echo ' aria-label="Quiz prompt: ' . e($modalTitle) . '">';
    echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';
    echo '<span>Quiz</span>';
    echo '</button>';
}

/**
 * @param list<string> $docFiles
 */
function render_doc_open_link(
    string $file,
    array $docFiles,
    string $label = '',
    ?int $line = null,
    ?int $endLine = null,
    ?string $anchor = null
): void {
    $label = $label !== '' ? $label : $file;
    $known = in_array($file, $docFiles, true);
    if (!$known) {
        echo '<span class="doc-ref doc-ref--missing" title="File not in this pack">' . e($label) . '</span>';
        return;
    }
    echo '<button type="button" class="doc-ref" data-open-doc="' . e($file) . '"';
    if ($line !== null) {
        echo ' data-open-line="' . e((string) $line) . '"';
    }
    if ($endLine !== null) {
        echo ' data-open-end-line="' . e((string) $endLine) . '"';
    }
    if ($anchor !== null && $anchor !== '') {
        echo ' data-open-anchor="' . e($anchor) . '"';
    }
    $title = 'Open ' . $file;
    if ($line !== null) {
        $title .= $endLine !== null && $endLine !== $line
            ? ' (lines ' . $line . '–' . $endLine . ')'
            : ' (line ' . $line . ')';
    } elseif ($anchor !== null && $anchor !== '') {
        $title .= ' (' . $anchor . ')';
    }
    echo ' title="' . e($title) . '"';
    echo ' aria-label="' . e($title) . '">';
    echo e($label);
    echo '</button>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Heavy Docs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=2.6">
</head>
<body<?= $reviewLocked ? '' : ' data-phase1-editable="1"' ?><?= $folderExists ? ' data-quiz-ready="1"' : '' ?><?= $folderExists ? ' data-doc-viewer="1"' : '' ?>>
<header class="app-header">
    <a href="index.php" class="app-header__brand">Heavy Docs</a>
    <div class="app-header__meta">
        <?php if ($showEmpty): ?>
            no folder configured
        <?php else: ?>
            inputs/<?= e($folder) ?> · phase <?= (int) $phase ?>
            <?php if ($pack['title'] !== ''): ?>
                · <?= e($pack['title']) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</header>

<details class="adhd-about">
    <summary>What this is for</summary>
    <div class="adhd-about__body">
        <p>
            For minds that face large technical documents, legal contracts, and financial pitches—
            and need a clear way to read them by need, see what each file provides, and hold keypoints
            or a map of content (MOC) with honest coverage.
        </p>
        <p>
            The Cursor / Claude Code skill maps the pack. This app is the artifact container:
            Phase 1 is where you check the work and respond on each card; Phase 2 shows the finalized pack.
        </p>
    </div>
</details>

<?php if ($showEmpty): ?>
    <main class="empty-state">
        <h1>No input folder pointed</h1>
        <ol>
            <li>
                Drop documents into a folder under <code>inputs/</code>,
                e.g. <code>inputs/series-a-pack/</code> —
                PDF, Word, Excel, CSV, markdown, plain text, and similar are fine.
            </li>
            <li>
                In Cursor or Claude Code, run the skill at
                <code>.agents/skills/heavy-docs</code> (or ask to use the heavy-docs skill).
                It lists folders under <code>inputs/</code> — pick one. The skill sets <code>app.config.json</code>.
            </li>
            <li>Refresh this page to review Phase 1 navigation artifacts, respond in the GUI, then return to chat for Phase 2.</li>
        </ol>
        <p class="muted">Return to Cursor or Claude Code after you have documents ready in an input folder.</p>
    </main>
<?php else:
    $counts = nav_review_counts($pack);
    render_count_bar([
        ['label' => 'Guides', 'count' => $counts['guides']],
        ['label' => 'Docs', 'count' => $counts['documents']],
        ['label' => 'Keypoints', 'count' => $counts['keypoints']],
        ['label' => 'MOC', 'count' => $counts['moc']],
    ], 'Navigation artifact counts');

    if ($phase === 2): ?>
    <aside class="banner">
        <h2>Phase 2 — finalized navigation</h2>
        <p>
            This is the final pack after the AI applied your GUI responses in chat.
            You can always say <strong>Revert to Phase 1</strong> in the agent chat, then refresh here.
            <strong>Quiz</strong> still copies a prompt for Cursor / Claude Code — the quiz runs in that chat, not here.
        </p>
    </aside>
    <?php else: ?>
    <aside class="banner banner--warn">
        <h2>Phase 1 — check the map, respond here</h2>
        <p>
            Review how to read by need, what each file provides, keypoints / MOC, and coverage %.
            Green-check items as you accept them. Use <strong>Respond</strong> on anything that should change —
            saves to <code>phase1-responses.md</code> (and checks into <code>phase1.json</code>) for the next AI phase.
            Use <strong>Quiz</strong> on a card or source file to copy a prompt into Cursor / Claude Code — the quiz runs in that chat, not here.
        </p>
        <ul>
            <li>When the pack looks right (or your responses are written), return to Cursor / Claude Code and say Phase 1 looks good — start Phase 2.</li>
        </ul>
    </aside>
    <?php endif; ?>

    <?php if ($phase1 === null && $phase !== 2): ?>
        <main class="empty-state">
            <h1>No Phase 1 map yet</h1>
            <p class="muted">Return to the heavy-docs skill in chat and run Phase 1 so <code>phase1.json</code> is written.</p>
        </main>
    <?php elseif ($phase === 2 && $phase2 === null): ?>
        <main class="empty-state">
            <h1>No Phase 2 pack yet</h1>
            <p class="muted">Return to chat and ask to start Phase 2 after finishing GUI review.</p>
        </main>
    <?php else: ?>

    <section class="nav-section">
        <div class="nav-section__head">
            <h2>How to read — by need</h2>
            <p class="muted">Different paths through the same pack depending on what you are doing.</p>
        </div>
        <?php if ($pack['readingGuides'] === []): ?>
            <p class="muted">No reading guides in this pack.</p>
        <?php else: ?>
            <div class="card-list">
                <?php foreach ($pack['readingGuides'] as $guide):
                    $id = (string) $guide['id'];
                    $checked = !empty($guide['checked']);
                    $guideNeed = (string) $guide['need'];
                    $guideMaterial = $guideNeed;
                    if ((string) $guide['howToRead'] !== '') {
                        $guideMaterial .= "\n\nHow to read:\n" . (string) $guide['howToRead'];
                    }
                    if ($guide['order'] !== []) {
                        $guideMaterial .= "\n\nSuggested order:\n- " . implode("\n- ", $guide['order']);
                    }
                    $guideQuiz = build_quiz_prompt($folder, 'Reading guide (' . $id . ')', $guideNeed, $guideMaterial);
                    ?>
                    <article class="item-card<?= $checked ? ' is-checked' : '' ?>" data-kind="guide" data-id="<?= e($id) ?>" data-checked="<?= $checked ? '1' : '0' ?>">
                        <div class="item-card__main">
                            <div class="item-card__title-row">
                                <?php render_check_button($checked, $reviewLocked, $checked ? 'Unmark as reviewed' : 'Mark as reviewed'); ?>
                                <h3><?= e($guideNeed) ?></h3>
                                <?php render_quiz_button('Quiz: ' . $guideNeed, $guideQuiz); ?>
                                <span class="pill"><?= e($id) ?></span>
                            </div>
                            <?php if ((string) $guide['howToRead'] !== ''): ?>
                                <p class="item-card__body-text"><?= e((string) $guide['howToRead']) ?></p>
                            <?php endif; ?>
                            <?php if ($guide['order'] !== []): ?>
                                <ol class="read-order">
                                    <?php foreach ($guide['order'] as $name): ?>
                                        <li><?php render_doc_open_link($name, $docFiles); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                            <?php render_response_box('guides', $id, $responses['guides'], $reviewLocked, 'e.g. put term sheet first, add an engineer-onboarding need…'); ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="nav-section">
        <div class="nav-section__head">
            <h2>Documents — what each provides</h2>
            <p class="muted">Coverage % is how much of that file this map actually covers — not a quality score.</p>
        </div>
        <?php if ($pack['documents'] === []): ?>
            <p class="muted">No documents mapped yet.</p>
        <?php else: ?>
            <div class="card-list">
                <?php foreach ($pack['documents'] as $filename => $doc):
                    $checked = !empty($doc['checked']);
                    $isActive = $filename === $activeFile;
                    $docMaterial = 'File: ' . $filename;
                    if ((string) $doc['provides'] !== '') {
                        $docMaterial .= "\nProvides: " . (string) $doc['provides'];
                    }
                    $docMaterial .= "\nCoverage: " . (int) $doc['coveragePercent'] . '%';
                    if ((string) $doc['coverageNote'] !== '') {
                        $docMaterial .= "\nCoverage note: " . (string) $doc['coverageNote'];
                    }
                    if ($doc['keypoints'] !== []) {
                        $docMaterial .= "\n\nKeypoints:";
                        foreach ($doc['keypoints'] as $kp) {
                            $docMaterial .= "\n- " . (string) ($kp['title'] ?? '') . ': ' . (string) ($kp['body'] ?? '');
                        }
                    }
                    $docQuiz = build_quiz_prompt($folder, 'Document', $filename, $docMaterial);
                    ?>
                    <article class="item-card<?= $checked ? ' is-checked' : '' ?><?= $isActive ? ' is-active-doc' : '' ?>" id="doc-<?= e($filename) ?>" data-kind="document" data-id="<?= e($filename) ?>" data-checked="<?= $checked ? '1' : '0' ?>">
                        <div class="item-card__main">
                            <div class="item-card__title-row">
                                <?php render_check_button($checked, $reviewLocked, $checked ? 'Unmark document' : 'Mark document reviewed'); ?>
                                <h3><?php render_doc_open_link($filename, $docFiles, $filename); ?></h3>
                                <?php render_quiz_button('Quiz: ' . $filename, $docQuiz); ?>
                                <span class="pill"><?= e($pack['kind']) ?></span>
                            </div>
                            <?php render_coverage_meter((int) $doc['coveragePercent']); ?>
                            <?php if ((string) $doc['provides'] !== ''): ?>
                                <p class="item-card__body-text"><strong>Provides:</strong> <?= e((string) $doc['provides']) ?></p>
                            <?php endif; ?>
                            <?php if ((string) $doc['coverageNote'] !== ''): ?>
                                <p class="muted"><?= e((string) $doc['coverageNote']) ?></p>
                            <?php endif; ?>
                            <?php render_response_box('documents', $filename, $responses['documents'], $reviewLocked, 'e.g. coverage too high — missed affiliates carve-out…'); ?>

                            <?php if ($doc['keypoints'] !== []): ?>
                                <div class="keypoint-list">
                                    <h4>Keypoints</h4>
                                    <?php foreach ($doc['keypoints'] as $kp):
                                        $kid = (string) $kp['id'];
                                        $kChecked = !empty($kp['checked']);
                                        $kpTitle = (string) $kp['title'];
                                        $kpMaterial = 'Document: ' . $filename . "\nKeypoint: " . $kpTitle;
                                        if ((string) $kp['body'] !== '') {
                                            $kpMaterial .= "\n\n" . (string) $kp['body'];
                                        }
                                        if ($kp['sources'] !== []) {
                                            $kpMaterial .= "\n\nSources: " . implode(', ', $kp['sources']);
                                        }
                                        $kpQuiz = build_quiz_prompt($folder, 'Keypoint (' . $kid . ') in ' . $filename, $kpTitle, $kpMaterial);
                                        ?>
                                        <div class="keypoint<?= $kChecked ? ' is-checked' : '' ?>" data-kind="keypoint" data-id="<?= e($kid) ?>" data-doc="<?= e($filename) ?>" data-checked="<?= $kChecked ? '1' : '0' ?>">
                                            <div class="item-card__title-row">
                                                <?php render_check_button($kChecked, $reviewLocked, $kChecked ? 'Unmark keypoint' : 'Mark keypoint reviewed'); ?>
                                                <h5><?= e($kpTitle) ?></h5>
                                                <?php render_quiz_button('Quiz: ' . $kpTitle, $kpQuiz); ?>
                                                <span class="pill"><?= e($kid) ?></span>
                                            </div>
                                            <?php if ((string) $kp['body'] !== ''): ?>
                                                <p class="item-card__body-text"><?= e((string) $kp['body']) ?></p>
                                            <?php endif; ?>
                                            <?php if ($kp['sources'] !== []): ?>
                                                <div class="item-card__meta item-card__sources">
                                                    <?php foreach ($kp['sources'] as $i => $src):
                                                        $ref = parse_source_ref($src);
                                                        if ($i > 0) {
                                                            echo '<span class="item-card__sources-sep">, </span>';
                                                        }
                                                        render_doc_open_link(
                                                            $ref['file'],
                                                            $docFiles,
                                                            $src,
                                                            $ref['line'],
                                                            $ref['endLine'],
                                                            $ref['anchor']
                                                        );
                                                    endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php render_response_box('keypoints', $kid, $responses['keypoints'], $reviewLocked, 'e.g. survival is 5 years for trade secrets…'); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="nav-section">
        <div class="nav-section__head">
            <h2>Map of content (MOC)</h2>
            <p class="muted">Cross-document hubs — optional when keypoints alone are enough.</p>
        </div>
        <?php if ($pack['moc'] === []): ?>
            <p class="muted">No MOC nodes in this pack.</p>
        <?php else: ?>
            <div class="card-list">
                <?php foreach ($pack['moc'] as $node):
                    $id = (string) $node['id'];
                    $checked = !empty($node['checked']);
                    $mocTitle = (string) $node['title'];
                    $mocMaterial = $mocTitle;
                    if ((string) $node['summary'] !== '') {
                        $mocMaterial .= "\n\n" . (string) $node['summary'];
                    }
                    if ($node['links'] !== []) {
                        $mocMaterial .= "\n\nLinks:";
                        foreach ($node['links'] as $link) {
                            $mocMaterial .= "\n- " . (string) ($link['file'] ?? '');
                            if ((string) ($link['note'] ?? '') !== '') {
                                $mocMaterial .= ' — ' . (string) $link['note'];
                            }
                        }
                    }
                    $mocQuiz = build_quiz_prompt($folder, 'MOC node (' . $id . ')', $mocTitle, $mocMaterial);
                    ?>
                    <article class="item-card<?= $checked ? ' is-checked' : '' ?>" data-kind="moc" data-id="<?= e($id) ?>" data-checked="<?= $checked ? '1' : '0' ?>">
                        <div class="item-card__main">
                            <div class="item-card__title-row">
                                <?php render_check_button($checked, $reviewLocked, $checked ? 'Unmark MOC node' : 'Mark MOC node reviewed'); ?>
                                <h3><?= e($mocTitle) ?></h3>
                                <?php render_quiz_button('Quiz: ' . $mocTitle, $mocQuiz); ?>
                                <span class="pill"><?= e($id) ?></span>
                            </div>
                            <?php if ((string) $node['summary'] !== ''): ?>
                                <p class="item-card__body-text"><?= e((string) $node['summary']) ?></p>
                            <?php endif; ?>
                            <?php if ($node['links'] !== []): ?>
                                <ul class="moc-links">
                                    <?php foreach ($node['links'] as $link): ?>
                                        <li>
                                            <?php render_doc_open_link((string) $link['file'], $docFiles); ?>
                                            <?php if ((string) $link['note'] !== ''): ?>
                                                <span class="muted"> — <?= e((string) $link['note']) ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php render_response_box('moc', $id, $responses['moc'], $reviewLocked, 'e.g. add link to term-sheet liquidation waterfall…'); ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>

    <?php if ($docFiles !== []):
        $openSource = isset($_GET['file']) && $activeFile !== '';
        $rawFileBase = 'index.php?action=raw-file&file=';
        ?>
    <div class="source-dock<?= $openSource ? ' is-open' : '' ?>" data-source-dock>
        <button type="button" class="source-dock__fab" data-source-toggle aria-expanded="<?= $openSource ? 'true' : 'false' ?>" aria-controls="source-panel" title="Source files" aria-label="Toggle source files">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
        </button>
        <aside id="source-panel" class="source-dock__panel source-dock__panel--list" data-source-panel<?= $openSource ? '' : ' hidden' ?> aria-label="Source files">
            <div class="source-dock__header">
                <div>
                    <h2>Source files</h2>
                    <p class="muted">Open a document in the viewer.</p>
                </div>
                <button type="button" class="source-dock__close" data-source-toggle aria-label="Collapse source files" title="Collapse">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <nav class="source-dock__files" aria-label="Source documents">
                <?php foreach ($docFiles as $name):
                    $fileQuiz = build_quiz_prompt(
                        $folder,
                        'Source file',
                        $name,
                        "Source file in the pack: {$name}\n\nRe-read this file from the pack folder and quiz me on its contents and how it fits the navigation map (provides / keypoints / reading path)."
                    );
                    ?>
                    <div class="source-dock__file-row">
                        <button type="button" class="source-dock__file<?= $name === $activeFile ? ' is-active' : '' ?>" data-open-doc="<?= e($name) ?>" data-source-file="<?= e($name) ?>" aria-pressed="<?= $name === $activeFile ? 'true' : 'false' ?>">
                            <?= e($name) ?>
                        </button>
                        <?php render_quiz_button('Quiz: ' . $name, $fileQuiz); ?>
                    </div>
                <?php endforeach; ?>
            </nav>
        </aside>
    </div>

    <div class="doc-store" data-doc-store hidden aria-hidden="true">
        <?php foreach ($docFiles as $name):
            $path = $dir . '/' . $name;
            $previewable = is_text_previewable($name);
            $spreadsheet = is_spreadsheet_previewable($name);
            $embeddable = is_browser_embeddable($name);
            $rawUrl = $rawFileBase . rawurlencode($name);
            $sheetLazyUrl = 'index.php?action=sheet-preview&file=' . rawurlencode($name);
            if ($previewable && is_file($path)) {
                $raw = (string) file_get_contents($path);
                $lines = preg_split("/\r\n|\n|\r/", $raw);
                if ($lines === false) {
                    $lines = [$raw];
                }
                echo '<div data-doc-content="' . e($name) . '" data-doc-kind="text" data-doc-url="' . e($rawUrl) . '">';
                echo '<pre class="source-view source-view--lined">';
                foreach ($lines as $i => $line) {
                    $n = $i + 1;
                    echo '<span class="source-line" data-line="' . $n . '">';
                    echo '<span class="source-line__n" aria-hidden="true">' . $n . '</span>';
                    echo '<span class="source-line__t">' . e($line) . '</span>';
                    echo '</span>';
                }
                echo '</pre></div>';
            } elseif ($spreadsheet && is_file($path)) {
                echo '<div data-doc-content="' . e($name) . '" data-doc-kind="spreadsheet" data-doc-url="' . e($rawUrl) . '" data-doc-lazy-url="' . e($sheetLazyUrl) . '">';
                echo '<div class="sheet-preview sheet-preview--pending" data-sheet-pending>';
                echo '<p class="muted">Loading spreadsheet preview…</p>';
                echo '</div></div>';
            } elseif ($embeddable && is_file($path)) {
                echo '<div data-doc-content="' . e($name) . '" data-doc-kind="embed" data-doc-url="' . e($rawUrl) . '">';
                echo '<iframe class="doc-embed" title="' . e($name) . '" src="' . e($rawUrl) . '"></iframe>';
                echo '</div>';
            } else {
                echo '<div data-doc-content="' . e($name) . '" data-doc-kind="binary" data-doc-url="' . e($rawUrl) . '">';
                echo '<div class="doc-binary">';
                echo '<p class="muted">Binary / office format — listed in the pack; the heavy-docs skill extracts text when mapping in chat. Download the original to inspect it locally.</p>';
                echo '<a class="doc-binary__download" href="' . e($rawUrl) . '" download="' . e($name) . '">Download ' . e($name) . '</a>';
                echo '</div></div>';
            }
        endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="doc-modal" data-doc-modal hidden>
    <div class="doc-modal__backdrop" data-doc-minimize></div>
    <div class="doc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="doc-modal-title">
        <div class="doc-modal__header">
            <h2 id="doc-modal-title" data-doc-modal-title>Document</h2>
            <a class="doc-modal__download" data-doc-download href="#" download hidden title="Download file">Download</a>
            <button type="button" class="doc-modal__btn" data-doc-minimize title="Minimize" aria-label="Minimize">─</button>
            <button type="button" class="doc-modal__btn doc-modal__btn--close" data-doc-close aria-label="Close">×</button>
        </div>
        <div class="doc-modal__body" data-doc-modal-body></div>
    </div>
</div>

<div class="doc-taskbar" data-doc-taskbar hidden aria-label="Open documents">
    <div class="doc-taskbar__items" data-doc-taskbar-items></div>
</div>

<div class="note-modal" data-quiz-modal hidden>
    <div class="note-modal__backdrop" data-quiz-close></div>
    <div class="note-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="quiz-modal-title">
        <div class="note-modal__header">
            <h2 id="quiz-modal-title" data-quiz-modal-title>Quiz prompt</h2>
            <button type="button" class="note-modal__copy" data-quiz-copy title="Copy prompt" aria-label="Copy prompt">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <span data-quiz-copy-label>Copy</span>
            </button>
            <button type="button" class="note-modal__close" data-quiz-close aria-label="Close">×</button>
        </div>
        <p class="quiz-modal__remind">
            Paste this into <strong>Cursor or Claude Code</strong> (the chat behind this visual artifact container).
            The quiz runs there — not in this GUI. Return to that chat to get quizzed.
        </p>
        <pre class="note-modal__body" data-quiz-modal-body></pre>
    </div>
</div>

<script src="assets/app.js?v=2.6" defer></script>
</body>
</html>
