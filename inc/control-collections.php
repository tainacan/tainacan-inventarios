<?php

/**
 * COLEÇÕES DE CONTROLE
 * 
 * Em diversas coleções de um Acervo de Inventários, são utilizados metadados tipo relacionamento,
 * onde um item de uma coleção se relaciona com um item de outra coleção. Esta última, serviria 
 * para listar dados que complementam esta relação apenas. Seria uma espécie de "categorização" das
 * Coleções. Existem aquelas que de fato são relevantes de se estarem listadas e portanto devem ser
 * pesquisáveis e aparecerem direto no painel administrativo. Outras coleções, porém, guardam 
 * informações que só fazem sentido quando reaproveitadas como fonte de dados nas demais coleções,
 * dentro dos relacionamentos. Elas não poderiam, porém, ser coleções privadas, pois o usuário comum
 * ainda precisaria poder criar itens nela - a partir do metadado de relacionamento. Estas coleções
 * chamamos de Coleções de Controle.
 * 
 * Esta classe implementa a lógica necessária para categorização de coleções como controle e as 
 * restrições de acesso ou modificações de interface que elas demandam. Optou-se por guardar a lista
 * de coleções de controle em uma option 'tainacan_option_tainacan_inventarios_control_collections'.
 */

namespace Tainacan_Inventarios;

// Evita acesso direto ao arquivo
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class Control_Collections {
	
	use Singleton;

	private $control_collections_field = 'tainacan_inventarios_control_collections';

	protected function init() {		

		// Lógica para adicionar a opção das coleções de controle nas configurações do Tainacan
		add_action( 'admin_init', array( $this, 'settings_init' ) );

		// Estiliza algumas áreas do Admin diferente para coleções de controle (ou metadados de relacionamento com elas)
		add_action( 'admin_head', array( $this, 'customize_control_collection_css' ));

		// Filtra requisições para mostrar apenas coleções que não são de controle
		add_action( 'pre_get_posts', array( $this, 'hide_control_collections_from_get_posts' ) );

		// Filtra os cards de coleções de Controle no Dashboard
		add_filter( 'tainacan-dashboard-cards', array( $this, 'hide_control_collections_from_tainacan_dashboard' ) );
	}

	/**
	 * Função que usa da action 'admin_init' para registrar uma nova 'option' do Tainacan,
	 * que guarda o vetor de coleções de controle. A função 'create_tainacan_settings' usada
	 * é responsável por montar a lista de checkbox e registrar a option que é um wrapper na
	 * api de options do WordPress. As opções passam a estar disponíveis no menu "Tainacan" 
	 * -> "Outros" -> "Configurações" -> "Tainacan Inventários" -> "Coleções de Controle"
	 */
	public function settings_init() {

        $collections = \tainacan_collections()->fetch(array(), 'OBJECT');
        $collections_labels = [];

        foreach( $collections as $collection ) {
			if ( Inventario_Post_Type::get_instance()->get_inventarios_collection_id() != $collection->get_id() )
				$collections_labels[ (string) $collection->get_id() ] = esc_html( $collection->get_name() );
		}

		\Tainacan\Settings::get_instance()->create_tainacan_setting( array(
			'id' => $this->control_collections_field,
			'title' => __( 'Coleções de Controle', 'tainacan-inventarios' ),
			'section' => 'tainacan_settings_inventarios',
			'label' => $collections_labels,
			'type' => 'array',
            'input_type' => 'checkbox',
            'sanitize_callback' => function( $input ) {
				return is_array( $input )
					? array_map( 
						'sanitize_text_field', 
						array_filter( 
							$input, 
							function($collection_id) { 
								return $collection_id != Inventario_Post_Type::get_instance()->get_inventarios_collection_id();
							} 
						) 
					)
					: [];
			},
            'default' => $this->get_control_collections_ids(),
		) );
	}

	/**
	 * Função que usa da action 'admin_head' para acrescentar CSS inline no admin.
	 * Este CSS estiliza coleções de controle no Admin do Tainacan, em especial no contexto de 
	 * edições de item via metadados de relacionamento.
	 */
	public function customize_control_collection_css() {
		$control_collection_ids = $this->get_control_collections_ids();

		if ( empty($control_collection_ids) )
			return;
		
		$css = '';

		foreach( $control_collection_ids as $control_collection_id ) {

			$control_collection_metadatum_ids = [];
			$control_collection_metadata = \tainacan_metadata()->fetch([
				'meta_query' => [
					[
						'key'   => 'metadata_type',
						'value' => 'Tainacan\Metadata_Types\Relationship'
					],
					[
						'key' => '_option_collection_id',
						'value' => $control_collection_id
					]
				],
				'perpage' => -1
			], 'OBJECT');

			if ( empty($control_collection_metadata) )
				continue;
			
			$control_collection_metadatum_ids = array_map(function($metadatum) {
				return $metadatum->get_ID();
			}, $control_collection_metadata);

			foreach( $control_collection_metadatum_ids as $control_collection_metadatum_id ) {

				$control_collection_item_edition_page_selector = '#collection-page-container.tainacan-admin-collection-item-edition-mode[collection-id="' . $control_collection_id . '"]';
				$control_collection_relationship_metadatatum_selector = '#collection-page-container:not(.tainacan-admin-collection-item-edition-mode) .tainacan-metadatum-component--tainacan-relationship.tainacan-metadatum-id--' . $control_collection_metadatum_id;

				$css .= '
					/* Tweaks the relationship input on the collection that has relation to the control collection, so that it only allows creation or edition of existing items */
					' . $control_collection_relationship_metadatatum_selector . ' .tabs,
					' . $control_collection_relationship_metadatatum_selector . ' .tab-content>.tab-item:first-of-type {
						display: none;
						visibility: hidden;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .tab-content>.tab-item:last-of-type {
						display: block !important;
						visibility: visible;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .tainacan-modal .modal-content {
						max-width: 640px !important;
						max-height: 60vh !important;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .tainacan-modal .modal-content iframe {
						height: 59vh !important;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .tainacan-relationship-results-container {
						border: none;
						padding-left: 0;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .tainacan-relationship-results-container  .tainacan-relationship-group > div > .multivalue-separator {
						margin-left: 0;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .tainacan-relationship-results-container .tainacan-metadatum {
						margin-left: 0px;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .add-link {
						content: "";
						color: transparent !important;
						font-size: 0 !important;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .add-link>.icon {
						font-size: 0.875rem;
					}
					' . $control_collection_relationship_metadatatum_selector . ' .add-link::after {
						content: "Adicionar valor";
						color: var(--tainacan-secondary);
						font-size: 0.75rem;
						margin-top: 6px;
					}
				
					/* Hides elements not necessary for control collections inside the item edition modal */
					' . $control_collection_item_edition_page_selector . '>.tainacan-form>.columns>.column:first-of-type {
						width: 100%%;
						padding: 0 1rem;
					}
					' . $control_collection_item_edition_page_selector . '>.tainacan-form>.columns>.column:last-of-type,
					' . $control_collection_item_edition_page_selector . '>.tainacan-form>.columns>.column:first-of-type>.columns,
					' . $control_collection_item_edition_page_selector . '>.tainacan-form>.columns>.column:first-of-type>.b-tabs>.tabs,
					' . $control_collection_item_edition_page_selector . '>.tainacan-form>.columns>.column:first-of-type>.b-tabs .sub-header {
						display: none;
						visibility: hidden;
					}
					' . $control_collection_item_edition_page_selector . '>.tainacan-form>.columns>.column:first-of-type>.b-tabs>.tab-content {
						border-top: none;
					}
					' . $control_collection_item_edition_page_selector . '.page-container {
						padding-left: 0;
						padding-right: 0;
					}
					' . $control_collection_item_edition_page_selector . '.page-container .tainacan-page-title h1 {
						content: "";
						color: transparent !important;
						font-size: 0 !important;
					}
					' . $control_collection_item_edition_page_selector . '.page-container.item-creation-container .tainacan-page-title h1::after {
						content: "Adicionar valor";
						color: var(--tainacan-gray5);
						font-size: 1.25rem;
					}
					' . $control_collection_item_edition_page_selector . '.page-container.item-edition-container .tainacan-page-title h1::after {
						content: "Editar valor";
						color: var(--tainacan-gray5);
						font-size: 1.25rem;
					}
					' . $control_collection_item_edition_page_selector . '.page-container .column.is-main-column .tab-item > .field:last-child {
						margin-bottom: 0 !important;
					}
					' . $control_collection_item_edition_page_selector . '.page-container .column.is-main-column .tainacan-finder-columns-container {
						max-height: 50vh
					}
					' . $control_collection_item_edition_page_selector . '.page-container .form-submission-footer .item-edition-footer-dropdown {
						display: none !important;
						visibility: hidden;
					}
					' . $control_collection_item_edition_page_selector . '.page-container .update-info-section {
						margin-bottom: -2.5rem;
						margin-left: 0;
					}
					' . $control_collection_item_edition_page_selector . '.page-container .form-submission-footer .button.is-outlined {
						display: none;
						visibility: hidden;
					}
					' . $control_collection_item_edition_page_selector . '.page-container.item-creation-container .form-submission-footer .button.is-success::after {
						content: "Adicionar";
						color: white;
						font-size: 0.875rem;
					}
					' . $control_collection_item_edition_page_selector . '.page-container.item-edition-form .form-submission-footer .button.is-success {
						content: "";
						color: transparent !important;
						font-size: 0 !important;
						margin-left: auto;
					}
					' . $control_collection_item_edition_page_selector . ' .status-tag {
						display: none;
						visibility: hidden;
					}
					' . $control_collection_item_edition_page_selector . ' .field {
						padding-left: 0;
					}
					' . $control_collection_item_edition_page_selector . ' .field .collapse-handle,
					' . $control_collection_item_edition_page_selector . ' .field .collapse-handle .label {
						margin-left: 0;
					}
					' . $control_collection_item_edition_page_selector . ' .field .collapse-handle .icon {
						display: none;
						visibility: hidden;
					}
				';
			}
		}
		
		echo '<style type="text/css" id="tainacan-control-collections-style">' . sprintf( $css ) . '</style>';
	}

	/**
	 * Função que usa da action 'pre_get_posts' para filtrar a maioria das requisições feitas 
	 * por coleções do tainacan, retirando as coleções de controle
	 */
	public function hide_control_collections_from_get_posts($query) {

		if ( !is_admin() && $query->is_archive() && is_post_type_archive( 'tainacan-collection' ) ) {
			$query->query_vars['exclude'] = array_merge(
				$query->query_vars['exclude'] ?? [],
				$this->get_control_collections_ids()
			);
		}
	}

	/**
	 * Função que usa do filtro 'tainacan-dashboard-cards' para retirar da lista de
	 * cartões do dashboard do Tainacan as coleções de controle.
	 */
	public function hide_control_collections_from_tainacan_dashboard($tainacan_dashboard_cards) {

		$control_collections = $this->get_control_collections_ids();
		
		return array_filter(
			$tainacan_dashboard_cards,
			
			function($dashboard_card) use ($control_collections) {
				
				return !str_contains($dashboard_card['id'], 'tainacan-dashboard-collection-card-') || 
					!in_array(
						$dashboard_card['id'], 
						array_map(function($collection_id) {
							return 'tainacan-dashboard-collection-card-' . $collection_id;
						}, $control_collections)
					);
			}
		);
	}

	/**
	 * Método utilitário para acesso a option que guarda os IDs das coleções de controle
	 */
	public function get_control_collections_ids() {
		return get_option('tainacan_option_' . $this->control_collections_field, [] );
	}
}

