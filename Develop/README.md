# Develop — Docker 環境

Nginx + PHP-FPM 8.3 + MariaDB 11.4 + Mailpit の開発用スタックです。

SSL / リバースプロキシは本ディレクトリでは扱いません。フロントのリバースプロキシから `http://<host>:8080` へ転送してください。

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

## 構成

- `php/Dockerfile` … PHP 8.3-FPM、拡張、**Noto Sans CJK**（PDF用日本語フォント）
- `nginx/conf.d/default.conf` … Laravel `public` 向け。`X-Forwarded-*` を考慮
- `mariadb/my.cnf` … `utf8mb4` / `utf8mb4_uca1400_ai_ci`、TZ=+09:00

## 注意

- `Application/` に Laravel が入るまで Nginx は 404 になります（Phase 2 で配置）。
- DB ボリューム初回作成時のみ `mariadb/init/` が実行されます。
- `storage/` の Permission denied が出た場合（root で artisan 実行後など）:

```bash
docker compose exec -u root php chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
docker compose exec -u www-data php php artisan view:clear
```

artisan はできるだけ `docker compose exec -u www-data php ...` で実行してください。
