# Phase 12 計画 — ロール割り当て UI（Azure IAM 風）

## 目的

ユーザーへの権限付与を、Azure IAM（Azure RBAC）に近い **「ロールの割り当て」** として再設計する。  
パーミッションの直接付与は行わず、既存の `roles` / `permissions` / `user_role` を活用する。

## 合意ステータス

| 項目 | 決定 |
|------|------|
| メンタルモデル | **Azure IAM 風**（Role assignment。Action のユーザー直接付与はしない） |
| 複数ロール | **正式仕様**（1ユーザーに複数 `user_role` を許可） |
| Phase 12 範囲 | **A: 割り当て UI のみ**（既存シードロールの付与・解除） |
| ロール定義 UI | **含まない**（カスタムロール作成・`role_permission` 編集は後続） |
| DB 大幅変更 | **原則なし**（`user_role` は既に複数可。ロジックの1本化をやめる） |

## Azure との対応（再掲）

| Azure | 本システム |
|-------|-----------|
| Role | `roles`（permissions の束） |
| Role assignment | `user_role`（user + role + scope） |
| Scope | `scope_type` + `scope_id`（system / bp / customer） |
| Actions | `permissions`（ロール経由のみ） |

Phase 12-A ではスコープは **ユーザーの所属組織に固定**（admin→system、BP→自 `bp_id`、customer→自 `customer_id`）。  
Azure の「任意リソースへの割り当てウィザード」までは広げない。

## 現状と課題

| 現状 | 課題 |
|------|------|
| ユーザー作成／編集で `role_code` を1つ選択 | Azure の「割り当て」概念とズレる |
| `UserManagementService::syncPrimaryRole` が既存割当を全削除 | 複数ロール不可 |
| ロール表示がコードのみ | 権限内容が見えない |
| `iam.role.manage` はシードのみ | Phase 12-A では未使用（ロール定義 UI なし） |

## スコープ

### 含む（12-A）

| 項目 | 内容 |
|------|------|
| 割り当て一覧 | ユーザー編集（または詳細）に「ロール割り当て」表（ロール名・コード・スコープ） |
| 割り当て追加 | 割当可能ロールから選択 → 権限プレビュー → 追加 |
| 割り当て解除 | 行単位で解除（確認あり） |
| 複数ロール | 同一ユーザーに複数ロール可。実効権限は和集合 |
| 作成時 | 初回は **1つ以上** のロール必須（空ユーザー禁止） |
| 対象画面 | admin / BP / カスタマーのユーザー管理（既存 CRUD 上に載せる） |
| 割当可能ロール | 既存 `assignableRoleCodes` を踏襲（種別・操作者による制限） |
| 監査 | ロール付与／解除を監査ログに記録 |
| テスト | Feature: 複数付与・解除・権限和集合・越権拒否 |

### 含まない（後続）

| 項目 | 備考 |
|------|------|
| ~~ロール定義 UI~~ | **12-B で実装** |
| `user_permission` 直接付与 | Azure RBAC でも通常しない |
| 任意スコープ選択（他BPへの兼務割当など） | 1ユーザー1BP方針と衝突するため別議論 |
| グループ／サービスプリンシパル | 対象外 |
| ABAC ポリシー編集 UI | 既存のまま |

## 12-B — ロール定義 UI（管理者）

| 項目 | 内容 |
|------|------|
| 権限 | `iam.role.manage` |
| 画面 | `/admin/roles` 一覧・作成・編集・削除 |
| 組み込み | 6種は削除不可。コード／スコープ固定。権限・名前・説明は編集可（`system_admin` の権限は常に全権限） |
| カスタム | 作成・編集・削除可（割当ユーザーがいる場合は削除不可） |
| 割当候補 | `assignableRoleCodes` は DB の同スコープロールを返す（カスタムもユーザー割当に出る） |

## UI 方針

```text
[ユーザー編集]

  基本情報（login_id / 氏名 / 有効 …）

  ロールの割り当て
  ┌──────────────┬────────┬────────┐
  │ ロール        │ スコープ │ 操作   │
  ├──────────────┼────────┼────────┤
  │ BPオーナー    │ BP xxx  │ 解除   │
  │ BP営業        │ BP xxx  │ 解除   │
  └──────────────┴────────┴────────┘
  [割り当てを追加]

  （追加パネル）
  ロール: [▼ bp_sales — BP営業]
  含まれる権限: contract.view, contract.create, …
  [追加]
```

- ロール選択肢は **表示名＋コード**（シードの `roles.name` を利用）
- 権限プレビューは選択ロールの `permissions.code` 一覧（読み取りのみ）
- Phase 11 の `ssp-page-title` / テーブル／パネルトーンに合わせる

## サービス変更方針

| 現在 | Phase 12 |
|------|----------|
| `syncPrimaryRole`（全削除→1付与） | **廃止または作成時のみの初期付与に限定** |
| — | `assignRoleToUser` / `revokeRoleFromUser`（割当可能チェック付き） |
| — | `listAssignments(User)` / `effectivePermissionCodes(User)`（表示・テスト用） |
| `create` の `role_code` | `role_codes: list<string>`（最低1件）または初回1件＋編集で追加 |
| `update` の単一 `role_code` | フォームから外し、割当 API／別 POST に分離 |

スコープ解決は現行どおり:

| user_type | scope_type | scope_id |
|-----------|------------|----------|
| admin | system | null |
| bp | bp | `users.bp_id` |
| customer | customer | `users.customer_id` |

## 実効権限

- `RbacService::permissionCodes` は既に **全ロールの和集合** を返す実装を前提に維持
- 画面・テストで「複数ロール時に和集合になる」ことを明示確認

## Step

| Step | 内容 | 成果物 |
|------|------|--------|
| **12-0** | 本計画の Document 反映・iam-design 追記 | **完了** |
| **12-A1** | サービス：複数割当 API・`syncPrimaryRole` 改修 | **完了** |
| **12-A2** | UI：割り当て一覧・追加・解除・権限プレビュー | **完了** |
| **12-A3** | 作成フォームの初期ロール（1つ以上） | **完了** |
| **12-A4** | 監査ログ・Feature テスト | **完了** |
| **12-A5** | 回帰（既存ユーザー1ロールのまま動作）・受け入れ | **完了** |
| **12-B1** | ロール定義 UI（管理者・`iam.role.manage`） | **完了** |
| **12-C1** | ABACポリシー管理 UI（管理者・`iam.policy.manage`） | **完了** |

## 受け入れ基準

1. ユーザーに **複数ロール** を割り当てでき、実効権限がその和集合になる  
2. 割り当て解除ができ、残ロールのみが有効  
3. 操作者は **割当可能ロール以外** を付与できない  
4. ロール定義の編集画面は存在しない（12-A 範囲）  
5. 既存の単一ロールユーザーはマイグレーションなしで継続利用できる  
6. admin / BP / カスタマーのユーザー管理で同様の割り当て UX が使える（権限・スコープ制限は既存どおり）

## リスク・注意

- 作成直後にロール0件のユーザーを許すと何もできないため、**最低1ロール**を維持する  
- 「最後の1ロール解除」は拒否するか、確認のうえ拒否（推奨: **拒否**）  
- 一覧の `roles->first()` 表示は **複数表示** または「N件」に直す必要あり

## 関連

- 実装メモ: [phase12-setup.md](phase12-setup.md)
- 運用マニュアル: [../iam/iam-operations-manual.md](../iam/iam-operations-manual.md)
- IAM: [../iam/iam-design.md](../iam/iam-design.md)
- 前フェーズ: [phase11-plan.md](phase11-plan.md) / [phase11-setup.md](phase11-setup.md)
- ユーザー管理導入: [phase7-plan.md](phase7-plan.md) / [phase8-plan.md](phase8-plan.md)
