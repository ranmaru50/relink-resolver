# Reference Resolver 実装ガイド

## 起動

Native profile では Apache の DocumentRoot を `public/` に設定し、PHP 8.3 以上の `pdo_sqlite` 拡張を有効にします。SQLite ファイルは `RELINK_DATA_DIR`（既定は `var/data`）に作成され、`public/` 配下には置かれません。

Container profile は `docker compose --env-file .env up --build` で起動します。`.env` は `.env.example` をコピーして作成し、本番パスワードを Secret 管理から注入してください。

初回管理ログインには `RELINK_ADMIN_USERNAME` と `RELINK_ADMIN_PASSWORD` を使用します。空パスワードではログインできません。運用時は TLS、管理ネットワーク制限、プロキシ設定を別途構成してください。

## 公開エンドポイント

`GET /relink/{uuid}` は登録済み ACTIVE レコードを `303 See Other` で HTTPS の Description Location へ転送します。公開処理は AR-XML を取得せず、Manifest に依存しません。`GET /relink/{uuid}/manifest` は利用可能な Manifest JSON を返します。

## バックアップと復元

`bin/backup.sh /secure/backup/resolver.sqlite` は SQLite の `.backup` を使用し、journal/WAL を考慮した一貫性のあるバックアップを作成します。復元前に `bin/restore.sh /secure/backup/resolver.sqlite` を実行し、`PRAGMA integrity_check` とアプリケーションの UUID・状態・場所・履歴確認を行ってください。バックアップ先は Web ルート外のアクセス制御された場所に限定します。

## Issue #9 の適合性

公開 Resolver、Lifecycle 状態遷移、Manifest 生成、SQLite の明示的 migration、CSRF と HTML エスケープを含む最小管理面を実装しています。管理面の到達制御（TLS、IP 制限、認証基盤）と高度な outbound reachability/integrity publishing はデプロイメントで有効化する任意機能であり、公開解決からは分離されています。

## 既知の制限

管理者向けの到達性診断、SSRF 対策付き outbound fetch、integrity publishing は未実装です。追加する場合は、設定可能なネットワークポリシー、リダイレクトごとの再評価、DNS rebinding 対策、サイズ・時間上限を備えた専用アダプタとして実装します。適合性の最終判定は `relink-testbed` の実行可能ケースを Native/Container の両 profile に適用して行ってください。
