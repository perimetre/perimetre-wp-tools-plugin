<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Tests\Unit\RemoteLogin;

use Perimetre\WpTools\RemoteLogin\Token;
use Perimetre\WpTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Token::verify against tokens produced by Helm's own signer (`signToken` in
 * the portal's tokens service), so these tests double as a contract check
 * between the portal and the plugin rather than re-deriving the plugin's logic.
 *
 * Valid tokens expire in 2100; the expired one expired in 2023.
 */
final class TokenTest extends TestCase
{
    private const KEY = 'test-api-key-not-a-real-secret';

    private const VALID = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
        . 'eyJqdGkiOiJqdGktdmFsaWQiLCJzdWIiOiJwZXJzb25AZXhhbXBsZS5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
        . 'vsVitOKmqZp3mCFiw54eblC2sdky9RHk_EA2KowtBQQ';

    public function test_accepts_a_token_signed_by_helm_with_this_sites_key(): void
    {
        $payload = Token::verify(self::VALID, self::KEY);

        self::assertSame(
            ['jti' => 'jti-valid', 'sub' => 'person@example.com', 'iat' => 1762000000, 'exp' => 4102444800],
            $payload
        );
    }

    public function test_rejects_a_token_signed_with_another_sites_key(): void
    {
        $signed_elsewhere = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
            . 'eyJqdGkiOiJqdGktdmFsaWQiLCJzdWIiOiJwZXJzb25AZXhhbXBsZS'
            . '5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
            . '14KFoSWhen9Z2bNOPveA9Ytull2Lao1_bkl13Fms2cI';

        self::assertNull(Token::verify($signed_elsewhere, self::KEY));
    }

    public function test_rejects_a_valid_token_checked_with_the_wrong_key(): void
    {
        self::assertNull(Token::verify(self::VALID, 'a-different-key'));
    }

    public function test_rejects_a_token_whose_payload_was_altered_after_signing(): void
    {
        [$header, , $signature] = explode('.', self::VALID);
        // Same claims, but the email swapped for another person's.
        $forged_payload = rtrim(strtr(base64_encode((string) json_encode([
            'jti' => 'jti-valid',
            'sub' => 'someone-else@example.com',
            'iat' => 1762000000,
            'exp' => 4102444800,
        ])), '+/', '-_'), '=');

        self::assertNull(Token::verify($header . '.' . $forged_payload . '.' . $signature, self::KEY));
    }

    public function test_rejects_an_expired_token(): void
    {
        $expired = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
            . 'eyJqdGkiOiJqdGktZXhwaXJlZCIsInN1YiI6InBlcnNvbkBleGFtcGx'
            . 'lLmNvbSIsImlhdCI6MTcwMDAwMDAwMCwiZXhwIjoxNzAwMDAwMDYwfQ.'
            . '_Vwu07q5JjZxxzpDmvuf6_BLbfJEGq0vVJ-Pu3h-1m0';

        self::assertNull(Token::verify($expired, self::KEY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty string' => [''];
        yield 'one part' => ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'];
        yield 'two parts' => ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJqdGkiOiJ4In0'];
        yield 'four parts' => [self::VALID . '.extra'];
        yield 'signature not base64' => [substr(self::VALID, 0, strrpos(self::VALID, '.') + 1) . '!!!'];
    }

    #[DataProvider('malformedTokens')]
    public function test_rejects_a_malformed_token(string $token): void
    {
        self::assertNull(Token::verify($token, self::KEY));
    }

    /**
     * Payloads Helm never produces, but signed with the right key so that only
     * the field checks can reject them.
     *
     * @return iterable<string, array{string}>
     */
    public static function signedPayloadsWithBadFields(): iterable
    {
        yield 'missing jti' => [
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
            . 'eyJzdWIiOiJwZXJzb25AZXhhbXBsZS5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
            . 'PUZCco5JGaii7oZ8xWrhd3hib4RoY1fHjxXNOK2gcmM',
        ];
        yield 'exp as a string' => [
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
            . 'eyJqdGkiOiJqdGkteCIsInN1YiI6InBlcnNvbkBleGFtcGxlLmNv'
            . 'bSIsImlhdCI6MTc2MjAwMDAwMCwiZXhwIjoiNDEwMjQ0NDgwMCJ9.'
            . 'Tot9urCJdRb_tgsqW6SYmha4sgLd8k9D5Baf7T_B2IY',
        ];
        yield 'sub as a number' => [
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
            . 'eyJqdGkiOiJqdGkteCIsInN1YiI6NDIsImlhdCI6MTc2MjAwMDAwMCwiZXhwIjo0MTAyNDQ0ODAwfQ.'
            . 'RhqqQuhm8EWSHKuC4DhQtJ-Jo-VK0UAsjwpZqn9TK3U',
        ];
    }

    #[DataProvider('signedPayloadsWithBadFields')]
    public function test_rejects_a_signed_payload_with_missing_or_wrongly_typed_fields(string $token): void
    {
        self::assertNull(Token::verify($token, self::KEY));
    }
}
