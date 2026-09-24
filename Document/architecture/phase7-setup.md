# Phase 7 実装メモ

## 合意済み方針

| 項目 | 内容 |
|------|------|
| A. 共通UIシェル | 左上エリア名→ダッシュボード。機能メニューはヘッダー。右はユーザー名メニュー（PW/2FA/ログアウト）。ダッシュボードにメニュー一覧は置かない |
| B. ユーザー管理 | **管理者・BPのみ**作成・編集・無効化・ロール付与（カスタマーユーザーの代理作成は含む）。カスタマー自身のユーザー作成は **別フェーズ** |
| C. 請求・決済 | **案α**: 契約×請求月、ステータス管理のみ（PGなし）。※ Phase 10 で自動生成に統合し、**手動発行 UI は廃止** |
| D. ファイル生成 | データ項目に **置換コード**。テンプレ差し込み後 **PDF化**。再生成は **上書き**（版管理なし） |
| D. 記法 | `{{code}}` 短コード。予約語は任意指定不可（[ガイド](../templates/document-template-guide.md)）。税率 `rate`・税額 `tax`・税込 `price_in` 等 |
| 実施順 | **A → B → D → C** |

## スコープ

| 含む | 含まない |
|------|----------|
| ヘッダーナビ＋アカウントメニュー | カスタマー自身のユーザー招待・作成 |
| admin/BP ユーザー CRUD | Redis / WebSocket |
| 請求ステータス（入金／取下げ）※発行は Phase 10 | 外部決済（Stripe等） |
| 置換コード＋PDF生成 | 帳票デザイナ・版管理・Word |
| 監査ログ拡充 | 会計連携 |
| | **カスタマー自組織ユーザー管理 → [Phase 8](phase8-plan.md)** |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 7-0 | 合意反映・Document（本メモ＋テンプレートガイド） | **完了** |
| 7-A | 共通UIシェル | **完了** |
| 7-B | ユーザー管理（admin/BP） | **完了** |
| 7-D | 置換コード＋PDF生成（Dompdf + Noto） | **完了** |
| 7-C | 請求・決済（案α） | **完了** |

## 実装メモ

- BP連絡先（住所・電話・メール）は予約語 `bp_addr` / `bp_tel` / `bp_mail` 用
- フォント: `Application/storage/fonts/NotoSansJP-Regular.ttf`
- 請求番号: `INV{YYYYMM}{seq3}`。権限: `invoice.view` / `invoice.manage`
- **請求の月次自動生成・キックバック・生成履歴は Phase 10**（[phase10-setup.md](phase10-setup.md)）

## 関連 Document

- 計画詳細: [phase7-plan.md](phase7-plan.md)
- テンプレート作成・予約語: [../templates/document-template-guide.md](../templates/document-template-guide.md)
- デプロイ: [deploy.md](deploy.md)
