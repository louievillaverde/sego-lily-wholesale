<?php
/**
 * Field-Level Encryption for Sensitive PII
 *
 * AES-256-CBC encryption for EIN numbers, resale certificates, and other
 * sensitive fields. Uses WordPress AUTH_KEY as the encryption key source
 * so each installation has a unique key without extra configuration.
 *
 * Existing plaintext values are handled transparently: if decrypt() sees
 * a value without the :: separator, it returns it as-is (plaintext fallback).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Encryption {

    public static function init() {
        // Nothing to hook — utility class only.
    }

    /**
     * Encrypt a plaintext value using AES-256-CBC.
     *
     * @param string $value Plaintext value.
     * @return string Base64-encoded IV::ciphertext, or empty string if input is empty.
     */
    public static function encrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }
        $key       = self::get_key();
        $iv        = openssl_random_pseudo_bytes( 16 );
        $encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );
        return base64_encode( $iv . '::' . $encrypted );
    }

    /**
     * Decrypt an encrypted value. Falls back to returning the original
     * value if it does not look encrypted (no :: separator after decode).
     *
     * @param string $value Encrypted (base64) or plaintext value.
     * @return string Decrypted plaintext.
     */
    public static function decrypt( $value ) {
        if ( empty( $value ) ) {
            return $value;
        }
        $decoded = base64_decode( $value, true );
        if ( $decoded === false || strpos( $decoded, '::' ) === false ) {
            return $value; // Plaintext fallback.
        }
        $key  = self::get_key();
        $parts = explode( '::', $decoded, 2 );
        if ( count( $parts ) !== 2 ) {
            return $value;
        }
        $iv        = $parts[0];
        $encrypted = $parts[1];
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );
        return $decrypted !== false ? $decrypted : $value;
    }

    /**
     * Derive a 256-bit key from WordPress AUTH_KEY salt.
     *
     * @return string Binary key (32 bytes).
     */
    private static function get_key() {
        return hash( 'sha256', AUTH_KEY . 'slw_encryption', true );
    }
}
