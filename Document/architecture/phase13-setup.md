# Phase 13 実装メモ — メール通知

## 目的

業務イベントのメール通知（個人 email・オプトアウト可・キュー送信）。

## 合意ステータス

| 項目 | 状態 |
|------|------|
| 第1弾 = A全部 | **確定** |
| オプトアウト必要 | **確定** |
| 宛先 = ユーザー email のみ | **確定** |
| 組織代表アドレス | **対象外**（後続。用途は plan に記載） |
| queue コンテナ追加 | **確定** |
| 強制PWはオプトアウト不可 | **確定** |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 13-0 | 計画 Document | **完了** |
| 13-1 | 購読・Dispatcher | **完了** |
| 13-2 | queue コンテナ | **完了** |
| 13-3 | チケット通知 | **完了** |
| 13-4 | 価格申請通知 | **完了** |
| 13-5 | 強制PW通知 | **完了** |
| 13-F | テスト・受け入れ | **完了** |

## 実装サマリ

| 要素 | 場所 |
|------|------|
| 購読テーブル | `user_notification_preferences` |
| Dispatcher | `App\Domains\Notification\Services\NotificationDispatcher` |
| 業務フック | `NotificationService` ← Inquiry / Contract / AdminPrivilege / UserManagement |
| 設定 UI（本人） | `/{guard}/notification-settings`（アカウントメニュー） |
| 設定 UI（管理者） | 各ポータルのユーザー編集「メール通知設定」 |
| queue | `Develop/docker-compose.yml` の `ssp-queue` |
| 通知クラス | `TicketMessageNotification` / `ContractApplicationNotification` / `ForcePasswordChangeNotification` |

## 環境変数（Application/.env）

| キー | 用途 |
|------|------|
| `APP_URL` | メール内リンク・ボタンのベース URL |
| `QUEUE_CONNECTION` | 既定 `database`（`ssp-queue` が消化） |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` | 開発は `smtp` / `mailpit` / `1025` |
| `MAIL_SCHEME` | TLS 等（`smtps` など）。旧 `MAIL_ENCRYPTION` は使わない |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | 認証あり SMTP |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | 差出人 |

キー例は `Application/.env.example` および `Develop/.env.example` の同期メモを参照。

## 運用上の注意

- **email 未設定のユーザーには送らない**（ログに `notification.skipped_no_email`）
- 強制パスワード変更は、編集画面のチェック／管理者の「PW強制変更」／ユーザー作成時の要求で通知。強制ONのまま初めてメールを入れたときも通知する
- 価格承認申請は **契約の管理BPのみ** 申請可（親BPが子契約を申請すると宛先本人除外でメールが飛ぶ相手がいなくなるため）
- メール本文に操作者名は出さない

## 開発確認

1. `docker compose up -d queue`
2. チケット起票・返信 / 価格申請 / 強制PW を実行（対象に email を設定）
3. Mailpit UI `http://localhost:8025` で本文・宛先を確認（リンクが `APP_URL` ベースであること）
4. 「通知設定」またはユーザー編集でオプトアウト → 業務通知が飛ばないこと（強制PWは除く）

## 本番

- `APP_URL` を公開 HTTPS URL に合わせる
- `MAIL_*` を実 SMTP に差し替える
- `QUEUE_CONNECTION=database` と `ssp-queue`（または同等の `queue:work`）が必須

## 関連

- 計画: [phase13-plan.md](phase13-plan.md)
- デプロイ: [deploy.md](deploy.md)
- IAM 運用（ユーザー編集・通知）: [../iam/iam-operations-manual.md](../iam/iam-operations-manual.md)
