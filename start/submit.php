<?php
/**
 * Know the Score: seals a member's five gut calls, and later records outcomes.
 * Called by start/index.html.
 *
 *   {"action":"seal", "job_title", "occupation", "member" (Skool name, optional),
 *    "email" (optional; needs "consent": true), "client_sealed_at" (set when a
 *    browser retries a seal it couldn't send earlier),
 *    "answers":[{"question_id","gut_answer","confidence"}]}
 *     -> {"ok":true, "id", "token", "sealed_at"}
 *   {"action":"load", "id", "token"}
 *     -> {"ok":true, "record"}   (the member's personal link reloads their calls on any device)
 *   {"action":"analysis", "id", "token", "question_id", "answer", "confidence"}
 *     -> {"ok":true}            (after checking the numbers; before the outcome)
 *   {"action":"outcome", "id", "token", "question_id", "outcome"}
 *     -> {"ok":true, "gut_was_right", "analysis_was_right" (null if no analysis)}
 *
 * Order is enforced: gut (sealed) -> analysis (optional, once) -> outcome (once).
 * Storage: append-only JSON lines outside the public web folder
 * (~/intuint-data/start-sealed.jsonl, start-analysis.jsonl, start-outcomes.jsonl).
 * Nothing is rewritten. Only the browser holding the token from the seal can
 * add an analysis or outcome to it.
 */

const NOTIFY_TO  = 'rudolph@intuint.co.za';
const MAIL_FROM  = 'start@intuint.co.za';
const MAX_BYTES  = 8000;
const SEALS_PER_HOUR    = 40;    // a whole shop on one Wi-Fi must fit
const OUTCOMES_PER_HOUR = 300;
const LOADS_PER_HOUR    = 120;
const SITE = 'https://www.intuint.co.za';
const CONFIDENCE = [50, 70, 90];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function clean(?string $s, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s);
    return mb_substr(trim($s), 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'code' => 'method_not_allowed']);
}

// Only accept posts from this site's own pages.
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
    reply(403, ['ok' => false, 'code' => 'bad_origin']);
}

$raw = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
if (isset($_POST['payload'])) {
    $raw = (string) $_POST['payload'];
}
if ($raw === false || $raw === '' || strlen($raw) > MAX_BYTES) {
    reply(413, ['ok' => false, 'code' => 'too_large_or_empty']);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    reply(400, ['ok' => false, 'code' => 'invalid_payload']);
}

$questionnaires = json_decode((string) @file_get_contents(__DIR__ . '/questionnaires.json'), true);
if (!is_array($questionnaires)) {
    reply(500, ['ok' => false, 'code' => 'questionnaires_missing']);
}

// Storage lives outside public_html so it can't be downloaded.
$dir = getenv('INTUINT_DATA_DIR') ?: dirname($_SERVER['DOCUMENT_ROOT'] ?: __DIR__) . '/intuint-data';
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    reply(500, ['ok' => false, 'code' => 'storage_unavailable']);
}
$sealedFile   = $dir . '/start-sealed.jsonl';
$analysisFile = $dir . '/start-analysis.jsonl';
$outcomeFile  = $dir . '/start-outcomes.jsonl';

function rate_limit(string $dir, string $bucket, int $max): void {
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|intuint|' . $bucket);
    $file = $dir . '/rate-start.json';
    $now = time();
    $fh = fopen($file, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $rate = json_decode((string) stream_get_contents($fh), true) ?: [];
    foreach ($rate as $k => $times) {
        $rate[$k] = array_values(array_filter((array) $times, fn($t) => $t > $now - 3600));
        if (!$rate[$k]) unset($rate[$k]);
    }
    $hits = $rate[$ipHash] ?? [];
    if (count($hits) >= $max) {
        flock($fh, LOCK_UN); fclose($fh);
        reply(429, ['ok' => false, 'code' => 'rate_limited']);
    }
    $rate[$ipHash] = array_merge($hits, [$now]);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rate));
    flock($fh, LOCK_UN); fclose($fh);
}

function find_lines(string $file, callable $match): array {
    $found = [];
    if (!is_file($file)) return $found;
    $fh = fopen($file, 'r');
    while (($line = fgets($fh)) !== false) {
        $rec = json_decode($line, true);
        if (is_array($rec) && $match($rec)) $found[] = $rec;
    }
    fclose($fh);
    return $found;
}

$action = $data['action'] ?? '';

if ($action === 'seal') {
    $occ = $data['occupation'] ?? '';
    if (!is_string($occ) || !isset($questionnaires[$occ])) {
        reply(400, ['ok' => false, 'code' => 'unknown_occupation']);
    }
    $questions = [];
    foreach ($questionnaires[$occ]['questions'] as $q) $questions[$q['id']] = $q;
    $answers = [];
    foreach ((array) ($data['answers'] ?? []) as $a) {
        $q = $questions[$a['question_id'] ?? ''] ?? null;
        if (!$q || !in_array($a['gut_answer'] ?? null, $q['options'], true)
            || !in_array($a['confidence'] ?? null, CONFIDENCE, true)
            || isset($answers[$q['id']])) {
            reply(400, ['ok' => false, 'code' => 'invalid_answer']);
        }
        $answers[$q['id']] = ['question_id' => $q['id'], 'gut_answer' => $a['gut_answer'], 'confidence' => $a['confidence']];
    }
    if (!$answers) {
        reply(400, ['ok' => false, 'code' => 'no_answers']);
    }
    $email = clean($data['email'] ?? '', 200);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        reply(400, ['ok' => false, 'code' => 'invalid_email']);
    }
    $member = clean($data['member'] ?? '', 120);
    $consent = ($data['consent'] ?? false) === true;
    if (($email !== '' || $member !== '') && !$consent) {
        reply(400, ['ok' => false, 'code' => 'consent_required']);
    }
    $clientSealed = clean($data['client_sealed_at'] ?? '', 40);
    if ($clientSealed !== '' && !preg_match('/^\d{4}-\d\d-\d\dT[\d:.]+Z?$/', $clientSealed)) $clientSealed = '';
    rate_limit($dir, 'seal', SEALS_PER_HOUR);

    $token = bin2hex(random_bytes(16));
    $record = [
        'id'          => bin2hex(random_bytes(8)),
        'token_hash'  => hash('sha256', $token),
        'sealed_at'   => gmdate('c'),
        'job_title'   => clean($data['job_title'] ?? '', 120),
        'occupation'  => $occ,
        'client_sealed_at' => $clientSealed ?: null,   // set only for late-sent seals; not proof of timing
        'member'      => $member,
        'email'       => $email,
        'consent'     => $consent ? gmdate('c') : null,
        'answers'     => array_values($answers),
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($sealedFile, $line, FILE_APPEND | LOCK_EX) === false) {
        reply(500, ['ok' => false, 'code' => 'write_failed']);
    }

    $who = $record['member'] ?: ($record['email'] ?: 'someone');
    $body = "New sealed calls on /start/.\n\nWho: {$who}\nJob: {$record['job_title']}\nTrack: {$occ}\nSealed: {$record['sealed_at']}\nID: {$record['id']}\n\n";
    foreach ($record['answers'] as $a) {
        $body .= "- {$questions[$a['question_id']]['prompt']}\n  Gut: {$a['gut_answer']} ({$a['confidence']}%)\n";
    }
    @mail(NOTIFY_TO, 'Sealed calls: ' . preg_replace('/[\r\n]+/', ' ', $who) . " ({$occ})", $body,
          "From: Know the Score <" . MAIL_FROM . ">\r\nContent-Type: text/plain; charset=utf-8");

    // Send the member their personal link, so they can open their calls on any device.
    if ($email !== '') {
        $link = SITE . "/start/?ref={$record['id']}.{$token}";
        @mail($email, 'Your sealed calls (Know the Score)',
              "Your five calls are sealed.\n\nOpen them on any device, add your analysis and log outcomes here:\n{$link}\n\nKeep this link private: anyone with it can log outcomes for these calls.\n\nRudolph\nIntuint. Know the score.\n",
              "From: Know the Score <" . MAIL_FROM . ">\r\nReply-To: " . NOTIFY_TO . "\r\nContent-Type: text/plain; charset=utf-8");
    }

    reply(200, ['ok' => true, 'id' => $record['id'], 'token' => $token, 'sealed_at' => $record['sealed_at']]);
}

if ($action === 'load') {
    $id = (string) ($data['id'] ?? '');
    $token = (string) ($data['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $id) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        reply(400, ['ok' => false, 'code' => 'invalid_reference']);
    }
    rate_limit($dir, 'load', LOADS_PER_HOUR);
    $seal = find_lines($sealedFile, fn($r) => ($r['id'] ?? '') === $id)[0] ?? null;
    if (!$seal || !hash_equals($seal['token_hash'], hash('sha256', $token))) {
        reply(404, ['ok' => false, 'code' => 'not_found']);
    }
    $analysis = []; foreach (find_lines($analysisFile, fn($r) => ($r['id'] ?? '') === $id) as $r) $analysis[$r['question_id']] = $r;
    $outcomes = []; foreach (find_lines($outcomeFile, fn($r) => ($r['id'] ?? '') === $id) as $r) $outcomes[$r['question_id']] = $r;
    $byId = [];
    foreach ($questionnaires[$seal['occupation']]['questions'] ?? [] as $q) $byId[$q['id']] = $q;
    $answers = [];
    foreach ($seal['answers'] as $a) {
        $q = $byId[$a['question_id']] ?? null;
        if (!$q) continue;
        $an = $analysis[$a['question_id']] ?? null;
        $answers[] = [
            'question_id' => $a['question_id'], 'prompt' => $q['prompt'], 'outcome_prompt' => $q['outcome_prompt'],
            'options' => $q['options'], 'know_in_days' => $q['know_in_days'] ?? null,
            'gut_answer' => $a['gut_answer'], 'confidence' => $a['confidence'],
            'analysis' => $an ? ['answer' => $an['analysis_answer'], 'confidence' => $an['confidence']] : null,
            'outcome' => $outcomes[$a['question_id']]['outcome'] ?? null,
        ];
    }
    reply(200, ['ok' => true, 'record' => [
        'id' => $id, 'token' => $token, 'sent' => true, 'sealed_at' => $seal['sealed_at'],
        'occupation' => $seal['occupation'], 'job_title' => $seal['job_title'],
        'track_title' => $questionnaires[$seal['occupation']]['title'] ?? $seal['occupation'],
        'answers' => $answers,
    ]]);
}

if ($action === 'analysis' || $action === 'outcome') {
    $id = (string) ($data['id'] ?? '');
    $token = (string) ($data['token'] ?? '');
    $qid = (string) ($data['question_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $id) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        reply(400, ['ok' => false, 'code' => 'invalid_reference']);
    }
    rate_limit($dir, 'outcome', OUTCOMES_PER_HOUR);
    $seal = find_lines($sealedFile, fn($r) => ($r['id'] ?? '') === $id)[0] ?? null;
    if (!$seal || !hash_equals($seal['token_hash'], hash('sha256', $token))) {
        reply(404, ['ok' => false, 'code' => 'not_found']);
    }
    $gut = null;
    foreach ($seal['answers'] as $a) if ($a['question_id'] === $qid) $gut = $a;
    $q = null;
    foreach ($questionnaires[$seal['occupation']]['questions'] as $qq) if ($qq['id'] === $qid) $q = $qq;
    if (!$gut || !$q) {
        reply(400, ['ok' => false, 'code' => 'unknown_question']);
    }
    $same = fn($r) => ($r['id'] ?? '') === $id && ($r['question_id'] ?? '') === $qid;
    $outcomeDone = find_lines($outcomeFile, $same);
    $analysis = find_lines($analysisFile, $same)[0] ?? null;

    if ($action === 'analysis') {
        if ($outcomeDone) reply(409, ['ok' => false, 'code' => 'outcome_already_recorded']);
        if ($analysis)    reply(409, ['ok' => false, 'code' => 'analysis_already_recorded']);
        $ans = $data['answer'] ?? null;
        if (!in_array($ans, $q['options'], true) || !in_array($data['confidence'] ?? null, CONFIDENCE, true)) {
            reply(400, ['ok' => false, 'code' => 'invalid_answer']);
        }
        $rec = ['id' => $id, 'question_id' => $qid, 'analysis_answer' => $ans,
                'confidence' => $data['confidence'], 'recorded_at' => gmdate('c')];
        if (@file_put_contents($analysisFile, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) {
            reply(500, ['ok' => false, 'code' => 'write_failed']);
        }
        reply(200, ['ok' => true]);
    }

    $outcome = $data['outcome'] ?? null;
    if (!in_array($outcome, $q['options'], true)) {
        reply(400, ['ok' => false, 'code' => 'invalid_outcome']);
    }
    if ($outcomeDone) {
        reply(409, ['ok' => false, 'code' => 'outcome_already_recorded']);
    }
    $rec = ['id' => $id, 'question_id' => $qid, 'outcome' => $outcome,
            'gut_was_right' => $gut['gut_answer'] === $outcome,
            'analysis_was_right' => $analysis ? $analysis['analysis_answer'] === $outcome : null,
            'recorded_at' => gmdate('c')];
    if (@file_put_contents($outcomeFile, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) {
        reply(500, ['ok' => false, 'code' => 'write_failed']);
    }
    reply(200, ['ok' => true, 'gut_was_right' => $rec['gut_was_right'], 'analysis_was_right' => $rec['analysis_was_right']]);
}

reply(400, ['ok' => false, 'code' => 'unknown_action']);
