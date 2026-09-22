<?php
/**
 * Kurage Kintai (kkintai) — Kurage 勤怠システム。顔打刻つき・1ファイルPHP。
 *
 * 入口のタブレット/PCのブラウザで顔打刻(出勤/退勤/休憩)→写真証跡を保存→
 * 管理画面で台帳・月次集計・給与ソフト向けCSV。DBサーバー不要(SQLite)・
 * レンタルサーバーで動く。
 *
 * 【設計の芯】(kcrmagent / kaima と同じ思想)
 *  1. 顔照合は「サーバーが正」— ブラウザは顔の特徴量(128次元)を計算するだけで、
 *     誰かの判定はサーバーが登録済みデータと照合して決める(決定的なコード。AIの
 *     自己申告を信用しない)。しきい値未満は照合失敗→名前タップにフォールバック。
 *  2. 入口が違っても同じ関門(kk_can)を通る — 打刻端末(kiosk)は打刻の作成だけ。
 *     社員・顔データ・台帳の修正は管理者だけ。全操作を監査ログに記録。
 *  3. 打刻は丸めない — 記録は分単位の生データ。集計時も丸め処理をしない
 *     (打刻の切り捨ては労務リスク)。修正は管理者操作として履歴に残る。
 *  4. 顔特徴量は個人識別符号(個人情報保護法) — 登録時に本人同意を記録し、
 *     削除機能(同意撤回)を備える。証跡写真はログイン済み管理者にのみ配信。
 *
 * 構成: kkintai.php(本体) + kkintai_config.php(設定) + kkintai_assets/(顔認識
 * ライブラリface-api.js+モデル・MITライセンス同梱) + kkintai_data/(データ)。
 * PHP 7.0+ / pdo_sqlite / gd。ブラウザ側はHTTPS必須(カメラAPIの仕様)。
 */

date_default_timezone_set('Asia/Tokyo');

$cfg = __DIR__ . '/kkintai_config.php';
if (!is_file($cfg)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'kkintai_config.php がありません。kkintai_config.php.example をコピーして作成してください。';
    exit;
}
require $cfg;

if (!defined('KKINTAI_TITLE'))          { define('KKINTAI_TITLE', 'Kurage 勤怠システム'); }
if (!defined('KKINTAI_BRAND_COLOR'))    { define('KKINTAI_BRAND_COLOR', '#2c6e49'); }
if (!defined('KKINTAI_PASSWORD'))       { define('KKINTAI_PASSWORD', ''); }
if (!defined('KKINTAI_PASSWORD_HASH'))  { define('KKINTAI_PASSWORD_HASH', ''); }
if (!defined('KKINTAI_KIOSK_TOKEN'))    { define('KKINTAI_KIOSK_TOKEN', ''); }
if (!defined('KKINTAI_FACE_THRESHOLD')) { define('KKINTAI_FACE_THRESHOLD', 0.5); }
if (!defined('KKINTAI_WORK_START'))     { define('KKINTAI_WORK_START', '09:00'); }
if (!defined('KKINTAI_WORK_END'))       { define('KKINTAI_WORK_END', '18:00'); }
if (!defined('KKINTAI_STANDARD_MIN'))   { define('KKINTAI_STANDARD_MIN', 480); }
if (!defined('KKINTAI_RATE_PER_HOUR'))  { define('KKINTAI_RATE_PER_HOUR', 240); }
if (!defined('KKINTAI_DEMO'))           { define('KKINTAI_DEMO', false); }
if (!defined('KKINTAI_DATA_DIR'))       { define('KKINTAI_DATA_DIR', __DIR__ . '/kkintai_data'); }

/* ================= ユーティリティ ================= */

function kk_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kk_json_out($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function kk_now() { return date('Y-m-d H:i:s'); }

/* ================= DB ================= */

function kk_pdo() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    if (!is_dir(KKINTAI_DATA_DIR)) { @mkdir(KKINTAI_DATA_DIR, 0755, true); }
    if (!is_dir(KKINTAI_DATA_DIR . '/img')) { @mkdir(KKINTAI_DATA_DIR . '/img', 0755, true); }
    $pdo = new PDO('sqlite:' . KKINTAI_DATA_DIR . '/kkintai.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=8000');
    kk_schema($pdo);
    return $pdo;
}

function kk_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, code TEXT NOT NULL DEFAULT '',
        face_json TEXT NOT NULL DEFAULT '[]',
        consented_at TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS punches(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL REFERENCES employees(id),
        type TEXT NOT NULL,               -- in / out / brk_in / brk_out
        ts TEXT NOT NULL,                 -- 'Y-m-d H:i:s' 生データ(丸めない)
        pdate TEXT NOT NULL,              -- 'Y-m-d'
        photo TEXT NOT NULL DEFAULT '',
        method TEXT NOT NULL DEFAULT '',  -- face / name / admin
        distance REAL,
        edited INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL)");
}

function kk_audit($actor, $action, $detail) {
    $st = kk_pdo()->prepare('INSERT INTO audit(ts,actor,action,detail) VALUES(?,?,?,?)');
    $st->execute(array(kk_now(), $actor, $action, mb_substr((string)$detail, 0, 400, 'UTF-8')));
}

/* ================= 関門 ================= */

class KkDenied extends Exception {}

function kk_can($actor, $action) {
    $rules = array(
        'kiosk' => array('punch.create'),
        'admin' => array('punch.create', 'punch.edit', 'punch.delete',
                         'employee.create', 'employee.edit',
                         'face.enroll', 'face.delete', 'export'),
    );
    return isset($rules[$actor]) && in_array($action, $rules[$actor], true);
}

function kk_assert($actor, $action) {
    if (!kk_can($actor, $action)) {
        throw new KkDenied($actor . ' は ' . $action . ' を許可されていません');
    }
}

/* ================= 顔照合(サーバーが正・決定的) ================= */

/** 128次元ベクトルのユークリッド距離。 */
function kk_face_distance($a, $b) {
    $s = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $d = (float)$a[$i] - (float)$b[$i];
        $s += $d * $d;
    }
    return sqrt($s);
}

/** 特徴量の形式検証: 128個の数値・ノルムが妥当か。 */
function kk_face_valid($desc) {
    if (!is_array($desc) || count($desc) !== 128) { return false; }
    $norm = 0.0;
    foreach ($desc as $v) {
        if (!is_numeric($v) || abs($v) > 1.5) { return false; }
        $norm += $v * $v;
    }
    $norm = sqrt($norm);
    return $norm > 0.5 && $norm < 2.0;   // face-api.jsの特徴量はほぼ単位ノルム
}

/** 登録済み全社員と照合し、しきい値内の最良一致を返す。 */
function kk_face_match($pdo, $desc) {
    $best = null; $bestD = 1e9;
    $rows = $pdo->query("SELECT id, name, face_json FROM employees WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $faces = json_decode($r['face_json'], true);
        if (!is_array($faces)) { continue; }
        foreach ($faces as $f) {
            if (!is_array($f) || count($f) !== 128) { continue; }
            $d = kk_face_distance($desc, $f);
            if ($d < $bestD) { $bestD = $d; $best = $r; }
        }
    }
    if ($best !== null && $bestD <= (float)KKINTAI_FACE_THRESHOLD) {
        return array('id' => (int)$best['id'], 'name' => $best['name'], 'distance' => round($bestD, 4));
    }
    return null;
}

/* ================= 打刻 ================= */

$KK_TYPES = array('in' => '出勤', 'out' => '退勤', 'brk_in' => '休憩入', 'brk_out' => '休憩戻');

function kk_punch_create($actor, $employeeId, $type, $method, $photo = '', $distance = null, $ts = null) {
    kk_assert($actor, 'punch.create');
    global $KK_TYPES;
    if (!isset($KK_TYPES[$type])) { return array('error' => '不明な打刻種別です', 'code' => 400); }
    $pdo = kk_pdo();
    $st = $pdo->prepare('SELECT id, name FROM employees WHERE id=? AND active=1');
    $st->execute(array((int)$employeeId));
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) { return array('error' => '社員が見つかりません', 'code' => 404); }
    $ts = $ts ?: kk_now();
    $pdate = substr($ts, 0, 10);
    // 同種別の連続二重打刻(1分以内)は弾く
    $st = $pdo->prepare("SELECT ts FROM punches WHERE employee_id=? AND type=? AND pdate=? ORDER BY id DESC LIMIT 1");
    $st->execute(array((int)$employeeId, $type, $pdate));
    $last = $st->fetchColumn();
    if ($last && (strtotime($ts) - strtotime($last)) < 60) {
        return array('error' => '直前に同じ打刻があります(二重打刻防止)', 'code' => 409);
    }
    $pdo->prepare('INSERT INTO punches(employee_id,type,ts,pdate,photo,method,distance,created_at) VALUES(?,?,?,?,?,?,?,?)')
        ->execute(array((int)$employeeId, $type, $ts, $pdate, $photo, $method, $distance, kk_now()));
    kk_audit($actor, 'punch.create', $emp['name'] . ' ' . $KK_TYPES[$type] . ' ' . $ts . ' (' . $method . ')');
    return array('employee' => $emp['name'], 'type_label' => $KK_TYPES[$type], 'ts' => $ts);
}

/* ================= 集計(丸めない・決定的) ================= */

/**
 * 1日ぶんの打刻列から労働時間を計算する。
 * in=最初の出勤 / out=最後の退勤 / 休憩=brk_in→brk_outのペア合計。
 * 戻り値: array(in,out,break_min,work_min,overtime_min,late,early,incomplete)
 */
function kk_day_calc($punches) {
    $in = null; $out = null; $break = 0; $brkStart = null;
    foreach ($punches as $p) {
        $t = strtotime($p['ts']);
        if ($p['type'] === 'in' && $in === null) { $in = $t; }
        if ($p['type'] === 'out') { $out = $t; }
        if ($p['type'] === 'brk_in') { $brkStart = $t; }
        if ($p['type'] === 'brk_out' && $brkStart !== null) {
            $break += max(0, (int)(($t - $brkStart) / 60));
            $brkStart = null;
        }
    }
    $work = null; $ot = null; $late = false; $early = false;
    $incomplete = ($in === null || $out === null || $brkStart !== null);
    if ($in !== null && $out !== null && $out > $in) {
        $work = max(0, (int)(($out - $in) / 60) - $break);
        $ot = max(0, $work - (int)KKINTAI_STANDARD_MIN);
    }
    if ($in !== null) {
        $ws = strtotime(date('Y-m-d', $in) . ' ' . KKINTAI_WORK_START . ':00');
        $late = $in > $ws;
    }
    if ($out !== null) {
        $we = strtotime(date('Y-m-d', $out) . ' ' . KKINTAI_WORK_END . ':00');
        $early = $out < $we;
    }
    return array('in' => $in ? date('H:i', $in) : '', 'out' => $out ? date('H:i', $out) : '',
        'break_min' => $break, 'work_min' => $work, 'overtime_min' => $ot,
        'late' => $late, 'early' => $early, 'incomplete' => $incomplete);
}

/** 社員×月の集計。 */
function kk_month_calc($pdo, $employeeId, $month) {
    $st = $pdo->prepare("SELECT * FROM punches WHERE employee_id=? AND pdate LIKE ? ORDER BY ts");
    $st->execute(array((int)$employeeId, $month . '%'));
    $byDay = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) { $byDay[$p['pdate']][] = $p; }
    $days = array(); $workSum = 0; $otSum = 0; $lateN = 0; $dayN = 0;
    ksort($byDay);
    foreach ($byDay as $d => $ps) {
        $c = kk_day_calc($ps);
        $days[$d] = $c;
        if ($c['work_min'] !== null) { $workSum += $c['work_min']; $otSum += $c['overtime_min']; $dayN++; }
        if ($c['late']) { $lateN++; }
    }
    return array('days' => $days, 'work_min' => $workSum, 'overtime_min' => $otSum,
        'day_count' => $dayN, 'late_count' => $lateN);
}

function kk_min_fmt($m) {
    if ($m === null) { return ''; }
    return floor($m / 60) . ':' . str_pad($m % 60, 2, '0', STR_PAD_LEFT);
}

/* ================= 写真証跡 ================= */

function kk_save_photo($b64) {
    if ($b64 === '' || strlen($b64) > 2 * 1024 * 1024) { return ''; }
    $bin = base64_decode($b64, true);
    if ($bin === false) { return ''; }
    $img = @imagecreatefromstring($bin);
    if (!$img) { return ''; }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    // 証跡は480pxで十分(容量対策)
    $w = imagesx($img); $h = imagesy($img);
    if (max($w, $h) > 480) {
        $r = 480 / max($w, $h);
        $img2 = imagescale($img, (int)($w * $r), (int)($h * $r));
        imagedestroy($img); $img = $img2;
    }
    imagejpeg($img, KKINTAI_DATA_DIR . '/img/' . $name, 82);
    imagedestroy($img);
    return $name;
}

/* ================= レート制限 ================= */

function kk_rate_ok($ip, $n = 1) {
    $f = KKINTAI_DATA_DIR . '/rate.json';
    if (!is_dir(KKINTAI_DATA_DIR)) { @mkdir(KKINTAI_DATA_DIR, 0755, true); }
    $key = substr(hash('sha256', $ip . '|kk'), 0, 16);
    $now = time();
    $max = (int)KKINTAI_RATE_PER_HOUR;
    if (KKINTAI_DEMO) { $max = min($max, 60); }
    $fp = fopen($f, 'c+');
    if (!$fp) { return true; }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $all = $raw ? json_decode($raw, true) : array();
    if (!is_array($all)) { $all = array(); }
    $hits = isset($all[$key]) ? $all[$key] : array();
    $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 3600; }));
    $ok = (count($hits) + $n) <= $max;
    if ($ok) {
        for ($i = 0; $i < $n; $i++) { $hits[] = $now; }
        $all[$key] = $hits;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($all));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

/* ================= 認証 ================= */

function kk_session_start() {
    if (session_status() === PHP_SESSION_NONE) { session_name('KKSESSID'); session_start(); }
}
function kk_logged_in() { kk_session_start(); return !empty($_SESSION['kk_ok']); }
function kk_try_login($pw) {
    if (KKINTAI_PASSWORD_HASH !== '') { return password_verify($pw, KKINTAI_PASSWORD_HASH); }
    if (KKINTAI_PASSWORD !== '') { return hash_equals(KKINTAI_PASSWORD, $pw); }
    return false;
}
function kk_csrf() {
    kk_session_start();
    if (empty($_SESSION['kk_csrf'])) { $_SESSION['kk_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['kk_csrf'];
}
function kk_csrf_ok() {
    kk_session_start();
    $t = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    return !empty($_SESSION['kk_csrf']) && hash_equals($_SESSION['kk_csrf'], $t);
}
function kk_kiosk_ok() {
    $t = isset($_GET['kiosk']) ? (string)$_GET['kiosk'] : (isset($_SERVER['HTTP_X_KK_KIOSK']) ? $_SERVER['HTTP_X_KK_KIOSK'] : '');
    return KKINTAI_KIOSK_TOKEN !== '' && $t !== '' && hash_equals(KKINTAI_KIOSK_TOKEN, $t);
}

/* ================= 写真配信(管理者のみ) ================= */

if (isset($_GET['img'])) {
    if (!kk_logged_in()) { http_response_code(403); exit('forbidden'); }
    $name = basename((string)$_GET['img']);
    $path = KKINTAI_DATA_DIR . '/img/' . $name;
    if (!is_file($path)) { http_response_code(404); exit('not found'); }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

/* ================= API ================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    if ($api === 'health') { kk_json_out(200, array('ok' => 1, 'app' => 'kkintai')); }

    if ($api === 'kiosk_state') {
        if (!kk_kiosk_ok()) { kk_json_out(401, array('error' => '端末トークンが違います')); }
        $pdo = kk_pdo();
        $emps = array();
        foreach ($pdo->query("SELECT id, name, face_json FROM employees WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $faces = json_decode($r['face_json'], true);
            $emps[] = array('id' => (int)$r['id'], 'name' => $r['name'],
                'enrolled' => is_array($faces) && count($faces) > 0);
        }
        kk_json_out(200, array('employees' => $emps, 'types' => $GLOBALS['KK_TYPES']));
    }

    if ($api === 'punch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { kk_json_out(405, array('error' => 'POST')); }
        if (!kk_kiosk_ok()) { kk_json_out(401, array('error' => '端末トークンが違います')); }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if (!kk_rate_ok($ip)) { kk_json_out(429, array('error' => '打刻が集中しています。少し待ってください')); }
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) { kk_json_out(400, array('error' => 'JSONを確認してください')); }
        $type = isset($in['type']) ? (string)$in['type'] : '';
        $photo = kk_save_photo(isset($in['photo']) ? (string)$in['photo'] : '');
        $pdo = kk_pdo();
        if (isset($in['descriptor'])) {
            // 顔照合(サーバーが正)
            if (!kk_face_valid($in['descriptor'])) { kk_json_out(422, array('error' => '顔データの形式が不正です')); }
            $m = kk_face_match($pdo, $in['descriptor']);
            if ($m === null) { kk_json_out(404, array('error' => 'unmatched', 'message' => '照合できませんでした。お名前をタップしてください')); }
            $r = kk_punch_create('kiosk', $m['id'], $type, 'face', $photo, $m['distance']);
        } elseif (isset($in['employee_id'])) {
            $r = kk_punch_create('kiosk', (int)$in['employee_id'], $type, 'name', $photo, null);
        } else {
            kk_json_out(400, array('error' => 'descriptorかemployee_idが必要です'));
        }
        if (isset($r['error'])) { kk_json_out($r['code'], array('error' => $r['error'])); }
        kk_json_out(200, $r);
    }

    kk_json_out(404, array('error' => '不明なAPIです'));
}

/* ================= 打刻端末(キオスク)画面 ================= */

if (isset($_GET['kiosk'])) {
    if (!kk_kiosk_ok()) { http_response_code(403); header('Content-Type: text/plain; charset=UTF-8'); exit('端末トークンが違います(kkintai_config.phpのKKINTAI_KIOSK_TOKEN)'); }
    $KTOK = kk_h($_GET['kiosk']);
    ?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>打刻 - <?php echo kk_h(KKINTAI_TITLE); ?></title>
<meta name="robots" content="noindex">
<style>
:root{--brand:<?php echo kk_h(KKINTAI_BRAND_COLOR); ?>}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,"Segoe UI","Noto Sans JP",sans-serif;background:#101d16;color:#fff;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:12px}
h1{font-size:17px;display:flex;align-items:center;gap:8px;margin:4px 0 8px}
h1 img{width:28px;height:28px;border-radius:50%}
#clock{font-size:34px;font-weight:900;letter-spacing:.04em}
#date{font-size:13px;color:#9fc3ad;margin-bottom:8px}
#cam{width:min(92vw,420px);border-radius:14px;border:3px solid var(--brand);background:#000}
#status{min-height:52px;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;text-align:center;padding:6px;color:#cfe8d8}
#status.ok{color:#7ee2a8}#status.ng{color:#ffb4a8}
.btns{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:min(92vw,420px);margin-top:4px}
.btns button{padding:20px 8px;font-size:20px;font-weight:900;border:none;border-radius:14px;cursor:pointer;color:#fff}
.b-in{background:#2c8c57}.b-out{background:#b3541e}.b-bi{background:#3a6ea5}.b-bo{background:#7a5aa0}
.btns button:disabled{opacity:.45}
#names{display:none;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:10px;width:min(92vw,420px)}
#names button{padding:12px 16px;font-size:16px;font-weight:700;border:none;border-radius:10px;background:#27563c;color:#fff;cursor:pointer}
.note{font-size:11px;color:#7fa78e;margin-top:10px;text-align:center;line-height:1.6}
</style></head>
<body>
<h1><img src="kkintai_assets/kurage_mascot.png" alt=""><?php echo kk_h(KKINTAI_TITLE); ?> 打刻</h1>
<div id="clock">--:--:--</div>
<div id="date"></div>
<video id="cam" autoplay muted playsinline></video>
<div id="status">カメラを起動しています…</div>
<div class="btns">
  <button class="b-in" data-t="in">出勤</button>
  <button class="b-out" data-t="out">退勤</button>
  <button class="b-bi" data-t="brk_in">休憩入</button>
  <button class="b-bo" data-t="brk_out">休憩戻</button>
</div>
<div id="names"></div>
<div class="note">顔で照合できない場合は、ボタンの後に表示されるお名前をタップしてください。<br>
打刻時にカメラ画像を証跡として保存します。<?php if (KKINTAI_DEMO): ?><b>【デモ環境】データは定期的に初期化されます。実在の個人情報は登録しないでください。</b><?php endif; ?></div>
<script src="kkintai_assets/face-api.min.js"></script>
<script>
(function(){
  var TOK = <?php echo json_encode((string)$_GET['kiosk']); ?>;
  var video = document.getElementById('cam');
  var statusEl = document.getElementById('status');
  var namesEl = document.getElementById('names');
  var pendingType = null, ready = false, employees = [];

  function tick(){
    var d = new Date();
    document.getElementById('clock').textContent = d.toLocaleTimeString('ja-JP');
    document.getElementById('date').textContent = d.toLocaleDateString('ja-JP', {year:'numeric',month:'long',day:'numeric',weekday:'long'});
  }
  setInterval(tick, 500); tick();

  function setStatus(msg, cls){ statusEl.textContent = msg; statusEl.className = cls||''; }

  async function boot(){
    try {
      await faceapi.nets.tinyFaceDetector.loadFromUri('kkintai_assets/models');
      await faceapi.nets.faceLandmark68Net.loadFromUri('kkintai_assets/models');
      await faceapi.nets.faceRecognitionNet.loadFromUri('kkintai_assets/models');
    } catch(e) { setStatus('顔認識モデルの読込に失敗: '+e, 'ng'); }
    try {
      var s = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user', width:640}});
      video.srcObject = s;
      ready = true;
      setStatus('ボタンを押して打刻してください');
    } catch(e) { setStatus('カメラを使用できません(名前タップで打刻できます): '+e.name, 'ng'); }
    var r = await fetch('?api=kiosk_state&kiosk='+encodeURIComponent(TOK));
    var j = await r.json();
    employees = j.employees || [];
  }

  function snap(){
    if (!ready) return '';
    var c = document.createElement('canvas');
    c.width = video.videoWidth||640; c.height = video.videoHeight||480;
    c.getContext('2d').drawImage(video, 0, 0);
    return c.toDataURL('image/jpeg', 0.8).split(',')[1];
  }

  async function detectDescriptor(){
    if (!ready || !window.faceapi) return null;
    try {
      var det = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({inputSize:320}))
        .withFaceLandmarks().withFaceDescriptor();
      return det ? Array.from(det.descriptor) : null;
    } catch(e) { return null; }
  }

  function showNames(type, photo){
    namesEl.innerHTML = '';
    employees.forEach(function(e){
      var b = document.createElement('button');
      b.textContent = e.name;
      b.onclick = function(){ namesEl.style.display='none'; send({employee_id:e.id, type:type, photo:photo}); };
      namesEl.appendChild(b);
    });
    namesEl.style.display = 'flex';
    setStatus('お名前をタップしてください');
  }

  async function send(payload){
    setStatus('記録中…');
    try {
      var r = await fetch('?api=punch&kiosk='+encodeURIComponent(TOK), {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
      var j = await r.json();
      if (r.ok) {
        setStatus(j.employee+'さん '+j.type_label+' '+j.ts.slice(11,16)+' を記録しました', 'ok');
        setTimeout(function(){ setStatus('ボタンを押して打刻してください'); }, 4000);
      } else if (j.error === 'unmatched') {
        showNames(payload.type, payload.photo||'');
      } else {
        setStatus(j.error||'エラー', 'ng');
      }
    } catch(e){ setStatus('通信エラー: '+e, 'ng'); }
  }

  document.querySelectorAll('.btns button').forEach(function(b){
    b.onclick = async function(){
      namesEl.style.display='none';
      var type = b.getAttribute('data-t');
      var photo = snap();
      setStatus('顔を照合しています…');
      var d = await detectDescriptor();
      if (d) { send({descriptor:d, type:type, photo:photo}); }
      else { showNames(type, photo); }
    };
  });
  boot();
})();
</script>
<?php if (($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp'): ?><p style="text-align:center;font-size:13px;margin:14px 0;color:#5d6b7a">これはデモです。<a href="https://kappstore.exbridge.jp/app.php?id=f0f56c6e4da881be&amp;ref=kkintai" target="_blank" rel="noopener">この製品をオンプレミスで導入する（商品ページ）</a></p><?php endif; ?>
</body></html>
<?php
    exit;
}

/* ================= 管理画面 ================= */

kk_session_start();
$login_error = '';
if (isset($_POST['kk_pw'])) {
    if (kk_try_login((string)$_POST['kk_pw'])) { $_SESSION['kk_ok'] = 1; header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
    $login_error = 'パスワードが違います';
}
if (isset($_GET['logout'])) { unset($_SESSION['kk_ok']); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

$SELF = strtok($_SERVER['REQUEST_URI'], '?');

/* ---- CSV(給与ソフト向け・月次) ---- */
if (kk_logged_in() && isset($_GET['export']) && $_GET['export'] === 'csv') {
    kk_assert('admin', 'export');
    $month = preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month']) ? $_GET['month'] : date('Y-m');
    $pdo = kk_pdo();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kkintai_' . $month . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('社員コード', '氏名', '日付', '出勤', '退勤', '休憩(分)', '労働(分)', '残業(分)', '遅刻', '早退', '要確認'));
    foreach ($pdo->query("SELECT id, name, code FROM employees WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $m = kk_month_calc($pdo, (int)$e['id'], $month);
        foreach ($m['days'] as $d => $c) {
            fputcsv($out, array($e['code'], $e['name'], $d, $c['in'], $c['out'], $c['break_min'],
                $c['work_min'] === null ? '' : $c['work_min'], $c['overtime_min'] === null ? '' : $c['overtime_min'],
                $c['late'] ? '1' : '', $c['early'] ? '1' : '', $c['incomplete'] ? '1' : ''));
        }
    }
    fclose($out);
    kk_audit('admin', 'export', 'csv ' . $month);
    exit;
}

/* ---- 管理POST ---- */
$flash = ''; $flash_err = '';
if (kk_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    if (!kk_csrf_ok()) {
        $flash_err = 'ページの有効期限が切れました。再読み込みしてください';
    } else {
        $act = (string)$_POST['act'];
        $pdo = kk_pdo();
        try {
            if ($act === 'emp_add') {
                kk_assert('admin', 'employee.create');
                $nm = trim((string)$_POST['name']);
                if ($nm === '' || mb_strlen($nm, 'UTF-8') > 50) { $flash_err = '氏名を1〜50文字で'; }
                else {
                    $pdo->prepare('INSERT INTO employees(name,code,created_at) VALUES(?,?,?)')
                        ->execute(array($nm, trim((string)$_POST['code']), kk_now()));
                    kk_audit('admin', 'employee.create', $nm);
                    $flash = '社員を追加しました。顔登録は一覧の「顔登録」から';
                }
            } elseif ($act === 'emp_toggle') {
                kk_assert('admin', 'employee.edit');
                $pdo->prepare('UPDATE employees SET active=1-active WHERE id=?')->execute(array((int)$_POST['id']));
                kk_audit('admin', 'employee.edit', 'toggle #' . (int)$_POST['id']);
                $flash = '在籍状態を切り替えました';
            } elseif ($act === 'face_enroll') {
                kk_assert('admin', 'face.enroll');
                $descs = json_decode((string)$_POST['descriptors'], true);
                $okDescs = array();
                if (is_array($descs)) {
                    foreach ($descs as $d) { if (kk_face_valid($d)) { $okDescs[] = array_map('floatval', $d); } }
                }
                if (count($okDescs) < 1) { $flash_err = '顔データを取得できませんでした。明るい場所で正面からやり直してください'; }
                else {
                    $pdo->prepare('UPDATE employees SET face_json=?, consented_at=? WHERE id=?')
                        ->execute(array(json_encode($okDescs), kk_now(), (int)$_POST['id']));
                    kk_audit('admin', 'face.enroll', '#' . (int)$_POST['id'] . ' x' . count($okDescs));
                    $flash = '顔を登録しました(' . count($okDescs) . 'サンプル・本人同意記録: ' . kk_now() . ')';
                }
            } elseif ($act === 'face_delete') {
                kk_assert('admin', 'face.delete');
                $pdo->prepare("UPDATE employees SET face_json='[]', consented_at='' WHERE id=?")->execute(array((int)$_POST['id']));
                kk_audit('admin', 'face.delete', '#' . (int)$_POST['id']);
                $flash = '顔データを削除しました(同意撤回に対応)';
            } elseif ($act === 'punch_add') {
                kk_assert('admin', 'punch.create');
                $ts = (string)$_POST['date'] . ' ' . (string)$_POST['time'] . ':00';
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts)) { $flash_err = '日時の形式が不正です'; }
                else {
                    $r = kk_punch_create('admin', (int)$_POST['employee_id'], (string)$_POST['type'], 'admin', '', null, $ts);
                    if (isset($r['error'])) { $flash_err = $r['error']; }
                    else { $flash = '打刻を手動追加しました(監査ログに記録)'; }
                }
            } elseif ($act === 'punch_delete') {
                kk_assert('admin', 'punch.delete');
                $st = $pdo->prepare('SELECT p.*, e.name FROM punches p JOIN employees e ON e.id=p.employee_id WHERE p.id=?');
                $st->execute(array((int)$_POST['id']));
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if ($p) {
                    $pdo->prepare('DELETE FROM punches WHERE id=?')->execute(array((int)$_POST['id']));
                    kk_audit('admin', 'punch.delete', $p['name'] . ' ' . $p['type'] . ' ' . $p['ts']);
                    $flash = '打刻を削除しました(監査ログに記録)';
                }
            }
        } catch (KkDenied $e) {
            $flash_err = $e->getMessage();
        }
    }
}

$page = isset($_GET['p']) ? (string)$_GET['p'] : 'home';
$pdo = kk_logged_in() ? kk_pdo() : null;
?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo kk_h(KKINTAI_TITLE); ?></title>
<meta name="robots" content="noindex">
<style>
:root { --brand:<?php echo kk_h(KKINTAI_BRAND_COLOR); ?>; --ink:#22312a; --line:#d8e5dc; --paper:#f6faf7; --err:#a33; --warn:#a06a10; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:-apple-system,"Segoe UI","Noto Sans JP",sans-serif; color:var(--ink); background:var(--paper); font-size:14.5px; line-height:1.9; }
a { color:var(--brand); }
.wrap { max-width:1000px; margin:0 auto; padding:0 14px 40px; }
header.top { background:#fff; border-bottom:2px solid var(--brand); margin:0 -14px 16px; padding:12px 16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
header.top img.m { width:30px; height:30px; border-radius:50%; }
header.top h1 { font-size:17px; }
header.top h1 a { color:var(--brand); text-decoration:none; }
.demo-badge { background:#fff3d8; border:1px solid #e8c87a; color:#7a5b12; font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; }
nav.tabs { display:flex; gap:4px; margin-left:auto; flex-wrap:wrap; }
nav.tabs a { text-decoration:none; padding:6px 12px; border-radius:8px; font-size:13px; color:#41564a; }
nav.tabs a.on { background:var(--brand); color:#fff; }
.card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; }
.card h2 { font-size:15.5px; color:var(--brand); margin-bottom:10px; }
.btn { background:var(--brand); color:#fff; border:none; border-radius:8px; padding:8px 16px; font-size:13.5px; font-weight:700; cursor:pointer; }
.btn.ng { background:#8a8f94; }
.flash { border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:13.5px; }
.flash.ok { background:#e8f5ec; border:1px solid #b5d9c2; color:#215c3c; }
.flash.err { background:#fdeaea; border:1px solid #eab6b6; color:var(--err); }
table.list { width:100%; border-collapse:collapse; font-size:13.5px; }
table.list th, table.list td { text-align:left; padding:7px 10px; border-bottom:1px solid var(--line); vertical-align:middle; }
table.list th { color:#5a6f60; font-size:12px; }
.pill { display:inline-block; font-size:11.5px; border-radius:999px; padding:1px 10px; background:#e9f2ec; color:#41564a; }
.pill.ng { background:#fdeaea; color:var(--err); }
input, select { padding:7px 9px; border:1.5px solid var(--line); border-radius:7px; font-size:13.5px; }
.gate { max-width:380px; margin:80px auto; background:#fff; border:1px solid var(--line); border-radius:12px; padding:28px; text-align:center; }
.gate input { width:100%; margin:12px 0; }
.gate button { width:100%; background:var(--brand); color:#fff; border:none; border-radius:8px; padding:11px; font-size:15px; font-weight:700; cursor:pointer; }
.muted { color:#748a7c; font-size:12.5px; }
.stat { display:flex; gap:12px; flex-wrap:wrap; }
.stat .s { background:#fff; border:1px solid var(--line); border-radius:10px; padding:10px 18px; min-width:120px; }
.stat .s b { display:block; font-size:20px; color:var(--brand); }
.stat .s span { font-size:12px; color:#5a6f60; }
form.inline { display:inline; }
#enrollbox { display:none; margin-top:10px; }
#enrollbox video { width:280px; border-radius:10px; border:2px solid var(--brand); background:#000; }
.foot { text-align:center; font-size:11px; color:#8aa091; padding:16px 0 6px; }
</style></head>
<body>
<?php if (!kk_logged_in()): ?>
<div class="gate">
  <img src="kkintai_assets/kurage_mascot.png" alt="" style="width:64px;height:64px;border-radius:16px">
  <h1 style="font-size:18px;color:var(--brand);margin:8px 0 4px"><?php echo kk_h(KKINTAI_TITLE); ?></h1>
  <p class="muted">顔で打刻・丸めない台帳・給与ソフトCSV</p>
  <?php if ($login_error): ?><p style="color:var(--err);font-size:13px"><?php echo kk_h($login_error); ?></p><?php endif; ?>
  <form method="post"><input type="password" name="kk_pw" placeholder="管理者パスワード" autofocus><button>ログイン</button></form>
  <?php if (KKINTAI_DEMO): ?><p class="muted">デモ環境です。データは定期的に初期化されます。実在の個人情報は登録しないでください。</p><?php endif; ?>
</div>
<?php else: ?>
<div class="wrap">
<header class="top">
  <img class="m" src="kkintai_assets/kurage_mascot.png" alt="">
  <h1><a href="<?php echo kk_h($SELF); ?>"><?php echo kk_h(KKINTAI_TITLE); ?></a></h1>
  <?php if (KKINTAI_DEMO): ?><span class="demo-badge">デモ</span><?php endif; ?>
  <nav class="tabs">
    <?php
    $tabs = array('home' => '今日の状況', 'emp' => '社員・顔登録', 'ledger' => '勤怠台帳', 'month' => '月次・CSV', 'history' => '監査ログ');
    foreach ($tabs as $k => $label) {
        echo '<a class="' . ($page === $k ? 'on' : '') . '" href="' . kk_h($SELF) . '?p=' . $k . '">' . kk_h($label) . '</a>';
    }
    ?>
    <a href="<?php echo kk_h($SELF); ?>?logout=1">ログアウト</a>
  </nav>
</header>

<?php if ($flash): ?><div class="flash ok"><?php echo kk_h($flash); ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash err"><?php echo kk_h($flash_err); ?></div><?php endif; ?>

<?php if ($page === 'home'): ?>
<div class="card">
  <h2>今日の状況 (<?php echo date('Y-m-d'); ?>)</h2>
  <?php
  $today = date('Y-m-d');
  $emps = $pdo->query("SELECT id, name FROM employees WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  $inN = 0; $outN = 0; $noneN = 0; $rows = array();
  foreach ($emps as $e) {
      $st = $pdo->prepare("SELECT * FROM punches WHERE employee_id=? AND pdate=? ORDER BY ts");
      $st->execute(array((int)$e['id'], $today));
      $ps = $st->fetchAll(PDO::FETCH_ASSOC);
      $c = kk_day_calc($ps);
      if ($c['in'] && !$c['out']) { $state = '<span class="pill">出勤中</span>'; $inN++; }
      elseif ($c['out']) { $state = '<span class="pill">退勤済</span>'; $outN++; }
      else { $state = '<span class="pill ng">未打刻</span>'; $noneN++; }
      $rows[] = array($e['name'], $state, $c);
  }
  ?>
  <div class="stat" style="margin-bottom:12px">
    <div class="s"><b><?php echo $inN; ?></b><span>出勤中</span></div>
    <div class="s"><b><?php echo $outN; ?></b><span>退勤済</span></div>
    <div class="s"><b><?php echo $noneN; ?></b><span>未打刻</span></div>
  </div>
  <table class="list"><tr><th>氏名</th><th>状態</th><th>出勤</th><th>退勤</th><th>休憩</th><th>労働</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?php echo kk_h($r[0]); ?></td><td><?php echo $r[1]; ?></td>
      <td><?php echo kk_h($r[2]['in']); ?><?php echo $r[2]['late'] ? ' <span class="pill ng">遅刻</span>' : ''; ?></td>
      <td><?php echo kk_h($r[2]['out']); ?></td>
      <td><?php echo $r[2]['break_min'] ? kk_min_fmt($r[2]['break_min']) : ''; ?></td>
      <td><?php echo kk_min_fmt($r[2]['work_min']); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<div class="card">
  <h2>打刻端末(キオスク)の開き方</h2>
  <p class="muted">入口のタブレット/PCで次のURLを開き、ホーム画面に追加してください(HTTPS必須・カメラ許可)。</p>
  <p><code style="background:#e9f2ec;border-radius:6px;padding:3px 10px;font-size:13px"><?php echo kk_h('https://' . $_SERVER['HTTP_HOST'] . $SELF . '?kiosk=' . KKINTAI_KIOSK_TOKEN); ?></code></p>
</div>

<?php elseif ($page === 'emp'): ?>
<div class="card">
  <h2>社員一覧</h2>
  <table class="list"><tr><th>氏名</th><th>コード</th><th>顔登録</th><th>在籍</th><th></th></tr>
  <?php foreach ($pdo->query("SELECT * FROM employees ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e):
      $faces = json_decode($e['face_json'], true); $nf = is_array($faces) ? count($faces) : 0; ?>
  <tr><td><?php echo kk_h($e['name']); ?></td><td class="muted"><?php echo kk_h($e['code']); ?></td>
      <td><?php echo $nf ? '<span class="pill">登録済(' . $nf . ') ' . kk_h(substr($e['consented_at'], 0, 10)) . '同意</span>' : '<span class="pill ng">未登録</span>'; ?></td>
      <td><?php echo $e['active'] ? '在籍' : '<span class="pill ng">停止</span>'; ?></td>
      <td>
        <button class="btn" type="button" onclick="startEnroll(<?php echo (int)$e['id']; ?>, '<?php echo kk_h($e['name']); ?>')">顔登録</button>
        <?php if ($nf): ?>
        <form class="inline" method="post" onsubmit="return confirm('顔データを削除しますか(同意撤回)?')">
          <input type="hidden" name="csrf" value="<?php echo kk_h(kk_csrf()); ?>"><input type="hidden" name="act" value="face_delete"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
          <button class="btn ng" type="submit">顔削除</button>
        </form>
        <?php endif; ?>
        <form class="inline" method="post">
          <input type="hidden" name="csrf" value="<?php echo kk_h(kk_csrf()); ?>"><input type="hidden" name="act" value="emp_toggle"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
          <button class="btn ng" type="submit"><?php echo $e['active'] ? '停止' : '復帰'; ?></button>
        </form>
      </td></tr>
  <?php endforeach; ?></table>

  <div id="enrollbox" class="card" style="background:#f2f8f4">
    <h2 id="enrolltitle">顔登録</h2>
    <p class="muted">本人の同意を得た上で、明るい場所で正面から3枚撮影します。顔の特徴データは打刻の照合のみに使用し、削除ボタンでいつでも削除できます(個人情報保護法の個人識別符号として管理)。</p>
    <video id="ev" autoplay muted playsinline></video>
    <div style="margin-top:8px">
      <button class="btn" type="button" id="capbtn">撮影 (<span id="capn">0</span>/3)</button>
      <button class="btn ng" type="button" onclick="stopEnroll()">やめる</button>
    </div>
    <form id="enrollform" method="post" style="display:none">
      <input type="hidden" name="csrf" value="<?php echo kk_h(kk_csrf()); ?>">
      <input type="hidden" name="act" value="face_enroll">
      <input type="hidden" name="id" id="enrollid">
      <input type="hidden" name="descriptors" id="enrolldescs">
    </form>
  </div>
</div>
<div class="card">
  <h2>社員を追加</h2>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?php echo kk_h(kk_csrf()); ?>"><input type="hidden" name="act" value="emp_add">
    <input name="name" placeholder="氏名" required>
    <input name="code" placeholder="社員コード(給与ソフト連携用・任意)">
    <button class="btn" type="submit">追加</button>
  </form>
  <p class="muted" style="margin-top:6px">顔登録は本人の同意が前提です。未登録でも「名前タップ」で打刻できます。</p>
</div>
<script src="kkintai_assets/face-api.min.js"></script>
<script>
var eStream=null, eDescs=[], eId=null;
async function startEnroll(id, name){
  eId=id; eDescs=[];
  document.getElementById('enrolltitle').textContent = name+'さんの顔登録';
  document.getElementById('capn').textContent='0';
  document.getElementById('enrollbox').style.display='block';
  await faceapi.nets.tinyFaceDetector.loadFromUri('kkintai_assets/models');
  await faceapi.nets.faceLandmark68Net.loadFromUri('kkintai_assets/models');
  await faceapi.nets.faceRecognitionNet.loadFromUri('kkintai_assets/models');
  try { eStream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user', width:640}});
    document.getElementById('ev').srcObject = eStream;
  } catch(e){ alert('カメラを使用できません: '+e.name); }
}
function stopEnroll(){
  if(eStream){ eStream.getTracks().forEach(function(t){t.stop();}); eStream=null; }
  document.getElementById('enrollbox').style.display='none';
}
document.getElementById('capbtn').onclick = async function(){
  var v = document.getElementById('ev');
  var det = await faceapi.detectSingleFace(v, new faceapi.TinyFaceDetectorOptions({inputSize:320}))
    .withFaceLandmarks().withFaceDescriptor();
  if(!det){ alert('顔を検出できませんでした。正面から明るい場所でもう一度'); return; }
  eDescs.push(Array.from(det.descriptor));
  document.getElementById('capn').textContent = eDescs.length;
  if(eDescs.length>=3){
    document.getElementById('enrollid').value = eId;
    document.getElementById('enrolldescs').value = JSON.stringify(eDescs);
    stopEnroll();
    document.getElementById('enrollform').submit();
  }
};
</script>

<?php elseif ($page === 'ledger'): ?>
<?php $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)(isset($_GET['d']) ? $_GET['d'] : '')) ? $_GET['d'] : date('Y-m-d'); ?>
<div class="card">
  <h2>勤怠台帳</h2>
  <form method="get" style="display:flex;gap:8px;margin-bottom:12px">
    <input type="hidden" name="p" value="ledger">
    <input type="date" name="d" value="<?php echo kk_h($day); ?>">
    <button class="btn" type="submit">表示</button>
  </form>
  <table class="list"><tr><th>時刻</th><th>氏名</th><th>種別</th><th>方式</th><th>証跡</th><th></th></tr>
  <?php
  $st = $pdo->prepare("SELECT p.*, e.name FROM punches p JOIN employees e ON e.id=p.employee_id WHERE p.pdate=? ORDER BY p.ts");
  $st->execute(array($day));
  global $KK_TYPES;
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p): ?>
  <tr><td><?php echo kk_h(substr($p['ts'], 11, 5)); ?></td><td><?php echo kk_h($p['name']); ?></td>
      <td><?php echo kk_h($KK_TYPES[$p['type']]); ?></td>
      <td><span class="pill"><?php echo $p['method'] === 'face' ? '顔' . ($p['distance'] !== null ? '(' . $p['distance'] . ')' : '') : ($p['method'] === 'name' ? '名前タップ' : '管理者'); ?></span></td>
      <td><?php echo $p['photo'] !== '' ? '<a href="' . kk_h($SELF) . '?img=' . urlencode($p['photo']) . '" target="_blank">写真</a>' : '—'; ?></td>
      <td><form class="inline" method="post" onsubmit="return confirm('この打刻を削除しますか?')">
        <input type="hidden" name="csrf" value="<?php echo kk_h(kk_csrf()); ?>"><input type="hidden" name="act" value="punch_delete"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
        <button class="btn ng" type="submit">削除</button></form></td></tr>
  <?php endforeach; ?></table>
</div>
<div class="card">
  <h2>打刻の手動追加(修正)</h2>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?php echo kk_h(kk_csrf()); ?>"><input type="hidden" name="act" value="punch_add">
    <select name="employee_id"><?php foreach ($pdo->query("SELECT id,name FROM employees WHERE active=1 ORDER BY id") as $e): ?><option value="<?php echo (int)$e['id']; ?>"><?php echo kk_h($e['name']); ?></option><?php endforeach; ?></select>
    <select name="type"><?php foreach ($KK_TYPES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo kk_h($v); ?></option><?php endforeach; ?></select>
    <input type="date" name="date" value="<?php echo kk_h($day); ?>">
    <input type="time" name="time" value="09:00">
    <button class="btn" type="submit">追加</button>
  </form>
  <p class="muted" style="margin-top:6px">手動追加・削除はすべて監査ログに記録されます(打刻の丸め・改変はしない設計です)。</p>
</div>

<?php elseif ($page === 'month'): ?>
<?php $month = preg_match('/^\d{4}-\d{2}$/', (string)(isset($_GET['m']) ? $_GET['m'] : '')) ? $_GET['m'] : date('Y-m'); ?>
<div class="card">
  <h2>月次集計 (<?php echo kk_h($month); ?>)</h2>
  <form method="get" style="display:flex;gap:8px;margin-bottom:12px">
    <input type="hidden" name="p" value="month">
    <input type="month" name="m" value="<?php echo kk_h($month); ?>">
    <button class="btn" type="submit">表示</button>
    <a class="btn" style="text-decoration:none" href="<?php echo kk_h($SELF); ?>?export=csv&month=<?php echo kk_h($month); ?>">給与ソフト向けCSV</a>
  </form>
  <table class="list"><tr><th>氏名</th><th>出勤日数</th><th>労働時間</th><th>残業時間</th><th>遅刻</th></tr>
  <?php foreach ($pdo->query("SELECT id, name FROM employees WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e):
      $m = kk_month_calc($pdo, (int)$e['id'], $month); ?>
  <tr><td><?php echo kk_h($e['name']); ?></td><td><?php echo $m['day_count']; ?>日</td>
      <td><?php echo kk_min_fmt($m['work_min']); ?></td>
      <td><?php echo $m['overtime_min'] ? kk_min_fmt($m['overtime_min']) : '0:00'; ?></td>
      <td><?php echo $m['late_count'] ? $m['late_count'] . '回' : '—'; ?></td></tr>
  <?php endforeach; ?></table>
  <p class="muted">残業=1日<?php echo (int)KKINTAI_STANDARD_MIN / 60; ?>時間超の合計(生データ・丸めなし)。CSVは日別明細です。</p>
</div>

<?php elseif ($page === 'history'): ?>
<div class="card">
  <h2>監査ログ(直近60件)</h2>
  <p class="muted" style="margin:0 0 8px">打刻端末は打刻の作成しかできません。社員・顔データ・台帳の変更はすべて管理者操作として記録されます。</p>
  <table class="list"><tr><th>日時</th><th>誰が</th><th>操作</th><th>詳細</th></tr>
  <?php foreach ($pdo->query('SELECT * FROM audit ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC) as $a): ?>
  <tr><td class="muted"><?php echo kk_h($a['ts']); ?></td><td><?php echo kk_h($a['actor']); ?></td>
      <td><?php echo kk_h($a['action']); ?></td><td class="muted"><?php echo kk_h($a['detail']); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<div class="foot">Kurage Kintai — 顔で打刻・丸めない台帳。<?php if (KKINTAI_DEMO): ?>(デモ環境)<?php endif; ?></div>
</div>
<?php endif; ?>
<?php if (($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp'): ?><p style="text-align:center;font-size:13px;margin:14px 0;color:#5d6b7a">これはデモです。<a href="https://kappstore.exbridge.jp/app.php?id=f0f56c6e4da881be&amp;ref=kkintai" target="_blank" rel="noopener">この製品をオンプレミスで導入する（商品ページ）</a></p><?php endif; ?>
</body></html>
