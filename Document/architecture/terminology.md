# 用語集（命名規約）

今後の設計・実装・ドキュメントでは、次の用語に統一する。

| 禁止（使わない） | 正式呼称（日本語） | コード / URL |
|------------------|--------------------|--------------|
| エンドユーザー / End User / EU | **カスタマー** | `customer` / `/customer/*` |
| 品目の「ドキュメント」（単独） | **Documentテンプレート** | `item_documents` |

## 識別子

| 対象 | コード | 例 | 意味 |
|------|--------|-----|------|
| BP | BPN | BPN202609001 | Business Partner Number |
| カスタマー | CN | CN202609001 | **Customer Number** |

## 主な置換対応

| 旧 | 新 |
|----|----|
| `user_type = end_user` | `user_type = customer` |
| `end_users` テーブル | `customers` |
| `end_user_id` | `customer_id` |
| `end_user_prices` | `customer_prices` |
| `end_user_member` ロール | `customer_member` |
| `/end-user/login` | `/customer/login` |
| `end_user.view` 等 | `customer.view` 等 |
