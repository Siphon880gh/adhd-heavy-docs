<?php
declare(strict_types=1);

function app_root(): string
{
    return dirname(__DIR__);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_phase(int $phase): int
{
    return $phase === 2 ? 2 : 1;
}

function app_config_path(): string
{
    return app_root() . '/app.config.json';
}

/**
 * Folder names directly under inputs/. Each one is a separate project.
 *
 * @return list<string>
 */
function list_input_projects(): array
{
    $root = app_root() . '/inputs';
    if (!is_dir($root)) {
        return [];
    }

    $names = [];
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
            continue;
        }
        if (is_dir($root . '/' . $name)) {
            $names[] = $name;
        }
    }

    natcasesort($names);
    return array_values($names);
}

/**
 * Phase for a project nothing has remembered yet: Phase 2 once phase2.json exists.
 */
function infer_project_phase(string $folder): int
{
    return is_file(input_dir($folder) . '/phase2.json') ? 2 : 1;
}

/**
 * @param mixed $raw
 * @return array<string, array{phase: int}>
 */
function normalize_project_registry($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $name => $row) {
        $name = basename(str_replace('\\', '/', trim((string) $name)));
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }
        $phase = 1;
        if (is_array($row) && isset($row['phase'])) {
            $phase = (int) $row['phase'];
        } elseif (is_numeric($row)) {
            $phase = (int) $row;
        }
        $out[$name] = ['phase' => normalize_phase($phase)];
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * @return array{inputFolder: string, phase: int, projects: array<string, array{phase: int}>}
 */
function load_config(): array
{
    $path = app_config_path();
    $defaults = ['inputFolder' => '', 'phase' => 1, 'projects' => []];

    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return $defaults;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }

    $folder = isset($data['inputFolder']) ? trim((string) $data['inputFolder']) : '';
    $folder = basename(str_replace('\\', '/', $folder));
    if ($folder === '.' || $folder === '..') {
        $folder = '';
    }
    $phase = isset($data['phase']) ? normalize_phase((int) $data['phase']) : 1;

    return [
        'inputFolder' => $folder,
        'phase' => $phase,
        'projects' => normalize_project_registry($data['projects'] ?? null),
    ];
}

/**
 * Phase shown for a project. The live folder uses the top-level phase,
 * then a remembered phase, then whatever the artifacts on disk suggest.
 *
 * @param array{inputFolder: string, phase: int, projects: array<string, array{phase: int}>} $config
 */
function project_phase_for(array $config, string $folder): int
{
    if ($folder !== '' && $folder === ($config['inputFolder'] ?? '')) {
        return normalize_phase((int) ($config['phase'] ?? 1));
    }
    if (isset($config['projects'][$folder]['phase'])) {
        return normalize_phase((int) $config['projects'][$folder]['phase']);
    }
    return infer_project_phase($folder);
}

/**
 * @param array<string, array{phase: int}> $projects
 */
function save_app_config(string $folder, int $phase, array $projects): bool
{
    $folder = basename(str_replace('\\', '/', trim($folder)));
    if ($folder === '.' || $folder === '..') {
        $folder = '';
    }
    $phase = normalize_phase($phase);
    $projects = normalize_project_registry($projects);
    if ($folder !== '') {
        $projects[$folder] = ['phase' => $phase];
        ksort($projects, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $json = json_encode(
        [
            'inputFolder' => $folder,
            'phase' => $phase,
            'projects' => $projects === [] ? new stdClass() : $projects,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        return false;
    }
    return file_put_contents(app_config_path(), $json . "\n") !== false;
}

/**
 * Remember the live phase and fill in projects that have never been opened.
 *
 * @return array{inputFolder: string, phase: int, projects: array<string, array{phase: int}>}
 */
function ensure_project_registry(): array
{
    $config = load_config();
    $projects = $config['projects'];
    $known = list_input_projects();
    $knownSet = array_fill_keys($known, true);
    foreach (array_keys($projects) as $name) {
        if (!isset($knownSet[$name])) {
            unset($projects[$name]);
        }
    }
    foreach ($known as $name) {
        if (!isset($projects[$name])) {
            $projects[$name] = ['phase' => infer_project_phase($name)];
        }
    }
    $folder = $config['inputFolder'];
    $phase = $config['phase'];
    if ($folder !== '' && isset($knownSet[$folder])) {
        $projects[$folder] = ['phase' => $phase];
    }

    if (json_encode($config['projects']) !== json_encode(normalize_project_registry($projects))) {
        save_app_config($folder, $phase, $projects);
        $config = load_config();
    }
    return $config;
}

function input_dir(string $folder): string
{
    return app_root() . '/inputs/' . $folder;
}

/**
 * Extensions treated as source documents in the batch folder.
 *
 * @return list<string>
 */
function source_doc_extensions(): array
{
    return [
        'md', 'txt', 'csv', 'tsv',
        'pdf',
        'doc', 'docx',
        'xls', 'xlsx',
        'ppt', 'pptx',
        'rtf', 'html', 'htm',
        'pages', 'numbers', 'key',
    ];
}

/**
 * @return list<string>
 */
function text_preview_extensions(): array
{
    return ['md', 'txt', 'csv', 'tsv', 'html', 'htm', 'rtf'];
}

function is_source_doc_file(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, source_doc_extensions(), true);
}

function is_text_previewable(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, text_preview_extensions(), true);
}

function is_browser_embeddable(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'html', 'htm'], true);
}

function mime_for_source_file(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'md' => 'text/markdown; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'tsv' => 'text/tab-separated-values; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'rtf' => 'application/rtf',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Parse refs like "nda.md", "nda.md:40", "nda.md:40-48", or "nda.md:Auth".
 *
 * @return array{file: string, line: int|null, endLine: int|null, anchor: string|null}
 */
function parse_source_ref(string $src): array
{
    $empty = ['file' => '', 'line' => null, 'endLine' => null, 'anchor' => null];
    $text = trim($src);
    if ($text === '') {
        return $empty;
    }

    if (preg_match('/^(.+):(\d+)(?:-(\d+))?$/', $text, $m)) {
        $start = (int) $m[2];
        $end = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $start;
        if ($start < 1) {
            $start = 1;
        }
        if ($end < $start) {
            $end = $start;
        }
        return [
            'file' => trim($m[1]),
            'line' => $start,
            'endLine' => $end,
            'anchor' => null,
        ];
    }

    if (preg_match('/^(.+\.[A-Za-z0-9]+):(.+)$/', $text, $m)) {
        $anchor = trim($m[2]);
        return [
            'file' => trim($m[1]),
            'line' => null,
            'endLine' => null,
            'anchor' => $anchor !== '' ? $anchor : null,
        ];
    }

    return [
        'file' => $text,
        'line' => null,
        'endLine' => null,
        'anchor' => null,
    ];
}

/**
 * @return list<string>
 */
function list_doc_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $skip = [
        'phase1-responses.md' => true,
        '.ds_store' => true,
        'thumbs.db' => true,
    ];
    $files = [];

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (isset($skip[strtolower($name)])) {
            continue;
        }
        if (!is_source_doc_file($name)) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            $files[] = $name;
        }
    }

    natcasesort($files);
    return array_values($files);
}

/**
 * @return array<string, mixed>|null
 */
function load_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $data
 */
function save_json_file(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . "\n") !== false;
}

/**
 * @param mixed $links
 * @return list<array{file: string, note: string}>
 */
function normalize_moc_links($links): array
{
    $out = [];
    if (!is_array($links)) {
        return $out;
    }
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $file = trim((string) ($link['file'] ?? ''));
        if ($file === '') {
            continue;
        }
        $out[] = [
            'file' => $file,
            'note' => trim((string) ($link['note'] ?? '')),
        ];
    }
    return $out;
}

/**
 * @param mixed $sources
 * @return list<string>
 */
function normalize_sources($sources): array
{
    $out = [];
    if (!is_array($sources)) {
        return $out;
    }
    foreach ($sources as $src) {
        $text = trim((string) $src);
        if ($text !== '') {
            $out[] = $text;
        }
    }
    return $out;
}

/**
 * @param mixed $order
 * @return list<string>
 */
function normalize_order($order): array
{
    $out = [];
    if (!is_array($order)) {
        return $out;
    }
    foreach ($order as $name) {
        $text = trim((string) $name);
        if ($text !== '') {
            $out[] = $text;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function normalize_keypoint(array $item): array
{
    return [
        'id' => trim((string) ($item['id'] ?? '')),
        'title' => (string) ($item['title'] ?? 'Untitled'),
        'body' => (string) ($item['body'] ?? ''),
        'sources' => normalize_sources($item['sources'] ?? []),
        'checked' => !empty($item['checked']),
    ];
}

/**
 * @param array<string, mixed>|null $data
 * @return array{
 *   kind: string,
 *   title: string,
 *   readingGuides: list<array<string, mixed>>,
 *   moc: list<array<string, mixed>>,
 *   documents: array<string, array<string, mixed>>
 * }
 */
function normalize_nav_pack(?array $data): array
{
    $out = [
        'kind' => 'mixed',
        'title' => '',
        'readingGuides' => [],
        'moc' => [],
        'documents' => [],
    ];
    if (!is_array($data)) {
        return $out;
    }

    $kind = trim((string) ($data['kind'] ?? 'mixed'));
    $allowed = ['technical' => true, 'legal' => true, 'financial' => true, 'mixed' => true];
    $out['kind'] = isset($allowed[$kind]) ? $kind : 'mixed';
    $out['title'] = trim((string) ($data['title'] ?? ''));

    $guides = $data['readingGuides'] ?? [];
    if (is_array($guides)) {
        foreach ($guides as $guide) {
            if (!is_array($guide)) {
                continue;
            }
            $id = trim((string) ($guide['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out['readingGuides'][] = [
                'id' => $id,
                'need' => (string) ($guide['need'] ?? ''),
                'howToRead' => (string) ($guide['howToRead'] ?? ''),
                'order' => normalize_order($guide['order'] ?? []),
                'checked' => !empty($guide['checked']),
            ];
        }
    }

    $moc = $data['moc'] ?? [];
    if (is_array($moc)) {
        foreach ($moc as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out['moc'][] = [
                'id' => $id,
                'title' => (string) ($node['title'] ?? 'Untitled'),
                'summary' => (string) ($node['summary'] ?? ''),
                'links' => normalize_moc_links($node['links'] ?? []),
                'checked' => !empty($node['checked']),
            ];
        }
    }

    $docs = $data['documents'] ?? [];
    if (is_array($docs)) {
        foreach ($docs as $filename => $doc) {
            $name = trim((string) $filename);
            if ($name === '' || !is_array($doc)) {
                continue;
            }
            $percent = (int) ($doc['coveragePercent'] ?? 0);
            if ($percent < 0) {
                $percent = 0;
            }
            if ($percent > 100) {
                $percent = 100;
            }
            $keypoints = [];
            $rawPoints = $doc['keypoints'] ?? [];
            if (is_array($rawPoints)) {
                foreach ($rawPoints as $point) {
                    if (!is_array($point)) {
                        continue;
                    }
                    $normalized = normalize_keypoint($point);
                    if ($normalized['id'] === '') {
                        continue;
                    }
                    $keypoints[] = $normalized;
                }
            }
            $out['documents'][$name] = [
                'provides' => (string) ($doc['provides'] ?? ''),
                'coveragePercent' => $percent,
                'coverageNote' => (string) ($doc['coverageNote'] ?? ''),
                'keypoints' => $keypoints,
                'checked' => !empty($doc['checked']),
            ];
        }
    }

    return $out;
}

/**
 * Merge checked flags from a GUI payload into an existing nav pack.
 *
 * @param array<string, mixed> $pack
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function apply_nav_checked(array $pack, array $payload): array
{
    $pack = normalize_nav_pack($pack);
    $checked = $payload['checked'] ?? null;
    if (!is_array($checked)) {
        return $pack;
    }

    $guideChecked = $checked['guides'] ?? [];
    if (is_array($guideChecked)) {
        foreach ($pack['readingGuides'] as $i => $guide) {
            $id = $guide['id'];
            if (array_key_exists($id, $guideChecked)) {
                $pack['readingGuides'][$i]['checked'] = !empty($guideChecked[$id]);
            }
        }
    }

    $mocChecked = $checked['moc'] ?? [];
    if (is_array($mocChecked)) {
        foreach ($pack['moc'] as $i => $node) {
            $id = $node['id'];
            if (array_key_exists($id, $mocChecked)) {
                $pack['moc'][$i]['checked'] = !empty($mocChecked[$id]);
            }
        }
    }

    $docChecked = $checked['documents'] ?? [];
    if (is_array($docChecked)) {
        foreach ($pack['documents'] as $name => $doc) {
            if (array_key_exists($name, $docChecked) && is_array($docChecked[$name])) {
                if (array_key_exists('_doc', $docChecked[$name])) {
                    $pack['documents'][$name]['checked'] = !empty($docChecked[$name]['_doc']);
                }
                $kpChecked = $docChecked[$name]['keypoints'] ?? [];
                if (is_array($kpChecked)) {
                    foreach ($pack['documents'][$name]['keypoints'] as $i => $kp) {
                        $id = $kp['id'];
                        if (array_key_exists($id, $kpChecked)) {
                            $pack['documents'][$name]['keypoints'][$i]['checked'] = !empty($kpChecked[$id]);
                        }
                    }
                }
            }
        }
    }

    return $pack;
}

function phase1_responses_path(string $dir): string
{
    return $dir . '/phase1-responses.md';
}

/**
 * @param mixed $data
 * @return array{guides: array<string, string>, documents: array<string, string>, keypoints: array<string, string>, moc: array<string, string>}
 */
function normalize_phase1_responses($data): array
{
    $out = [
        'guides' => [],
        'documents' => [],
        'keypoints' => [],
        'moc' => [],
    ];
    if (!is_array($data)) {
        return $out;
    }

    foreach (['guides', 'documents', 'keypoints', 'moc'] as $bucket) {
        $items = $data[$bucket] ?? null;
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $id => $text) {
            $key = trim((string) $id);
            $body = trim((string) $text);
            if ($key === '' || $body === '') {
                continue;
            }
            $out[$bucket][$key] = $body;
        }
    }

    return $out;
}

/**
 * @return array{guides: array<string, string>, documents: array<string, string>, keypoints: array<string, string>, moc: array<string, string>}
 */
function parse_phase1_responses_md(string $raw): array
{
    $out = [
        'guides' => [],
        'documents' => [],
        'keypoints' => [],
        'moc' => [],
    ];

    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    $section = null;
    $buf = [];

    $flush = static function () use (&$section, &$buf, &$out): void {
        if ($section === null) {
            $buf = [];
            return;
        }
        $text = trim(implode("\n", $buf));
        $buf = [];
        if ($text === '') {
            return;
        }
        if (strpos($section, 'guide:') === 0) {
            $out['guides'][substr($section, 6)] = $text;
            return;
        }
        if (strpos($section, 'doc:') === 0) {
            $out['documents'][substr($section, 4)] = $text;
            return;
        }
        if (strpos($section, 'keypoint:') === 0) {
            $out['keypoints'][substr($section, 9)] = $text;
            return;
        }
        if (strpos($section, 'moc:') === 0) {
            $out['moc'][substr($section, 4)] = $text;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^##\s+Guide:\s*(\S+)\s*$/i', $line, $m)) {
            $flush();
            $section = 'guide:' . trim($m[1]);
            continue;
        }
        if (preg_match('/^##\s+Doc:\s*(.+?)\s*$/i', $line, $m)) {
            $flush();
            $section = 'doc:' . trim($m[1]);
            continue;
        }
        if (preg_match('/^##\s+Keypoint:\s*(\S+)\s*$/i', $line, $m)) {
            $flush();
            $section = 'keypoint:' . trim($m[1]);
            continue;
        }
        if (preg_match('/^##\s+Moc:\s*(\S+)\s*$/i', $line, $m)) {
            $flush();
            $section = 'moc:' . trim($m[1]);
            continue;
        }
        if (preg_match('/^#\s+/', $line) && $section === null) {
            continue;
        }
        if ($section !== null) {
            $buf[] = $line;
        }
    }
    $flush();

    return normalize_phase1_responses($out);
}

/**
 * @param array{guides: array<string, string>, documents: array<string, string>, keypoints: array<string, string>, moc: array<string, string>} $data
 */
function format_phase1_responses_md(array $data): string
{
    $data = normalize_phase1_responses($data);
    $parts = ['# Phase 1 responses', ''];

    foreach ($data['guides'] as $id => $text) {
        $parts[] = '## Guide: ' . $id;
        $parts[] = '';
        $parts[] = $text;
        $parts[] = '';
    }
    foreach ($data['documents'] as $id => $text) {
        $parts[] = '## Doc: ' . $id;
        $parts[] = '';
        $parts[] = $text;
        $parts[] = '';
    }
    foreach ($data['keypoints'] as $id => $text) {
        $parts[] = '## Keypoint: ' . $id;
        $parts[] = '';
        $parts[] = $text;
        $parts[] = '';
    }
    foreach ($data['moc'] as $id => $text) {
        $parts[] = '## Moc: ' . $id;
        $parts[] = '';
        $parts[] = $text;
        $parts[] = '';
    }

    $md = rtrim(implode("\n", $parts));
    return $md === '# Phase 1 responses' ? "# Phase 1 responses\n" : $md . "\n";
}

/**
 * @return array{guides: array<string, string>, documents: array<string, string>, keypoints: array<string, string>, moc: array<string, string>}
 */
function load_phase1_responses(string $dir): array
{
    $path = phase1_responses_path($dir);
    if (!is_file($path)) {
        return normalize_phase1_responses(null);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return normalize_phase1_responses(null);
    }
    return parse_phase1_responses_md($raw);
}

/**
 * @param array<string, mixed> $data
 */
function save_phase1_responses(string $dir, array $data): bool
{
    $normalized = normalize_phase1_responses($data);
    $md = format_phase1_responses_md($normalized);
    $path = phase1_responses_path($dir);

    $empty = $normalized['guides'] === []
        && $normalized['documents'] === []
        && $normalized['keypoints'] === []
        && $normalized['moc'] === [];

    if ($empty) {
        if (is_file($path)) {
            return unlink($path);
        }
        return true;
    }

    return file_put_contents($path, $md) !== false;
}

/**
 * @param array<string, mixed> $pack
 * @return array{guides: int, documents: int, keypoints: int, moc: int}
 */
function nav_review_counts(array $pack): array
{
    $keypoints = 0;
    foreach ($pack['documents'] as $doc) {
        $keypoints += count($doc['keypoints'] ?? []);
    }
    return [
        'guides' => count($pack['readingGuides']),
        'documents' => count($pack['documents']),
        'keypoints' => $keypoints,
        'moc' => count($pack['moc']),
    ];
}
