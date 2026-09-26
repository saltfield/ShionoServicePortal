# Phase 12 実装メモ — ロール割り当て UI（Azure IAM 風）

## 目的

ユーザー権限付与を **ロール割り当て（複数可）** に刷新する。ロール定義 UI は含まない（12-A）。

## 合意ステータス

| 項目 | 状態 |
|------|------|
| Azure IAM 風（Role assignment） | **確定** |
| 複数ロール正式仕様 | **確定** |
| 範囲 = 割り当て UI のみ（A） | **確定** |
| ロール定義 UI | **後続**（本フェーズ対象外） |
| スコープ = 所属組織固定 | **確定**（12-A） |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 12-0 | 計画 Document | **完了** |
| 12-A1 | 複数割当サービス | **完了** |
| 12-A2 | 割り当て UI | **完了** |
| 12-A3 | 作成時初期ロール | **完了** |
| 12-A4 | 監査・テスト | **完了** |
| 12-A5 | 回帰・受け入れ | **完了** |
| 12-B1 | ロール定義 UI（管理者） | **完了** |
| 12-C1 | ABACポリシー管理 UI（管理者） | **完了** |

## 実装サマリ

### 12-A（割り当て）

- `UserManagementService`: `assignRoleToUser` / `revokeRoleFromUser`（最後の1件は解除不可）。`syncPrimaryRole` 廃止
- ルート: `{guard}/users/{user}/roles` POST（割当）/ DELETE（解除）
- UI: ユーザー編集に「ロールの割り当て」パネル
- 監査: `role.assign` / `role.revoke`

### 12-B（定義）

- `RoleManagementService` + `Admin\RoleController`（`/admin/roles`）
- ナビ「ロール管理」（`iam.role.manage`）
- 組み込みロール保護・カスタム作成／編集／削除
- `assignableRoleCodes` をスコープ別 DB 照会に変更（カスタムロールが割当候補に出る）
- 監査: `role.create` / `role.update` / `role.delete`
- テスト: `RoleManagementTest`

### 12-C（ABACポリシー）

- `PolicyManagementService` + `Admin\PolicyController`（`/admin/policies`）
- ナビ「ABACポリシー」（`iam.policy.manage`）
- 条件行（group_no OR/AND、演算子、値）の作成・編集・削除・有効無効
- 監査: `policy.create` / `policy.update` / `policy.delete`
- テスト: `PolicyManagementTest`

## 主な対象ファイル

| 領域 | パス |
|------|------|
| サービス | `Application/app/Domains/Iam/Services/UserManagementService.php` |
| RBAC | `Application/app/Domains/Iam/Services/RbacService.php` |
| コントローラ | `Admin\UserPrivilegeController` / `Bp\UserController` / `Customer\UserController` |
| ビュー | `resources/views/admin/users/*`（BPも共用）、`customer/users/*` |
| テスト | `tests/Feature/Iam/*` |

## 実装メモ（着手時）

- `syncPrimaryRole` の全削除ロジックをやめ、追加／解除 API に置換
- 最後の1ロール解除は拒否
- 一覧のロール表示を複数対応に更新
- 監査アクション例: `iam.role.assign` / `iam.role.revoke`（既存監査の付け方に合わせる）

## 関連

- 計画: [phase12-plan.md](phase12-plan.md)
- 運用マニュアル: [../iam/iam-operations-manual.md](../iam/iam-operations-manual.md)
- IAM: [../iam/iam-design.md](../iam/iam-design.md)
- 前フェーズ: [phase11-setup.md](phase11-setup.md)
