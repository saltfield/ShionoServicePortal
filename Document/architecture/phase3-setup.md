# Phase 3 実装メモ

## 合意済み方針

| 項目 | 内容 |
|------|------|
| A. ユーザーアカウント作成 | Phase 3 では見送り（後続） |
| B. BP削除 | 配下BP / カスタマー / 所属ユーザーがある場合は削除不可 |
| C. サブツリー移動 | Phase 3 で実装（TDD） |
| D. 権限分担 | ルートBP作成は管理者のみ。子BP・カスタマー・拠点は管理者＋BP（自配下） |
| E. 主拠点 | 1カスタマーにつき `is_primary` は最大1件。主拠点以外の追加は可 |
| 請求書送付先 | **拠点ごとに別設定**（設置住所とは独立カラム） |

## スコープ

| 含む | 含まない（後続） |
|------|------------------|
| BP階層 CRUD（作成・編集・論理削除、親子、クロージャ、サブツリー移動） | ユーザーアカウント追加UI |
| カスタマー CRUD（CN採番、管理BP、2FAモード） | 任意の自己パスワード変更・共通ヘッダーナビ完成 |
| 拠点（sites）CRUD（設置情報 + 請求書送付先） | 品目・価格（Phase 4） |
| 管理者画面 + BP画面（自配下スコープ） | 契約・申請WF（Phase 5） |
| 認可・監査ログ連携 | 問い合わせ・お知らせ（Phase 6） |

## Step 進捗

| Step | 内容 | 状態 |
|------|------|------|
| 3-0 | 合意反映・ドキュメント更新 | **完了** |
| 3-1 | `BpHierarchyService` 拡充（移動・削除制約）TDD | **完了** |
| 3-2 | 管理者向け BP CRUD 画面 | **完了** |
| 3-3 | BP向け（自配下）BP CRUD 画面 | **完了** |
| 3-4 | カスタマー CRUD（管理者 / 管理BP） | **完了** |
| 3-5 | 拠点 `sites` + CRUD（送付先独立） | **完了** |
| 3-6 | 監査ログ・権限の通し確認 | **完了** |

## 確認結果

- `BpHierarchyService`: update / delete制約 / move / ancestors・descendants
- 管理者: `/admin/business-partners`, `/admin/customers`, 拠点CRUD
- BP: `/bp/business-partners`, `/bp/customers`（自配下のみ、ルート作成不可）
- `sites`: 設置先と請求書送付先を分離。主拠点は最大1件（初回は自動主拠点）
- Pest Feature: BP関連 + 既存回帰（実行時に確認）

## 拠点カラム

| 区分 | カラム |
|------|--------|
| 基本 | customer_id, name, is_primary, is_active |
| 請求書送付先 | billing_name, billing_department, billing_postal_code, billing_address, billing_building_name, billing_phone |
| 設置 | postal_code, address, building_name, phone |
