<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Tests\Unit\RemoteLogin;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Perimetre\WpTools\RemoteLogin\Auth;
use Perimetre\WpTools\Tests\Support\Redirected;
use Perimetre\WpTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use WP_Error;
use WP_User;

/**
 * The one thing a quick try on a real Site can't show: that a portal login
 * never ends up logged in without the Site's 2FA. Everything up to the user
 * lookup is the existing flow and is faked to succeed.
 */
final class AuthTest extends TestCase
{
    private const KEY = 'test-api-key-not-a-real-secret';

    /** Signed by Helm's `signToken` with KEY; expires in 2100. */
    private const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
        . 'eyJqdGkiOiJqdGktdmFsaWQiLCJzdWIiOiJwZXJzb25AZXhhbXBsZS5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
        . 'vsVitOKmqZp3mCFiw54eblC2sdky9RHk_EA2KowtBQQ';

    /** @var list<string> */
    private array $active_plugins = [];

    /** @var list<string> What happened, in order: 'wp_login', 'cookie'. */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Remote Login is on and connected.
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => match ($name) {
            'perimetre_remote_login_enabled' => true,
            'perimetre_remote_login_api_key' => self::KEY,
            'active_plugins'                 => $this->active_plugins,
            default                          => $default,
        });
        Functions\when('is_multisite')->justReturn(false);

        // Helm confirms the single-use claim and returns the person's email.
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->justReturn(['response' => 'ok']);
        Functions\when('is_wp_error')->alias(static fn (mixed $thing): bool => $thing instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":true,"email":"person@example.com"}');

        // The person has an account on the Site.
        Functions\when('get_user_by')->justReturn(new WP_User(7, 'person', 'person@example.com'));

        Functions\when('wp_clear_auth_cookie')->justReturn(null);
        Functions\when('wp_set_current_user')->justReturn(null);
        Functions\when('wp_set_auth_cookie')->alias(function (): void {
            $this->events[] = 'cookie';
        });
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://site.example/wp-admin/');
        Functions\when('wp_login_url')->justReturn('https://site.example/wp-login.php');
        Functions\when('wp_safe_redirect')->alias(static function (string $location): never {
            throw new Redirected($location);
        });
    }

    /**
     * @return iterable<string, array{callable(self): void, string}>
     */
    public static function unsupported_two_factor_setups(): iterable
    {
        yield 'a dedicated 2FA plugin that is not supported' => [
            static function (self $test): void {
                $test->active_plugins = ['two-factor/two-factor.php'];
            },
            'two-factor',
        ];
        yield 'a plugin that objects in the authenticate check (e.g. Wordfence with 2FA on)' => [
            static function (): void {
                Filters\expectApplied('authenticate')
                    ->andReturn(new WP_Error('wfls_twofactor_required', 'Two-factor code required.'));
            },
            'login_check',
        ];
    }

    #[DataProvider('unsupported_two_factor_setups')]
    public function test_refuses_without_creating_a_session_when_the_sites_2fa_is_unsupported(
        callable $arrange,
        string $reason
    ): void {
        $arrange($this);
        Actions\expectDone('wp_login')->never();

        $redirect = $this->login();

        self::assertSame(
            'https://site.example/wp-login.php?perimetre_remote_login_refused=' . $reason,
            $redirect->location
        );
        self::assertSame([], $this->events, 'Neither the login event nor a session cookie may happen');
    }

    public function test_a_2fa_plugin_takes_over_before_any_session_cookie_exists(): void
    {
        $this->active_plugins = ['wp-health/wp-health.php'];
        // WP Umbrella shows its code screen during the login event and exits.
        Actions\expectDone('wp_login')->once()->whenHappen(function (): void {
            $this->events[] = 'wp_login';
            throw new RuntimeException('2FA plugin took over the request');
        });

        try {
            Auth::handle(self::TOKEN);
            self::fail('Expected the 2FA plugin to take over');
        } catch (RuntimeException $takeover) {
            self::assertSame('2FA plugin took over the request', $takeover->getMessage());
        }

        self::assertSame(['wp_login'], $this->events, 'No session cookie before the 2FA plugin has checked the code');
    }

    private function login(): Redirected
    {
        try {
            Auth::handle(self::TOKEN);
        } catch (Redirected $redirect) {
            return $redirect;
        }
        self::fail('Expected a redirect');
    }
}
