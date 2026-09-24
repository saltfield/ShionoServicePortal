# Shiono Service Portal — 契約管理システム

管理者 / BP / カスタマー向けの契約・請求管理ポータル。

※ 旧称「エンドユーザー」は使わず、以降は **カスタマー** に統一する。

## ディレクトリ

| パス | 役割 |
|------|------|
| `Application/` | Laravel 11 アプリケーション本体 |
| `Document/` | DB・IAM・BP階層・フェーズ計画／実装メモ・デプロイ手順 |
| `Develop/` | Docker（Nginx / PHP-FPM / MariaDB / Mailpit / Scheduler） |

## 技術スタック

- PHP 8.3 / Laravel 11 / Livewire / Bootstrap
- MariaDB 11.4（`utf8mb4_uca1400_ai_ci`）
- Docker 開発・デプロイ用スタック（公開ポート 8080。SSL は外部リバースプロキシ）
- スケジューラ: `php artisan schedule:work`（自動請求バッチ）

## ドキュメント

- [全体アーキテクチャ](Document/architecture/overview.md)
- [用語集](Document/architecture/terminology.md)
- [価格の種類と使い分け](Document/architecture/pricing.md)
- [デプロイ手順](Document/architecture/deploy.md)
- [Phase 2〜6 実装メモ](Document/architecture/phase2-setup.md)（個別ファイルあり）
- [Phase 7](Document/architecture/phase7-plan.md) · [setup](Document/architecture/phase7-setup.md)（共通UI・ユーザー管理・PDF・請求基盤）
- [Phase 8](Document/architecture/phase8-plan.md) · [setup](Document/architecture/phase8-setup.md)（カスタマー自組織ユーザー管理）
- [Phase 9](Document/architecture/phase9-plan.md) · [setup](Document/architecture/phase9-setup.md)（チケット管理）
- [Phase 10](Document/architecture/phase10-plan.md) · [setup](Document/architecture/phase10-setup.md)（月次請求・キックバック）**実装済**
- [Phase 11](Document/architecture/phase11-plan.md) · [setup](Document/architecture/phase11-setup.md)（デザイン刷新・未着手）
- [DBスキーマ / ER](Document/database/er-diagram.md)
- [IAM（RBAC + ABAC）設計](Document/iam/iam-design.md)
- [BP階層設計](Document/bp/hierarchy-design.md)
- [Document テンプレートガイド](Document/templates/document-template-guide.md)

## 開発フェーズ（現状）

| Phase | 内容 | 状態 |
|-------|------|------|
| 1 | 環境構築とドキュメント化 | 完了 |
| 2 | Laravel 基盤・認証・IAM・2FA | 完了 |
| 3 | BP階層・カスタマー・拠点 | 完了 |
| 4 | 品目・価格 | 完了 |
| 5 | 契約・開通案内 PDF | 完了 |
| 6 | 問い合わせ・お知らせ・ダッシュボード | 完了 |
| 7 | 共通UIシェル・ユーザー管理（admin/BP）・契約PDF・請求基盤 | **完了** |
| 8 | カスタマー画面の自組織ユーザー管理 | **完了** |
| 9 | チケット管理（受領／発行・添付・ステータス） | **完了** |
| 10 | 月次請求・キックバック・自動生成・生成履歴 | **完了** |
| 11 | デザイン刷新 | **未着手** |

### Phase 10 概要（現行の請求運用）

| 項目 | 内容 |
|------|------|
| カスタマー請求 | ルートBP発行（`issuer_bp_id`）＋管理BP（`owning_bp_id`）。金額は契約明細の **EU単価（`unit_price`）** |
| キックバック | BP間多段。差額＝上値−仕切り。負額はエラー（契約単位スキップ） |
| 自動生成 | 管理者設定の月末／毎月N日＋時刻。Docker `scheduler` 必須 |
| 手動実行 | 管理者「自動請求設定」から対象月指定 |
| 生成履歴 | 成功／一部失敗／失敗、請求・キックバック件数、作成請求一覧への導線 |
| イニシャル | 初回請求月のみ。未請求なら後続月でキャッチアップ可 |

詳細: [phase10-plan.md](Document/architecture/phase10-plan.md) / [phase10-setup.md](Document/architecture/phase10-setup.md)

### Phase 11 概要

機能追加ではなく **見た目・情報設計の刷新**。詳細は [phase11-plan.md](Document/architecture/phase11-plan.md)。

### さらに後続（未定義）

- 外部決済（Stripe 等）・会計連携
- メール招待リンクによるユーザー招待
- Redis / リアルタイム通知
- 帳票デザイナ・版管理・Word 出力

## クイックスタート（開発）

```bash
cd Develop
cp .env.example .env
docker compose up -d --build
docker compose exec -u www-data php php artisan migrate --seed
```

- Web: http://localhost:8080
- Mailpit: http://localhost:8025

本番相当の手順は [デプロイ手順](Document/architecture/deploy.md) を参照。
