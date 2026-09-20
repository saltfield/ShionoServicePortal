# BP 階層構造設計

## 要件

- Business Partner（BP）は最大 **5階層** の親子関係を持つ。
- 下位 → 上位への申請ワークフローを想定。
- 「自BPの配下すべて」「祖先一覧」などの検索を頻繁に行う。
- パフォーマンスを落とさない DB 設計が必要。

## 採用方式

**隣接リスト（`parent_id`）+ クロージャテーブル（`bp_closure`）**

| 方式 | 不採用理由 / 採用理由 |
|------|------------------------|
| 隣接リストのみ | 配下全件が再帰 CTE 依存で複雑・負荷 |
| 経路列挙 | 移動時の path 一括更新が煩雑 |
| Nested Set | 挿入・移動コスト大 |
| **クロージャテーブル** | 配下/祖先が等価結合で高速。5階層なら行数も実用範囲 |

## テーブル

### `business_partners`

| カラム | 型 | 説明 |
|--------|-----|------|
| id | BIGINT PK | |
| code | VARCHAR(32) UNIQUE, **utf8mb4_bin** | BPN（正規化済み） |
| name | VARCHAR(255) | |
| parent_id | BIGINT NULL FK → self | ルートは NULL |
| depth | TINYINT UNSIGNED | 1〜5（ルート=1） |
| two_factor_mode | ENUM('forced','optional','disabled') | 2FA制御。**DEFAULT optional**。管理者オーバーライド |
| is_active | BOOLEAN | |
| created_at / updated_at | TIMESTAMP | |

制約:
- `depth BETWEEN 1 AND 5`
- 子作成時: `parent.depth + 1 <= 5` をアプリ＋DBで保証

### `bp_closure`

| カラム | 型 | 説明 |
|--------|-----|------|
| ancestor_id | BIGINT FK | 祖先 |
| descendant_id | BIGINT FK | 子孫 |
| depth_diff | TINYINT UNSIGNED | 世代差（自己参照は 0） |

- PK: `(ancestor_id, descendant_id)`
- INDEX: `(descendant_id, ancestor_id)`, `(ancestor_id, depth_diff)`

**不変条件**
- 各 BP について自己行 `(id, id, 0)` が必ず存在
- 親子追加時、親の全祖先 × 新ノード（およびその子孫）を追加

## 操作アルゴリズム（概要）

### 新規ルート BP

```sql
INSERT business_partners (... parent_id=NULL, depth=1);
INSERT bp_closure (ancestor_id, descendant_id, depth_diff) VALUES (:id, :id, 0);
```

### 子 BP 追加（parent = P, child = C）

1. `P.depth + 1 <= 5` を検証
2. `C` を insert（`depth = P.depth + 1`）
3. クロージャ:

```sql
-- 自己
INSERT INTO bp_closure VALUES (C, C, 0);
-- 親の祖先すべて → C
INSERT INTO bp_closure (ancestor_id, descendant_id, depth_diff)
SELECT ancestor_id, C, depth_diff + 1
FROM bp_closure
WHERE descendant_id = P;
```

### 配下全件取得

```sql
SELECT bp.*
FROM bp_closure c
JOIN business_partners bp ON bp.id = c.descendant_id
WHERE c.ancestor_id = :bp_id AND c.depth_diff > 0;
```

### 祖先一覧（申請の上位承認者探索など）

```sql
SELECT bp.*
FROM bp_closure c
JOIN business_partners bp ON bp.id = c.ancestor_id
WHERE c.descendant_id = :bp_id AND c.depth_diff > 0
ORDER BY c.depth_diff ASC;  -- 直近の親が先
```

### 配下判定（認可で多用）

```sql
SELECT 1 FROM bp_closure
WHERE ancestor_id = :actor_bp_id AND descendant_id = :target_bp_id
LIMIT 1;
```

（自己含むなら `depth_diff >= 0`、配下のみなら `> 0`）

### サブツリー移動（将来）

稀な運用を想定。手順は「旧クロージャ削除 → parent_id/depth 更新 → 新クロージャ再構築」。  
Phase 3 でサービス層に実装し、単体テスト必須。

## ユーザーとの関係（合意）

- **1ユーザー = 1BP**（`users.bp_id`）
- 同一人物が複数 BP を扱う場合は **BPごとにアカウントを払い出し**、人間側でログインを切り替える
- 「親BPユーザーが子のデータを見る」は **兼務ではなく配下スコープ＋権限**で実現

## 申請ワークフローとの接続（Phase 3+）

- 申請者 BP → 直近親（`depth_diff = 1`）へ承認依頼が基本
- ABAC で「金額閾値を超えたら depth_diff >= 2 の祖先承認が必要」等を表現可能（IAM設計書参照）

## パフォーマンス見積もり

- 階層深さ最大 5、1ノードあたりクロージャ行は最大 5（祖先）+ 自己 + 子孫分
- インデックス付き等価結合のため、数万 BP 規模でも配下検索は実用的
- ホットパスには `ancestor_id` 先頭の複合インデックスを維持
