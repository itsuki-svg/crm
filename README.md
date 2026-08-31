# 社内 CRM

顧客管理・タスク管理・Gmail 連携・Google カレンダー連携・商談パイプラインを備えた社内向け CRM です。

## Features

- **顧客管理** — 顧客情報の登録・検索・詳細表示、対応履歴の時系列表示
- **商談パイプライン** — ステージ管理、金額・確度、受注予測（確度加重平均）
- **タスク管理** — カンバンボード形式、優先度・期限・担当者の設定
- **社内 TODO** — 個人タスクの管理、CSV ダウンロード対応
- **Gmail 連携** — OAuth 認証で Gmail の送受信をアプリ内で完結、顧客と自動紐付け
- **Google カレンダー連携** — アプリ内カレンダーと双方向同期
- **未登録通知** — 顧客 DB に未登録のアドレスからのメールを検出・通知
- **ダッシュボード** — KPI 集計、商談サマリー、タスク進捗、今日の予定を一画面に集約
- **レポート** — 月別売上・顧客獲得推移のグラフ表示
- **MA（リード管理）** — 外部フォームからの問い合わせを API で受信、顧客への変換機能
- **社員管理** — 部署・役職・権限の管理、管理者 / 一般ユーザーのロール分離
- **Cron** — Gmail 自動同期（5 分ごと）、リマインダー通知（毎朝 9 時）

## Database Schema

2データベース構成（社員DB / CRM DB）で運用。

### 社員 DB (`staff_db`)

| テーブル | 概要 | 主なカラム |
|---------|------|-----------|
| `employees` | 社員マスタ | id, emp_code, name, department, position, role, email |
| `auth` | 認証情報 | employee_id FK, password_hash (bcrypt), login_failure_count, locked_until, last_login_at, last_login_ip |
| `sessions` | ログインセッション | id, emp_id, token, expires_at |

### CRM DB (`crm_db`)

| テーブル | 概要 | 主なカラム |
|---------|------|-----------|
| `customers` | 顧客 | id, company_name, contact_name, email, phone, assigned_to, source |
| `contact_history` | 対応履歴 | id, customer_id, type, content, created_by |
| `deals` | 商談 | id, customer_id, title, amount, probability, stage, pipeline_id |
| `pipelines` | パイプライン定義 | id, name, stages (JSON) |
| `tasks` | タスク | id, title, status, priority, due_date, assigned_to, customer_id |
| `todos` | 個人TODO | id, user_id, title, done, due_date |
| `leads` | MAリード | id, name, email, message, source, status, customer_id |
| `gmail_tokens` | OAuth トークン（暗号化） | id, emp_id, access_token, refresh_token |
| `gmail_messages` | 同期済みメール | id, message_id, from_addr, to_addr, subject, snippet |
| `calendar_events` | カレンダーイベント | id, google_event_id, title, start_at, end_at |
| `settings` | システム設定 | key_name, value |

## API Endpoints

| Method | Path | 認証 | 概要 |
|--------|------|------|------|
| `GET` | `/api/customers.php?action=list` | Session | 顧客一覧（検索・ページング） |
| `POST` | `/api/customers.php?action=create` | Session | 顧客登録 |
| `POST` | `/api/customers.php?action=update` | Session | 顧客更新 |
| `GET` | `/api/deals.php?action=list` | Session | 商談一覧 |
| `POST` | `/api/deals.php?action=upsert` | Session | 商談作成/更新 |
| `GET` | `/api/tasks.php?action=list` | Session | タスク一覧（カンバン用） |
| `POST` | `/api/tasks.php?action=update` | Session | タスクステータス更新 |
| `GET` | `/api/gmail_api.php?action=inbox` | Session | 受信メール取得 |
| `POST` | `/api/gmail_api.php?action=send` | Session | メール送信 |
| `GET` | `/api/calendar_api.php?action=events` | Session | カレンダーイベント取得 |
| `POST` | `/api/leads.php?action=receive` | API Key | 外部リード受信 |
| `POST` | `/api/leads.php?action=convert` | Session | リード→顧客変換 |

## Security

| 項目 | 実装 |
|------|------|
| 認証 | セッション認証 + bcrypt パスワードハッシュ |
| ログイン制限 | 5回失敗で30分ロック (`LOGIN_MAX_FAILURES`, `LOGIN_LOCK_MINUTES`) |
| CSRF | `bin2hex(random_bytes(32))` トークン検証 |
| API キー暗号化 | AES-256-CBC（Gmail OAuth トークン等） |
| 権限管理 | 管理者 / 一般ユーザーのロール分離 |
| CORS | `APP_URL` ベースの Origin 制限 |

## Tech Stack

| 項目 | 内容 |
|------|------|
| Backend | PHP 8.1+ |
| Database | MySQL 8.0 (utf8mb4) — 社員 DB と CRM DB の 2 データベース構成 |
| Auth | セッション認証, bcrypt, ログイン試行制限 |
| External | Gmail API, Google Calendar API, Google OAuth 2.0 |
| Security | CSRF, AES-256 暗号化, .htaccess アクセス制御 |
| Frontend | Vanilla JS, Tabler Icons |

## Directory Structure

```
crm/
├── config/config.php       # 設定・DB接続クラス（config.example.php を参照）
├── auth/                   # 認証（ログイン・Google OAuth・ログアウト）
├── lib/                    # Gmail・カレンダー・通知・ヘルパー
├── api/                    # REST API（顧客・タスク・TODO・商談・メール等）
├── pages/                  # 画面（ダッシュボード・顧客・タスク・メール等）
├── public/                 # 静的ファイル（CSS・JS・ログイン画面）
├── cron/                   # Gmail 同期・リマインダー
├── uploads/                # アップロードファイル
└── logs/                   # アプリケーションログ
```

## Setup

1. `config/config.example.php` を `config/config.php` にコピーし、DB 情報・Google OAuth を設定
2. README の Database Schema を参考に、社員 DB (`staff_db`) と CRM DB (`crm_db`) のテーブルを作成
3. Google Cloud Console で Gmail API・Calendar API を有効化し、OAuth 認証情報を設定
4. Cron を設定（Gmail 同期: 5 分ごと、リマインダー: 毎朝 9 時）
