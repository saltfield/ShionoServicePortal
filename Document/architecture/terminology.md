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

## 価格まわり（要約）

| 用語 | 意味 |
|------|------|
| 標準仕切り | 品目マスタの BP 間卸の既定値 |
| 推奨価格 | カタログ目安。計算・初期値には使わない |
| ユーザー標準 | カスタマー向け売価の既定値 |
| EU単価 / 請求額 | 契約明細 `unit_price`。カスタマー請求の根拠 |
| 仕切り（契約） | 契約明細 `partition_price`／価格レイヤ。キックバック用 |

詳細: [pricing.md](pricing.md)
