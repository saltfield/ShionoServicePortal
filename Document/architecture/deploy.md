# デプロイ手順

Shiono Service Portal を Docker スタックで起動・更新するための手順。  
開発・ステージング・本番（リバースプロキシ配下）を想定する。

## 構成

| サービス | 役割 |
|----------|------|
| `nginx` | HTTP :8080 → Laravel `public` |
| `php` | PHP-FPM 8.3 |
| `scheduler` | `php artisan schedule:work`（**自動請求に必須**） |
| `queue` | `php artisan queue:work`（**メール通知に必須**） |
| `mariadb` | MariaDB 11.4 |
| `mailpit` | 開発用 SMTP／受信 UI（本番では外部 SMTP に置換推奨） |

ソース配置:

| ホストパス | コンテナ |
|------------|----------|
| `Application/` | `/var/www/html`（php / scheduler / queue / nginx） |
| `Develop/` | compose・Nginx・PHP・MariaDB 設定 |

TLS は本スタック外のリバースプロキシで終端する（平文 HTTP を 8080 で公開）。

## 前提

- Docker / Docker Compose v2
- ホストから `Application/`・`Develop/` を参照できること
- 本番では `APP_KEY`・DB パスワード・メール設定を必ず差し替えること

## 初回デプロイ

### 1. 設定ファイル

```bash
cd Develop
cp .env.example .env
# MYSQL_*（および同期メモの DB_*）を本番値に変更

cd ../Application
cp .env.example .env
# 下記「Application/.env の要点」を本番値に変更
```

#### `Develop/.env`（Compose / MariaDB）

| キー | 例 / 注意 |
|------|-----------|
| `MYSQL_ROOT_PASSWORD` | root パスワード |
| `MYSQL_DATABASE` | DB 名（例: `ssp`） |
| `MYSQL_USER` / `MYSQL_PASSWORD` | アプリ用 DB ユーザー |
| `DB_*`（同期メモ） | `Application/.env` の `DB_*` と一致させる |
| `TZ` | `Asia/Tokyo` |

#### `Application/.env` の要点

| キー | 例 / 注意 |
|------|-----------|
| `APP_ENV` | 本番は `production` |
| `APP_DEBUG` | 本番は `false` |
| `APP_KEY` | 下記 `key:generate` で生成 |
| `APP_URL` | 公開 URL（リバースプロキシの HTTPS URL）。**メール本文のリンク／ボタンもこの値から生成**される |
| `APP_TIMEZONE` | `Asia/Tokyo` |
| `DB_HOST` | compose 内なら `mariadb` |
| `DB_*` | `Develop/.env` の DB と一致させる |
| `MAIL_*` | 本番は実 SMTP。開発は `mailpit:1025`。認証あり SMTP は `MAIL_USERNAME` / `MAIL_PASSWORD`、TLS は `MAIL_SCHEME=smtps`（ほか `MAIL_HOST` / `MAIL_PORT` / `MAIL_FROM_*`）。キー一覧は `Application/.env.example` / `Develop/.env.example` の同期メモを参照 |
| `SESSION_DRIVER` | 既定 `database` |
| `CACHE_STORE` | 既定 `database` |
| `QUEUE_CONNECTION` | 既定 `database` |

### 2. コンテナ起動

```bash
cd Develop
docker compose up -d --build
```

`scheduler` / `queue` が含まれていることを確認:

```bash
docker compose ps
# ssp-nginx / ssp-php / ssp-scheduler / ssp-queue / ssp-mariadb / ssp-mailpit
```

### 3. アプリ初期化

```bash
docker compose exec -u www-data php php artisan key:generate
docker compose exec -u www-data php php artisan migrate --force
docker compose exec -u www-data php php artisan db:seed --force   # 初回のみ（IAM 等）
```

権限・キャッシュ:

```bash
docker compose exec -u root php chown -R www-data:www-data \
  /var/www/html/storage /var/www/html/bootstrap/cache
docker compose exec -u www-data php php artisan config:cache
docker compose exec -u www-data php php artisan route:cache
docker compose exec -u www-data php php artisan view:cache
```

### 4. フロントエンド資産（変更時）

ホストまたは Node コンテナで Vite ビルド:

```bash
docker run --rm -v "$PWD/../Application:/app" -w /app node:22-bookworm \
  bash -lc "npm ci && npm run build"
```

（`Develop` ディレクトリから実行する例）

### 5. 疎通確認

| 確認 | 方法 |
|------|------|
| Web | `http://<host>:8080/admin/login` 等 |
| DB | `docker compose exec mariadb mariadb -u ssp -p ssp -e 'SELECT 1'` |
| スケジューラ | `docker compose logs -f scheduler` に毎分 `Running scheduled tasks` |
| 自動請求設定 | 管理者 → 自動請求設定 → 「次回の自動実行予定」が表示される |

## 更新デプロイ（コード反映）

```bash
cd Develop
git pull   # または成果物の配置

docker compose up -d --build

docker compose exec -u www-data php php artisan migrate --force
docker compose exec -u www-data php php artisan config:cache
docker compose exec -u www-data php php artisan route:cache
docker compose exec -u www-data php php artisan view:cache
docker compose exec -u www-data php php artisan queue:restart   # queue ワーカー利用時
```

フロント変更がある場合は上記 Vite ビルドを再実行。

`scheduler` は `restart: unless-stopped` のため、compose 再作成後も自動起動する。止まっている場合:

```bash
docker compose up -d scheduler
docker compose logs --tail=50 scheduler
```

## メール通知（運用上の必須事項）

業務メール（チケット・価格申請・強制パスワード変更）は **キューワーカーが動いていること**が前提。

| 項目 | 内容 |
|------|------|
| コンテナ | `ssp-queue` → `php artisan queue:work` |
| キュー接続 | `Application/.env` の `QUEUE_CONNECTION=database`（既定） |
| 開発確認 | Mailpit UI `http://localhost:8025` |
| 本番 SMTP | `MAIL_HOST` / `MAIL_PORT` / `MAIL_SCHEME` / `MAIL_USERNAME` / `MAIL_PASSWORD` |
| 購読 | 各ポータルの「通知設定」。強制PWはオフ不可 |

`queue` が止まっていると通知は `jobs` テーブルに溜まるだけで送信されない。

```bash
docker compose up -d queue
docker compose logs --tail=50 queue
docker compose exec -u www-data php php artisan queue:restart
```

compose 外で動かす場合:

```bash
php artisan queue:work --sleep=1 --tries=3 --timeout=90
```

## 自動請求（運用上の必須事項）

月次請求は **Laravel スケジューラが動いていること**が前提。

| 項目 | 内容 |
|------|------|
| コンテナ | `ssp-scheduler` → `php artisan schedule:work` |
| アプリ内定義 | `routes/console.php` の毎分タスク `billing-monthly-scheduler` |
| 実行条件 | 管理者「自動請求設定」の有効・実行日・時刻（Asia/Tokyo）と一致 |
| 取りこぼし | 予定時刻以降・当日中で、当該請求月の自動実行が未実施なら 1 回回収 |
| 手動 | 同画面の「今すぐ生成」（対象 YYYYMM） |
| 履歴 | 生成履歴（成功／一部失敗／失敗）→「請求一覧」で作成伝票を確認 |

本番で compose の `scheduler` を使わない場合は、ホスト cron で毎分以下を実行すること:

```bash
* * * * * cd /path/to/Application && php artisan schedule:run >> /dev/null 2>&1
```

## リバースプロキシ（本番）

例: 上位で HTTPS 終端し、バックエンドへ HTTP 転送。

- 転送先: `http://127.0.0.1:8080`
- `X-Forwarded-For` / `X-Forwarded-Proto` を付与（Nginx 設定済みで受信）
- `Application/.env` の `APP_URL` を `https://example.com` に合わせる
- `TrustProxies` は Laravel 既定＋転送ヘッダ前提

## バックアップ

| 対象 | 例 |
|------|-----|
| DB | `docker compose exec mariadb mariadb-dump -u ssp -p"$MYSQL_PASSWORD" ssp > backup.sql` |
| 成果物 | `Application/storage/app`（契約 PDF・チケット添付など） |
| 設定 | `Application/.env` / `Develop/.env`（秘密情報。リポジトリに含めない） |

リストアは空き DB へ import 後、`migrate --force` はスキーマ一致を確認してから。

## ロールバック

1. アプリコードを前リビジョンに戻す  
2. `docker compose up -d --build`  
3. DB マイグレーションの down は原則使わず、**バックアップからのリストア**を優先  
4. `config:cache` / `route:cache` / `view:cache` を再実行  

## トラブルシュート

| 症状 | 確認 |
|------|------|
| 502 / 空応答 | `docker compose ps`、`php` / `nginx` ログ |
| Permission denied (storage) | `chown -R www-data:www-data storage bootstrap/cache` |
| 自動請求が動かない | `scheduler` 起動有無、設定の有効・日時、生成履歴 |
| 予定時刻を過ぎても履歴なし | スケジューラ未起動が大半。起動後は当日取りこぼし回収あり |
| メールが届かない | `queue` 起動有無、`jobs` / `failed_jobs`、MAIL_*、Mailpit（開発） |
| マイグレーション失敗 | DB 接続、既存テーブル差分、ログ |
| 日本語 PDF 化け | コンテナに Noto CJK が入っているか（php イメージ標準） |
| セッション切れ・URL 不正 | `APP_URL` とプロキシの `X-Forwarded-Proto` |

ログ参照:

```bash
docker compose logs -f php nginx scheduler queue
docker compose exec -u www-data php tail -n 100 storage/logs/laravel.log
```

## 関連

- 開発用メモ: [../../Develop/README.md](../../Develop/README.md)
- 月次請求: [phase10-setup.md](phase10-setup.md)
- メール通知: [phase13-setup.md](phase13-setup.md)
- 全体構成: [overview.md](overview.md)
