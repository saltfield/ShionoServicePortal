# Application（Laravel 11）

Shiono Service Portal のアプリケーション本体です。

## スタック

- Laravel 11.x / PHP 8.3
- Livewire 3
- Bootstrap 5（Vite）
- Pest 3
- Dompdf + Noto Sans CJK（契約 PDF）
- `pragmarx/google2fa-laravel` + `bacon/bacon-qr-code`（2FA）

## 起動

```bash
cd ../Develop
docker compose up -d --build
docker compose exec -u www-data php php artisan migrate --seed
```

- Web: http://localhost:8080
- Mailpit: http://localhost:8025

```bash
# コンテナ内
docker compose exec -u www-data php php artisan migrate
docker compose exec -u www-data php ./vendor/bin/pest

# フロントビルド（Node コンテナ例）
docker run --rm -v "$PWD/../Application:/app" -w /app node:22-bookworm \
  bash -lc "npm ci && npm run build"
```

デプロイ・本番更新は [Document/architecture/deploy.md](../Document/architecture/deploy.md)。

## ディレクトリ方針

```text
app/Domains/Auth|Iam|Billing|Contract|Catalog|…
app/Http/Controllers/{Admin,Bp,Customer}
resources/views/{admin,bp,customer,auth,layouts}
```

## 請求バッチ（Phase 10）

- スケジュール定義: `routes/console.php`
- 手動（単月）: `php artisan billing:run-monthly --month=YYYYMM`
- 手動（範囲・BP配下）: `php artisan billing:run-monthly --from=YYYYMM --to=YYYYMM --bp-tree=BPN...`
- 手動（範囲・BP単体）: `php artisan billing:run-monthly --from=YYYYMM --to=YYYYMM --bp=BPN...`
- 手動（範囲・CN）: `php artisan billing:run-monthly --from=YYYYMM --to=YYYYMM --customer=CN...`
- UI: `/admin/billing-batch`（履歴・作成請求一覧・過去月一括）
- **Scheduler コンテナ必須**（`Develop` の `scheduler` サービス）
