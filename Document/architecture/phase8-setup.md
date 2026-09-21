# Phase 8 実装メモ

## 目的

カスタマー画面での **自組織（同一 CN）ユーザー管理**。

## 合意（推奨案どおり確定）

| # | 決定 |
|---|------|
| 1 | 操作は `customer_owner` のみ（`iam.user.manage`） |
| 2 | 直接作成（初期PW）。招待メールは後続 |
| 3 | 付与ロール: `customer_owner` / `customer_member` |
| 4 | 権限は `iam.user.manage` を再利用 |
| 5 | 自分の削除・無効化不可 |
| 6 | カスタマー画面に管理者特権は置かない |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 8-0 | 合意 | **完了** |
| 8-A | ロール・権限・Document | **完了** |
| 8-B1 | `UserManagementService` customer actor | **完了** |
| 8-B2 | `/customer/users` UI・ルート・ナビ | **完了** |
| 8-B3 | テスト・回帰 | **完了** |

## 実装メモ

- デモユーザー `CUSUSER001` は `customer_owner`
- ヘッダー「ユーザー」は `iam.user.manage` 保持時のみ表示
- **管理者 / 管理BP** からもカスタマーユーザーを追加・確認・編集・削除できる
  - ユーザー管理画面（`/admin/users?tab=customer` / `/bp/users?tab=customer`）
  - カスタマー詳細の「カスタマーユーザー」セクション（追加・編集。作成/更新/削除後は詳細へ戻る）
- 既存 DB では `php artisan db:seed --class=IamSeeder` を再実行し、`bp_owner` に `iam.user.manage` を付与すること（未反映だと BP カスタマー詳細にユーザー欄が出ない）

## 関連

- 計画: [phase8-plan.md](phase8-plan.md)
- Phase 7（admin/BP ユーザー管理）: [phase7-setup.md](phase7-setup.md)
- 次: [phase9-plan.md](phase9-plan.md)（チケット管理）
