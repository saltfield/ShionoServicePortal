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
| `Develop/mariadb-data/` | MariaDB データ（`/var/lib/mysql`）。ホスト永続・gitignore 済み |

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
| `APP_NAME`（任意） | Compose が `${APP_NAME}` を展開する場合の WARN 回避用。例: `SSP`（アプリ本体は `Application/.env` の `APP_NAME`） |
| `TZ` | `Asia/Tokyo` |

#### `Application/.env` の要点

| キー | 例 / 注意 |
|------|-----------|
| `APP_NAME` | 例: `SSP`（メール差出人名など） |
| `APP_ENV` | 本番は `production` |
| `APP_DEBUG` | 本番は `false` |
| `APP_KEY` | 下記 `key:generate` で生成 |
| `APP_URL` | 公開 URL（リバースプロキシの HTTPS URL）。**メール本文のリンク／ボタンもこの値から生成**される |
| `ADMIN_SEED_*` | 本番初回の `ProductionBootstrapSeeder` 用（`LOGIN_ID` / `PASSWORD` / `NAME` / `EMAIL`）。シード後は `PASSWORD` 削除推奨 |
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

期待するコンテナ:

```bash
docker compose ps
# ssp-nginx / ssp-php / ssp-scheduler / ssp-queue / ssp-mariadb / ssp-mailpit
```

> **注意:** 初回はまだ `vendor` が無いため、`ssp-scheduler` / `ssp-queue` が `Restarting` になることがあります。次の手順 3 で解消します。

### 3. Composer 依存関係（必須）

リポジトリに `vendor/` は含めない想定です。**コンテナ起動後に必ず**インストールします。

ホスト側の `Application/` は多くの環境で `www-data` から書き込めないため、**root で実行**します。

```bash
cd Develop

# 本番寄り
docker compose exec -u root php composer install --no-dev --optimize-autoloader

# 開発（dev 依存も入れる場合）
# docker compose exec -u root php composer install

docker compose exec -u root php chown -R www-data:www-data \
  /var/www/html/vendor \
  /var/www/html/storage \
  /var/www/html/bootstrap/cache
```

`www-data` で `vendor does not exist and could not be created` と出たら、上記のとおり `-u root` を使ってください。

> **注意:** `--no-dev` では `fakerphp/faker` が入らないため、開発用の `db:seed`（`DemoUserSeeder`）は失敗します。  
> **本番初回は `ProductionBootstrapSeeder` を使ってください**（管理者1名のみ・Faker 不要・BPデモなし）。

### 4. アプリ初期化

ホスト上の `Application/` は多くの環境で `www-data` から書けないため、**書き込みを伴う artisan は `-u root`** で実行します。

```bash
cd Develop

# APP_KEY を Application/.env に書き込む（www-data では Permission denied になる）
docker compose exec -u root php php artisan key:generate

docker compose exec -u root php php artisan migrate --force

# --- 本番初回シード（推奨）---
# Application/.env に例:
#   ADMIN_SEED_LOGIN_ID=ADMIN001
#   ADMIN_SEED_PASSWORD=（10文字以上の初期パスワード）
#   ADMIN_SEED_NAME=管理者
#   ADMIN_SEED_EMAIL=admin@example.com
docker compose exec -u root php php artisan db:seed \
  --class=Database\\Seeders\\ProductionBootstrapSeeder --force
# シード後、ADMIN_SEED_PASSWORD は .env から削除推奨

# --- 開発のみ: デモ BP/カスタマー込み（要 composer install ※--no-dev なし）---
# docker compose exec -u root php php artisan db:seed --force
```

権限・キャッシュ:

```bash
docker compose exec -u root php chown -R www-data:www-data \
  /var/www/html/storage \
  /var/www/html/bootstrap/cache \
  /var/www/html/.env
docker compose exec -u www-data php php artisan config:cache
docker compose exec -u www-data php php artisan route:cache
docker compose exec -u www-data php php artisan view:cache
```

Composer 後に scheduler / queue を起こす:

```bash
docker compose up -d scheduler queue
docker compose ps
# ssp-scheduler / ssp-queue が Up（Restarting ではない）であること
```

### 5. フロントエンド資産（変更時）

ホストまたは Node コンテナで Vite ビルド:

```bash
docker run --rm -v "$PWD/../Application:/app" -w /app node:22-bookworm \
  bash -lc "npm ci && npm run build"
```

（`Develop` ディレクトリから実行する例）

### 6. 疎通確認

| 確認 | 方法 |
|------|------|
| Web | `http://<host>:8080/admin/login` 等 |
| DB | `docker compose exec mariadb mariadb -u ssp -p ssp -e 'SELECT 1'` |
| スケジューラ | `docker compose logs -f scheduler` に毎分 `Running scheduled tasks` |
| キュー | `docker compose logs --tail=30 queue` に致命的エラーが無い |
| 自動請求設定 | 管理者 → 自動請求設定 → 「次回の自動実行予定」が表示される |

## 更新デプロイ（コード反映）

```bash
cd Develop
git pull   # または成果物の配置

docker compose up -d --build

# composer.lock が変わった／vendor が無い場合
docker compose exec -u root php composer install --no-dev --optimize-autoloader
docker compose exec -u root php chown -R www-data:www-data \
  /var/www/html/vendor /var/www/html/storage /var/www/html/bootstrap/cache

docker compose exec -u www-data php php artisan migrate --force
docker compose exec -u www-data php php artisan config:cache
docker compose exec -u www-data php php artisan route:cache
docker compose exec -u www-data php php artisan view:cache
docker compose exec -u www-data php php artisan queue:restart   # queue ワーカー利用時
docker compose up -d scheduler queue
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
| `scheduler` / `queue` が Restarting | ログに `vendor/autoload.php` → **手順 3 の Composer 未実施**。実施後 `docker compose up -d scheduler queue` |
| `vendor does not exist and could not be created` | `www-data` に書込権なし。`docker compose exec -u root php composer install ...` のあと `chown` |
| `Class "Faker\Factory" not found`（db:seed） | 開発用フルシードは Faker 必須。本番は `ProductionBootstrapSeeder` を使う（`--no-dev` 可） |
| `ADMIN_SEED_PASSWORD` 関連エラー | `Application/.env` に `ADMIN_SEED_PASSWORD`（10文字以上）を設定してから ProductionBootstrapSeeder を再実行 |
| `file_put_contents(.../.env): Permission denied` | `key:generate` は必ず `-u root`。終了後 `chown www-data:www-data /var/www/html/.env` |
| `The "APP_NAME" variable is not set`（Compose WARN） | `Develop/.env` に `APP_NAME=SSP` を追加（または同期メモの `${APP_NAME}` をリテラルに変更） |
| Permission denied (storage) | `chown -R www-data:www-data storage bootstrap/cache` |
| 自動請求が動かない | `scheduler` 起動有無、設定の有効・日時、生成履歴 |
| 予定時刻を過ぎても履歴なし | スケジューラ未起動が大半。起動後は当日取りこぼし回収あり |
| メールが届かない | `queue` 起動有無、`jobs` / `failed_jobs`、MAIL_*、Mailpit（開発）、対象ユーザーの email |
| `Access denied for user 'ssp'@...` | `Application/.env` の `DB_PASSWORD` と `Develop/.env` の `MYSQL_PASSWORD` を一致させる。既存データが別パスワードで初期化されている場合は `Develop/mariadb-data/` を退避・削除して再作成（空ディレクトリで `MYSQL_*` が再適用される） |
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
