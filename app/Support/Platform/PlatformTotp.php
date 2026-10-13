<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/** RFC 6238 SHA-1, 6 digits, 30 seconds, +/- one time-step drift. */
final class PlatformTotp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function newSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec($chunk)];
        }

        return $secret;
    }

    public function encrypt(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decrypt(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    public function uri(string $secret, string $email): string
    {
        return 'otpauth://totp/'.rawurlencode('Property ERP Platform:'.$email)
            .'?secret='.$secret.'&issuer='.rawurlencode('Property ERP Platform')
            .'&algorithm=SHA1&digits=6&period=30';
    }

    /** Return a valid, not-previously-used time step, or null. */
    public function acceptedStep(string $secret, string $code, ?int $previousStep): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }

        $nowStep = intdiv(now()->timestamp, 30);
        for ($step = $nowStep - 1; $step <= $nowStep + 1; $step++) {
            if ($step < 0 || ($previousStep !== null && $step <= $previousStep)) {
                continue;
            }
            if (hash_equals($this->atStep($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public function atStep(string $secret, int $step): string
    {
        $secret = strtoupper(trim($secret));
        if ($secret === '' || preg_match('/[^A-Z2-7]/', $secret)) {
            throw new InvalidArgumentException('Invalid TOTP secret.');
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $bytes .= chr(bindec($byte));
        }
        $digest = hash_hmac('sha1', pack('N2', 0, $step), $bytes, true);
        $offset = ord($digest[19]) & 0x0f;
        $number = ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function recoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 4));
        }
        return $codes;
    }

    public function recoveryHash(string $code): ?string
    {
        $normalized = strtoupper(trim($code));
        if (! preg_match('/^[A-F0-9]{4}(?:-[A-F0-9]{4}){7}$/D', $normalized)) {
            return null;
        }
        return hash('sha256', str_replace('-', '', $normalized));
    }
}
