# スキーマ決定メモ

## 文字セット / 照合順序

| 対象 | 設定 |
|------|------|
| Server / DB デフォルト | `utf8mb4` / `utf8mb4_uca1400_ai_ci` |
| 識別子カラム（login_id, BPN, CN, item code 等） | `utf8mb4_bin` |
| アプリ正規化 | trim + 大文字統一（識別子） |

### なぜ `utf8mb4_0900_ai_ci` ではないか

- 本基盤は MariaDB 11.4。
- `utf8mb4_0900_ai_ci` は MariaDB では主に **uca1400 系へのエイリアス**（11.4.5+）。
- ネイティブな `utf8mb4_uca1400_ai_ci` の方が意図が明確で、パッチ版依存が少ない。

## 命名規約

- テーブル: 複数形 snake_case（Laravel 慣例）
- PK: `id` BIGINT UNSIGNED AI
- FK: `{table_singular}_id`
- 日時: `*_at` TIMESTAMP（アプリは Carbon / TZ Asia/Tokyo）

## Soft Delete

`users`, `business_partners`, `customers`, `items`, `contracts`, `inquiries`, `announcements`, `invoices`, `kickback_invoices` は `deleted_at`（または同等の論理削除）を持つ。  
クロージャ整合性のため、BP削除は論理削除＋配下制約を Phase 3 で詳細化。

請求・キックバック・バッチ実行テーブルの現行定義は [er-diagram.md](er-diagram.md) および Phase 10 マイグレーションを参照。
