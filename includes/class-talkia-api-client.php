<?php
// Fala com a API do plugin na Talkia (/api/plugin/v1/..., ver
// customizations/plugin_api.py no repo talkia-web) — autenticação por Bearer
// token, chamada servidor-a-servidor (WP -> Talkia), nunca pelo navegador do
// visitante do site. Usa a HTTP API do próprio WordPress (wp_remote_*), nunca
// cURL direto — é o jeito padrão e o que respeita proxy/SSL configurados no
// servidor do cliente.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Talkia_Api_Client {

	private static function base_url() {
		return defined( 'TALKIA_CONNECT_API_BASE' ) ? TALKIA_CONNECT_API_BASE : 'https://talkia.8bitlab.com.br';
	}

	private static function request( $method, $path, $raw_token, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $raw_token,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'     => false,
				'error'  => isset( $data['error'] ) ? $data['error'] : ( 'HTTP ' . $code ),
				'status' => $code,
			);
		}
		return $data;
	}

	public static function validate( $raw_token ) {
		return self::request( 'POST', '/api/plugin/v1/validar/', $raw_token );
	}

	public static function get_bot( $raw_token ) {
		return self::request( 'GET', '/api/plugin/v1/bot/', $raw_token );
	}

	public static function update_bot( $raw_token, $updates ) {
		return self::request( 'PATCH', '/api/plugin/v1/bot/', $raw_token, $updates );
	}

	public static function get_conversations( $raw_token ) {
		return self::request( 'GET', '/api/plugin/v1/conversas/', $raw_token );
	}

	public static function get_usage( $raw_token ) {
		return self::request( 'GET', '/api/plugin/v1/uso/', $raw_token );
	}

	public static function revoke( $raw_token ) {
		return self::request( 'POST', '/api/plugin/v1/revogar/', $raw_token );
	}
}
