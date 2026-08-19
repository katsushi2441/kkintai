<?php
/** デモ環境の設定(https://proto.exbridge.jp/kkintai/)。トークンはデプロイ時に注入される。 */
define('KKINTAI_TITLE',       'Kurage 勤怠システム デモ');
define('KKINTAI_BRAND_COLOR', '#2c6e49');
define('KKINTAI_PASSWORD',    '__KK_DEMO_PASSWORD__');
define('KKINTAI_PASSWORD_HASH', '');
define('KKINTAI_KIOSK_TOKEN', '__KK_DEMO_KIOSK__');
define('KKINTAI_FACE_THRESHOLD', 0.5);
define('KKINTAI_WORK_START',   '09:00');
define('KKINTAI_WORK_END',     '18:00');
define('KKINTAI_STANDARD_MIN', 480);
define('KKINTAI_RATE_PER_HOUR', 120);
define('KKINTAI_DEMO', true);
