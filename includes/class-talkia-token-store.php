<?php
// Guarda o token do plugin criptografado no banco do WordPress (nunca em
// claro) e um cache local dos dados básicos do bot, pra tela de ajustes não
// depender de chamada à Talkia toda vez que abre.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Talkia_Token_Store {

	const OPTION_TOKEN = 'talkia_connect_token';
	const OPTION_BOT   = 'talkia_connect_bot';

	/**
	 * Chave de criptografia (AES-256): vem de uma constante dedicada em
	 * wp-config.php quando definida (TALKIA_ENCRYPTION_KEY) — nunca salva
	 * junto do dado que ela protege, que é o requisito de segurança real.
	 * Sem a constante, deriva dos salts que o próprio WordPress já guarda em
	 * wp-config.php (AUTH_KEY/SECURE_AUTH_KEY), pra funcionar sem exigir
	 * configuração extra do cliente leigo.
	 */
	private static function encryption_key() {
		if ( defined( 'TALKIA_ENCRYPTION_KEY' ) && TALKIA_ENCRYPTION_KEY ) {
			return hash( 'sha256', TALKIA_ENCRYPTION_KEY, true );
		}
		$fallback = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		return hash( 'sha256', 'talkia-connect|' . $fallback, true );
	}

	public static function save_token( $raw_token ) {
		$key    = self::encryption_key();
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$cipher = openssl_encrypt( $raw_token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return false;
		}
		// IV vai junto do valor salvo (não é segredo, só precisa ser único
		// por mensagem) — sem ele não dá pra decifrar de volta.
		update_option( self::OPTION_TOKEN, base64_encode( $iv . $cipher ), false );
		return true;
	}

	public static function get_token() {
		$stored = get_option( self::OPTION_TOKEN, '' );
		if ( ! $stored ) {
			return '';
		}
		$raw = base64_decode( $stored, true );
		if ( false === $raw ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv     = substr( $raw, 0, $iv_len );
		$cipher = substr( $raw, $iv_len );
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	public static function clear_token() {
		delete_option( self::OPTION_TOKEN );
		delete_option( self::OPTION_BOT );
	}

	public static function save_bot_cache( $bot_data ) {
		update_option( self::OPTION_BOT, $bot_data, false );
	}

	public static function get_bot_cache() {
		$cached = get_option( self::OPTION_BOT, null );
		return is_array( $cached ) ? $cached : null;
	}

	public static function is_connected() {
		return '' !== self::get_token();
	}
}
