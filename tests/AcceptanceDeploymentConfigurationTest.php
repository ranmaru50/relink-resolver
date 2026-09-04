<?php
// tests/AcceptanceDeploymentConfigurationTest.php
// Testbed 受入用 Native/Container profile の配備境界と TLS 設定を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AcceptanceDeploymentConfigurationTest extends TestCase
{
    /** 受入用 Compose は HTTPS、loopback 公開、永続 volume、HTTP 管理面拒否を明示する。 */
    public function testAcceptanceComposeDefinesAnIsolatedHttpsProfile(): void
    {
        $compose = $this->read('compose.acceptance.yaml');

        self::assertStringContainsString('127.0.0.1:${RELINK_CONTAINER_HTTP_PORT:-8080}:80', $compose);
        self::assertStringContainsString('127.0.0.1:${RELINK_CONTAINER_HTTPS_PORT:-8443}:443', $compose);
        self::assertStringContainsString('resolver-acceptance-data:/var/lib/relink-resolver', $compose);
        self::assertStringContainsString('RELINK_TLS_ENABLED: "1"', $compose);
        self::assertStringContainsString('RELINK_ADMIN_ALLOW_HTTP: "0"', $compose);
        self::assertStringContainsString('.env.acceptance', $compose);
        self::assertStringNotContainsString('change-me', $compose);
        self::assertStringNotContainsString('relink-acceptance', $compose);
    }

    /** 直接 TLS の両 profile は証明書を web root 外から読み、HSTS を HTTPS に限定する。 */
    public function testNativeAndContainerTlsVirtualHostsUsePrivateCertificates(): void
    {
        foreach (['deploy/apache-ssl-vhost.conf', 'deploy/apache-native-ssl-vhost.conf.example'] as $path) {
            $configuration = $this->read($path);
            self::assertStringContainsString('SSLEngine on', $configuration);
            self::assertStringContainsString('SSLCertificateFile /etc/relink/tls/cert.pem', $configuration);
            self::assertStringContainsString('SSLCertificateKeyFile /etc/relink/tls/key.pem', $configuration);
            self::assertStringContainsString('Header always set Strict-Transport-Security "max-age=31536000"', $configuration);
            self::assertStringNotContainsString('/var/www/html/tls', $configuration);
        }

        $nativeConfiguration = $this->read('deploy/apache-native-ssl-vhost.conf.example');
        foreach (['ServerTokens Prod', 'ServerSignature Off', 'TraceEnable Off', 'LimitRequestBody 65536'] as $directive) {
            self::assertStringContainsString($directive, $nativeConfiguration);
        }
        self::assertStringContainsString('Listen 127.0.0.1:8444', $nativeConfiguration);
        self::assertStringContainsString('<VirtualHost 127.0.0.1:8444>', $nativeConfiguration);
    }

    /** Container image は受入時だけ TLS VirtualHost を有効化でき、HTTPS ポートを公開する。 */
    public function testContainerImageContainsTheAcceptanceTlsSwitch(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $entrypoint = $this->read('docker-entrypoint.sh');

        self::assertStringContainsString('a2enmod rewrite headers reqtimeout ssl', $dockerfile);
        self::assertStringContainsString('EXPOSE 80 443', $dockerfile);
        self::assertStringContainsString('RELINK_TLS_ENABLED', $entrypoint);
        self::assertStringContainsString('a2ensite relink-ssl', $entrypoint);
    }

    /** 受入専用の fault fixture は通常 profile では有効化されない。 */
    public function testAcceptanceFaultConfigurationIsNotEnabledByDefault(): void
    {
        $configuration = $this->read('deploy/apache-acceptance.conf');
        $dockerfile = $this->read('Dockerfile');
        $entrypoint = $this->read('docker-entrypoint.sh');

        self::assertStringContainsString('<LocationMatch "^/__security__/apache-error$">', $configuration);
        self::assertStringContainsString('Require all denied', $configuration);
        self::assertStringContainsString('Redirect 503 "/__security__/503"', $configuration);
        self::assertStringContainsString('conf-available/relink-acceptance.conf', $dockerfile);
        self::assertStringContainsString('RELINK_ENV:-development', $entrypoint);
        self::assertStringContainsString('a2enconf relink-acceptance', $entrypoint);
        self::assertStringContainsString('a2disconf relink-acceptance', $entrypoint);
    }

    /** Native受入 profile は HTTP開発面と HTTPS面を分離し、HSTSをHTTPSへ限定する。 */
    public function testNativeAcceptanceProfileProvidesHttpDevelopmentSurface(): void
    {
        $configuration = $this->read('deploy/apache-native-ssl-vhost.conf.example');
        $httpSection = strstr($configuration, '<VirtualHost 127.0.0.1:8081>');

        self::assertNotFalse($httpSection);
        self::assertStringContainsString('Listen 127.0.0.1:8081', $configuration);
        self::assertStringContainsString('DocumentRoot /var/www/relink-resolver/public', $httpSection);
        self::assertStringNotContainsString('Strict-Transport-Security', $httpSection);
    }

    /** 受入 fixture の準備は明示的な acceptance 環境でだけ実行できる。 */
    public function testAcceptanceFixturePreparationIsExplicitlyGuarded(): void
    {
        $script = $this->read('bin/prepare-acceptance-fixtures.php');

        self::assertStringContainsString('RELINK_ENV=acceptance', $script);
        self::assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $script);
        self::assertStringContainsString("manifest-without-integrity", $script);
    }

    /** Testbed #3へ渡すprofile別URLと、Resolver側ではPASS判定しない境界を文書化する。 */
    public function testSecurityHeaderHandoffIsDocumented(): void
    {
        $documentation = $this->read('docs/acceptance-deployments.md');

        foreach (['RELINK_SECURITY_NATIVE_HTTPS_URL', 'RELINK_SECURITY_NATIVE_HTTP_URL', 'RELINK_SECURITY_CONTAINER_HTTP_URL', 'RELINK_SECURITY_NATIVE_5XX_URL', 'RELINK_SECURITY_CONTAINER_5XX_URL', 'pnpm security:headers', 'relink-testbed'] as $term) {
            self::assertStringContainsString($term, $documentation);
        }
    }

    /** 受入アカウント例は placeholder のみを含み、HTTP 管理面を明示的に無効化する。 */
    public function testAcceptanceEnvironmentExampleContainsNoProductionSecret(): void
    {
        $environment = $this->read('.env.acceptance.example');

        self::assertStringContainsString('RELINK_ADMIN_PASSWORD=replace-with-a-local-test-secret', $environment);
        self::assertStringContainsString('RELINK_ADMIN_ALLOW_HTTP=0', $environment);
        self::assertStringNotContainsString('change-me', $environment);
    }

    /** リポジトリルートから設定ファイルを UTF-8 として読み込む。 */
    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__) . '/' . $relativePath;
        if (!is_file($path)) {
            // Container imageには受入Compose等のsource-onlyファイルをコピーしない。
            self::markTestSkipped($relativePath . ' is not included in the runtime image.');
        }
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);
        return $contents;
    }
}
