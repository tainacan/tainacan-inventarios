<?php

/**
 * PÁGINAS E COLEÇÃO DE INVENTÁRIO
 * 
 * Em um Acervo de Inventários, os Inventários são a entidade mais importante, ao redor dos quais se
 * definem e constituem uma série de dados estruturados (coleções relacionadas) e não estruturados
 * (páginas de apresentação).
 * 
 * Nestas classe está implementada a lógica para configuração de qual coleção será "A coleção de 
 * inventário", ou seja, aquela que guardará os dados estruturados relacionados a cada inventário 
 * (seus itens). Também está implementada a lógica para criação de um Post Type 'inventarios'. Este
 * tipo de post permite que sejam criadas páginas usando editor de blocos do WordPress com 
 * flexibilidade para se customizar aparência de um conteúdo editorial mais livre. Um padrão de
 * blocos pode ser definido como layout inicial. Sem padrão, a página nasce em branco. A página
 * e o item são independentes. O vínculo entre eles só serve para exibir a página no lugar do
 * conteúdo do item. Um botão no item cria essa página já preenchida e vinculada, sem depender
 * do salvamento do item.
 */

namespace Tainacan_Inventarios;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class Inventario_Post_Type {

    use Singleton;

    private $inventario_post_id_field = 'tainacan-inventarios-inventario-post-id';
    private $inventarios_collection_id_field = 'tainacan_inventarios_collection_id';
    private $inventarios_default_pattern_id_field = 'tainacan_inventarios_default_pattern_id';

    protected function init() {

        // Lógica para adicionar a opção de coleção de inventários nas configurações do Tainacan
		add_action( 'admin_init', array( $this, 'settings_init' ) );

        // Lógica para registrar o tipo de post "inventários"
        add_action( 'init', array( $this, 'register_post_type' ) );

        // Lógica para adicionar a opção extra no formulário de item que vinculará o item a um inventário
        add_action( 'tainacan-register-admin-hooks', array( $this, 'register_hook' ) );
		add_action( 'tainacan-insert-tainacan-item', array( $this, 'save_data' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_item_form_script' ) );
		add_action( 'wp_ajax_tainacan_inventarios_create_post', array( $this, 'ajax_create_post' ) );
		add_filter( 'tainacan-api-response-item-meta', array( $this, 'add_meta_to_response' ), 10, 2 );

        // Lógica para redirecionar o link do item para a página de inventário correspondente
        add_filter( 'the_content', array($this, 'custom_the_content'), 20, 1 );
    }

    /**
	 * Função que usa da action 'admin_init' para registrar uma nova 'option' do Tainacan,
	 * que é o ID da coleções de Inventários. A função 'create_tainacan_settings' usada
	 * é responsável por montar o selectbox e registrar a option que é um wrapper na
	 * api de options do WordPress. As opções passam a estar disponíveis no menu "Tainacan" 
	 * -> "Outros" -> "Configurações" -> "Tainacan Inventários" -> "Coleção de Inventário"
	 */
    public function settings_init() {

        // Lógica para registrar a seção de configurações do plugin de inventários
		add_settings_section(
			'tainacan_settings_inventarios', // ID
			__( 'Tainacan Inventários', 'tainacan-inventarios' ), // Title
			array( $this, 'inventarios_section_description' ), // Callback
			'tainacan_settings'               		    // Page
		);
		
        // Lógica para montar a lista de coleções de Inventários
        $collections = \tainacan_collections()->fetch(array(), 'OBJECT');
        $collections_options = '';

        $collections_options .= '<option value="">' . __( 'Selecione uma coleção...', 'tainacan-inventarios' ) . '</option>';

        foreach( $collections as $collection ) {
            if ( !in_array( $collection->get_id(), Control_Collections::get_instance()->get_control_collections_ids() ) )
                $collections_options .= '<option value="' . esc_attr( $collection->get_id() ) . '">' . esc_html( $collection->get_name() ) . '</option>';
        }

		\Tainacan\Settings::get_instance()->create_tainacan_setting( array(
			'id' => $this->inventarios_collection_id_field,
			'title' => __( 'Coleção de Inventários', 'tainacan-inventarios' ),
			'section' => 'tainacan_settings_inventarios',
			'type' => 'string',
            'input_type' => 'select',
            'input_inner_html' => $collections_options,
            'description' => __( 'Selecione a coleção cujos itens serão inventários. Isto permitirá vincular seus itens com páginas do tipo inventário.', 'tainacan-inventarios' ),
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
		) );

        // Lógica para montar a lista de padrões de blocos
        $patterns = get_posts(
            array(
                'post_type'              => 'wp_block',
                'post_status'            => 'publish',
                'posts_per_page'         => -1,
                'orderby'                => 'title',
                'order'                  => 'ASC',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $patterns_options = '<option value="">' . esc_html__( 'Nenhum', 'tainacan-inventarios' ) . '</option>';

        foreach ( $patterns as $pattern ) {
            $patterns_options .= '<option value="' . esc_attr( $pattern->ID ) . '">' . esc_html( $pattern->post_title ) . '</option>';
        }

        \Tainacan\Settings::get_instance()->create_tainacan_setting( array(
            'id' => $this->inventarios_default_pattern_id_field,
            'title' => __( 'Padrão de blocos para novos inventários', 'tainacan-inventarios' ),
            'section' => 'tainacan_settings_inventarios',
            'type' => 'string',
            'input_type' => 'select',
            'input_inner_html' => $patterns_options,
            'description' => __( 'Selecione um padrão de blocos criado em Aparência → Editor → Padrões. Ele será o layout inicial de um novo inventário e o conteúdo copiado pelo botão no item. Sem padrão, a página nasce em branco.', 'tainacan-inventarios' ),
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ) );

    }

    /**
     * Usa da action 'init' para registrar o tipo de post dedicado às páginas de inventários.
     */
    public function register_post_type() {
        $args = array(
            'labels'             => array(
                'name'                  => _x('Inventários', 'Post type general name', 'tainacan-inventarios'),
                'singular_name'         => _x('Inventário', 'Post type singular name', 'tainacan-inventarios'),
                'menu_name'             => _x('Inventários', 'Admin Menu text', 'tainacan-inventarios'),
                'name_admin_bar'        => _x('Inventário', 'Add New on Toolbar', 'tainacan-inventarios'),
                'add_new'               => __('Adicionar Novo', 'tainacan-inventarios'),
                'add_new_item'          => __('Adicionar Novo Inventário', 'tainacan-inventarios'),
                'new_item'              => __('Novo Inventário', 'tainacan-inventarios'),
                'edit_item'             => __('Editar Inventário', 'tainacan-inventarios'),
                'view_item'             => __('Ver Inventário', 'tainacan-inventarios'),
                'all_items'             => __('Todos os Inventários', 'tainacan-inventarios'),
                'search_items'          => __('Pesquisar Inventários', 'tainacan-inventarios'),
                'parent_item_colon'     => __('Inventários pais:', 'tainacan-inventarios'),
                'not_found'             => __('Nenhum Inventário encontrado.', 'tainacan-inventarios'),
                'not_found_in_trash'    => __('Nenhum Inventário encontrado na lixeira.', 'tainacan-inventarios'),
                'featured_image'        => _x('Imagem de capa do Inventário', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'tainacan-inventarios'),
                'set_featured_image'    => _x('Configurar imagem de capa', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'tainacan-inventarios'),
                'remove_featured_image' => _x('Remover imagem de capa', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'tainacan-inventarios'),
                'use_featured_image'    => _x('Usar como imagem de capa', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'tainacan-inventarios'),
                'archives'              => _x('Lista de Inventários', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'tainacan-inventarios'),
                'insert_into_item'      => _x('Inserir no Inventário', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'tainacan-inventarios'),
                'uploaded_to_this_item' => _x('Enviado para este Inventário', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'tainacan-inventarios'),
                'filter_items_list'     => _x('Filtrar lista de Inventários', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'tainacan-inventarios'),
                'items_list_navigation' => _x('Navegação da lista de inventários', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'tainacan-inventarios'),
                'items_list'            => _x('Lista de Inventários', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'tainacan-inventarios'),
            ),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'inventarios'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'show_in_rest'       => true,
            'menu_position'      => null,
            'supports'           => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'custom-fields'),
            'template'           => $this->get_inventario_template(),
        );
        register_post_type('inventarios', $args);
    }

    /**
     * Breve descrição sobre a seção de opções registrada pelo plugin de inventários.
     * Esta descrição aparecerá no menu "Tainacan" -> "Outros" -> "Configurações" ->
     * "Tainacan Inventários"
     */
    public function inventarios_section_description() {
	?>
		<p class="settings-section-description">
			<?php echo _e('Opções do plugin "Tainacan Inventários"', 'tainacan-inventarios');?>
		</p>
	<?php
	}

    /**
     * Usa da action 'tainacan-register-admin-hooks' para registrar uma nova área de formulários
     * extra na página de edição do item Tainacan, onde ficará a opção relacionada à página de
     * inventário
     */
    function register_hook() {
		if ( function_exists( 'tainacan_register_admin_hook' ) ) {

			tainacan_register_admin_hook(
                'item',                 // Entity
                array( $this, 'form' ), // Form HTML Callback
                'begin-left',           // Position
                array( 'collectionId' => '' . $this->get_inventarios_collection_id() )  // Conditional
            );
		}
	}

    /**
     * Grava apenas o vínculo escolhido no seletor. A criação da página acontece pelo botão, via AJAX.
     */
    function save_data( $object ) {
        if ( ! is_object( $object ) || ! method_exists( $object, 'can_edit' ) || ! $object->can_edit() ) {
			return;
		}

        if ( ! function_exists( 'tainacan_get_api_postdata' ) ) {
            return;
        }

        $post = tainacan_get_api_postdata();

        if ( ! isset( $post->{$this->inventario_post_id_field} ) ) {
            return;
        }

        $item_id = $object->get_id();
        $submitted_link = absint( $post->{$this->inventario_post_id_field} );

        if ( $this->is_live_inventario_post( $submitted_link ) ) {
            $this->assign_inventario_post( $item_id, $submitted_link );
            return;
        }

        if ( ! $submitted_link ) {
            $this->clear_inventario_link( $item_id );
        }
    }

    /**
     * Carrega o script do botão que cria a página de inventário na tela do Tainacan.
     */
    function enqueue_item_form_script( $hook ) {
        if ( false === strpos( (string) $hook, 'tainacan' ) ) {
            return;
        }

        wp_enqueue_script(
            'tainacan-inventarios-item-form',
            plugins_url( 'assets/js/inventario-item-form.js', dirname( __DIR__ ) . '/functions.php' ),
            array(),
            defined( 'TAINACAN_INVENTARIOS_VERSION' ) ? TAINACAN_INVENTARIOS_VERSION : '0.2.1',
            true
        );

        wp_localize_script(
            'tainacan-inventarios-item-form',
            'tainacanInventariosItemForm',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'tainacan_inventarios_create_post' ),
                'action'    => 'tainacan_inventarios_create_post',
                'fieldName' => $this->inventario_post_id_field,
                'messages'  => array(
                    'saveItemFirst' => __( 'Salve o item antes de criar a página de inventário.', 'tainacan-inventarios' ),
                    'creating'      => __( 'Criando página…', 'tainacan-inventarios' ),
                    'created'       => __( 'Página criada e vinculada a este item.', 'tainacan-inventarios' ),
                    'alreadyLinked' => __( 'Este item já possui uma página de inventário vinculada.', 'tainacan-inventarios' ),
                    'editPage'      => __( 'Editar a página de inventário', 'tainacan-inventarios' ),
                    'failed'        => __( 'Não foi possível criar a página de inventário.', 'tainacan-inventarios' ),
                ),
            )
        );
    }

    /**
     * Cria a página de inventário a partir do item atual, sem passar pelo salvamento do item.
     */
    function ajax_create_post() {
        check_ajax_referer( 'tainacan_inventarios_create_post', 'nonce' );

        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

        if ( ! $item_id || ! function_exists( 'tainacan_items' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Salve o item antes de criar a página de inventário.', 'tainacan-inventarios' ) ),
                400
            );
        }

        $item = tainacan_items()->fetch( $item_id );

        if ( ! is_object( $item ) || ! method_exists( $item, 'can_edit' ) || ! $item->can_edit() ) {
            wp_send_json_error(
                array( 'message' => __( 'Você não pode editar este item.', 'tainacan-inventarios' ) ),
                403
            );
        }

        if ( ! $this->is_inventarios_collection_item( $item ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Este item não pertence à coleção de inventários.', 'tainacan-inventarios' ) ),
                400
            );
        }

        $linked_post_id = absint( get_post_meta( $item_id, $this->inventario_post_id_field, true ) );

        if ( $this->is_live_inventario_post( $linked_post_id ) ) {
            wp_send_json_success(
                array(
                    'postId'  => $linked_post_id,
                    'title'   => get_the_title( $linked_post_id ),
                    'editUrl' => get_edit_post_link( $linked_post_id, 'raw' ),
                    'created' => false,
                )
            );
        }

        if ( ! $this->user_can_create_inventario_post() ) {
            wp_send_json_error(
                array( 'message' => __( 'Você não pode criar páginas de inventário.', 'tainacan-inventarios' ) ),
                403
            );
        }

        $post_id = $this->create_inventario_post_for_item( $item );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            wp_send_json_error(
                array( 'message' => __( 'Não foi possível criar a página de inventário.', 'tainacan-inventarios' ) ),
                500
            );
        }

        wp_send_json_success(
            array(
                'postId'  => $post_id,
                'title'   => get_the_title( $post_id ),
                'editUrl' => get_edit_post_link( $post_id, 'raw' ),
                'created' => true,
            )
        );
    }

    /**
     * O tipo inventarios usa as capacidades de post, então criar uma página exige edit_posts.
     */
    private function user_can_create_inventario_post() {
        $post_type = get_post_type_object( 'inventarios' );

        if ( ! $post_type || empty( $post_type->cap->create_posts ) ) {
            return false;
        }

        return current_user_can( $post_type->cap->create_posts );
    }

    /**
     * Aponta o item para uma página de inventário já existente, sem alterar o conteúdo dela.
     */
    private function assign_inventario_post( $item_id, $post_id ) {
        update_post_meta( $item_id, $this->inventario_post_id_field, $post_id );
    }

    /**
     * Remove o vínculo usado no redirecionamento do item. A página de inventário permanece.
     */
    private function clear_inventario_link( $item_id ) {
        delete_post_meta( $item_id, $this->inventario_post_id_field );
    }

    /**
     * Uma página vinculada conta como existente enquanto não estiver na lixeira ou excluída.
     */
    private function is_live_inventario_post( $post_id ) {
        $post_id = absint( $post_id );

        if ( ! $post_id ) {
            return false;
        }

        $post = get_post( $post_id );

        if ( ! $post || 'inventarios' !== $post->post_type ) {
            return false;
        }

        return in_array( $post->post_status, array( 'publish', 'private', 'draft', 'pending', 'future' ), true );
    }

    /**
     * Cria a página de inventário vinculada ao item e grava o itemId nos blocos Tainacan.
     * A página nasce como rascunho; a publicação fica com o fluxo normal do WordPress.
     */
    private function create_inventario_post_for_item( $item ) {
        $item_id = $item->get_id();
        $title = method_exists( $item, 'get_title' ) ? $item->get_title() : '';

        if ( '' === trim( (string) $title ) || 'Auto Draft' === $title ) {
            $title = __( 'Inventário', 'tainacan-inventarios' );
        }

        $author_id = method_exists( $item, 'get_author_id' ) ? absint( $item->get_author_id() ) : get_current_user_id();

        $post_id = wp_insert_post(
            array(
                'post_type'    => 'inventarios',
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_content' => $this->get_inventario_post_content_for_item( $item_id ),
                'post_author'  => $author_id ? $author_id : get_current_user_id(),
            ),
            true
        );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return is_wp_error( $post_id ) ? $post_id : new \WP_Error( 'tainacan_inventarios_create_post', __( 'Não foi possível criar a página de inventário.', 'tainacan-inventarios' ) );
        }

        update_post_meta( $item_id, $this->inventario_post_id_field, $post_id );

        return $post_id;
    }

    /**
     * Indica se o item pertence à coleção configurada como coleção de inventários.
     */
    private function is_inventarios_collection_item( $item ) {
        $collection_id = absint( $this->get_inventarios_collection_id() );

        if ( ! $collection_id || ! is_object( $item ) || ! method_exists( $item, 'get_collection_id' ) ) {
            return false;
        }

        return absint( $item->get_collection_id() ) === $collection_id;
    }

    /**
     * Usa do filtro 'tainacan-api-response-item-meta' para fazer com que o campo com ID da página
     * de inventário salvo no post meta 'tainacan-inventarios-inventario-post-id' apareça no
     * retorno da API quando usamos o endpoint de itens.
     */
    function add_meta_to_response( $extra_meta, $request ) {
		$extra_meta = array_merge( $extra_meta, array($this->inventario_post_id_field) );
		return $extra_meta;
	}

    /**
     * Callback passada para a função `tainacan_register_admin_hook` com o formulário interno que será
     * passado para a página de edição de item, contendo o campo extra da configuração de página de 
     * inventário.
     */
    function form() {
		if ( !function_exists( 'tainacan_get_api_postdata' ) )
			return '';
        
        $inventario_posts = get_posts( array(
            'post_type'      => 'inventarios',
            'posts_per_page' => -1,
            'nopaging'       => true
        ) );
   

		ob_start();
		?>
		<div class="tainacan-inventarios-extra-fields"> 
            <h4><?php _e( 'Inventário', 'tainacan-inventarios'); ?></h4>
            <div class="field">
                <label class="label"><?php _e( 'Página de Inventário', 'tainacan-inventarios' ); ?></label>
                <div class="control">
                    <span class="select is-fullwidth">
                        <select name="<?php echo $this->inventario_post_id_field; ?>">
                            <option value=""><?php _e( 'Selecione uma página de Inventário', 'tainacan-inventarios' ); ?></option>
                            <?php foreach ( $inventario_posts as $post ) : ?>   
                                <option value="<?php echo esc_attr( $post->ID ); ?>">
                                    <?php echo esc_html( $post->post_title ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                </div>
                <p class="help">
                    <?php _e( 'A página de inventário é independente do item. Escolha uma página existente para exibi-la no lugar do conteúdo deste item. A opção vazia remove só o vínculo.', 'tainacan-inventarios'); ?>
                </p>
            </div>
            <?php if ( $this->user_can_create_inventario_post() ) : ?>
            <div class="field">
                <button type="button" class="button" id="tainacan-inventarios-create-post">
                    <?php _e( 'Criar página de inventário a partir deste item', 'tainacan-inventarios' ); ?>
                </button>
                <p class="help" id="tainacan-inventarios-create-post-feedback" hidden></p>
                <p class="help">
                    <?php _e( 'Cria agora uma página, copia o padrão de blocos selecionado e preenche os blocos Tainacan com este item. Sem padrão, a página nasce em branco. Se já houver uma página vinculada, ela não é alterada. Se essa página for excluída, use o botão de novo.', 'tainacan-inventarios'); ?>
                </p>
            </div>
            <?php endif; ?>
		</div>
		<?php
        
		return ob_get_clean();
	}
 
    /**
     * Usa do filtro 'the_content' para substituir o "miolo" das página de Itens da coleção de inventários
     * pelo conteúdo gerado na página configurada como "Página do Inventário".
     */
    function custom_the_content ($content) {
        if (!is_singular()) return $content;

        global $post;

        $redirect_id = get_post_meta($post->ID, $this->inventario_post_id_field, true);
        $redirect_id = absint($redirect_id);

        if ( !$redirect_id || $redirect_id === $post->ID ) {
            return $content; // evita loop ou valores inválidos
        }

        $redirect_post = get_post($redirect_id);

        if ( $redirect_post && ( $redirect_post->post_status == 'publish' ||  $redirect_post->post_status == 'private' ) ) {
            return do_blocks($redirect_post->post_content);
        }

        return $content;
    }

    /**
     * Template inicial de novas páginas de inventário, a partir do padrão selecionado.
     * Sem padrão, o editor abre em branco.
     */
    function get_inventario_template() {
        $template = array();
        $pattern_id = absint( $this->get_inventarios_default_pattern_id() );

        if ( $pattern_id ) {
            $pattern_post = get_post( $pattern_id );

            if ( $pattern_post && $pattern_post->post_type === 'wp_block' && $pattern_post->post_status === 'publish' ) {
                $template = $this->blocks_to_template( parse_blocks( $pattern_post->post_content ) );
            }
        }

        return apply_filters( 'tainacan_inventarios_default_template', $template );
    }

    /**
     * Converte blocos parseados em notação de template do CPT.
     */
    private function blocks_to_template( $blocks ) {
        $template = array();

        foreach ( $blocks as $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }

            $item = array(
                $block['blockName'],
                ! empty( $block['attrs'] ) ? $block['attrs'] : array(),
            );

            if ( ! empty( $block['innerBlocks'] ) ) {
                $inner_template = $this->blocks_to_template( $block['innerBlocks'] );

                if ( ! empty( $inner_template ) ) {
                    $item[] = $inner_template;
                }
            }

            $template[] = $item;
        }

        return $template;
    }

    /**
     * Conteúdo da página criada junto com o item.
     * O padrão escolhido é copiado como foi salvo, para o HTML dos blocos continuar
     * igual ao que o editor espera. Só os atributos de item são preenchidos.
     */
    private function get_inventario_post_content_for_item( $item_id ) {
        $pattern_id = absint( $this->get_inventarios_default_pattern_id() );

        if ( $pattern_id ) {
            $pattern_post = get_post( $pattern_id );

            if ( $pattern_post && 'wp_block' === $pattern_post->post_type && 'publish' === $pattern_post->post_status ) {
                $blocks = $this->apply_item_id_to_parsed_blocks( parse_blocks( $pattern_post->post_content ), $item_id );

                return serialize_blocks( $blocks );
            }
        }

        return '';
    }

    /**
     * Preenche itemId nos blocos já parseados, preservando innerHTML e innerContent.
     */
    private function apply_item_id_to_parsed_blocks( $blocks, $item_id ) {
        $item_id = absint( $item_id );
        $collection_id = $this->get_inventarios_collection_id();
        $block_names = $this->get_item_id_block_names();
        $runtime_attributes = array(
            'metadataSectionsRequestSource',
            'metadataSectionRequestSource',
            'itemMetadataRequestSource',
        );

        foreach ( $blocks as $index => $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }

            if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
                $block['attrs'] = array();
            }

            if ( in_array( $block['blockName'], $block_names, true ) ) {
                $block['attrs']['itemId'] = $this->item_id_attribute_for_block( $block['blockName'], $item_id );

                if ( empty( $block['attrs']['collectionId'] ) && $collection_id ) {
                    $block['attrs']['collectionId'] = $this->collection_id_attribute_for_block( $block['blockName'], $collection_id );
                }

                $block['attrs'] = $this->stamp_item_id_in_block_templates( $block['attrs'], $item_id );
            }

            foreach ( $runtime_attributes as $runtime_attribute ) {
                unset( $block['attrs'][ $runtime_attribute ] );
            }

            if ( ! empty( $block['innerBlocks'] ) ) {
                $block['innerBlocks'] = $this->apply_item_id_to_parsed_blocks( $block['innerBlocks'], $item_id );
            }

            $blocks[ $index ] = $block;
        }

        return $blocks;
    }

    /**
     * Atualiza itemId dentro de templates de blocos guardados em atributos, como metadataSectionsTemplate.
     */
    private function stamp_item_id_in_block_templates( $value, $item_id ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( isset( $value[0] ) && is_string( $value[0] ) && 0 === strpos( $value[0], 'tainacan/' ) ) {
            if ( isset( $value[1] ) && is_array( $value[1] ) && array_key_exists( 'itemId', $value[1] ) ) {
                $value[1]['itemId'] = $this->item_id_attribute_for_block( $value[0], $item_id );
                $value[1] = $this->stamp_item_id_in_block_templates( $value[1], $item_id );
            }

            if ( isset( $value[2] ) && is_array( $value[2] ) ) {
                $value[2] = $this->stamp_item_id_in_block_templates( $value[2], $item_id );
            }

            return $value;
        }

        foreach ( $value as $key => $child ) {
            if ( is_array( $child ) ) {
                $value[ $key ] = $this->stamp_item_id_in_block_templates( $child, $item_id );
            }
        }

        return $value;
    }

    /**
     * Blocos Tainacan que devem receber o ID do item recém-criado.
     */
    private function get_item_id_block_names() {
        return apply_filters(
            'tainacan_inventarios_item_id_blocks',
            array(
                'tainacan/item-metadata-sections',
                'tainacan/item-metadata-section',
                'tainacan/item-metadata',
                'tainacan/related-items-list',
            )
        );
    }

    /**
     * item-metadata-sections declara itemId como integer; related-items-list, como string.
     */
    private function item_id_attribute_for_block( $block_name, $item_id ) {
        if ( 'tainacan/related-items-list' === $block_name ) {
            return (string) absint( $item_id );
        }

        return absint( $item_id );
    }

    /**
     * collectionId segue o mesmo tipo declarado no block.json de cada bloco.
     */
    private function collection_id_attribute_for_block( $block_name, $collection_id ) {
        if ( 'tainacan/related-items-list' === $block_name ) {
            return (string) $collection_id;
        }

        return absint( $collection_id );
    }

    /**
     * Método utilitário para acesso a option que guarda o ID da coleção de inventários.
     */
    function get_inventarios_collection_id() {
        return get_option('tainacan_option_' . $this->inventarios_collection_id_field);
    }

    /**
     * Método utilitário para acesso a option que guarda o ID do padrão de blocos padrão.
     */
    function get_inventarios_default_pattern_id() {
        return get_option( 'tainacan_option_' . $this->inventarios_default_pattern_id_field );
    }
}

