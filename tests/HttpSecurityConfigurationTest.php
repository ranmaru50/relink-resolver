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
    }
}
