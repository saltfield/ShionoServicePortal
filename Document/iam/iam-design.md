# IAM 設計（RBAC + ABAC）

## 目標

Azure IAM に近い柔軟性を持つ。

- **ユーザー種別**（admin / bp / customer）は粗い分類
- **ロール（RBAC）**で「何ができるか」の基本セットを付与
- **ポリシー（ABAC）**で属性・関係・コンテキストによる許可/拒否を評価
- データ範囲は BP クロージャ（自BP・配下）と組み合わせる

## 合意事項

| 項目 | 内容 |
|------|------|
| モデル | **RBAC + 本格 ABAC**（Phase 2 で導入） |
| ユーザー↔BP | 1対1。兼務は別アカウント |
| 2FA | **BP単位**で強制/任意/無効（デフォルト任意）。TOTP有効中はログイン必須。本人無効化可（強制BP除く）。管理者はBPオーバーライド可 |
| 識別子 | アプリ正規化 + DB `utf8mb4_bin` |
| ログイン画面 | **管理者 / BP / カスタマー**で完全分離（別画面・別URL） |
| 監査ログ | **管理者画面から参照可能**（一覧・フィルタ・詳細） |
| 呼称 | 「エンドユーザー」は使わない。**カスタマー**（`customer`）に統一 |

---

## 1. 認証

### ログイン画面の分離（必須）

**管理者 / BP / カスタマー**のログイン画面は **完全に分離**する（URL・画面・認証ガードいずれも共有しない）。

| 画面 | 想定URL（Phase 2 で確定） | 対象 | 備考 |
|------|---------------------------|------|------|
| 管理者ログイン | `/admin/login` | `user_type = admin` のみ | BPN/CN 入力欄なし |
| BPログイン | `/bp/login` | `user_type = bp` のみ | **BPN** 必須 |
| カスタマーログイン | `/customer/login` | `user_type = customer` のみ | **CN** 必須 |

**ガード方針**
- 各ログインURLでは、対応する `user_type` 以外の資格情報では認証させない。
- ログイン成功後の領域も分離する。
  - 管理者: `/admin/*`
  - BP: `/bp/*`（配下ポータル）
  - カスタマー: `/customer/*`
- 他領域のURLへはセッション種別不一致で拒否（リダイレクト先は自領域のログイン画面）。
- 画面間に「種別切替タブ」や共通ログインフォームは置かない。

### ログイン入力

| 種別 | 画面 | 必須入力 |
|------|------|----------|
| admin | `/admin/login` | login_id, password, TOTP |
| bp | `/bp/login` | login_id, **BPN**, password, TOTP |
| customer | `/customer/login` | login_id, **CN**, password, TOTP |

### 識別子正規化ルール（アプリ）

- BPN / CN / login_id: trim → 英数字は **大文字統一**（login_id は方針を Phase 2 で最終化。推奨は大文字統一）
- 形式バリデーション: `/^(BPN|CN)\d{6}\d{3}$/` つまり `PREFIX + YYYYMM + 3桁`
- DB 格納・比較は正規化後の値のみ。照合は **utf8mb4_bin**

### 採番

`number_sequences`（prefix, year_month, last_seq）をトランザクション＋行ロックで採番。

例: `BPN` + `202609` + `001` → `BPN202609001`

### 2FA・管理者特権

Google Authenticator（TOTP）。制御の主単位は **BP**（管理者によるオーバーライド）。全体のデフォルトは **任意**。

#### モード定義（3値）

| モード | コード | 意味 |
|--------|--------|------|
| 強制 | `forced` | 配下ユーザーは2FA必須。未設定ならセットアップ強制。**本人・一般操作での無効化不可** |
| 任意 | `optional` | 未設定ならパスワードのみ可。有効化は本人任意。**ログイン後に本人が無効化可能** |
| 無効 | `disabled` | 2FAを使わない（TOTP入力スキップ）。新規有効化も不可 |

**共通ルール:** ユーザーの TOTP が有効（`two_factor_confirmed_at` あり）のあいだは、実効モードが `disabled` でなく、かつ管理者緊急無効化中でなければ、**ログイン時 TOTP は必須**。

#### 実効モードの解決順

```text
1. user_type = bp  
     → business_partners.two_factor_mode（自 bp_id）
2. user_type = customer  
     → customers.two_factor_mode（自 customer_id。管理BPとは独立）
3. user_type = admin  
     → users.two_factor_mode（BPを持たないためユーザー単位。未設定時は optional）
```

| 対象 | デフォルト |
|------|------------|
| 新規 BP の `two_factor_mode` | **optional（任意）** |
| 新規カスタマーの `two_factor_mode` | **optional（任意）** |
| 管理者ユーザーの `two_factor_mode` | **optional（任意）** |

#### BP / カスタマー強制時の制御

- BP の `forced` は当該 BP に属する **BP ユーザー** に適用する。
- カスタマーの `forced` は当該 CN に属する **カスタマーユーザー** に適用する（管理BPのモードとは独立）。
- セットアップ未完了ならログイン後フローでセットアップ完了まで先に進めない。
- 設定画面に「2FAを無効にする」を出さない／実行しても拒否。
- 管理者のみ BP のモード変更（`forced` → `optional` / `disabled`）で解除可能。

#### カラム

**`business_partners`**

| フィールド | 意味 |
|------------|------|
| two_factor_mode | `forced` / `optional` / `disabled` … **DEFAULT `optional`**。管理者がBP単位でオーバーライド |

**`users`**

| フィールド | 意味 |
|------------|------|
| two_factor_mode | admin のみ使用。`forced` / `optional` / `disabled` / `NULL`（NULL=optional） |
| two_factor_secret | TOTP シークレット（暗号化保存推奨） |
| two_factor_confirmed_at | セットアップ完了（有効化済み）。NULL なら未設定 |
| two_factor_forced_disabled | 管理者による**緊急**TOTPスキップ（端末紛失等）。監査必須。BP強制下でも一時解除可 |
| must_change_password | 次回ログインでパスワード変更必須 |
| password_changed_at | 監査用 |

※ 旧案の「種別デフォルト required + users.two_factor_policy」は廃止。BP（および admin 個人）の3値モードに置き換える。

#### ログイン・設定時の挙動

| 実効モード | 2FA未設定 | 2FA有効中 | 本人による無効化 | 管理者緊急無効化中 |
|------------|-----------|-----------|------------------|-------------------|
| **forced** | セットアップ強制 | TOTP必須 | **不可** | TOTPスキップ（監査） |
| **optional** | パスワードのみ可 | TOTP必須 | **可**（ログイン後） | TOTPスキップ（監査） |
| **disabled** | TOTPなし | 入力スキップ（**秘密鍵・confirmed_at は保持**。再任意/強制時にそのまま利用可） | 該当なし（そもそも無効） | — |

本人無効化成功時: `two_factor_secret` / `two_factor_confirmed_at` をクリアし、監査ログを残す。

**BPモードを `disabled` にしたとき:** 配下ユーザーの TOTP 設定は **削除しない（保持）**。ログイン時のみスキップする。再び `optional` / `forced` に戻した時点で、保持済み設定があれば従来どおり TOTP 必須となる。
#### 管理者画面

| 操作 | permission（例） |
|------|------------------|
| BPの `two_factor_mode` 変更（強制/任意/無効） | `admin.bp.two_factor.manage` |
| カスタマーの `two_factor_mode` 変更（強制/任意/無効） | `admin.bp.two_factor.manage` |
| ユーザーの緊急TOTPスキップ | `admin.user.reset_2fa` |
| 管理者ユーザーの `two_factor_mode` 変更 | `admin.user.manage` |
| パスワード強制変更 | `admin.user.force_password` |

すべて監査ログ必須。

---

## 2. RBAC（ロールと権限）

### 概念

```text
User ──< UserRole >── Role ──< RolePermission >── Permission
```

### `permissions`（例）

コードは `resource.action` 形式。

| code | 説明 |
|------|------|
| iam.user.manage | ユーザー管理 |
| iam.role.manage | ロール管理 |
| iam.policy.manage | ABACポリシー管理 |
| bp.view / bp.manage | BP参照・管理 |
| customer.view / customer.manage | カスタマー |
| site.manage | 拠点 |
| item.manage | 品目 |
| price.wholesale.edit | 子BP卸価格 |
| price.customer.edit | カスタマー請求額 |
| contract.view / contract.create / contract.approve | 契約 |
| inquiry.view / inquiry.reply / inquiry.close | 問い合わせ |
| announcement.manage | お知らせ |
| admin.user.reset_2fa | ユーザー緊急の2FAスキップ |
| admin.user.force_password | パスワード強制変更 |
| admin.bp.two_factor.manage | BP単位の2FAモード（強制/任意/無効）変更 |
| audit.log.view | 監査ログ参照（管理者画面） |

### `roles`（例）

| code | scope | 想定 |
|------|-------|------|
| system_admin | system | 全体管理 |
| bp_owner | bp | BP責任者 |
| bp_sales | bp | 営業 |
| bp_support | bp | 問い合わせ対応 |
| customer_owner | customer | 自CNユーザー管理・契約・請求・問い合わせ |
| customer_member | customer | 契約・請求の閲覧、問い合わせ起票 |

### `user_roles`

| カラム | 説明 |
|--------|------|
| user_id | |
| role_id | |
| scope_type | `system` / `bp` / `customer` |
| scope_id | 対象ID（system は NULL） |

Phase 2 初期は「1ユーザー1BP」のため、BPロールの `scope_id` は概ね `users.bp_id` と一致。将来の拡張余地を残す。

**RBAC の意味**: アクション実行の **前提条件**（その permission を持っているか）。  
最終許可は ABAC とデータスコープ評価後に決定。

---

## 3. ABAC（本格ポリシーエンジン）

### 評価モデル

リクエストごとに次を評価する。

```text
Allow if:
  (User has required Permission via Role)
  AND (all applicable ABAC policies permit)
  AND (resource is in data scope, when required)
```

明示 Deny ポリシーがあれば Allow より優先（Azure に近い deny-overrides）。

### 属性（Subject / Resource / Action / Environment）

**Subject（主体）**
- user_id, user_type
- bp_id, bp_depth
- role_codes[], permission_codes[]
- is_2fa_ok

**Resource（対象）**
- resource_type（contract, inquiry, price, …）
- owner_bp_id, customer_id
- status, amount（契約・申請）
- relation: `same_bp` / `descendant` / `ancestor` / `unrelated`（クロージャから算出）

**Action**
- permission code（例: `contract.approve`）

**Environment**
- now（日時）、ip（任意）、channel（web）

### ポリシー格納

#### `policies`

| カラム | 説明 |
|--------|------|
| id | |
| code | 一意キー |
| name | |
| effect | `allow` / `deny` |
| resource | 対象リソース種別 or `*` |
| action | permission code or `*` |
| priority | 評価順（deny 高優先のタイブレーク用） |
| is_active | |
| description | |

#### `policy_conditions`

ポリシーに紐づく条件行（AND 結合を基本。OR はグループ ID で表現）。

| カラム | 説明 |
|--------|------|
| policy_id | |
| group_no | 同一番号は OR、異なる番号は AND |
| attribute | 例: `resource.relation`, `resource.amount`, `subject.bp_depth` |
| operator | `eq`, `neq`, `in`, `gte`, `lte`, `exists` |
| value_json | 比較値 |

条件 DSL を JSON 1本にまとめる案もあるが、**管理画面での編集容易性**のため行分割を推奨。

### ポリシー例

**P1: 契約閲覧は自BPまたは配下のみ Allow**
- effect: allow
- action: `contract.view`
- conditions: `resource.relation in ['same_bp','descendant']`

**P2: 卸価格編集は「直接の子」のみ Allow**
- action: `price.wholesale.edit`
- conditions: `resource.relation eq 'descendant'` AND `resource.depth_diff eq 1`

**P3: 高額申請は2階層以上の祖先承認が必要（直近親だけでは不足）**
- 承認アクションを二段に分ける、または
- deny: `contract.approve` when `resource.amount gte 1000000` AND `subject.depth_diff_to_applicant eq 1`
- allow: same when `subject.depth_diff_to_applicant gte 2`

**P4: クローズ済み問い合わせのメッセージ追加は Deny（再オープン権限者以外）**
- deny: `inquiry.reply` when `resource.status eq 'closed'` AND not `subject.permissions contains 'inquiry.reopen'`

**P5: 管理者の特権アクションは system scope のみ**
- allow: `admin.user.reset_2fa` when `subject.user_type eq 'admin'`

### エンジン実装方針（Phase 2）

- `AuthorizationService::can(User $user, string $action, ?Model $resource, array $context = []): bool`
- 関係属性は `BpHierarchy::relation(actorBp, targetBp)` でクロージャから注入
- ポリシーは起動時/更新時にキャッシュ（Phase 2 は array cache、Redis は後続）
- すべての deny/allow 決定を `authorization_audit_logs` に残す（少なくとも特権・否認）

### RBAC と ABAC の分担

| 層 | 責務 |
|----|------|
| RBAC | 「そのアクションを試みる資格があるか」（粗く落とす） |
| ABAC | 「この対象・状況でよいか」（細かく制御） |
| クエリスコープ | 一覧 API で配下に絞り込む（IDOR 防止の二重化） |

一覧は「ABAC を1件ずつ」ではなく、**Eloquent Global/Local Scope で SQL 段階絞り込み**し、詳細操作で ABAC を評価する。

---

## 4. 管理者特権

| 操作 | 必要 permission | ABAC |
|------|-----------------|------|
| 他ユーザーの2FA緊急スキップ | `admin.user.reset_2fa` | 対象が admin 自身より低い権限、など |
| BPの2FAモード変更（強制/任意/無効） | `admin.bp.two_factor.manage` | system のみ |
| パスワード強制変更 | `admin.user.force_password` | 同上 |
| ポリシー編集 | `iam.policy.manage` | system のみ |
| 監査ログ参照 | `audit.log.view` | system / admin のみ（BP・カスタマーからは不可） |

特権操作・認可の重要イベントはすべて監査ログ必須。

### 監査ログの管理者画面参照

管理者画面に監査ログ機能を提供する（Phase 2 で基盤、以降フェーズでイベント種類を拡充）。

| 項目 | 内容 |
|------|------|
| 対象テーブル | `authorization_audit_logs`（認可・特権）、`activity_logs`（重要業務操作）、ログイン成否ログ |
| 画面 | `/admin/audit-logs`（一覧）および詳細モーダル/ページ |
| 機能 | 日時・実行者・対象ユーザー/リソース・アクション・結果（allow/deny/success/failure）・IP でフィルタ／検索 |
| 権限 | `audit.log.view`（`system_admin` に付与）。改ざん防止のため **画面からの削除・編集は不可**（参照専用） |
| 保持 | 削除UIなし。アーカイブ方針は運用要件確定後に別途 |

---

## 5. 初期ロールセット（シード案）

Phase 2 で Seeder 投入。

1. `system_admin` … 全 permission
2. `bp_owner` … BP配下の顧客・契約・価格・問い合わせ・お知らせ
3. `bp_sales` … 契約・価格（閲覧/作成中心）
4. `bp_support` … 問い合わせ中心
5. `customer_owner` … 自CNユーザー管理（`iam.user.manage`）＋メンバー相当
6. `customer_member` … 自己契約・請求閲覧、問い合わせ起票、PDFダウンロード

ポリシー P1〜P5 相当をシードし、テストで固定。

---

## 6. テスト方針（TDD・Phase 2）

- 採番の一意性・並行採番
- ログイン画面分離（admin / bp / customer の各URLで他種別を拒否）
- ログイン（BPN/CN 不一致拒否、正規化）
- 2FA: BPモード（forced/optional/disabled、既定optional）、有効中はTOTP必須、本人無効化（forced以外）、BP強制時は無効化不可
- RBAC 不足で拒否
- ABAC: 配下のみ閲覧、直接子のみ卸価格、高額承認の階層差
- 他BPリソースへの IDOR 拒否
- 監査ログが特権操作で記録され、`audit.log.view` 保持者のみ管理者画面から参照できること
