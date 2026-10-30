<?php
// WordPress só inclui este arquivo dentro do fluxo real de "Apagar" plugin
// (nunca na desativação) e define essa constante antes — fora desse fluxo,
// bloqueia acesso direto.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A desinstalação roda sem o bootstrap normal do plugin (talkia-connect.php
// não é carregado aqui) — precisa requerer o que for usar.
require_once __DIR__ . '/includes/class-talkia-token-store.php';
require_once __DIR__ . '/includes/class-talkia-api-client.php';

if ( ! defined( 'TALKIA_CONNECT_API_BASE' ) ) {
	define( 'TALKIA_CONNECT_API_BASE', 'https://talkia.8bitlab.com.br' );
}

// Autorrevogação: nenhum concorrente pesquisado faz isso, mas evita deixar
// token órfão ativo depois que o cliente removeu o plugin (ver
// PLANO_PLUGIN_WORDPRESS.md). Best-effort — se a Talkia estiver fora do ar, a
// desinstalação continua mesmo assim, o cliente não pode ficar travado.
$raw_token = Talkia_Token_Store::get_token();
if ( $raw_token ) {
	Talkia_Api_Client::revoke( $raw_token );
}

// Única coisa que o plugin grava no banco do WordPress: essas duas options
// (token criptografado + cache do bot). Sem tabela própria, sem transient,
// sem cron agendado — nada mais a limpar.
Talkia_Token_Store::clear_token();
