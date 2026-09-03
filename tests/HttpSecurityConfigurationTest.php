<?php
// tests/HttpSecurityConfigurationTest.php
// Native/Container の HTTP ハードニング設定と公開・管理アダプタのヘッダー方針を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HttpSecurityConfigurationTest extends TestCase
{
    /** 両 deployment profile はバージョン露出、TRACE、MIME sniffing を抑止する。 */
    public function testApacheProfilesApplyCommonHardeningToErrorResponses(): void
    {
        foreach (['deploy/apache-docker.conf', 'deploy/apache-vhost.conf.example'] as $path) {
            $configuration = file_get_contents(dirname(__DIR__) . '/' . $path);
            self::assertNotFalse($configuration);
            self::assertStringContainsString('ServerTokens Prod', $configuration);
            self::assertStringContainsString('ServerSignature Off', $configuration);
            self::assertStringContainsString('TraceEnable Off', $configuration);
            self::assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $configuration);
        }
    }

    /** Container の PHP と Apache 設定は PHP バージョン露出および MIME sniffing を抑止する。 */
    public function testContainerApacheAndPhpDisableVersionExposureAndSniffing(): void
    {
        $phpConfiguration = file_get_contents(dirname(__DIR__) . '/deploy/php-security.ini');
        self::assertNotFalse($phpConfiguration);
        self::assertStringContainsString('expose_php = Off', $phpConfiguration);
        self::assertStringContainsString('post_max_size = 64K', $phpConfiguration);
        self::assertStringContainsString('max_input_vars = 32', $phpConfiguration);
        self::assertStringContainsString('arg_separator.input = "&"', $phpConfiguration);
        self::assertStringContainsString('max_input_time = 10', $phpConfiguration);
        self::assertStringContainsString('max_execution_time = 15', $phpConfiguration);
        self::assertStringContainsString('memory_limit = 128M', $phpConfiguration);
    }

    /** HSTS は HTTPS Native VirtualHost、クリックジャッキング対策は全管理応答に限定する。 */
    public function testHstsAndAdminClickjackingPolicyAreScopedToTheirSurfaces(): void
    {
        $nativeConfiguration = file_get_contents(dirname(__DIR__) . '/deploy/apache-vhost.conf.example');
        $containerConfiguration = file_get_contents(dirname(__DIR__) . '/deploy/apache-docker.conf');
        $adminAdapter = file_get_contents(dirname(__DIR__) . '/public/admin.php');
        self::assertNotFalse($nativeConfiguration);
        self::assertNotFalse($containerConfiguration);
        self::assertNotFalse($adminAdapter);
        self::assertStringContainsString('Header always set Strict-Transport-Security "max-age=31536000"', $nativeConfiguration);
        self::assertStringNotContainsString('Strict-Transport-Security', $containerConfiguration);
        self::assertStringContainsString("frame-ancestors 'none'", $adminAdapter);
        self::assertStringContainsString('assertRawVariableCount', $adminAdapter);
    }

    /** Native/Containerで本文、ヘッダー、低速接続、過大要求の応答方針を一致させる。 */
    public function testNativeAndContainerApplyRequestResourceLimits(): void
    {
        $native = file_get_contents(dirname(__DIR__) . '/deploy/apache-vhost.conf.example');
        $container = file_get_contents(dirname(__DIR__) . '/deploy/apache-docker.conf');
        self::assertNotFalse($native);
        self::assertNotFalse($container);
        foreach (['LimitRequestBody 65536', 'LimitRequestFields 50', 'LimitRequestFieldSize 8190', 'RequestReadTimeout header=10-20,MinRate=500 body=10,MinRate=500', 'ErrorDocument 413'] as $directive) {
            self::assertStringContainsString($directive, $native);
            self::assertStringContainsString($directive, $container);
        }
    }

    /** 公開Resolver/Manifestは管理面の入力上限実装に依存しない。 */
    public function testPublicAdapterDoesNotUseAdministrativeListQuery(): void
    {
        $publicAdapter = file_get_contents(dirname(__DIR__) . '/public/index.php');
        self::assertNotFalse($publicAdapter);
        self::assertStringNotContainsString('AdminRequestGuard', $publicAdapter);
        self::assertStringNotContainsString('->search(', $publicAdapter);
    }
}
