# Shiono Service Portal — 契約管理システム

管理者 / BP / カスタマー向けの契約・請求管理ポータル。

※ 旧称「エンドユーザー」は使わず、以降は **カスタマー** に統一する。

## ディレクトリ

| パス | 役割 |
|------|------|
| `Application/` | Laravel 11 アプリケーション本体（Phase 2 以降） |
| `Document/` | DB・IAM・BP階層などの設計ドキュメント |
| `Develop/` | Docker（Nginx / PHP-FPM / MariaDB / Mailpit） |

## 技術スタック

- PHP 8.3 / Laravel 11 / Livewire / Bootstrap
- MariaDB 11.4（`utf8mb4_uca1400_ai_ci`）
- Docker 開発環境（公開ポート 8080。SSL は外部リバースプロキシ）

## ドキュメント

- [全体アーキテクチャ](Document/architecture/overview.md)
- [用語集](Document/architecture/terminology.md)（カスタマー呼称の統一）
- [Phase 2 実装メモ](Document/architecture/phase2-setup.md)
- [DBスキーマ / ER](Document/database/er-diagram.md)
- [IAM（RBAC + ABAC）設計](Document/iam/iam-design.md)
- [BP階層設計](Document/bp/hierarchy-design.md)

## 開発フェーズ

1. **Phase 1** … 環境構築とドキュメント化（現行）
2. Phase 2 … Laravel 基盤・認証・IAM・2FA
3. Phase 3 … BP階層・カスタマー・拠点
4. Phase 4 … 品目・価格
5. Phase 5 … 契約・開通案内 PDF
6. Phase 6 … 問い合わせ・お知らせ・ダッシュボード
