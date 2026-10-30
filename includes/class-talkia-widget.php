<?php
// Injeta o mesmo embed.js que já existe (ver static/js/embed.js no repo
// talkia-web) no site do cliente, sem precisar editar tema nenhum. De
// propósito, NUNCA depende de uma chamada viva à Talkia durante o carregamento
// do site do cliente — usa só o cache local gravado quando o token foi
// conectado/validado (ver Talkia_Token_Store). Se a Talkia estiver fora do ar
// num momento qualquer, o site do cliente carrega normal mesmo assim.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Talkia_Widget {

	const HANDLE = 'talkia-connect-embed';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		$bot = self::active_bot();
		if ( ! $bot ) {
			return;
		}

		$src = rtrim( defined( 'TALKIA_CONNECT_API_BASE' ) ? TALKIA_CONNECT_API_BASE : 'https://talkia.8bitlab.com.br', '/' ) . '/static/js/embed.js';

		wp_enqueue_script(
			self::HANDLE,
			$src,
			array(),
			TALKIA_CONNECT_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// embed.js lê o ID do bot em document.currentScript.dataset.bot — não
		// tem como wp_enqueue_script setar um atributo customizado sozinho,
		// então completa a tag via filtro, só para o nosso próprio handle.
		add_filter( 'script_loader_tag', array( __CLASS__, 'add_data_bot_attribute' ), 10, 3 );
	}

	public static function add_data_bot_attribute( $tag, $handle, $src ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$bot = self::active_bot();
		if ( ! $bot ) {
			return $tag;
		}
		return str_replace( ' src=', ' data-bot="' . esc_attr( $bot['bot_id'] ) . '" src=', $tag );
	}

	/**
	 * Devolve os dados do bot cacheado localmente, só se conectado E marcado
	 * como pago — mesmo critério que a Talkia já aplica do lado dela
	 * (bot_info retorna 402 se não pago). Checar aqui também evita soltar um
	 * <script> que vai falhar silenciosamente em toda visita à toa.
	 */
	private static function active_bot() {
		if ( ! Talkia_Token_Store::is_connected() ) {
			return null;
		}
		$bot = Talkia_Token_Store::get_bot_cache();
		if ( ! $bot || empty( $bot['bot_id'] ) || empty( $bot['is_paid'] ) ) {
			return null;
		}
		return $bot;
	}
}
