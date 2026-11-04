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
 * The two payloads that aren't a JSON object, which `signToken` can't produce,
 * were signed with the same HMAC-SHA256 construction directly.
 *
 * Valid tokens expire in 2100; the expired one expired in 2023.
 */
final class TokenTest extends TestCase
{
    private const KEY = 'test-api-key-not-a-real-secret';

    /** base64url of {"alg":"HS256","typ":"JWT"}, the header Helm always sends. */
    private const HEADER = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';

    private const VALID_TOKEN = self::HEADER . '.'
        . 'eyJqdGkiOiJqdGktdmFsaWQiLCJzdWIiOiJwZXJzb25AZXhhbXBsZS5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
        . 'vsVitOKmqZp3mCFiw54eblC2sdky9RHk_EA2KowtBQQ';

    public function test_accepts_a_token_signed_by_helm_with_this_sites_key(): void
    {
        $payload = Token::verify(self::VALID_TOKEN, self::KEY);

        self::assertSame(
            ['jti' => 'jti-valid', 'sub' => 'person@example.com', 'iat' => 1762000000, 'exp' => 4102444800],
            $payload
        );
    }

    public function test_rejects_a_token_signed_with_another_sites_key(): void
    {
        // The same claims as VALID_TOKEN, signed by Helm with a different site's key.
        $signed_elsewhere = self::HEADER . '.'
            . 'eyJqdGkiOiJqdGktdmFsaWQiLCJzdWIiOiJwZXJzb25AZXhhbXBsZS'
            . '5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
            . '14KFoSWhen9Z2bNOPveA9Ytull2Lao1_bkl13Fms2cI';

        self::assertNull(Token::verify($signed_elsewhere, self::KEY));
    }

    public function test_rejects_a_token_whose_payload_was_altered_after_signing(): void
    {
        [, , $signature] = explode('.', self::VALID_TOKEN);
        // Same claims, but the email swapped for another person's.
        $forged_payload = rtrim(strtr(base64_encode((string) json_encode([
            'jti' => 'jti-valid',
            'sub' => 'someone-else@example.com',
            'iat' => 1762000000,
            'exp' => 4102444800,
        ])), '+/', '-_'), '=');

        self::assertNull(Token::verify(self::HEADER . '.' . $forged_payload . '.' . $signature, self::KEY));
    }

    public function test_rejects_an_expired_token(): void
    {
        $expired = self::HEADER . '.'
            . 'eyJqdGkiOiJqdGktZXhwaXJlZCIsInN1YiI6InBlcnNvbkBleGFtcGx'
            . 'lLmNvbSIsImlhdCI6MTcwMDAwMDAwMCwiZXhwIjoxNzAwMDAwMDYwfQ.'
            . '_Vwu07q5JjZxxzpDmvuf6_BLbfJEGq0vVJ-Pu3h-1m0';

        self::assertNull(Token::verify($expired, self::KEY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed_tokens(): iterable
    {
        yield 'empty string' => [''];
        yield 'one part' => [self::HEADER];
        yield 'two parts' => [self::HEADER . '.eyJqdGkiOiJ4In0'];
        yield 'four parts' => [self::VALID_TOKEN . '.extra'];
        yield 'signature not base64' => [substr(self::VALID_TOKEN, 0, strrpos(self::VALID_TOKEN, '.') + 1) . '!!!'];
        yield 'signed payload not JSON' => [
            self::HEADER . '.bm90IGpzb24gYXQgYWxs.AZGJcGEP4SEfJb1Bcx2vObDVSJB1NTdaVnK80c3HcMo',
        ];
        yield 'signed payload a JSON string, not an object' => [
            self::HEADER . '.Imp1c3QgYSBzdHJpbmci.gToOijJti_TroN-R-Nat16c1GGtAcxLwfJTOWsJkCIA',
        ];
    }

    #[DataProvider('malformed_tokens')]
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
    public static function signed_payloads_with_bad_fields(): iterable
    {
        yield 'missing jti' => [
            self::HEADER . '.'
            . 'eyJzdWIiOiJwZXJzb25AZXhhbXBsZS5jb20iLCJpYXQiOjE3NjIwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.'
            . 'PUZCco5JGaii7oZ8xWrhd3hib4RoY1fHjxXNOK2gcmM',
        ];
        yield 'missing sub' => [
            self::HEADER . '.'
            . 'eyJqdGkiOiJqdGkteCIsImlhdCI6MTc2MjAwMDAwMCwiZXhwIjo0MTAyNDQ0ODAwfQ.'
            . 'VU3LnLSJ-ocJqev1dmhKfY3u2f9V60eGQRYX2KYDzLo',
        ];
        yield 'missing exp' => [
            self::HEADER . '.'
            . 'eyJqdGkiOiJqdGkteCIsInN1YiI6InBlcnNvbkBleGFtcGxlLmNvbSIsImlhdCI6MTc2MjAwMDAwMH0.'
            . 'CrG6138j6GjpOLjr0xBnWQQgbcuREqCCePICBQaJ0sw',
        ];
        yield 'jti as a number' => [
            self::HEADER . '.'
            . 'eyJqdGkiOjcsInN1YiI6InBlcnNvbkBleGFtcGxlLmNvbSIsImlhdCI6MTc2MjAwMDAwMCwiZXhwIjo0MTAyNDQ0ODAwfQ.'
            . 'wkAvTWb9kC3_hNoIAnOWwP3CfvVshA6LiemfLYbki-4',
        ];
        yield 'sub as a number' => [
            self::HEADER . '.'
            . 'eyJqdGkiOiJqdGkteCIsInN1YiI6NDIsImlhdCI6MTc2MjAwMDAwMCwiZXhwIjo0MTAyNDQ0ODAwfQ.'
            . 'RhqqQuhm8EWSHKuC4DhQtJ-Jo-VK0UAsjwpZqn9TK3U',
        ];
        yield 'exp as a string' => [
            self::HEADER . '.'
            . 'eyJqdGkiOiJqdGkteCIsInN1YiI6InBlcnNvbkBleGFtcGxlLmNv'
            . 'bSIsImlhdCI6MTc2MjAwMDAwMCwiZXhwIjoiNDEwMjQ0NDgwMCJ9.'
            . 'Tot9urCJdRb_tgsqW6SYmha4sgLd8k9D5Baf7T_B2IY',
        ];
    }

    #[DataProvider('signed_payloads_with_bad_fields')]
    public function test_rejects_a_signed_payload_with_missing_or_wrongly_typed_fields(string $token): void
    {
        self::assertNull(Token::verify($token, self::KEY));
    }
}
