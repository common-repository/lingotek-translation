<?php
if ( ! defined( 'ABSPATH' ) ) exit();
/*
 * Adds row and bulk actions to categories and post tags list
 * Manages actions which trigger communication with Lingotek TMS
 *
 * @since 0.2
 */
class Lingotek_Term_actions extends Lingotek_Actions {

	/*
	 * Constructor
	 *
	 * @since 0.2
	 */
	public function __construct() {
		parent::__construct( 'term' );

		foreach ( $this->pllm->get_translated_taxonomies() as $taxonomy ) {
			add_filter( $taxonomy . '_row_actions', array( &$this, 'term_row_actions' ), 10, 2 );
		}

		// add_action('admin_footer-edit-tags.php', array(&$this, 'add_bulk_actions')); // FIXME admin_print_footer_scripts instead?
		add_action( 'load-edit-tags.php', array( &$this, 'manage_actions' ) );
	}

	/*
	 * get the language of a term
	 *
	 * @since 0.2
	 *
	 * @param int $term_id
	 * @return object
	 */
	protected function get_language( $term_id ) {
		return PLL()->model->term->get_language( $term_id );
	}

	/*
	 * displays the icon of an uploaded term with the relevant link
	 *
	 * @since 0.2
	 *
	 * @param int $id
	 */
	public static function uploaded_icon( $id ) {
		// 2nd case for quick edit
		$post_type = isset( $GLOBALS['post_type'] ) ? sanitize_text_field( $GLOBALS['post_type'] ) : sanitize_text_field( $_REQUEST['post_type'] );
		$taxonomy  = isset( $GLOBALS['taxonomy'] ) ? sanitize_text_field( $GLOBALS['taxonomy'] ) : sanitize_text_field( $_REQUEST['taxonomy'] );
		$link      = get_edit_term_link( $id, $taxonomy, $post_type );
		return self::display_icon( 'uploaded', $link );
	}

	/*
	 * adds a row action link
	 *
	 * @since 0.2
	 *
	 * @param array $actions list of action links
	 * @param object $term
	 * @return array
	 */
	public function term_row_actions( $actions, $term ) {
		if ( $this->pllm->is_translated_taxonomy( $term->taxonomy ) ) {
			$actions = $this->_row_actions( $actions, $term->term_id );

			$language = PLL()->model->term->get_language( $term->term_id );
			if ( ! empty( $language ) ) {
				$profile = Lingotek_Model::get_profile( $term->taxonomy, $language );
				if ( 'disabled' == $profile['profile'] ) {
					unset( $actions['lingotek-upload'] );
				}
			}
		}
		return $actions;
	}

	/*
	 * adds actions to bulk dropdown in posts list table
	 *
	 * @since 0.1
	 */
	// public function add_bulk_actions($bulk_actions) {
	// 	if ($this->pllm->is_translated_taxonomy($GLOBALS['taxnow']))
	// 		$this->_add_bulk_actions($bulk_actions);
	// }

	/*
	 * manages Lingotek specific actions before WordPress acts
	 *
	 * @since 0.2
	 */
	public function manage_actions() {
		global $taxnow, $post_type;

		if ( ! $this->pllm->is_translated_taxonomy( $taxnow ) ) {
			return;
		}

        if ( ! current_user_can( 'manage_categories' ) ) {
            wp_die( __( 'You do not have sufficient permissions to manage categories.', 'lingotek-translation' ) );
        }
		// get the action
		$wp_list_table = _get_list_table( 'WP_Terms_List_Table' );
		$action        = $wp_list_table->current_action();

		if ( empty( $action ) ) {
			return;
		}

		$redirect = remove_query_arg( array( 'action', 'action2', 'delete_tags' ), wp_get_referer() );
		if ( ! $redirect ) {
			$redirect = admin_url( "edit-tags.php?taxonomy=$taxnow&post_type=$post_type" );
		}

		switch ( $action ) {
			case 'bulk-lingotek-upload':
				if ( empty( $_REQUEST['delete_tags'] ) ) {
					return;
				}

				$term_ids = array();

				foreach ( array_map( 'intval', $_REQUEST['delete_tags'] ) as $term_id ) {
					// safe upload
					if ( $this->lgtm->can_upload( 'term', $term_id ) ) {
						$term_ids[] = $term_id;
					}

					// the document is already translated so will be overwritten
					elseif ( ( $document = $this->lgtm->get_group( 'term', $term_id ) ) && empty( $document->source ) ) {
						// take care to upload only one post in a translation group
						$intersect = array_intersect( $term_ids, PLL()->model->term->get_translations( $term_id ) );
						if ( empty( $intersect ) ) {
							$term_ids[] = $term_id;
							$redirect   = add_query_arg( 'lingotek_warning', 1, $redirect );
						}
					}
				}

				// check if translation is disabled
				if ( ! empty( $term_ids ) ) {
					foreach ( $term_ids as $key => $term_id ) {
						$language = PLL()->model->term->get_language( $term_id );
						$profile  = Lingotek_Model::get_profile( $taxnow, $language );
						if ( 'disabled' == $profile['profile'] ) {
							unset( $term_ids[ $key ] );
						}
					}
				}
			case 'bulk-lingotek-request':
			case 'bulk-lingotek-download':
			case 'bulk-lingotek-status':
			case 'bulk-lingotek-delete':
				if ( empty( $_REQUEST['delete_tags'] ) ) {
					return;
				}

				if ( empty( $term_ids ) ) {
					$term_ids = array_map( 'intval', $_REQUEST['delete_tags'] );
				}

				check_admin_referer( 'bulk-tags' );

				$redirect = add_query_arg( $action, 1, $redirect );
				$redirect = add_query_arg( 'ids', implode( ',', $term_ids ), $redirect );

				break;

			case 'lingotek-upload':
				check_admin_referer( 'lingotek-upload' );
				$id       = absint( filter_input( INPUT_GET, 'term' ) );
				$this->lgtm->upload_term( (int)$id, $taxnow );
				$redirect = add_query_arg( 'id', $id, $redirect );
				$redirect = add_query_arg( 'type', 'term', $redirect );
				$redirect = add_query_arg( 'source', $this->pllm->term->get_language( $id )->locale, $redirect );
				break;

			case 'lingotek-copy':
				check_admin_referer( 'lingotek-copy' );
				$term_to_copy = get_term( (int) absint( filter_input( INPUT_GET, 'term' ) ) );
				$target 		= sanitize_lingotek_locale(filter_input( INPUT_GET, 'target' ));
				$this->lgtm->copy_term( $term_to_copy, $target, $taxnow );
				break;

			default:
				if ( ! $this->_manage_actions( $action ) ) {
					// do not redirect if this is not one of our actions.
					return;
				}
		}//end switch

		wp_redirect( $redirect );
		exit();
	}

	/*
	 * ajax response to upload documents and showing progress
	 *
	 * @since 0.1
	 */
	public function ajax_upload() {
		check_ajax_referer( 'lingotek_progress', '_lingotek_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'lingotek-translation' ) );
        }
		$id       = (int) absint( filter_input( INPUT_POST, 'id' ) );
		$taxonomy = sanitize_text_field( filter_input( INPUT_POST, 'taxonomy' ) );
		if ( taxonomy_exists( sanitize_text_field( $_POST['taxonomy'] ) ) ) {
			$this->lgtm->upload_term( $id, $taxonomy );
		}
		die();
	}
}
