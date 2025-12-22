<?php

/**
 * RESTRIÇÃO DE USUÁRIOS POR EQUIPE
 * 
 * Em um Acervo de Inventários, há uma granularidade maior de permissões de acesso do que a presente em
 * acervos digitais tradicionais do Tainacan. No Tainacan, se um usuário tem permissões para editar itens
 * em uma coleção, vai poder editar quaisquer itens dela. É possível restringir isso à "somente itens que
 * o usuário é autor" ou "somente itens não-públicos", mas este critério não atende ao conjunto de condições
 * esperadas pelo Inventário.
 * 
 * No inventário há o conceito de equipe responsável por cada projeto de inventário. Na impossibilidade de se
 * criar um perfil de usuário para cada inventário, optou-se por definir um metadado do tipo usuário dentro
 * da própria coleção de inventários. O metadado costuma ser privado e vai guardar a lista de usuários que
 * poderão editar não só o próprio inventário, como também os itens de outras coleções que estejam relacionados
 * ao inventário. 
 * 
 * Ou seja, para certos perfis de usuários (restrictive roles), o acesso será restrito por uma camada mais complexa.
 * A princípio um usuário pode até ter permissão para editar itens de uma coleção. Mas se esta coleção tem um
 * metadado de relacionamento com a coleção de inventários, o usuário só poderá editar o item se o mesmo estiver
 * relacionado com um item de inventário cuja equipe incluir ele próprio.
 */

namespace Tainacan_Inventarios;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class Restrict_Users_By_Team {

    use Singleton;

    private $team_metadatum_id_field = 'tainacan_inventarios_team_metadatum_id';
    private $restrictive_roles_field = 'tainacan_inventarios_restrictive_roles';

    protected function init() {

        // Lógica para adicionar a opção de metadado de equipe nas configurações do Tainacan
		add_action( 'admin_init', array( $this, 'settings_init' ) );

        // Lógica para adicionar a opção de restrição de acesso ao formulário de usuário
        add_action( 'tainacan-register-admin-hooks', array($this, 'register_admin_hooks') );
        
        // Lógica para salvar na entidade 'role' o campo extra com a opção de restrição de acesso para o perfil de usuário
        add_action( 'tainacan-api-role-prepare-for-response', array($this, 'set_restrictive_roles'), 10, 2 );

        // Lógica para restringir as permissões de edição para usuários a depender do item
        add_filter( 'user_has_cap', array($this, 'user_has_cap_filter'), 20, 4 );

        // Lógica para filtrar, inclusive na API os itens e coleções restringidos
        add_filter( 'tainacan-fetch-args', array($this, 'fetch_args'), 10, 2 );
    }

    /**
	 * Função que uso da action 'admin_init' para registrar uma nova 'option' do Tainacan,
	 * que o ID do metadado de Equipe. A função 'create_tainacan_settings' usada é 
     * responsável por montar o selectbox e registrar a option que é um wrapper na
	 * api de options do WordPress. As opções passam a estar disponíveis no menu "Tainacan" 
	 * -> "Outros" -> "Configurações" -> "Tainacan Inventários" -> "Metadado de Equipe"
	 */
    public function settings_init() {

        $user_metadata = [];
        $inventario_collection_id = Inventario_Post_Type::get_instance()->get_inventarios_collection_id();

        if ( $inventario_collection_id ) {
            $user_metadata = \tainacan_metadata()->fetch_by_collection(
                \tainacan_get_collection( array( 'collection_id' => $inventario_collection_id) ),
                [
                    'posts_per_page' => -1,
                    'meta_query' => [
                        [
                            'key'   => 'metadata_type',
                            'value' => 'Tainacan\Metadata_Types\User'
                        ]
                    ]
                ], 'OBJECT'
            );
        }
        $metadata_options = '';

        $metadata_options .= '<option value="">' . __( 'Selecione um metadado...', 'tainacan-inventarios' ) . '</option>';

        foreach( $user_metadata as $metadatum ) {
            $metadata_options .= '<option value="' . esc_attr( $metadatum->get_id() ) . '">' . esc_html( $metadatum->get_name() ) . '</option>';
        }

		\Tainacan\Settings::get_instance()->create_tainacan_setting( array(
			'id' => $this->team_metadatum_id_field,
			'title' => __( 'Metadado de Equipe', 'tainacan-inventarios' ),
			'section' => 'tainacan_settings_inventarios',
			'type' => 'string',
            'input_type' => 'select',
            'input_inner_html' => $metadata_options,
            'description' => __( 'Selecione o metadado que listará os integrantes da equipe de cada inventário. Isto impactará no acesso que alguns usuários terão aos itens de coleções relacionados ao inventário.', 'tainacan-inventarios' ),
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
		) );
    }

    /**
     * Método utilitário para acesso a option que guarda o ID do metadado da equipe
     */
    function get_team_metadatum_id() {
        return get_option('tainacan_option_' . $this->team_metadatum_id_field);
    }

    /**
     * Método utilitário para facilitar a chamada dos perfis que terão o acesso restrito com base
     * na equipe e relacionamentos
     */
    public function get_restrictive_roles() { 
        return get_option($this->restrictive_roles_field, []);
    }

    /**
     * Método para obter os ids dos itens da coleção de inventário que o usuário atual tem acesso
     * baseado no metadado de equipe.
     */
    public function get_current_user_allowed_inventarios_ids() {
        $restrictive_ids = array();

        $inventario_collection_id = Inventario_Post_Type::get_instance()->get_inventarios_collection_id();
        $team_metadatum_id = $this->get_team_metadatum_id();

        if ( !$inventario_collection_id || !$team_metadatum_id ) {
            return false;
        }

        $items = \tainacan_items()->fetch(
            array(
                'meta_query' => [
                    [
                        'key'   => $team_metadatum_id,
                        'value' => [ get_current_user_id() ],
                        'compare' => 'IN'
                    ]
                ],
                'status' => 'any'
            ),
            $inventario_collection_id,
            'OBJECT'
        );

        $restrictive_ids = array_map(function($item) { return $item->get_id(); }, $items);
        return $restrictive_ids;
    }

    /**
     * Método para obter os IDs de usuário que tem permissão para acessar um determinado
     * item, baseando-se na presença ou não deles no metadado de equipe do item de inventário
     * relacionado.
     */
    public function get_allowed_users_ids($item) {
        $inventario_collection_id = Inventario_Post_Type::get_instance()->get_inventarios_collection_id();
        $team_metadatum_id = $this->get_team_metadatum_id();

        if ( !$inventario_collection_id || !$team_metadatum_id ) {
            return false;
        }

        $team_users = [];
        $item_metadata = $item->get_metadata();

        foreach ($item_metadata as $item_metadatum) {
            $metadatum = $item_metadatum->get_metadatum();
            
            if ( $metadatum->get_metadata_type() == 'Tainacan\\Metadata_Types\\Relationship' ) {
                $options = $metadatum->get_metadata_type_options();

                if ( 
                    isset($options['collection_id']) &&
                    $options['collection_id'] == $inventario_collection_id
                ) {
                    $inventario_items_ids = $item_metadatum->get_value();
                    $inventario_items_ids = is_array($inventario_items_ids) ? $inventario_items_ids: [ $inventario_items_ids ];

                    foreach($inventario_items_ids as $id) {
                        $inventario_team_users = get_post_meta( $id, $team_metadatum_id );

                        if ( !$inventario_team_users ) continue;

                        $inventario_team_users = is_array($inventario_team_users) ? $inventario_team_users: [ $inventario_team_users ];
                        $team_users = array_merge($team_users, $inventario_team_users);
                    }
                }
            }
        }
        return $team_users;
    }

    /**
     * Usa do filtro 'user_has_cap' para filtrar as permissões que certo usuário terá,
     * baseando-se no que está sendo acessado ($entity_id)
     */
    public function user_has_cap_filter( $allcaps, $caps, $args, $user ) {
        $exist_roles = !empty(array_intersect($this->get_restrictive_roles(), $user->roles));
        
        if ( $exist_roles && is_array($args) && count($args) >= 3 ) {
            $entity_id = $args[2];

            if ( is_numeric( $entity_id ) ) {
                $current_item = \tainacan_items()->fetch( (int) $entity_id );
                
                // A restrição de acesso se aplica apenas a itens
                if ( $current_item instanceof \Tainacan\Entities\Item && $current_item->get_status() != 'auto-draft') {
                    $control_collections_ids = Control_Collections::get_instance()->get_control_collections_ids();
                    $current_collection_id = $current_item->get_collection_id();

                    // Obtém os IDs dos usuários permitidos para editar o item (aqueles que fazem parte da equipe)
                    $allowed_users_ids = $this->get_allowed_users_ids($current_item);
                    
                    // Se o item está em uma coleção de controle, não há restrição de acesso
                    if ( 
                        $allowed_users_ids === false ||
                        in_array($current_collection_id, $control_collections_ids)
                    ) {
                        return $allcaps;
                    }

                    // Se o usuário não está na lista de usuários permitidos, restringe o acesso
                    if ( !in_array($user->ID . '', $allowed_users_ids) ) {
                        $allcaps['read'] = false;
                        $allcaps["tnc_col_{$current_collection_id}_edit_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_edit_others_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_edit_published_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_read_private_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_publish_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_delete_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_delete_others_items"] = false;
                        $allcaps["tnc_col_{$current_collection_id}_delete_published_items"] = false;
                    }
                }
            }
        }
        
        return $allcaps;
    }

    /**
     * Usa do filtro 'tainacan-fetch-args' (indiretamente, chamado pela 'fetch_args') para filtrar os argumentos
     * para buscar itens na API REST do Tainacan com base nos papéis do usuário e nos metadados restritivos.
     */
    public function fetch_items_args($args, $user) {
        $exist_restrictive_roles = !empty(array_intersect($this->get_restrictive_roles(), $user->roles));
        $post_type = $args['post_type'];
        
        // Se o usuário tem um papel restritivo e o tipo de post é um item de coleção...
        if (
            $exist_restrictive_roles &&
            isset($post_type) &&
            count($post_type) == 1 && 
            \strpos($post_type[0], 'tnc_col_' ) === 0
        ) {
            $current_collection_id = preg_replace('/[a-z_]+(\d+)[a-z_]+?$/', '$1', $post_type[0] );
            $inventario_collection_id = Inventario_Post_Type::get_instance()->get_inventarios_collection_id();
            
            if ( !is_numeric($current_collection_id) || !$inventario_collection_id ) {
                return $args;
            }
            
            // Se estamos buscando itens da coleção do Inventário, restringimos pelo metadado da equipe
            if ( $current_collection_id == $inventario_collection_id ) {
                $team_metadatum_id = $this->get_team_metadatum_id();

                if ( $team_metadatum_id) {
                    if ( !isset($args['meta_query'] ) ) {
                        $args['meta_query'] = array();
                    }

                    $args['meta_query'][] = [
                        'key' => $team_metadatum_id,
                        'value' => [ $user->id ],
                        'compare' => 'IN'
                    ];
                }

            // Se estamos buscando itens de uma coleção que não é a do Inventário, mas que tem um 
            // relacionamento com a coleção do Inventário, restringimos pelo metadado de equipe
            // do item de inventário relacionado.
            } else {
                $current_collection = \tainacan_collections()->fetch($current_collection_id, 'OBJECT');
                $query_args =   array(
                    'meta_query' => [
                        [
                            'key'   => 'metadata_type',
                            'value' => 'Tainacan\\Metadata_Types\\Relationship'
                        ],
                        [
                            'key' => '_option_collection_id',
                            'value' => $inventario_collection_id,
                        ]
                    ]
                );
                
                $relationship_metadata = \tainacan_metadata()->fetch_by_collection($current_collection,
                  $query_args,
                    'OBJECT'
                );

                // Idealmente haverá apenas um metadado de relacionamento desta coleção com a
                // coleção do Inventário, mas vamos iterar...
                foreach ( $relationship_metadata as $relationship_metadatum ) {
                    $inventario_items_ids = $this->get_current_user_allowed_inventarios_ids();
                    
                    if ( $inventario_items_ids === false ) {
                        continue;
                    }

                    if ( !isset($args['meta_query'] ) ) {
                        $args['meta_query'] = array();
                    }

                    $args['meta_query'][] = [
                        'key' => $relationship_metadatum->get_id(),
                        'value' => empty($inventario_items_ids)? [-1] : $inventario_items_ids,
                        'compare' => 'IN'
                    ];
                }
            }
        }
        return $args;
    }

    /**
     * Usa do filtro 'tainacan-fetch-args' (indiretamente, chamado pela 'fetch_args') para filtrar os argumentos
     * para buscar coleções na API REST do Tainacan com base nos papéis do usuário e nos metadados restritivos.
     */
    public function fetch_collections_args($args, $user) {
        if ( $args['posts_per_page'] == -1 ) {
            return $args;
        }

        $exist_restrictive_roles = !empty(array_intersect($this->get_restrictive_roles(), $user->roles));

        if ( $exist_restrictive_roles ) {
            $inventario_collection_id = Inventario_Post_Type::get_instance()->get_inventarios_collection_id();

            if ( !$inventario_collection_id ) {
                return $args;
            }

            $relationship_metadata = \tainacan_metadata()->fetch(
                array(
                    'meta_query' => [
                        [
                            'key'   => 'metadata_type',
                            'value' => 'Tainacan\\Metadata_Types\\Relationship'
                        ],
                        [
                            'key' => '_option_collection_id',
                            'value' => $inventario_collection_id,
                        ]
                    ]
                ), 'OBJECT'
            );
            // Se não houver metadado de relacionamento, não há restrição de acesso.
            // Neste caso não passamos um array vazio porque o WP entende que deve retornar os itens mais recentes
            // mas não queremos isso. Ver https://core.trac.wordpress.org/ticket/28099.
            if ( empty($relationship_metadata) ) {
                $args['post__in'] = [-1];
            } else {
                $collections_ids = array_map( function($relationship_metadatum) { return $relationship_metadatum->get_collection_id(); }, $relationship_metadata );
                $args['post__in'] = $collections_ids;
            }
        }

        if ( !$user->has_cap('manage_tainacan') ) {
            $control_collections_ids = Control_Collections::get_instance()->get_control_collections_ids();
            $args['post__not_in'] = $control_collections_ids;
        }

        return $args;
    }

    /**
     * Usa do filtro 'tainacan-fetch-args' para filtrar os argumentos de busca da API REST do Tainacan.
     */
    public function fetch_args($args, $type) {
        $user = \wp_get_current_user();

        if ( $type == 'items' ) {
        
            $has_filter_posts_pre_query = has_filter( 'posts_pre_query', '__return_empty_array' );
            if ( $has_filter_posts_pre_query ) {
                remove_filter('posts_pre_query', '__return_empty_array');
            }

            $args = $this->fetch_items_args($args, $user);

            if ( $has_filter_posts_pre_query ) {
                add_filter('posts_pre_query', '__return_empty_array');
            }
        } elseif ( $type == 'collections' ) {
            $args = $this->fetch_collections_args($args, $user);
        }

        return $args;
    }

    /**
     * Usa da action 'tainacan-register-admin-hooks' para registrar uma nova área de formulários extra na 
     * página de edição dos perfis de usuário do Tainacan, onde ficará a nova opção relacionadas à restrição
     * de usuário baseando-se na equipe.
     */
    public function register_admin_hooks() {
        if ( function_exists( 'tainacan_register_admin_hook' ) ) {

            tainacan_register_admin_hook(
                'role',                 // Entity
                array( $this, 'form' ), // Form HTML Callback
                'end-right'             // Position
            );
        }
    }

    /**
     * Callback passada para a função `tainacan_register_admin_hook` com o formulário interno que será
     * passado para a página de edição de perfil de usuário, contendo o campo extra da configuração de
     * restrição de acesso.
     */
    public function form() {
        ob_start();
        ?>
            <div class="name-edition-box tainacan-set-role-to-restrict-access">
                <label for="is_restrictive"><?php _e('Restringir edição dos itens baseando-se no metadado de equipe', 'tainacan-inventarios'); ?></label>
                <select name="is_restrictive" id="set-user-to-restrict-access-select">
                    <option value="yes"><?php _e('Sim', 'tainacan-inventarios'); ?></option>
                    <option value="no"><?php _e('Não', 'tainacan-inventarios'); ?></option>
                </select>
                <p><span class="dashicons dashicons-info"></span>&nbsp;<?php _e('Com esta opção ativa, o usuário terá acesso restrito mesmo à coleções que pode editar. Se uma coleção tiver um metadado de relacionamento com a coleção de inventários, ele só poderá editar itens relacionados com inventários dos quais faça parte da equipe.', 'tainacan-inventarios'); ?></p>
            </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Usa da action 'tainacan-api-role-prepare-for-response' para de fato atualizar a option onde está
     * sendo guardado um array com opções extras para cada perfil de usuário criadas pelo plugin.
     */
    public function set_restrictive_roles($role, $request) {
        $slug = $role['slug'];
        $roles = get_option($this->restrictive_roles_field, []);

        if ( $request->get_method() != 'GET') {
            if ( isset($request['is_restrictive']) ) {
                if ($request['is_restrictive'] == 'yes') {
                    update_option($this->restrictive_roles_field, array_merge($roles, [ $slug ] ) );
                    $role['is_restrictive'] = 'yes';
                } else {
                    update_option($this->restrictive_roles_field, array_filter($roles, function($el) use ($slug) { return $el != $slug; } ) );
                    $role['is_restrictive'] = 'no';
                }
            }
        } else {
            $is_restrictive = in_array($slug, $roles);
            $role['is_restrictive'] = $is_restrictive ? 'yes' : 'no';
        }

        return $role;
    }
}
