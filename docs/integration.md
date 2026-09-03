# Resolver Engine 統合ガイド

このリポジトリの `src/Domain`、`src/Application`、`src/Ports` は、Plain PHP の公開入口、管理画面、Apache、SQLite から独立した Resolver Engine です。フレームワークの Request/Response、ORM、認証、セッション、設定 API をこの層へ持ち込まず、ホストの composition root でアダプタを組み立てます。

## 依存方向

```text
Laravel / Symfony / Slim / CLI の入口
        ↓  入力の変換・応答の変換
ResolverService + Domain
        ↓
ResolverRepository / AdminCredentialVerifier などの Ports
        ↓
PostgreSQL / MySQL / SQLite / ホスト認証のアダプタ
```

`ResolverService::resolve()` は HTTP サーバーの Request オブジェクトではなく、メソッド、UUID、クエリ値を受け取り、`ResolutionResult` を返します。ホストは入力をこの形式へ変換し、結果の status/header を自分の Response へ写像できます。303、404、410、405、501 などのプロトコル結果はアプリケーション結果に残るため、ホスト統合で意味が変わりません。

## フレームワーク側の組み立て例

Laravel 風の擬似コードは次の形です。実際の Laravel 型や ORM モデルを Resolver Engine に渡すのではなく、Resolver 所有の `ResolverRepository` を実装するアダプタへ閉じ込めます。

```php
final class ResolverController
{
    public function __construct(private ResolverService $resolver) {}

    public function show(Request $request, string $uuid): Response
    {
        $result = $this->resolver->resolve(
            $request->method(),
            $uuid,
            $request->query->all(),
        );

        return new Response('', $result->status, $result->headers);
    }
}

// composition root。Laravel の設定・DI・DB 接続はここでアダプタへ注入する。
$resolver = new ResolverService(
    new CompanyPostgresResolverRepository($connection),
);
```

Symfony などでも同じ組み立てを行い、フレームワークの controller、middleware、DI container は hosting adapter の責務に留めます。ORM の Entity は `ResolverRecord` の代替ではなく、`ResolverRepository` アダプタ内部の保存形式です。

## 認証・セッションの置き換え

管理認証は RELink L2 ではなく、ホスト管理面の責務です。標準の Plain PHP 管理面は `ConfiguredAdminCredentialVerifier` と `AdminSessionPolicy` を使いますが、ホストは `AdminCredentialVerifier` を実装して、既存の Auth/RBAC/SSO の判定を注入できます。PHP の `$_SESSION` やフレームワークの session object はホスト側で `AdminSession` に変換し、`AdminSessionPolicy` へ渡します。

認証済みホストだけが `ResolverService::register()`、`updateLocation()`、`transition()` などの管理ユースケースを呼び出します。認証方式を変更しても Resolver のライフサイクル、公開解決、Manifest の意味論は変更しません。

## 履歴と永続化

`ResolverRepository::history()` は DB 行の汎用配列ではなく `ResolverHistoryEntry` を返します。履歴はライフサイクル遷移を含む Resolver の変更履歴であり、会社固有の管理監査ログや認証ログではありません。ホスト UI/API は必要な wire 表現へ明示的に変換します。

SQLite の `SqliteResolverRepository` はこの Port の一実装に過ぎません。別 DB を使う場合も、楽観的同時実行制御、ライフサイクル状態遷移と履歴の原子的更新、登録済み UUID の一意性という契約をアダプタで維持します。

## 構成とセキュリティ境界

環境変数の読み取りは `bootstrap.php` の composition root に限定し、Domain/Application へ設定名や環境 API を渡しません。公開 Resolver は読み取り専用であり、管理認証や外部 AR-XML 取得を呼び出しません。各ホストは自身の HTTPS、認証、CSRF、認可、ログ、ネットワークポリシーを adapter として構成してください。
