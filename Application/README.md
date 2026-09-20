# Application（Laravel 11）

Shiono Service Portal のアプリケーション本体です。

## スタック（Step 2-0 時点）

- Laravel 11.x / PHP 8.3
- Livewire 3
- Bootstrap 5（Vite）
- Pest 3
- `pragmarx/google2fa-laravel` + `bacon/bacon-qr-code`（2FA用・実装は Step 2-4）

## 起動

```bash
cd ../Develop
docker compose up -d
```

- Web: http://localhost:8080
- Mailpit: http://localhost:8025

```bash
# コンテナ内
docker compose exec php php artisan migrate
docker compose exec php ./vendor/bin/pest

# フロントビルド（Node コンテナ例）
docker run --rm -v "$PWD/../Application:/app" -w /app node:22-bookworm npm run build
```

## ディレクトリ方針

```text
app/Domains/Auth|Iam   # ドメインサービス（Step 2-1〜）
app/Http/Controllers/{Admin,Bp,Customer}
app/Livewire/{Admin,Bp,Customer}
```
