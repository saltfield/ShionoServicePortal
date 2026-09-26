# Phase 11 実装メモ — デザイン刷新

## 目的

**デザイン刷新**（見た目・情報設計・モバイル UX）。機能追加・DB変更は原則行わない。

## 合意ステータス

| 項目 | 状態 |
|------|------|
| Phase 11 = デザイン刷新のみ | **確定** |
| トーン = 管理コンソール寄り（社外利用者あり） | **確定** |
| Bootstrap 継続 | **確定** |
| 先行 = ログイン → ダッシュボード（三種） | **確定** |
| トークン詳細（色コード等） | **確定・実装済**（コーポレートカラーなし → ニュートラル＋スレートブルー） |

## Step 状態

| Step | 内容 | 状態 |
|------|------|------|
| 11-0 | デザイントークン草案 | **完了** |
| 11-A | トークン実装・共通部品 | **完了** |
| 11-B | ログイン刷新（三種） | **完了**（＋2FAチャレンジ／強制PW変更／セットアップ／419） |
| 11-C | ダッシュボード刷新（三種） | **完了** |
| 11-D | レイアウトシェル微調整 | **完了**（ナビ帯色） |
| 11-E | 一覧・詳細への展開 | **完了**（共通CSS＋全画面ページタイトル／パンくず／テーブル・カード） |
| 11-F | 回帰確認 | **完了** |

## 11-F 回帰チェックリスト

| 項目 | 結果 |
|------|------|
| Pest: `Auth` / `ForcedPasswordChange` / `DashboardContractPipeline` / `CsrfExpired` | **PASS** |
| HTTP 200: `/`・`/admin/login`・`/bp/login`・`/customer/login` | **PASS**（`ssp-login-panel` 確認） |
| 三種ログインが同一デザイン言語・種別明示 | **PASS** |
| ダッシュボード（権限なしユーザーでも 403 にならない） | **PASS**（未読チケット件数は権限なし時 0） |
| デザイン変更に合わせたアサーション更新（`ssp-stat-card--attention`） | **PASS** |
| モバイル幅の目視（ログイン／ダッシュ） | 運用時にブラウザ幅で最終確認可 |

### 11-F で直した回帰

- ダッシュボードの未読チケット集計が `inquiry.view` 未付与で `authorize` → 403 になっていたため、件数取得は権限なし時 **0** を返すよう変更（`InquiryService`）
- `DashboardContractPipelineTest` の注意スタイル期待値を `bg-warning-subtle` → `ssp-stat-card--attention` に更新

## トークン草案（要約）

- 背景 `#f4f6f8` / サーフェス `#fff` / 本文 `#1a2332`
- アクセント `#2f5d8a`（スレートブルー）
- エリア差はヘッダー下線程度（admin 青系 / BP 緑み / customer ブラウンみ）

詳細表は [phase11-plan.md](phase11-plan.md) の「デザイントークン草案」を参照。

## 主な対象ファイル

| 領域 | パス |
|------|------|
| スタイル | `Application/resources/css/app.css` |
| レイアウト | `Application/resources/views/layouts/app.blade.php` / `guest.blade.php` |
| ログイン等ゲスト | `auth/**`・`errors/419`・`welcome` |
| ダッシュ | `views/{admin,bp,customer}/dashboard.blade.php` |
| パンくず | `views/partials/breadcrumb.blade.php` |
| その他画面 | 各一覧・詳細・フォーム Blade（`ssp-page-title` 適用） |

## 実装メモ

- エリア区別はナビのラベルに加え、アクセント色のごく薄い差（例: ヘッダー下線）程度に留める
- ダッシュボードの集計ロジック・リンク先は変更しない（見た目のみ）
- **11-E**: 画面ごとの個別リデザインではなく、トークン＋`.ssp-main` 配下のテーブル／カード／フォーム／パンくず／アコーディオンを一括適用。見出しは `ssp-page-title`
- **11-F**: Auth／ダッシュ／419 の Feature テスト＋ゲスト画面の HTTP スモークを実施。残りの目視（モバイル幅）は任意

## 関連

- 計画: [phase11-plan.md](phase11-plan.md)
- 前フェーズ: [phase10-setup.md](phase10-setup.md)
- デプロイ: [deploy.md](deploy.md)
