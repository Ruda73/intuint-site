<?php
/**
 * Intuint Leadership Survey: receives one response from survey/index.html.
 *
 * Each response is
 *   1. appended as one JSON line to a file outside the public web folder
 *      (~/intuint-data/survey-responses.jsonl), and
 *   2. emailed to NOTIFY_TO.
 *
 * Works on ordinary PHP shared hosting; no database needed.
 * The page sends JSON first, then retries as a form field "payload".
 */

const NOTIFY_TO   = 'rudolph@intuint.co.za';
const MAIL_FROM   = 'survey@intuint.co.za';
const MAX_BYTES   = 20000;   // one response is ~2 KB
const MAX_PER_IP  = 40;      // per hour; a whole leadership team often shares one office IP
const SURVEY_ID   = 'intuint-leadership-v1';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
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

// Read the body: raw JSON, or the "payload" form field.
$raw = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
if (isset($_POST['payload'])) {
    $raw = (string) $_POST['payload'];
}
if ($raw === false || $raw === '' || strlen($raw) > MAX_BYTES) {
    reply(413, ['ok' => false, 'code' => 'too_large_or_empty']);
}

$data = json_decode($raw, true);
if (!is_array($data) || ($data['survey'] ?? '') !== SURVEY_ID || !is_array($data['answers'] ?? null)) {
    reply(400, ['ok' => false, 'code' => 'invalid_payload']);
}

// Storage lives outside the public folder (public_html), so it can't be downloaded.
$home = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$dir  = $home . '/intuint-data';
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    reply(500, ['ok' => false, 'code' => 'storage_unavailable']);
}

// Simple per-IP hourly limit (stores only a hash of the IP).
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|intuint');
$rateFile = $dir . '/rate.json';
$now = time();
$rate = is_file($rateFile) ? (json_decode((string) @file_get_contents($rateFile), true) ?: []) : [];
$rate = array_filter($rate, fn($t) => is_array($t) && count(array_filter($t, fn($x) => $x > $now - 3600)) > 0);
$hits = array_values(array_filter($rate[$ipHash] ?? [], fn($x) => $x > $now - 3600));
if (count($hits) >= MAX_PER_IP) {
    reply(429, ['ok' => false, 'code' => 'rate_limited']);
}
$hits[] = $now;
$rate[$ipHash] = $hits;
@file_put_contents($rateFile, json_encode($rate), LOCK_EX);

// Keep only plain scalar answers, trimmed.
$answers = [];
foreach ($data['answers'] as $k => $v) {
    if (!is_string($k) || strlen($k) > 64 || !(is_scalar($v) || $v === null)) continue;
    $answers[$k] = is_string($v) ? mb_substr(trim($v), 0, 2000) : $v;
}

$record = [
    'id'           => bin2hex(random_bytes(8)),
    'received_at'  => gmdate('c'),
    'submitted_at' => is_string($data['submitted_at'] ?? null) ? substr($data['submitted_at'], 0, 40) : null,
    'survey'       => SURVEY_ID,
    'answers'      => $answers,
];
$line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (@file_put_contents($dir . '/survey-responses.jsonl', $line, FILE_APPEND | LOCK_EX) === false) {
    reply(500, ['ok' => false, 'code' => 'write_failed']);
}

// Email a readable copy. A failed email doesn't lose the response: it's already saved.
// Every answer is visitor-supplied: strip control characters and cap length before it goes in a mail header.
$clean = fn($v, int $max) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v)), 0, $max);
$overall = $clean($answers['score_overall'] ?? '?', 8);
$who = !empty($answers['consent']) && !empty($answers['contact_name']) ? ' from ' . $clean($answers['contact_name'], 80) : '';
$subject = mb_encode_mimeheader('New Intuint survey response' . $who . " (overall {$overall})", 'UTF-8');
$body = "A new Leadership Survey response was saved.\n\nResponse ID: {$record['id']}\nReceived: {$record['received_at']}\n\n";
foreach ($answers as $k => $v) {
    $body .= str_pad($k, 26) . ': ' . (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) . "\n";
}
$headers = "From: Intuint Survey <" . MAIL_FROM . ">\r\nContent-Type: text/plain; charset=utf-8";
$replyTo = $answers['contact_email'] ?? '';
if (!empty($answers['consent']) && is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    $headers .= "\r\nReply-To: " . $replyTo;
}
@mail(NOTIFY_TO, $subject, $body, $headers);

reply(200, ['ok' => true, 'id' => $record['id']]);
