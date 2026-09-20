# Phase 8 実装メモ

## 目的

カスタマー画面での **自組織（同一 CN）ユーザー管理**。

## 合意待ち（計画の推奨案）

詳細は [phase8-plan.md](phase8-plan.md)。

| # | 推奨 |
|---|------|
| 1 | 操作は `customer_owner` のみ |
| 2 | 直接作成（初期PW）。招待メールは後続 |
| 3 | 付与ロール: `customer_owner` / `customer_member` |
| 4 | 権限は `iam.user.manage` を再利用 |
| 5 | 自分の削除・無効化不可 |
| 6 | カスタマー画面に管理者特権は置かない |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 8-0 | 合意 | **待ち** |
| 8-A | ロール・権限・Document | — |
| 8-B | サービス＋UI＋テスト | — |

## 関連

- 計画: [phase8-plan.md](phase8-plan.md)
- Phase 7（admin/BP ユーザー管理）: [phase7-setup.md](phase7-setup.md)
