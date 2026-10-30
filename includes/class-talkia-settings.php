<?php
// Tela dentro do wp-admin: conectar/desconectar, ajustar persona+balão do bot,
// e ver conversas/consumo — tudo via admin-post.php (não Settings API: o
// valor salvo não é exatamente o que o formulário manda, é o resultado já
// validado/sanitizado que volta da Talkia).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Talkia_Settings {

	const PAGE_SLUG        = 'talkia-connect';
	const NONCE_CONNECT    = 'talkia_connect_save_token';
	const NONCE_DISCONNECT = 'talkia_connect_disconnect';
	const NONCE_UPDATE_BOT = 'talkia_connect_update_bot';

	const BUBBLE_INTERVALS = array( 0, 5, 10, 15 );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_talkia_connect_save_token', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_talkia_connect_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_talkia_connect_update_bot', array( __CLASS__, 'handle_update_bot' ) );
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

	public static function handle_update_bot() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'talkia-connect' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_UPDATE_BOT );

		$raw_token = Talkia_Token_Store::get_token();
		if ( ! $raw_token ) {
			self::redirect_with_notice( 'invalid', 'settings' );
		}

		$updates = array(
			'persona' => array(
				'name'    => isset( $_POST['persona_name'] ) ? sanitize_text_field( wp_unslash( $_POST['persona_name'] ) ) : '',
				'tagline' => isset( $_POST['persona_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['persona_tagline'] ) ) : '',
			),
			'bubble'  => array(
				'emoji'    => isset( $_POST['bubble_emoji'] ) ? sanitize_text_field( wp_unslash( $_POST['bubble_emoji'] ) ) : '',
				'bg'       => isset( $_POST['bubble_bg'] ) ? sanitize_hex_color( wp_unslash( $_POST['bubble_bg'] ) ) : '',
				'greeting' => isset( $_POST['bubble_greeting'] ) ? sanitize_text_field( wp_unslash( $_POST['bubble_greeting'] ) ) : '',
				'interval' => isset( $_POST['bubble_interval'] ) ? (int) $_POST['bubble_interval'] : 0,
			),
		);
		// "mode" só entra na atualização se o formulário realmente mandou um
		// valor — a opção "Imagem" vem desabilitada no <select> quando é o
		// modo atual (navegador não envia campo desabilitado), então nesse
		// caso o PATCH simplesmente não toca no modo, preservando a imagem
		// configurada no painel principal da Talkia.
		if ( ! empty( $_POST['bubble_mode'] ) ) {
			$updates['bubble']['mode'] = sanitize_key( wp_unslash( $_POST['bubble_mode'] ) );
		}

		$result = Talkia_Api_Client::update_bot( $raw_token, $updates );
		if ( empty( $result['ok'] ) ) {
			self::redirect_with_notice( 'update_failed', 'settings' );
		}

		Talkia_Token_Store::save_bot_cache( $result );
		self::redirect_with_notice( 'updated', 'settings' );
	}

	private static function redirect_with_notice( $notice, $tab = '' ) {
		$args = array(
			'page'          => self::PAGE_SLUG,
			'talkia_notice' => $notice,
		);
		if ( $tab ) {
			$args['tab'] = $tab;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connected = Talkia_Token_Store::is_connected();
		$notice    = isset( $_GET['talkia_notice'] ) ? sanitize_key( wp_unslash( $_GET['talkia_notice'] ) ) : '';
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		echo '<div class="wrap"><h1>Talkia</h1>';
		self::render_notice( $notice );

		if ( ! $connected ) {
			self::render_connect_form();
			echo '</div>';
			return;
		}

		self::render_tabs( $tab );
		if ( 'settings' === $tab ) {
			self::render_settings_tab();
		} elseif ( 'conversations' === $tab ) {
			self::render_conversations_tab();
		} else {
			self::render_overview_tab();
		}
		echo '</div>';
	}

	private static function render_notice( $notice ) {
		$messages = array(
			'connected'      => array( 'success', 'Conectado com sucesso!' ),
			'invalid'        => array( 'error', 'Token inválido ou revogado. Gere um novo token no painel da Talkia (aba "Conectar WordPress").' ),
			'empty'          => array( 'error', 'Cole o token antes de salvar.' ),
			'disconnected'   => array( 'success', 'Desconectado.' ),
			'updated'        => array( 'success', 'Configurações atualizadas.' ),
			'update_failed'  => array( 'error', 'Não foi possível salvar agora. Tente de novo em instantes.' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $text ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	private static function render_connect_form() {
		?>
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
		<?php
	}

	private static function render_tabs( $current ) {
		$tabs = array(
			'overview'      => 'Visão geral',
			'settings'      => 'Configurações',
			'conversations' => 'Conversas e consumo',
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) );
			$class = 'nav-tab' . ( $current === $slug || ( 'overview' === $slug && ! in_array( $current, array_keys( $tabs ), true ) ) ? ' nav-tab-active' : '' );
			printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
		}
		echo '</h2>';
	}

	private static function render_overview_tab() {
		$bot = Talkia_Token_Store::get_bot_cache();
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Conectado com o bot:', 'talkia-connect' ); ?></strong>
				<?php echo esc_html( $bot && isset( $bot['persona']['name'] ) ? $bot['persona']['name'] : '' ); ?>
			</p>
			<?php if ( ! $bot || empty( $bot['is_paid'] ) ) : ?>
				<p><em><?php esc_html_e( 'Atenção: este chatbot ainda não está marcado como pago na Talkia — o widget não aparece no site até isso ser confirmado.', 'talkia-connect' ); ?></em></p>
			<?php endif; ?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="talkia_connect_disconnect">
			<?php wp_nonce_field( self::NONCE_DISCONNECT ); ?>
			<?php submit_button( __( 'Desconectar', 'talkia-connect' ), 'delete' ); ?>
		</form>
		<?php
	}

	private static function render_settings_tab() {
		$bot     = Talkia_Token_Store::get_bot_cache();
		$persona = $bot && isset( $bot['persona'] ) ? $bot['persona'] : array();
		$bubble  = $bot && isset( $bot['bubble'] ) ? $bot['bubble'] : array();
		$mode    = isset( $bubble['mode'] ) ? $bubble['mode'] : 'default';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="talkia_connect_update_bot">
			<?php wp_nonce_field( self::NONCE_UPDATE_BOT ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="persona_name"><?php esc_html_e( 'Nome do chatbot', 'talkia-connect' ); ?></label></th>
					<td><input type="text" id="persona_name" name="persona_name" class="regular-text" maxlength="120" value="<?php echo esc_attr( isset( $persona['name'] ) ? $persona['name'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="persona_tagline"><?php esc_html_e( 'Subtítulo', 'talkia-connect' ); ?></label></th>
					<td><input type="text" id="persona_tagline" name="persona_tagline" class="regular-text" maxlength="200" value="<?php echo esc_attr( isset( $persona['tagline'] ) ? $persona['tagline'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bubble_mode"><?php esc_html_e( 'Ícone do balão', 'talkia-connect' ); ?></label></th>
					<td>
						<select id="bubble_mode" name="bubble_mode">
							<option value="default" <?php selected( $mode, 'default' ); ?>><?php esc_html_e( 'Padrão (💬)', 'talkia-connect' ); ?></option>
							<option value="emoji" <?php selected( $mode, 'emoji' ); ?>><?php esc_html_e( 'Emoji', 'talkia-connect' ); ?></option>
							<?php if ( 'image' === $mode ) : ?>
								<option value="image" selected disabled><?php esc_html_e( 'Imagem (gerenciada no painel da Talkia)', 'talkia-connect' ); ?></option>
							<?php endif; ?>
						</select>
						<?php if ( 'image' === $mode ) : ?>
							<p class="description"><?php esc_html_e( 'O ícone de imagem só pode ser trocado no painel da Talkia — escolher outra opção aqui troca para esse modo.', 'talkia-connect' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bubble_emoji"><?php esc_html_e( 'Emoji do balão', 'talkia-connect' ); ?></label></th>
					<td><input type="text" id="bubble_emoji" name="bubble_emoji" maxlength="8" style="width:5em" value="<?php echo esc_attr( isset( $bubble['emoji'] ) ? $bubble['emoji'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bubble_bg"><?php esc_html_e( 'Cor do balão', 'talkia-connect' ); ?></label></th>
					<td><input type="color" id="bubble_bg" name="bubble_bg" value="<?php echo esc_attr( isset( $bubble['bg'] ) && $bubble['bg'] ? $bubble['bg'] : '#ea532e' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bubble_greeting"><?php esc_html_e( 'Mensagem ao passar o mouse', 'talkia-connect' ); ?></label></th>
					<td><input type="text" id="bubble_greeting" name="bubble_greeting" class="regular-text" maxlength="80" value="<?php echo esc_attr( isset( $bubble['greeting'] ) ? $bubble['greeting'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bubble_interval"><?php esc_html_e( 'Repetir mensagem a cada', 'talkia-connect' ); ?></label></th>
					<td>
						<select id="bubble_interval" name="bubble_interval">
							<?php foreach ( self::BUBBLE_INTERVALS as $minutes ) : ?>
								<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( isset( $bubble['interval'] ) ? (int) $bubble['interval'] : 0, $minutes ); ?>>
									<?php echo 0 === $minutes ? esc_html__( 'Desativado', 'talkia-connect' ) : esc_html( $minutes . ' minutos' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Salvar', 'talkia-connect' ) ); ?>
		</form>
		<?php
	}

	private static function render_conversations_tab() {
		$raw_token = Talkia_Token_Store::get_token();
		$usage     = Talkia_Api_Client::get_usage( $raw_token );
		$convs     = Talkia_Api_Client::get_conversations( $raw_token );

		if ( empty( $usage['ok'] ) || empty( $convs['ok'] ) ) {
			echo '<p>' . esc_html__( 'Não foi possível consultar as conversas agora. Tente de novo em instantes.', 'talkia-connect' ) . '</p>';
			return;
		}

		printf(
			'<p><strong>%s</strong> %d &middot; <strong>%s</strong> %d</p>',
			esc_html__( 'Mensagens totais:', 'talkia-connect' ),
			(int) $usage['message_count'],
			esc_html__( 'Tokens consumidos:', 'talkia-connect' ),
			(int) ( $convs['total_tokens'] ?? 0 )
		);

		if ( empty( $convs['conversations'] ) ) {
			echo '<p>' . esc_html__( 'Nenhuma conversa ainda.', 'talkia-connect' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Última mensagem', 'talkia-connect' ); ?></th>
					<th><?php esc_html_e( 'Mensagens', 'talkia-connect' ); ?></th>
					<th><?php esc_html_e( 'Tokens', 'talkia-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $convs['conversations'] as $conv ) : ?>
					<tr>
						<td><?php echo esc_html( self::format_datetime( $conv['last_at'] ?? '' ) ); ?></td>
						<td><?php echo (int) ( $conv['message_count'] ?? 0 ); ?></td>
						<td><?php echo (int) ( $conv['tokens'] ?? 0 ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function format_datetime( $iso ) {
		if ( ! $iso ) {
			return '—';
		}
		$timestamp = strtotime( $iso );
		return $timestamp ? date_i18n( 'd/m/Y H:i', $timestamp ) : $iso;
	}
}
