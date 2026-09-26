# IAM 運用マニュアル（ロール・割り当て・ABACポリシー）

管理者画面で行う **権限まわりの操作手順** です。設計の詳細は [iam-design.md](iam-design.md) を参照してください。

## 対象読者

- システム管理者（`system_admin` など `iam.*.manage` を持つユーザー）
- 社内でロール／ポリシーを設計・変更する担当者

## 全体像（3画面の役割）

```text
① ロール管理        … 「権限の束」を定義する
② ユーザー編集       … その束をユーザーに割り当てる
③ ABACポリシー管理   … 対象・状況でさらに許可／拒否する
```

| 画面 | URL | 必要権限 |
|------|-----|----------|
| ロール管理 | `/admin/roles` | `iam.role.manage` |
| ユーザー管理 | `/admin/users` など | `iam.user.manage` |
| ABACポリシー | `/admin/policies` | `iam.policy.manage` |

判定の流れ:

1. ユーザーがロール経由で必要な **permission** を持っているか（RBAC）
2. 該当する **ABACポリシー** を評価（deny 優先）
3. データ範囲（BPクロージャ等）と合わせて最終許可

パーミッションをユーザーに直接付けることはありません（Azure RBAC と同様、ロール経由）。

---

## 1. ロール管理

ナビ **「ロール管理」** → `/admin/roles`

### 1.1 組み込みロールとカスタムロール

| 種別 | 例 | できること |
|------|----|------------|
| 組み込み | `system_admin`, `bp_owner`, `bp_sales` … | 名前・説明・権限の編集可。**削除不可**。コード／スコープ変更不可 |
| カスタム | 管理者が作成したもの | 作成・編集・削除可（割当ユーザーがいると削除不可） |

`system_admin` の権限は常に全権限です（チェックを外しても保存時に全権限へ戻ります）。

### 1.2 カスタムロールを作る

1. **ロール作成** を開く
2. **コード** … 英小文字・数字・アンダースコア（例: `bp_field_ops`）
3. **表示名** … 画面に出す名前
4. **スコープ**（重要）

| スコープ | 意味 | どこで割当候補に出るか |
|----------|------|------------------------|
| `system` | 管理者ユーザー向け | 管理者ユーザーの作成／編集 |
| `bp` | BPユーザー向け | **BP画面・管理者からの BPユーザー管理** |
| `customer` | カスタマーユーザー向け | カスタマー画面・管理者／BPからの CNユーザー管理 |

> BP側のユーザー管理に出したいロールは、必ずスコープ **`bp`** にしてください。  
> `system` のまま作ると BP 画面には出ません。

5. 権限チェックリストから必要な permission を選択
6. **作成**

### 1.3 ロールを編集する

1. 一覧の **編集**
2. 表示名・説明・権限を変更して保存
3. カスタムのみスコープ変更可（誤って `system` にしたロールはここで `bp` に直せる）
4. カスタムで未割当なら **削除** 可

---

## 2. ユーザーへのロール割り当て

ナビ **「ユーザー管理」** → 対象ユーザーの編集、または BP／カスタマー詳細のユーザー編集。

### 2.1 作成時

- **初期ロールを1つ** 選んで作成します
- 追加のロールは作成後の編集画面で割り当てます

### 2.2 編集画面「ロールの割り当て」

1. 割り当て済み一覧を確認（ロール名・コード・スコープ）
2. **割り当てを追加** で未割当ロールを選択  
   - 選択すると含まれる権限のプレビューが表示されます
3. **追加**
4. 不要なロールは **解除**（ただし **最後の1件は解除不可**）

複数ロールを付けた場合、実効権限は **和集合** です。

### 2.3 操作できる人

| 操作者 | 対象 |
|--------|------|
| 管理者 | 全種別ユーザー（割当可能ロールは対象ユーザーの種別に応じたスコープ） |
| BP | 自組織／配下の BP・カスタマーユーザー（`iam.user.manage` が必要） |
| カスタマー | 自 CN のカスタマーユーザーのみ |

---

## 3. ABACポリシー管理

ナビ **「ABACポリシー」** → `/admin/policies`

### 3.1 いつ使うか

ロールだけでは足りないときです。例:

- 「契約は自BP・配下だけ見られる」
- 「クローズ済みチケットには返信させない」
- 「高額承認は条件付きで拒否する」

シード済みの `P1_…` などもこの画面で確認・変更できます。

### 3.2 一覧の見方

| 列 | 意味 |
|----|------|
| 効果 | `allow`＝許可条件 / `deny`＝拒否（allow より強い） |
| resource | 対象種別（`contract` / `inquiry` / `price` / `*`） |
| action | permission コードまたは `*` |
| 優先度 | 大きいほど先に評価 |
| 状態 | 無効にすると評価対象外 |

### 3.3 ポリシーを作る／編集する

1. **ポリシー作成** または一覧の **編集**
2. 基本項目を入力

| 項目 | 説明 | 例 |
|------|------|----|
| コード | 一意ID | `P10_field_view` |
| 表示名 | 管理用の名前 | 現場閲覧制限 |
| 効果 | allow / deny | allow |
| resource | リソース種別 | `contract` |
| action | 対象操作 | `contract.view` |
| 優先度 | 評価順 | allow は 100 前後、deny は 200 前後が目安 |
| 有効 | オフで一時停止 | 推奨の安全な止め方 |

3. **条件** を行で追加

| 項目 | 意味 |
|------|------|
| group | **同じ番号＝OR**、**違う番号＝AND** |
| attribute | 属性パス（後述） |
| operator | `eq` / `in` / `gte` など |
| value | 比較値。`in` はカンマ区切り可 |

空の属性行は無視されます。

4. **保存**

削除は認可に直撃するため、まずは **無効化** を推奨します。

### 3.4 よく使う attribute / operator

**attribute（例）**

| attribute | 内容 |
|-----------|------|
| `resource.relation` | `same_bp` / `descendant` / `ancestor` / `unrelated` |
| `resource.depth_diff` | 階層差（数値） |
| `resource.amount` | 金額など |
| `resource.status` | ステータス |
| `subject.user_type` | `admin` / `bp` / `customer` |
| `subject.permission_codes` | 主体が持つ権限コードの配列 |
| `subject.depth_diff_to_applicant` | 申請者との階層差 |

**operator**

| operator | 意味 | value の例 |
|----------|------|------------|
| `eq` | 等しい | `closed` |
| `neq` | 等しくない | `admin` |
| `in` | いずれかに一致 | `same_bp, descendant` |
| `gte` / `lte` | 以上／以下 | `1000000` |
| `contains` / `not_contains` | 配列に含む／含まない | `inquiry.reopen` |
| `exists` | 存在チェック | `true` / `false` |

### 3.5 設定例

#### 例A: 契約閲覧は自BPまたは配下のみ（allow）

| 項目 | 値 |
|------|-----|
| effect | allow |
| resource | contract |
| action | contract.view |
| 条件 | `resource.relation` `in` `same_bp, descendant` |

※ あわせてロール側に `contract.view` が必要です。

#### 例B: クローズ済みへの返信拒否（deny）

| 項目 | 値 |
|------|-----|
| effect | deny |
| resource | inquiry |
| action | inquiry.reply |
| 条件 group1 | `resource.status` `eq` `closed` |
| 条件 group2 | `subject.permission_codes` `not_contains` `inquiry.reopen` |

group が違うので AND。reopen 権限が無い人の返信を止めます。

#### 例C: 管理者だけ特別に許可（allow）

| 項目 | 値 |
|------|-----|
| effect | allow |
| action | （対象の permission） |
| 条件 | `subject.user_type` `eq` `admin` |
| 優先度 | 50 など（通常のスコープ allow より先／後は要件に応じて調整） |

### 3.6 評価の注意点

- **deny が1件でもマッチすれば拒否**
- その action に allow ポリシーが存在する場合、allow が1件以上マッチしないと許可されないことがある
- ポリシーが1件も適用されない action は、評価上は許可側に倒れる実装です（RBAC は別途必要）
- `resource` / `action` を `*` にすると影響範囲が広いので慎重に

---

## 4. 典型的な作業手順

### 4.1 「BP現場用ロールを新設して割り当てる」

1. ロール管理でスコープ **`bp`** のカスタムロールを作成し、必要な権限にチェック
2. ユーザー管理（または BP画面のユーザー編集）で対象ユーザーへ割り当て
3. 必要なら ABAC で対象範囲をさらに制限

### 4.2 「ある操作だけ一時的に止めたい」

1. ABACポリシーで deny を追加する、または既存 allow を **無効化**
2. 影響確認後、恒久対応（ロールから権限を外す／ポリシーを確定）

### 4.3 「BP画面にカスタムロールが出ない」

1. ロール編集で **スコープが `bp` か** を確認
2. `system` になっていれば `bp` に変更して保存
3. 対象ユーザー種別が BP であること（カスタマーユーザーには `customer` スコープが必要）

---

## 5. 監査

次の操作は監査ログに残ります（`/admin/audit-logs`、要 `audit.log.view`）。

| action | 内容 |
|--------|------|
| `role.create` / `role.update` / `role.delete` | ロール定義 |
| `role.assign` / `role.revoke` | ユーザーへの割当 |
| `policy.create` / `policy.update` / `policy.delete` | ABACポリシー |
| `user.create` / `user.update` / `user.delete` | ユーザー本体 |

---

## 6. 関連ドキュメント

- 設計: [iam-design.md](iam-design.md)
- Phase 12 実装: [../architecture/phase12-plan.md](../architecture/phase12-plan.md) / [../architecture/phase12-setup.md](../architecture/phase12-setup.md)
- 用語: [../architecture/terminology.md](../architecture/terminology.md)
