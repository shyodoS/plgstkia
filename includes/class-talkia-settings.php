<?php
// Tela dentro do wp-admin pra colar o token e conectar com a Talkia. Processa
// o formulário via admin-post.php (não Settings API): o valor salvo não é o
// token em claro digitado no formulário, é a versão criptografada que o
// Talkia_Token_Store produz — não se encaixa bem no "salva exatamente o que o
// sanitize_callback devolve" da Settings API.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Talkia_Settings {

	const PAGE_SLUG     = 'talkia-connect';
	const NONCE_CONNECT = 'talkia_connect_save_token';
	const NONCE_DISCONNECT = 'talkia_connect_disconnect';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_talkia_connect_save_token', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_talkia_connect_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'Talkia',
			'Talkia',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-chat',
			80
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'talkia-connect' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_CONNECT );

		$raw_token = isset( $_POST['talkia_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['talkia_token'] ) ) ) : '';
		if ( '' === $raw_token ) {
			self::redirect_with_notice( 'empty' );
		}

		// Valida na hora contra a Talkia antes de salvar qualquer coisa —
		// elimina o "colei e não sei se funcionou".
		$result = Talkia_Api_Client::validate( $raw_token );
		if ( empty( $result['ok'] ) ) {
			self::redirect_with_notice( 'invalid' );
		}

		Talkia_Token_Store::save_token( $raw_token );
		Talkia_Token_Store::save_bot_cache( $result );
		self::redirect_with_notice( 'connected' );
	}

	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'talkia-connect' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_DISCONNECT );

		$raw_token = Talkia_Token_Store::get_token();
		if ( $raw_token ) {
			// Best-effort: se a Talkia estiver fora do ar, desconecta local
			// mesmo assim — o cliente não pode ficar preso esperando.
			Talkia_Api_Client::revoke( $raw_token );
		}
		Talkia_Token_Store::clear_token();
		self::redirect_with_notice( 'disconnected' );
	}

	private static function redirect_with_notice( $notice ) {
		$url = add_query_arg(
			array(
				'page'          => self::PAGE_SLUG,
				'talkia_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bot       = Talkia_Token_Store::get_bot_cache();
		$connected = Talkia_Token_Store::is_connected();
		$notice    = isset( $_GET['talkia_notice'] ) ? sanitize_key( wp_unslash( $_GET['talkia_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1>Talkia</h1>

			<?php if ( 'connected' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Conectado com sucesso!', 'talkia-connect' ); ?></p></div>
			<?php elseif ( 'invalid' === $notice ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Token inválido ou revogado. Gere um novo token no painel da Talkia (aba "Conectar WordPress").', 'talkia-connect' ); ?></p></div>
			<?php elseif ( 'empty' === $notice ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Cole o token antes de salvar.', 'talkia-connect' ); ?></p></div>
			<?php elseif ( 'disconnected' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Desconectado.', 'talkia-connect' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $connected && $bot ) : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php esc_html_e( 'Conectado com o bot:', 'talkia-connect' ); ?></strong>
						<?php echo esc_html( isset( $bot['persona']['name'] ) ? $bot['persona']['name'] : '' ); ?>
					</p>
					<?php if ( empty( $bot['is_paid'] ) ) : ?>
						<p><em><?php esc_html_e( 'Atenção: este chatbot ainda não está marcado como pago na Talkia — o widget não vai aparecer no site até isso ser confirmado.', 'talkia-connect' ); ?></em></p>
					<?php endif; ?>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="talkia_connect_disconnect">
					<?php wp_nonce_field( self::NONCE_DISCONNECT ); ?>
					<?php submit_button( __( 'Desconectar', 'talkia-connect' ), 'delete' ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Cole aqui o token gerado no painel da Talkia (aba "Conectar WordPress") para sincronizar este site com o seu chatbot.', 'talkia-connect' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="talkia_connect_save_token">
					<?php wp_nonce_field( self::NONCE_CONNECT ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="talkia_token"><?php esc_html_e( 'Token', 'talkia-connect' ); ?></label></th>
							<td><input type="password" id="talkia_token" name="talkia_token" class="regular-text" autocomplete="off" required></td>
						</tr>
					</table>
					<?php submit_button( __( 'Conectar', 'talkia-connect' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
