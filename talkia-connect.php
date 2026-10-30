<?php
/**
 * Plugin Name: Talkia Connect
 * Plugin URI: https://github.com/shyodoS/plgstkia
 * Description: Sincroniza este site com o chatbot que você customizou na Talkia (talkia.8bitlab.com.br) — sem copiar/colar código, sem editar tema.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: 8Bit Lab
 * Author URI: https://8bitlab.com.br
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: talkia-connect
 */

// Bloqueia acesso direto ao arquivo (fora do carregamento normal do WordPress).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TALKIA_CONNECT_VERSION', '0.1.0' );
define( 'TALKIA_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TALKIA_CONNECT_FILE', __FILE__ );
// Fixo de propósito: é o domínio de produção da Talkia, não algo que o
// cliente final configura (ver PLANO_PLUGIN_WORDPRESS.md, repo talkia-web).
if ( ! defined( 'TALKIA_CONNECT_API_BASE' ) ) {
	define( 'TALKIA_CONNECT_API_BASE', 'https://talkia.8bitlab.com.br' );
}

require_once TALKIA_CONNECT_DIR . 'includes/class-talkia-token-store.php';
require_once TALKIA_CONNECT_DIR . 'includes/class-talkia-api-client.php';
require_once TALKIA_CONNECT_DIR . 'includes/class-talkia-settings.php';
require_once TALKIA_CONNECT_DIR . 'includes/class-talkia-widget.php';

Talkia_Settings::init();
Talkia_Widget::init();

// Repo público no GitHub: sem token de autenticação embutido no plugin (ver
// PLANO_PLUGIN_WORDPRESS.md — decisão de tornar o repo público justamente pra
// evitar isso). Cada "Release" novo no GitHub já aparece como atualização
// disponível pra quem tiver o plugin instalado.
require_once TALKIA_CONNECT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/shyodoS/plgstkia/',
	__FILE__,
	'talkia-connect'
);
