# テスト

ホストに PHP 8.3 以上と `pdo_sqlite` がある場合は、リポジトリルートで次を実行します。

```sh
php tests/run.php
```

`tests/ResolverServiceTest.php` は HTTP サーバーや外部ネットワークを使わず、公開 Resolver のステータス、メソッド独立性、UUID 正規化、L1 downgrade 防止、Manifest の基本構造を検証します。SQLite アダプタの検証は、PHP の `pdo_sqlite` が有効な環境で一時 DB を用いて実施してください。

`docs/specs/resolver-manifest-conformance-0.1.md` が Frozen Conformance Catalog です。実行可能な Testbed は `relink-testbed` 側で管理します。現在のスモークテストは RES-001、RES-003、HTTP-002〜HTTP-005、LIFE-001〜LIFE-003、MAN-001、MAN-009〜MAN-010 の基礎動作をカバーします。実装テスト全体では Resolver の UUID/HTTP method/status/cache/CORS/HTTPS 境界、Lifecycle の許可遷移・終端状態・同一状態 no-op・競合、Manifest の strict JSON と整合性、Persistence の binding/migration/履歴 transaction に対応付けます。
