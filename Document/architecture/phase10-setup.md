# Phase 10 実装メモ — 月次請求・キックバック

## 目的

カスタマー向け月次請求（ルートBP発行・案A二系統）の自動生成と、BP間多段キックバック。

## 合意ステータス

| 項目 | 状態 |
|------|------|
| 要件（請求・キックバック・スケジュール・案A） | **確定** |
| デザイン刷新 → Phase 11 | **確定** |
| 実装 | **完了**（運用確認フェーズ） |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 10-0 | 要件合意・ER | **完了** |
| 10-A | マイグレーション（契約／明細フラグ・issuer・kickbacks・system_settings） | **完了** |
| 10-B | カスタマー請求自動生成 TDD | **完了** |
| 10-C | 多段キックバック TDD（負額エラー） | **完了** |
| 10-D | カスタマー請求タブ・契約／明細フラグ UI | **完了** |
| 10-E | キックバック UI・ルート価格開示（区間内訳） | **完了** |
| 10-F | スケジューラ・管理者実行日時設定・手動実行・手動発行廃止 | **完了** |
| 10-G | 生成履歴・実行詳細（作成請求一覧）・Docker scheduler・イニシャル取りこぼし回収 | **完了** |

## 実装サマリ（現行）

### バッチ実行

| 項目 | 内容 |
|------|------|
| コマンド | `billing:run-monthly {--month=YYYYMM}` |
| スケジュール | `routes/console.php` … 毎分 `MonthlyBillingService::runIfScheduled()` |
| Docker | `Develop` の **`scheduler` サービス**（`php artisan schedule:work`）が必須 |
| 一致条件 | `system_settings.billing_batch_schedule` の有効・実行日・時刻（Asia/Tokyo） |
| 取りこぼし | 予定時刻以降かつ当日、当該請求月の **自動（scheduled）実行が未記録**なら 1 回実行 |
| 手動 | 管理者画面から対象月指定。`trigger=manual` |

### 金額

| 伝票 | 金額の根拠 |
|------|------------|
| カスタマー請求 | 契約明細の **`unit_price`（EU単価）**。仕切り `partition_price` は使わない |
| キックバック | レイヤ上の差額（上値 − 仕切り）。負額はエラー |

### イニシャル

- 原則: `first_billing_year_month` の月のみ請求行に載せる
- その月に未請求だった場合: **後続の請求月バッチでキャッチアップ**（未発行のイニシャル行のみ）

### 生成履歴 UI

- 画面: `/admin/billing-batch`（自動請求設定）
- **単月手動実行**と**過去月の範囲一括生成**（開始〜終了 YYYYMM）がある
- 履歴: 成功／一部失敗／失敗バッジ、請求・KB・スキップ・エラー件数
- 詳細: `/admin/billing-batch/runs/{run}` … 当該実行で作成した請求・キックバック一覧
- 紐付け: `invoices.billing_batch_run_id` / `kickback_invoices.billing_batch_run_id`

### 導入時の過去請求バックフィル

1. 契約を Activated にし、`first_billing_year_month`（請求開始）を実際の開始月（例: `202506`）にする  
2. 管理者 → 自動請求設定 → 「過去月の請求を生成」で **BP（配下含む） / BP単体 / カスタマー** と期間を指定して一括実行  
3. CLI 例:  
   - `php artisan billing:run-monthly --from=202506 --to=202508 --bp-tree=BPN0001`  
   - `php artisan billing:run-monthly --from=202506 --to=202508 --bp=BPN0001`  
   - `php artisan billing:run-monthly --from=202506 --to=202508 --customer=CN0001`  
4. キックバックはカスタマー請求の **6ヶ月後**のバッチで生成される（例: 202506 分は 202512 実行時）

### テーブル（追加）

| テーブル | 用途 |
|----------|------|
| `billing_batch_runs` | 実行履歴（trigger / status / 件数 / 実行者） |
| `billing_batch_errors` | 契約単位エラー（`billing_batch_run_id` 任意） |
| `system_settings` | スケジュール JSON（key=`billing_batch_schedule`） |
| `kickback_invoices` / `kickback_invoice_lines` | BP 間キックバック |
| `contract_item_price_layers` | 承認時仕切りスナップショット |

### 廃止

- Phase 7 の契約画面からの **手動請求発行** UI／ルート（生成はバッチ／手動実行のみ）

## 運用チェックリスト

1. `docker compose ps` で `ssp-scheduler` が Up  
2. 管理者 → 自動請求設定 → 有効・日時・「次回の自動実行予定」  
3. 実行後、生成履歴に行が増え、結果バッジが付く  
4. 「請求一覧」から作成伝票の金額（EU単価）を確認  

詳細手順: [deploy.md](deploy.md)

## 関連

- 計画: [phase10-plan.md](phase10-plan.md)
- デプロイ: [deploy.md](deploy.md)
- ER: [../database/er-diagram.md](../database/er-diagram.md)
- 延期: [phase11-plan.md](phase11-plan.md)
