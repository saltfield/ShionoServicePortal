# Phase 6 実装メモ

## 合意済み方針

| 項目 | 内容 |
|------|------|
| A. 問い合わせ主体 | **カスタマー**が起票 → **管理BP**（`customers.managing_bp_id`）が対応。BP同士・管理者起票も可（管理者は代理参照・介入可） |
| B. スレッド | 1問い合わせ = 1スレッド。`open` → `in_progress` → `closed`。クローズ後の返信は **Deny（P4）**。再オープンは `inquiry.reopen`（close 保持ロールに付与）または管理者 |
| C. UI | LINE風チャット。**Livewire ポーリング**（数秒間隔）。Redis / WebSocket は導入しない |
| D. 添付 | メッセージへのファイル添付は **当面なし**（テキストのみ） |
| E. お知らせ配信 | 管理者・BP（`announcement.manage`）が作成。配信先は **配下BP / 自配下カスタマー / 全体（管理者のみ）** |
| F. お知らせ既読 | `announcement_reads` でユーザー単位既読。ダッシュボードに未読件数 |
| G. ダッシュボード | 種別ごと：未読お知らせ・オープン問い合わせ件数・直近契約/申請サマリ（既存メニューは維持） |
| H. 削除 | 問い合わせ・お知らせの削除は **確認コード**。メッセージの物理削除は不可 |

## スコープ

| 含む | 含まない（後続） |
|------|------------------|
| `inquiries` / `inquiry_messages` | Redis / リアルタイムWS |
| `announcements` / `announcement_targets` / 既読 | メッセージ添付・メール通知本実装 |
| 管理者 / BP / カスタマー画面 | 請求・決済、ユーザーアカウント追加 UI |
| ダッシュボード強化 | Excel→PDF 変換エンジン |

## Step 進捗

| Step | 内容 | 状態 |
|------|------|------|
| 6-0 | 合意反映・ドキュメント更新 | **完了** |
| 6-1 | マイグレーション / モデル | **完了** |
| 6-2 | Inquiry サービス TDD（起票・返信・クローズ・P4） | **完了** |
| 6-3 | Livewire チャット画面（admin/BP/customer） | **完了** |
| 6-4 | Announcement サービス＋配信ターゲット | **完了** |
| 6-5 | ダッシュボード（件数・未読） | **完了** |
| 6-6 | 監査・権限通し確認 | **完了** |

## テーブル

- `inquiries`（subject, status, opened_by_user_id, customer_id?, owning_bp_id）
- `inquiry_messages`（inquiry_id, user_id, body）
- `announcements`（title, body, published_at, created_by_user_id, owning_bp_id?）
- `announcement_targets`（target_type: all\|bp\|customer, target_id）
- `announcement_reads`（announcement_id, user_id, read_at）
