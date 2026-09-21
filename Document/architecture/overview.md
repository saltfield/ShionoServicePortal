# 全体アーキテクチャ概要

## システム境界

```text
[クライアント]
      |
      v
[外部リバースプロキシ / SSL終端]  ← 本リポジトリの範囲外
      |
      v  HTTP :8080
[Nginx] --fastcgi--> [PHP-FPM 8.3] --pdo--> [MariaDB 11.4]
                           |
                      [Mailpit] 開発メール
```

- アプリ公開は Docker 内 Nginx の **8080**。
- 本番相当の TLS は上位リバースプロキシで実施。本スタックは平文 HTTP を想定。
- `X-Forwarded-For` / `X-Forwarded-Proto` を Nginx・PHP で受け取る設定済み。

## ディレクトリ役割

| ディレクトリ | 内容 |
|--------------|------|
| `/Application` | Laravel ソース、マイグレーション、Livewire、テスト |
| `/Document` | ER、IAM、BP階層、API/画面仕様（段階的に追加） |
| `/Develop` | docker-compose、Nginx / PHP / MariaDB 設定 |

## 決定事項（Phase 1 合意）

| 項目 | 決定 |
|------|------|
| BP階層 | 隣接リスト + **クロージャテーブル**（最大5階層） |
| IAM | **RBAC + 本格 ABAC**（ポリシーエンジン） |
| ユーザー↔BP | **1ユーザー = 1BP**。兼務は BP ごとに別アカウント |
| 文字コード | `utf8mb4` / **`utf8mb4_uca1400_ai_ci`** |
| 識別子 | アプリで正規化（大文字統一等）+ DB は **`utf8mb4_bin` 厳密比較** |
| PHP | 8.3 |
| タイムゾーン | Asia/Tokyo |
| Redis | Phase 6 前まで未導入（Mailpit のみ） |
| 管理者組織コード | なし |
| ログイン画面 | **管理者 / BP / カスタマー**で完全分離 |
| 監査ログ | **管理者画面から参照可能**（参照専用） |
| 呼称 | 旧「エンドユーザー」は使わず **カスタマー**（`customer` / `/customer/*`）に統一。詳細は [用語集](terminology.md) |
| 2FA | **BP単位** forced/optional/disabled（既定 optional）。有効中はTOTP必須。本人無効化可（forced BP除く） |

## ユーザー種別とログイン

| 種別 | ログイン画面 | 追加識別子 | セッション領域 |
|------|--------------|------------|----------------|
| 管理者 | `/admin/login` | なし | `/admin/*` |
| BP | `/bp/login` | **BPN**（例: BPN202609001） | `/bp/*` |
| カスタマー | `/customer/login` | **CN**（例: CN202609001） | `/customer/*` |

3画面はフォーム・URL・認証ガードを共有しない。他種別の資格情報ではログイン不可。

## 認証・権限の流れ（概要）

```text
種別専用ログイン画面（/admin/login | /bp/login | /customer/login）
  → 資格情報検証（当該 user_type のみ。BPはBPN、カスタマーはCN）
  → Google Authenticator 2FA
  → セッション確立（領域別）
  → 認可: RBAC（ロール→許可アクション） + ABAC（属性ポリシー評価）
  → データスコープ（自BP / 配下 BP クロージャ）と組み合わせ
  → 重要操作は監査ログへ。管理者は監査ログ画面で参照可
```

## 後続フェーズとの対応

| Phase | 主な成果 |
|-------|----------|
| 2 | Laravel / Livewire / Bootstrap、三種ログイン完全分離、採番、2FA、IAM・ABAC、管理者特権、監査ログ参照画面 |
| 3 | BP階層CRUD、カスタマー・拠点（請求書送付先は拠点単位で独立） |
| 4 | 品目（イニシャル/ランニング・必須セット）、卸価格・カスタマー価格 |
| 5 | 契約WF、価格承認・ロック（変更申請可）、Documentテンプレート（最大5）、契約データ（名称:値） |
| 6 | 問い合わせチャット、お知らせ、ダッシュボード |
| 7 | 共通UIシェル、ユーザー管理（admin/BP）、置換コード＋PDF生成、請求・決済（ステータス）、本人PW/2FA設定 |
| 8 | カスタマー画面の自組織ユーザー管理（計画: [phase8-plan.md](phase8-plan.md)） |
| 9 | チケット管理（受領／発行・添付・ステータス。計画: [phase9-plan.md](phase9-plan.md)） |
| 10 | 月次請求・キックバック（カスタマー請求タブ・自動生成・BPキックバック。計画: [phase10-plan.md](phase10-plan.md)） |
| 11 | デザイン刷新（見た目・情報設計。計画: [phase11-plan.md](phase11-plan.md)） |
