# Phase 2 実装メモ

## 合意済み方針

| 項目 | 内容 |
|------|------|
| テスト | Pest 3 |
| 認証UI | Breeze等なし・三種ログイン自前 |
| bp_closure | Phase 2 でテーブル骨格、操作は Phase 3 |
| login_id | 大文字正規化 |
| 構成 | `app/Domains/{Auth,Iam}` |
| 2FA | BP単位およびカスタマー（CN）単位で forced/optional/disabled（既定 optional）。有効中はTOTP必須。本人無効化可（forced除く）。disabled時は秘密鍵保持 |

## Step 進捗

| Step | 内容 | 状態 |
|------|------|------|
| 2-0 | Docker疎通、Laravel/Livewire/Bootstrap/Pest、DB接続 | **完了** |
| 2-1 | BPN/CN 採番サービス（TDD） | **完了** |
| 2-2 | users / BP・Customer 最小マイグレーション | **完了** |
| 2-3 | 三種ログイン（資格情報） | **完了** |
| 2-4 | 2FA | **完了** |
| 2-5 | パスワード強制変更 | **完了** |
| 2-6 | RBAC | **完了** |
| 2-7 | ABAC | **完了** |
| 2-8 | 管理者特権・監査ログ参照 | **完了** |

## Step 2-0 確認結果

- http://localhost:8080 → 200（トップページ表示）
- MariaDB 接続・Laravel 標準マイグレーション適用済み
- `./vendor/bin/pest` → 2 passed
- PHP コンテナに Noto CJK フォント導入済み

## Step 2-1 確認結果

- `number_sequences` テーブル追加（`utf8mb4_bin`）
- `NumberSequenceService` … `BPN|CN` + `YYYYMM` + 3桁、月次リセット、行ロック採番
- テストDB: `ssp_testing`
- Pest: NumberSequence 関連 **8 passed**

## Step 2-2 確認結果

- `business_partners` / `bp_closure` / `customers` / `users`（login_id・user_type・2FAカラム）
- `BpHierarchyService`（ルート/子作成・深さ5制限・クロージャ骨格）
- Factories: User / BusinessPartner / Customer
- Pest: 全体 **17 passed**

## Step 2-3 確認結果

- ガード分離: `admin` / `bp` / `customer`
- URL: `/admin/login`, `/bp/login`, `/customer/login`（完全分離）
- BPは BPN、カスタマーは CN 必須。他種別・非アクティブ・BPN不一致は拒否
- Pest: 全体 **26 passed**

## Step 2-4 確認結果

- 実効モード: BP単位およびカスタマー（CN）単位の forced/optional/disabled（admin はユーザー単位）
- ログイン後: 任意+未設定→ダッシュボード / 有効時→チャレンジ / 強制+未設定→セットアップ
- disabled・緊急スキップ時は TOTP 省略（秘密鍵は保持）
- 本人無効化は optional のみ可
- Pest: 全体 **34 passed**

## Step 2-5 確認結果

- `must_change_password=true` 時は `/…/password/change` へ誘導
- ダッシュボード等は変更完了までブロック
- 新パスワードは確認一致・現行と別・英字+数字・8文字以上
- Pest: 全体 **39 passed**

## Step 2-6 確認結果

- テーブル: `permissions` / `roles` / `role_permission` / `user_role`（スコープ付き）
- `RbacService` + `AuthorizationService`（ABAC は Step 2-7 で拡張）
- `IamSeeder` … system_admin / bp_owner / bp_sales / bp_support / customer_member
- ミドルウェア `permission:code,guard`
- Pest: 全体 **44 passed**

## Step 2-7 確認結果

- テーブル: `policies` / `policy_conditions`
- `AbacEvaluator`（deny-overrides）+ `BpRelationResolver`（クロージャ）
- シード: P1〜P5（契約範囲、卸価格は直接子、高額承認、クローズ問い合わせ、管理者2FA）
- Pest: 全体 **49 passed**

## Step 2-8 確認結果

- テーブル: `audit_logs`（参照専用。編集・削除UIなし）
- `AuditLogger` … ログイン成否・特権操作を記録
- 管理者画面: ユーザー特権（PW強制変更 / 2FA緊急スキップ）・2FAモード（BP / カスタマー）・監査ログ一覧/詳細
- `AdminPrivilegeService` … RBAC+ABAC 認可後に操作し監査ログへ残す
- Pest: 全体 **56 passed**
