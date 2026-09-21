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
- [Phase 7 計画 / 実装メモ](Document/architecture/phase7-plan.md) · [setup](Document/architecture/phase7-setup.md)
- [Phase 8 計画 / 実装メモ](Document/architecture/phase8-plan.md) · [setup](Document/architecture/phase8-setup.md)
- [Phase 9 計画 / 実装メモ](Document/architecture/phase9-plan.md) · [setup](Document/architecture/phase9-setup.md)
- [Phase 10 計画 / 実装メモ](Document/architecture/phase10-plan.md) · [setup](Document/architecture/phase10-setup.md)
- [DBスキーマ / ER](Document/database/er-diagram.md)
- [IAM（RBAC + ABAC）設計](Document/iam/iam-design.md)
- [BP階層設計](Document/bp/hierarchy-design.md)
- [Document テンプレートガイド](Document/templates/document-template-guide.md)（置換コード・予約語）

## 開発フェーズ

1. Phase 1 … 環境構築とドキュメント化
2. Phase 2 … Laravel 基盤・認証・IAM・2FA
3. Phase 3 … BP階層・カスタマー・拠点
4. Phase 4 … 品目・価格
5. Phase 5 … 契約・開通案内 PDF
6. Phase 6 … 問い合わせ・お知らせ・ダッシュボード
7. **Phase 7** … 共通UIシェル・ユーザー管理（admin/BP）・契約PDF・請求（実装済）
8. **Phase 8** … カスタマー画面の自組織ユーザー管理（実装済）
9. **Phase 9** … チケット管理（受領／発行・添付・ステータス）
10. **Phase 10** … デザイン刷新（見た目・情報設計・方針待ち）

### Phase 7 概要

Phase 2〜6 の積み残しと共通シェル刷新。実施順は **A → B → D → C**。

| トラック | 内容 |
|----------|------|
| **A. 共通UIシェル** | ヘッダーナビ、アカウントメニュー（PW / 2FA / ログアウト）、ダッシュボードはサマリのみ |
| **B. ユーザー管理** | 管理者・BPによるユーザー CRUD・ロール付与（カスタマーユーザーの代理作成含む）。カスタマー自身の作成は Phase 8 |
| **D. 契約ファイル生成** | テンプレート置換コード `{{code}}` → PDF 化（上書き・版管理なし） |
| **C. 請求・決済（案α）** | 契約×請求月、手動発行、ステータス管理のみ（外部PGなし） |

詳細: [phase7-plan.md](Document/architecture/phase7-plan.md) / [phase7-setup.md](Document/architecture/phase7-setup.md)

### Phase 8 概要

Phase 7 で見送った **カスタマー画面からの自組織ユーザー管理**。

| トラック | 内容 |
|----------|------|
| **A. ロール・権限** | `customer_owner` 等の管理ロールと `iam.user.manage` の customer スコープ |
| **B. ユーザー管理 UI** | `/customer/users` の一覧・作成・編集・削除（自 CN のみ） |
| **C. サービス拡張** | `UserManagementService` の customer actor 対応（スコープ強制） |
| **D. 監査・ナビ** | 監査ログ・ヘッダーメニュー連携 |

詳細: [phase8-plan.md](Document/architecture/phase8-plan.md) / [phase8-setup.md](Document/architecture/phase8-setup.md)

### Phase 9 概要

Phase 6 問い合わせを **チケット管理** に再編。

| トラック | 内容 |
|----------|------|
| **A. 受領／発行** | 別画面。管理者は受領のみ |
| **B. 起票・宛先** | カスタマー→管理BP、BP→親BP／管理者、代理起票は自BP受領 |
| **C. ステータス・添付** | 起票／取下／受領対応中／クローズ、ファイル添付（最大5・10MB） |
| **D. 未読・ダッシュボード** | 未読チケット数の表示（プッシュ通知なし） |

詳細: [phase9-plan.md](Document/architecture/phase9-plan.md) / [phase9-setup.md](Document/architecture/phase9-setup.md)

### Phase 10 概要

**デザイン刷新**。機能追加は行わず、UI の一貫性・モバイル・空状態などを整える。（旧 Phase 9 から繰り上げ）

| トラック | 内容 |
|----------|------|
| **A. デザインシステム** | カラー・タイポ・余白・コンポーネント規約 |
| **B. レイアウト** | ヘッダー / コンテンツ幅 / モバイル |
| **C. 画面テンプレート** | 一覧・詳細・フォーム・ダッシュボードの見た目統一 |
| **D. フィードバック UI** | アラート・空状態・確認ダイアログの統一 |

詳細: [phase10-plan.md](Document/architecture/phase10-plan.md) / [phase10-setup.md](Document/architecture/phase10-setup.md)

### さらに後続（未定義）

- 外部決済（Stripe 等）・会計連携
- メール招待リンクによるユーザー招待
- Redis / リアルタイム通知
- 帳票デザイナ・版管理・Word 出力
