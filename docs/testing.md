# テスト

ホストに PHP 8.3 以上、`pdo_sqlite`、Composer がある場合は、リポジトリルートで次を実行します。

```sh
composer install
vendor/bin/phpunit
```

PHPStan 2 のレベル 6 静的解析は、次のコマンドで実行します。解析対象はアプリケーションの `src`、`public`、`bin`、`bootstrap.php` です。

```sh
composer analyse
```

PHP の標準的なテストフレームワークである PHPUnit 11 を採用しています。`tests/ResolverServiceTest.php` は HTTP サーバーや外部ネットワークを使わず、公開 Resolver のステータス、メソッド独立性、UUID 正規化、L1 downgrade 防止、Manifest の基本構造を検証します。`tests/ResolverServiceEdgeCaseTest.php` は入力検証、全ライフサイクル遷移、同一状態 no-op、永続化障害のエラー境界を検証します。`tests/DomainValueObjectTest.php` は UUID、HTTPS Location、Lifecycle、キャッシュ結果の値オブジェクト境界を検証します。`tests/SqliteResolverRepositoryTest.php` は `pdo_sqlite` を使う一時 DB で migration の冪等性、更新・遷移履歴、トランザクション、楽観的競合を検証します。

`docs/specs/resolver-manifest-conformance-0.1.md` が Frozen Conformance Catalog です。実行可能な Testbed は `relink-testbed` 側で管理します。ローカルのユニットスモークは RES-001、RES-003、HTTP-002〜HTTP-005、LIFE-001〜LIFE-003、MAN-001、MAN-009〜MAN-010 に対応します。

`tests/RestoreScriptTest.php` は非root実行ユーザーのUID/GIDで空データディレクトリへ復元し、ディレクトリ／DBのmode、SQLiteトランザクション、WAL/SHM作成を検証します。Container CIでは別ジョブでroot復元とサービスユーザー所有権を確認します。

SQLite のマイグレーション・履歴 transaction、実 HTTP/Apache の status/header、管理画面の認証・CSRF、backup/restore、Native/Container 同値性、MNET-001 および MAN-005〜MAN-015 の Testbed 実行結果は、このリポジトリの PHPUnit 単体・統合テストだけでは判定しません。これらを PASS と報告するには `relink-testbed` の実行ログを添付してください。

初回起動時は Web リクエストでスキーマを作成せず、`php bin/migrate.php`（Container では entrypoint が実行）で明示的に適用します。ローカル HTTP で管理画面を試す場合だけ `RELINK_ADMIN_ALLOW_HTTP=1` を設定し、本番では TLS 終端とネットワーク制御を必須にしてください。TLS 終端プロキシ経由の管理面を確認する場合は、`RELINK_TRUSTED_PROXY_CIDRS` を設定したうえで、未信頼送信元の `X-Forwarded-Proto` が Secure Cookie を有効化しないこと、信頼済みプロキシの HTTPS 転送だけが有効化することを確認します。
