# Kurage Kintai (kkintai)

Kurage 勤怠システム。入口のタブレット/PCのブラウザで**顔打刻**(出勤/退勤/休憩)→写真証跡→管理画面で台帳・月次集計・**給与ソフト向けCSV**。1ファイルPHP+SQLite、レンタルサーバーで動きます。

- デモ: https://proto.exbridge.jp/kkintai/
- 顔照合は**サーバーの決定的なコードが正**(ブラウザは特徴量を計算するだけ・照合失敗は名前タップにフォールバック)
- **打刻は丸めない**(生データ)。修正はすべて管理者操作として監査ログに記録
- 顔特徴データは個人識別符号として扱い、**本人同意の記録と削除機能**を実装
- 顔認識はface-api.js(MIT)+モデル同梱。カメラAPIの仕様上**HTTPS必須**

## 設置

1. `public/` の中身(kkintai.php・kkintai_config.php.example・kkintai_assets/・kkintai_data/)をサーバーへ
2. `kkintai_config.php.example` を `kkintai_config.php` にコピーし、管理者パスワードと打刻端末トークンを設定
3. 管理画面にログイン→社員追加→(同意を得て)顔登録→タブレットでキオスクURLを開く

要件: PHP 7.0+ / pdo_sqlite / gd。データと証跡写真は `kkintai_data/` に保存(このフォルダごとバックアップ)。

## 開発

```
php scripts/check_kkintai.php   # 自己テスト38件(関門・顔照合・集計をカメラなしで機械検証)
```

構築・運用の詳しい手順書(顔認証の注意点・しきい値調整・給与ソフト連携・カスタマイズ用AIプロンプト)は有償で提供しています: [Kurage App Store](https://kappstore.exbridge.jp/) / [解説と入手先](https://kurage.exbridge.jp/itemlist.php)

© EXBRIDGE, Inc. / MIT License (face-api.js and models are MIT by their respective authors)
