<?php

/**
 * REDIRECIONAMENTO PARA O PAINEL DO TAINACAN
 *
 * Alguns perfis de um Acervo de Inventários não têm o painel do WordPress como página inicial.
 * Para esses perfis, o destino logo após o login na área administrativa é o painel do Tainacan
 * (admin.php?page=tainacan_dashboard).
 *
 * A lista de perfis fica numa option, no mesmo modelo da restrição por equipe: um campo extra
 * no formulário de papéis do Tainacan grava o slug do perfil nessa lista.
 */

namespace Tainacan_Inventarios;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class Redirect_To_Dashboard {

	use Singleton;

	private $dashboard_redirect_roles_field = 'tainacan_inventarios_dashboard_redirect_roles';

	protected function init() {

		// Campo extra no formulário de papéis do Tainacan.
		add_action( 'tainacan-register-admin-hooks', array( $this, 'register_admin_hooks' ) );

		// Persiste e devolve o campo extra na resposta da API de papéis.
		add_filter( 'tainacan-api-role-prepare-for-response', array( $this, 'set_dashboard_redirect_roles' ), 10, 2 );

		// Envia quem acabou de entrar no admin para o painel do Tainacan.
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 100, 3 );
	}

	/**
	 * Slugs dos perfis que devem cair no painel do Tainacan após o login.
	 *
	 * @return string[]
	 */
	public function get_dashboard_redirect_roles() {
		$roles = get_option( $this->dashboard_redirect_roles_field, array() );

		if ( ! is_array( $roles ) ) {
			return array();
		}

		return array_values( $roles );
	}

	/**
	 * URL do painel do Tainacan.
	 */
	public function get_dashboard_url() {
		return admin_url( 'admin.php?page=tainacan_dashboard' );
	}

	/**
	 * Registra o formulário extra na aba de opções do papel.
	 */
	public function register_admin_hooks() {
		if ( function_exists( 'tainacan_register_admin_hook' ) ) {
			tainacan_register_admin_hook(
				'role',
				array( $this, 'form' ),
				'end-right'
			);
		}
	}

	/**
	 * HTML do campo extra, no mesmo formato do campo de restrição por equipe.
	 * O nome `redirect_to_dashboard` é o que a API lê e o que o admin do Tainacan
	 * devolve para o select ao abrir o papel.
	 */
	public function form() {
		ob_start();
		?>
			<div class="name-edition-box tainacan-set-role-to-redirect-dashboard">
				<label for="redirect_to_dashboard"><?php esc_html_e( 'Redirecionar para o painel do Tainacan ao entrar no admin', 'tainacan-inventarios' ); ?></label>
				<select name="redirect_to_dashboard" id="set-role-to-redirect-dashboard-select">
					<option value="no"><?php esc_html_e( 'Não', 'tainacan-inventarios' ); ?></option>
					<option value="yes"><?php esc_html_e( 'Sim', 'tainacan-inventarios' ); ?></option>
				</select>
				<p><span class="dashicons dashicons-info"></span>&nbsp;<?php esc_html_e( 'Com esta opção ativa, usuários deste perfil que acabaram de entrar na área administrativa são redirecionados para o painel do Tainacan.', 'tainacan-inventarios' ); ?></p>
			</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Atualiza a option com os perfis marcados e inclui o valor na resposta da API,
	 * para o formulário de papéis preencher o select.
	 *
	 * @param array            $role
	 * @param \WP_REST_Request $request
	 * @return array
	 */
	public function set_dashboard_redirect_roles( $role, $request ) {
		$slug  = $role['slug'];
		$roles = $this->get_dashboard_redirect_roles();

		if ( $request->get_method() != 'GET' ) {
			if ( isset( $request['redirect_to_dashboard'] ) ) {
				if ( $request['redirect_to_dashboard'] == 'yes' ) {
					$roles[] = $slug;
					update_option( $this->dashboard_redirect_roles_field, array_values( array_unique( $roles ) ) );
					$role['redirect_to_dashboard'] = 'yes';
				} else {
					update_option(
						$this->dashboard_redirect_roles_field,
						array_values( array_filter( $roles, function( $el ) use ( $slug ) {
							return $el != $slug;
						} ) )
					);
					$role['redirect_to_dashboard'] = 'no';
				}
			}
		} else {
			$role['redirect_to_dashboard'] = in_array( $slug, $roles, true ) ? 'yes' : 'no';
		}

		return $role;
	}

	/**
	 * Troca o destino do login quando o usuário tem um perfil marcado e o WordPress
	 * o enviaria para a área administrativa.
	 *
	 * Um destino explícito fora do admin (por exemplo a página inicial do site) é mantido.
	 *
	 * @param string           $redirect_to
	 * @param string           $requested_redirect_to
	 * @param \WP_User|\WP_Error $user
	 * @return string
	 */
	public function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		if ( ! user_can( $user, 'read' ) ) {
			return $redirect_to;
		}

		$has_redirect_role = ! empty( array_intersect( $this->get_dashboard_redirect_roles(), (array) $user->roles ) );

		if ( ! $has_redirect_role || ! $this->is_admin_url( $redirect_to ) ) {
			return $redirect_to;
		}

		return $this->get_dashboard_url();
	}

	/**
	 * Indica se a URL de destino é a área administrativa deste site.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_admin_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return false;
		}

		return strpos( $url, admin_url() ) === 0;
	}
}
