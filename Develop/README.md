# Develop — Docker 環境

Nginx + PHP-FPM 8.3 + MariaDB 11.4 + Mailpit + **Scheduler** + **Queue** の開発用スタックです。

SSL / リバースプロキシは本ディレクトリでは扱いません。フロントのリバースプロキシから `http://<host>:8080` へ転送してください。

本番・更新手順の詳細は **[デプロイ手順](../Document/architecture/deploy.md)** を参照してください。

## 起動

```bash
cd Develop
cp .env.example .env   # 必要に応じてパスワードを変更
docker compose up -d --build
```

| サービス | URL / ポート |
|----------|----------------|
| Web (Nginx) | http://localhost:8080 |
| MariaDB | localhost:3306 |
| Mailpit UI | http://localhost:8025 |
| Mailpit SMTP | localhost:1025 |
| Scheduler | コンテナ内 `php artisan schedule:work`（自動請求） |
| Queue | コンテナ内 `php artisan queue:work`（メール通知） |

## 初回アプリセットアップ

```bash
docker compose exec -u www-data php php artisan key:generate
docker compose exec -u www-data php php artisan migrate --seed
```

## 構成

- `php/Dockerfile` … PHP 8.3-FPM、拡張、**Noto Sans CJK**（PDF用日本語フォント）
- `nginx/conf.d/default.conf` … Laravel `public` 向け。`X-Forwarded-*` を考慮
- `mariadb/my.cnf` … `utf8mb4` / `utf8mb4_uca1400_ai_ci`、TZ=+09:00
- `scheduler` … 自動請求バッチ用。停止しているとスケジュール実行されない
- `queue` … メール通知用。停止していると `jobs` に溜まるだけで送信されない

## 注意

- DB ボリューム初回作成時のみ `mariadb/init/` が実行されます。
- `storage/` の Permission denied が出た場合（root で artisan 実行後など）:

```bash
docker compose exec -u root php chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
docker compose exec -u www-data php php artisan view:clear
```

artisan はできるだけ `docker compose exec -u www-data php ...` で実行してください。

## テスト

```bash
docker compose exec -u www-data php ./vendor/bin/pest
```
