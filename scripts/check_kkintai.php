<?php
/**
 * kkintai の自己テスト。デプロイ前に実行する:  php scripts/check_kkintai.php
 * カメラ・ブラウザなしで、関門・顔照合(数値)・打刻・集計・CSV相当・監査を機械検証する。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok($name, $cond, $note = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $name\n"; }
    else { $fail++; echo "  NG  $name" . ($note ? " ($note)" : '') . "\n"; }
}

echo "== kkintai check ==\n";

ok('本体 kkintai.php が存在', is_file($root . '/public/kkintai.php'));
$lint = (string)shell_exec('php -l ' . escapeshellarg($root . '/public/kkintai.php') . ' 2>&1');
ok('本体のPHP構文', strpos($lint, 'No syntax errors') !== false, trim($lint));
ok('設定example が存在', is_file($root . '/public/kkintai_config.php.example'));
ok('kkintai_data保護(.htaccess deny)', trim((string)file_get_contents($root . '/public/kkintai_data/.htaccess')) === 'Require all denied');
ok('顔認識ライブラリ同梱', is_file($root . '/public/kkintai_assets/face-api.min.js'));
ok('顔認識モデル同梱(7ファイル)', count(glob($root . '/public/kkintai_assets/models/*')) >= 7);
ok('マスコット同梱', is_file($root . '/public/kkintai_assets/kurage_mascot.png'));

// ---- テスト用設定 ----
$tmp = sys_get_temp_dir() . '/kk_test_' . getmypid();
@mkdir($tmp, 0755, true);
define('KKINTAI_TITLE', 'テスト');
define('KKINTAI_PASSWORD', 'testpw');
define('KKINTAI_KIOSK_TOKEN', 'kiosktoken');
define('KKINTAI_FACE_THRESHOLD', 0.5);
define('KKINTAI_WORK_START', '09:00');
define('KKINTAI_WORK_END', '18:00');
define('KKINTAI_STANDARD_MIN', 480);
define('KKINTAI_RATE_PER_HOUR', 3);
define('KKINTAI_DEMO', false);
define('KKINTAI_DATA_DIR', $tmp);

$src = file_get_contents($root . '/public/kkintai.php');
$cut = strpos($src, '/* ================= 写真配信');
ok('関数部の切り出しマーカー', $cut !== false);
$funcs = substr($src, 0, $cut);
$funcs = preg_replace('/\$cfg = __DIR__.*?require \$cfg;/s', '', str_replace('<?php', '', $funcs), 1);
eval($funcs);

// ---- 関門 ----
ok('関門: kioskは打刻作成のみ', kk_can('kiosk', 'punch.create') && !kk_can('kiosk', 'punch.edit') && !kk_can('kiosk', 'face.enroll') && !kk_can('kiosk', 'export'));
ok('関門: adminは全操作可', kk_can('admin', 'face.enroll') && kk_can('admin', 'punch.delete') && kk_can('admin', 'export'));
ok('関門: 未知actorは全拒否', !kk_can('employee', 'punch.create'));
$denied = false;
try { kk_assert('kiosk', 'face.enroll'); } catch (KkDenied $e) { $denied = true; }
ok('関門: 違反は例外で止まる', $denied);

// ---- 顔照合(決定的な数値処理) ----
function vec($seed) { // 疑似的な単位ノルム128次元ベクトル
    mt_srand($seed);
    $v = array();
    for ($i = 0; $i < 128; $i++) { $v[] = (mt_rand(0, 2000) - 1000) / 1000.0; }
    $n = sqrt(array_sum(array_map(function ($x) { return $x * $x; }, $v)));
    return array_map(function ($x) use ($n) { return $x / $n; }, $v);
}
$A = vec(1); $B = vec(2);
ok('顔ベクトル検証: 正常形式を通す', kk_face_valid($A));
ok('顔ベクトル検証: 次元不足を弾く', !kk_face_valid(array_slice($A, 0, 100)));
ok('顔ベクトル検証: 異常値を弾く', !kk_face_valid(array_fill(0, 128, 9.9)));
ok('距離: 同一ベクトルは0', abs(kk_face_distance($A, $A)) < 1e-9);
ok('距離: 別ベクトルは離れる', kk_face_distance($A, $B) > 0.5);

$pdo = kk_pdo();
$pdo->prepare('INSERT INTO employees(name,code,face_json,consented_at,created_at) VALUES(?,?,?,?,?)')
    ->execute(array('山田太郎', 'E001', json_encode(array($A)), kk_now(), kk_now()));
$pdo->prepare('INSERT INTO employees(name,code,created_at) VALUES(?,?,?)')
    ->execute(array('佐藤花子', 'E002', kk_now()));
// わずかに揺らした本人ベクトル(距離小)
$A2 = $A; for ($i = 0; $i < 10; $i++) { $A2[$i] += 0.02; }
$m = kk_face_match($pdo, $A2);
ok('照合: 本人の揺らぎは一致する', $m !== null && $m['name'] === '山田太郎');
ok('照合: 他人ベクトルは一致しない(名前タップへ)', kk_face_match($pdo, $B) === null);

// ---- 打刻 ----
$r = kk_punch_create('kiosk', 1, 'in', 'face', '', 0.31, '2026-08-20 08:58:00');
ok('打刻: 出勤を記録', isset($r['employee']) && $r['type_label'] === '出勤');
$r2 = kk_punch_create('kiosk', 1, 'in', 'face', '', 0.31, '2026-08-20 08:58:30');
ok('打刻: 1分以内の二重打刻は409', isset($r2['error']) && $r2['code'] === 409);
kk_punch_create('kiosk', 1, 'brk_in', 'face', '', null, '2026-08-20 12:00:00');
kk_punch_create('kiosk', 1, 'brk_out', 'face', '', null, '2026-08-20 12:45:00');
kk_punch_create('kiosk', 1, 'out', 'face', '', null, '2026-08-20 19:15:00');
$bad = kk_punch_create('kiosk', 1, 'zzz', 'face');
ok('打刻: 不明種別は400', isset($bad['error']) && $bad['code'] === 400);
$bad2 = kk_punch_create('kiosk', 999, 'in', 'face');
ok('打刻: 不在社員は404', isset($bad2['error']) && $bad2['code'] === 404);

// ---- 集計(丸めない) ----
$st = $pdo->prepare("SELECT * FROM punches WHERE employee_id=1 AND pdate='2026-08-20' ORDER BY ts");
$st->execute();
$c = kk_day_calc($st->fetchAll(PDO::FETCH_ASSOC));
ok('集計: 出退勤時刻', $c['in'] === '08:58' && $c['out'] === '19:15');
ok('集計: 休憩45分', $c['break_min'] === 45);
// 8:58〜19:15 = 617分 - 45 = 572分
ok('集計: 労働572分(丸めなし)', $c['work_min'] === 572, '実際=' . $c['work_min']);
ok('集計: 残業92分(480分超過)', $c['overtime_min'] === 92, '実際=' . $c['overtime_min']);
ok('集計: 遅刻ではない', $c['late'] === false);
ok('集計: 早退ではない(18時以降退勤)', $c['early'] === false);

// 遅刻・退勤なしのケース
kk_punch_create('kiosk', 2, 'in', 'name', '', null, '2026-08-20 09:20:00');
$st->execute();
$st2 = $pdo->prepare("SELECT * FROM punches WHERE employee_id=2 AND pdate='2026-08-20' ORDER BY ts");
$st2->execute();
$c2 = kk_day_calc($st2->fetchAll(PDO::FETCH_ASSOC));
ok('集計: 遅刻判定', $c2['late'] === true);
ok('集計: 退勤なしは要確認(incomplete)', $c2['incomplete'] === true && $c2['work_min'] === null);

// ---- 月次 ----
$m1 = kk_month_calc($pdo, 1, '2026-08');
ok('月次: 出勤1日・労働572分', $m1['day_count'] === 1 && $m1['work_min'] === 572);
ok('月次: 残業92分', $m1['overtime_min'] === 92);
ok('時刻表記: 572分=9:32', kk_min_fmt(572) === '9:32');

// ---- 顔削除(同意撤回) ----
$pdo->prepare("UPDATE employees SET face_json='[]', consented_at='' WHERE id=1")->execute();
ok('顔削除後: 照合されない', kk_face_match($pdo, $A2) === null);

// ---- レート制限(上限3) ----
$okN = 0;
for ($i = 0; $i < 5; $i++) { if (kk_rate_ok('203.0.113.7')) { $okN++; } }
ok('レート制限: 上限で止まる', $okN === 3, "通過={$okN}");

// ---- 監査 ----
ok('監査ログが残る', (int)$pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn() >= 5);
ok('監査: kioskの打刻記録がある', (int)$pdo->query("SELECT COUNT(*) FROM audit WHERE actor='kiosk' AND action='punch.create'")->fetchColumn() >= 4);

// 後片付け
foreach (glob($tmp . '/img/*') as $f) { @unlink($f); }
@rmdir($tmp . '/img');
foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n結果: pass={$pass} fail={$fail}\n";
exit($fail ? 1 : 0);
