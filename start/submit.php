<?php
/**
 * Know the Score: seals a member's five gut calls, and later records outcomes.
 * Called by start/index.html.
 *
 *   {"action":"seal", "job_title", "occupation", "member" (Skool name, optional),
 *    "email" (optional), "answers":[{"question_id","gut_answer","confidence"}]}
 *     -> {"ok":true, "id", "token", "sealed_at"}
 *   {"action":"outcome", "id", "token", "question_id", "outcome"}
 *     -> {"ok":true, "gut_was_right"}
 *
 * Storage: append-only JSON lines outside the public web folder
 * (~/intuint-data/start-sealed.jsonl and start-outcomes.jsonl). A sealed
 * call is never rewritten; each outcome can be recorded once. Only the
 * browser holding the token from the seal can add outcomes to it.
 */

const NOTIFY_TO  = 'rudolph@intuint.co.za';
const MAIL_FROM  = 'start@intuint.co.za';
const MAX_BYTES  = 8000;
const SEALS_PER_HOUR    = 10;
const OUTCOMES_PER_HOUR = 60;
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
$sealedFile  = $dir . '/start-sealed.jsonl';
$outcomeFile = $dir . '/start-outcomes.jsonl';

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
    rate_limit($dir, 'seal', SEALS_PER_HOUR);

    $token = bin2hex(random_bytes(16));
    $record = [
        'id'          => bin2hex(random_bytes(8)),
        'token_hash'  => hash('sha256', $token),
        'sealed_at'   => gmdate('c'),
        'job_title'   => clean($data['job_title'] ?? '', 120),
        'occupation'  => $occ,
        'member'      => clean($data['member'] ?? '', 120),
        'email'       => $email,
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

    reply(200, ['ok' => true, 'id' => $record['id'], 'token' => $token, 'sealed_at' => $record['sealed_at']]);
}

if ($action === 'outcome') {
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
    $answer = null;
    foreach ($seal['answers'] as $a) if ($a['question_id'] === $qid) $answer = $a;
    if (!$answer) {
        reply(400, ['ok' => false, 'code' => 'unknown_question']);
    }
    $q = null;
    foreach ($questionnaires[$seal['occupation']]['questions'] as $qq) if ($qq['id'] === $qid) $q = $qq;
    $outcome = $data['outcome'] ?? null;
    if (!$q || !in_array($outcome, $q['options'], true)) {
        reply(400, ['ok' => false, 'code' => 'invalid_outcome']);
    }
    if (find_lines($outcomeFile, fn($r) => ($r['id'] ?? '') === $id && ($r['question_id'] ?? '') === $qid)) {
        reply(409, ['ok' => false, 'code' => 'outcome_already_recorded']);
    }
    $rec = ['id' => $id, 'question_id' => $qid, 'outcome' => $outcome,
            'gut_was_right' => $answer['gut_answer'] === $outcome, 'recorded_at' => gmdate('c')];
    if (@file_put_contents($outcomeFile, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) {
        reply(500, ['ok' => false, 'code' => 'write_failed']);
    }
    reply(200, ['ok' => true, 'gut_was_right' => $rec['gut_was_right']]);
}

reply(400, ['ok' => false, 'code' => 'unknown_action']);
