# Phase 8 計画 — カスタマーユーザー管理

## 目的

Phase 7 で見送った **カスタマー画面からの自組織ユーザー管理** を実装する。  
管理者・BPによる代理作成（Phase 7-B）は維持し、カスタマー側でも同一 CN 内のメンバーを運用できるようにする。

| トラック | 内容 | 備考 |
|----------|------|------|
| **A. ロール・権限** | カスタマー内の管理ロールと権限付与 | 誰がメンバーを増やせるかを明確化 |
| **B. ユーザー管理 UI** | `/customer/users` の一覧・作成・編集・削除 | 自 CN のみ |
| **C. サービス拡張** | `UserManagementService` の customer actor 対応 | Phase 7 の拒否を解除（スコープ付き） |
| **D. 監査・ナビ** | 監査ログ・ヘッダーメニュー | 既存パターン踏襲 |

---

## 合意が必要なポイント（推奨付き）

実装前に次を確定する。**推奨**を既定案とする。

| # | 項目 | 推奨 | 代替 |
|---|------|------|------|
| 1 | 操作主体 | **自 CN の `customer_owner` のみ**が CRUD | 全 `customer_member` に許可（緩い） |
| 2 | 作成方式 | Phase 7 と同様 **直接作成**（初期PW＋強制変更） | メール招待リンク（別途トークン設計） |
| 3 | 付与可能ロール | `customer_owner` / `customer_member` | member のみ（owner は BP/管理者が付与） |
| 4 | 権限コード | 既存 **`iam.user.manage`** を customer スコープで再利用 | 新コード `customer.user.manage` |
| 5 | 本人操作 | 自分の削除・無効化・ロール降格は不可 | 他 owner がいる場合のみ自分を降格可 |
| 6 | 特権 | カスタマー画面に PW強制・2FA緊急スキップは **置かない** | 管理者のみ（現状維持） |

### 推奨の理由（要約）

1. **owner 限定**: メンバー全員が招待できると事故りやすい。BP の `bp_owner` に相当する責任者ロールを置く。  
2. **直接作成**: Phase 7 と UX・実装を揃え、招待トークン／メール本実装は後続にできる。  
3. **owner も付与可**: 初回の owner は admin/BP が作成し、以降は owner が owner/member を増やせる。  
4. **`iam.user.manage` 再利用**: 権限キー乱立を避け、スコープはサービス層で強制（既存方針と一致）。

---

## 現状

| 項目 | 状態 |
|------|------|
| admin/BP のユーザー CRUD | Phase 7 で実装済 |
| `UserManagementService` | customer **actor** からの create は拒否 |
| カスタマーロール | `customer_member` のみ |
| `/customer/users` | なし |
| 本人の PW / 2FA | Phase 7 アカウントメニューで実装済 |

---

## A. ロール・権限

### 追加ロール（推奨）

| code | scope | 説明 |
|------|-------|------|
| `customer_owner` | customer | 自 CN のユーザー管理・メンバー運用の責任者 |
| `customer_member` | customer | 既存。契約・請求参照、問い合わせ等（ユーザー管理なし） |

### 権限付与

| ロール | `iam.user.manage` | その他（現状維持） |
|--------|-------------------|-------------------|
| `customer_owner` | **付与** | `customer_member` と同程度＋ユーザー管理 |
| `customer_member` | なし | `contract.view`, `invoice.view`, `inquiry.*` 等 |

`IamSeeder` / Demo シードで owner を定義。既存デモのカスタマーユーザーは **owner に昇格**するか、member のまま＋別途 owner を追加するかを実装時に決める（推奨: デモは owner 1名）。

### スコープ規則

```text
actor.user_type = customer
  AND actor.customer_id = target.customer_id
  AND target.user_type = customer
  AND actor has iam.user.manage
```

禁止:
- admin / BP ユーザーの作成・操作
- 他 CN のユーザー操作
- 自分自身の削除・無効化

---

## B. 画面（`/customer/users`）

### ナビ

カスタマーヘッダーに「ユーザー」を追加（`iam.user.manage` がある場合のみ表示）。

### 一覧

- 自 CN のカスタマーユーザーのみ
- 表示: ログインID、氏名、ロール、有効/無効、2FA状態（参照のみ）
- 操作: 編集（作成は「新規」）

### 作成

| 項目 | 内容 |
|------|------|
| login_id | 必須・正規化 |
| 氏名 / メール | 氏名必須 |
| ロール | `customer_owner` / `customer_member` |
| 初期パスワード | 必須・確認付き |
| 初回PW変更要求 | 既定 ON |
| 所属 | **固定**（actor の customer_id。選択 UI なし） |

### 編集

- 氏名、メール、ロール、有効/無効、PW再設定（任意）
- 削除: 論理削除＋4桁確認コード（admin/BP と同じ UX）

### 置かないもの

- PW強制変更 / 2FA緊急スキップ（管理者特権のまま）
- 他 CN・BP・管理者の作成

---

## C. サービス・ルート

### `UserManagementService`

| 変更 | 内容 |
|------|------|
| `assertActorCanManage` | customer を許可 |
| `assertCreateAllowed` | customer actor は自 `customer_id` のみ・`user_type=customer` のみ |
| `assertCanManageTarget` | 同一 `customer_id` のみ |
| `assignableRoleCodes` | customer actor → `customer_owner`, `customer_member` |

admin/BP の既存挙動は不変。

### ルート

```text
/customer/users
/customer/users/create
POST /customer/users
/customer/users/{user}/edit
PUT/DELETE /customer/users/{user}
```

コントローラは `Customer\UserController`。ビューは admin/BP と共有パーシャルまたは `admin.users.*` の `routePrefix=customer` 拡張。

### テスト（TDD）

| ケース | 期待 |
|--------|------|
| owner が自 CN に member 作成 | 成功・監査 |
| owner が他 CN に作成 | 拒否 |
| member（権限なし）が一覧 | 403 |
| customer が BP ユーザー作成 | 拒否 |
| 自分を削除 | 拒否 |
| admin/BP の既存テスト | 回帰パス |

---

## D. 監査・Document

- 監査アクションは既存 `user.create` / `user.update` / `user.delete` を流用（actor が customer）
- [iam-design.md](../iam/iam-design.md) に `customer_owner` を追記
- ER 変更は原則不要（ロール・権限シードのみ）

---

## Step

| Step | 内容 |
|------|------|
| 8-0 | 本計画の合意（上表 #1〜#6） |
| 8-A1 | `customer_owner` ロール＋権限シード、デモデータ |
| 8-A2 | IAM Document 更新 |
| 8-B1 | `UserManagementService` 拡張 TDD |
| 8-B2 | `Customer\UserController`＋画面＋ルート＋ナビ |
| 8-B3 | 監査確認・回帰テスト |

**実施順:** 8-0 → A → B（サービス→UI）

---

## スコープ表

| 含む | 含まない（さらに後続） |
|------|------------------------|
| 自 CN 内のユーザー CRUD | メール招待・マジックリンク |
| `customer_owner` ロール | カスタマー独自のロール編集 UI |
| `iam.user.manage` の customer 利用 | SSO / SCIM |
| 確認コード付き削除 | 承認ワークフロー付き招待 |
| | 複数 CN 兼務（1ユーザー1CN 維持） |

---

## 残りの合意ポイント

上記「合意が必要なポイント」#1〜#6 は **推奨どおりで合意済み**（実装反映済）。
