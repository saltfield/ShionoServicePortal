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
              +------------+------------+------------+
              |            |            |
         [Scheduler]    [Queue]     [Mailpit]
       schedule:work   queue:work   開発メール
        （自動請求）   （通知メール）
```

- アプリ公開は Docker 内 Nginx の **8080**。
- 本番相当の TLS は上位リバースプロキシで実施。本スタックは平文 HTTP を想定。
- `X-Forwarded-For` / `X-Forwarded-Proto` を Nginx・PHP で受け取る設定済み。
- **自動請求**は `scheduler` コンテナ（またはホスト cron の `schedule:run`）が必須。手順は [deploy.md](deploy.md)。
- **メール通知**は `queue` コンテナ（`queue:work`）が必須。手順は [phase13-setup.md](phase13-setup.md) / [deploy.md](deploy.md)。

## ディレクトリ役割

| ディレクトリ | 内容 |
|--------------|------|
| `/Application` | Laravel ソース、マイグレーション、Livewire、テスト |
| `/Document` | ER、IAM、BP階層、フェーズ計画／実装メモ、デプロイ手順 |
| `/Develop` | docker-compose、Nginx / PHP / MariaDB / Scheduler / Queue 設定 |

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
| Redis | 未導入（キャッシュ／セッション／キューは DB ドライバ） |
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
未ログインで `/admin`・`/bp`・`/customer` ルートにアクセスした場合は各ログインへ。認証済みならダッシュボードへ。

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

## フェーズ対応（現状）

| Phase | 主な成果 | 状態 |
|-------|----------|------|
| 2 | Laravel / Livewire / Bootstrap、三種ログイン、採番、2FA、IAM・ABAC、監査ログ | 完了 |
| 3 | BP階層CRUD、カスタマー・拠点（請求書送付先は拠点単位） | 完了 |
| 4 | 品目（イニシャル/ランニング・必須セット）、卸価格・カスタマー価格（価格の説明: [pricing.md](pricing.md)） | 完了 |
| 5 | 契約WF、価格承認・ロック、Documentテンプレート、契約データ | 完了 |
| 6 | 問い合わせ（後にチケット化）、お知らせ、ダッシュボード | 完了 |
| 7 | 共通UIシェル、ユーザー管理（admin/BP）、置換コード＋PDF、請求基盤 | **完了** |
| 8 | カスタマー自組織ユーザー管理 | **完了** |
| 9 | チケット管理（受領／発行・添付・ステータス） | **完了** |
| 10 | 月次請求・キックバック・自動生成・生成履歴 | **完了** |
| 11 | デザイン刷新（Bootstrap継続・トークン／全画面展開） | **完了** |
| 12 | ロール割り当て・定義・ABACポリシー UI（Azure IAM 風） | **完了**（12-A / 12-B / 12-C） |
| 13 | メール通知（チケット／価格申請／強制PW・オプトアウト・queue） | **完了** |

計画・実装メモ: 各 `phaseN-plan.md` / `phaseN-setup.md`。デプロイは [deploy.md](deploy.md)。
