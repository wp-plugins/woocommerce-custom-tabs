<?php
/*
Plugin Name: Woocommerce Custom Tabs
Plugin URI: http://webshoplogic.com/product/woocommerce-custom-tabs-lite/
Description: Custom product tab pages can be added to WooCommerce products using this plugin.  
Version: 1.0.9
Author: WebshopLogic
Author URI: http://webshoplogic.com/
License: GPLv2 or later
Text Domain: wct
Requires at least: 3.7
Tested up to: 3.9.1
*/

if ( ! class_exists( 'WCT' ) ) {

class WCT {

	public $plugin_path;

	public $plugin_url;


	function __construct() {
		
		global $is_premium;
			
		$is_premium = FALSE;
		include_once( 'wct-admin-page.php' );
		
		add_action( 'init', array( $this, 'init' ), 0 );
		
		//register_activation_hook( __FILE__, array( $this, 'wct_activation' ) );
		
		if ($is_premium) //disable auto update from wordpress.org
			add_filter( 'site_transient_update_plugins', array($this, 'filter_plugin_updates' ));
		
		$options = get_option( 'wct_general_settings' );
		
		if ( ! class_exists( 'Acf' ) ) {  //if ACF plugin is installed, it is not needed
			
			define( 'ACF_LITE', true ); //remove all visual interfaces of ACF plugin
			include_once( 'advanced-custom-fields/acf.php' );

		}			
		
		if ( ! class_exists( 'acf_field_wp_wysiwyg_plugin' ) ) {  //if acf_field_wp_wysiwyg_plugin plugin is installed, it is not needed

			include_once('acf-wordpress-wysiwyg-field/acf-wp_wysiwyg.php' );
		
		}
			
		do_action( 'wct_init' );

	}

	public function init() {


		load_plugin_textdomain( 'wct', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );


		global $is_premium, $wct_admin_page;
		$wct_admin_page = new WCT_Admin_Page;

		$options = get_option('wct_general_settings');

		if	( $is_premium or 1 == $options['enable_multiple_tabs_admin_test'] ) 
			add_action( 'init', array( $this, 'create_product_tabpage_post_type' ));

		if ( 1 == $options['enable_product_category_dependent_tabs'] ) {
			add_action('init', array( $this, 'taxonomy_for_objet_types' ));
		}
		
		add_filter( 'woocommerce_product_tabs', array($this, 'display_woocommerce_custom_tabs') );

		add_action( 'admin_init', array( $this, 'register_tabcontent_wysiwyg_edit_field_group' )); //admin_init - product
		add_action( 'admin_init', array( $this, 'register_product_tab_post_type_priority_field_group' )); //admin_init - tab 

		do_action( 'wct_init', $this );

	}

	function get_actual_product_related_tab_page_objects () { //$actual_post_id


		//1. get product_tab posts, when use_for_all_products attribute is ON --> $product_tabpage_postslist_1

		$args = array(
			'post_type' => 'product_tabpage',
			'posts_per_page' => 1000,			
			'meta_key'	=> 'priority',
			'orderby'	=> 'meta_value_name',
			'order'		=> 'ASC'
		);

		$product_tabpage_postslist_1 = get_posts( $args );
		
		foreach ($product_tabpage_postslist_1 as $key => $product_tabpage_object) {

			//x$use_for_all_products_field_object = get_field_object('use_for_all_products', $product_tabpage_object->ID);
			$use_for_all_products_field = get_field('use_for_all_products', $product_tabpage_object->ID, false);
			
			//xif (1 != $use_for_all_products_field_object['value']) {
			if (1 != $use_for_all_products_field) {				
				//delete values from the array when use for all product is not swiched on
				unset($product_tabpage_postslist_1[$key]); 
			}
			
		}
		
		global $post;

		//2. get those product_tabpages that has common product categor with actual product or has no product category at all -->  --> $product_tabpage_postslist_2 

		$product_tabpage_postslist_2 = array();

		$options = get_option( 'wct_general_settings' );					
		
		if ( 1 == $options['enable_product_category_dependent_tabs'] ) {

			//in case of admin page global $post variable can't be used, so $_GET['post] is available   
			$post_id = intval( $post->id != null ? $post->id : $_GET['post'] );
					
			//get product categories of actual product
			$actual_product_categories = get_the_terms( $post_id, 'product_cat');
			
			//qery product tab pages custom posts that has common product category with the actual product
			//create tax_query_array
			$tax_query_array = array();
			$tax_query_array['relation'] = 'OR';
			
			if ( ! is_array( $actual_product_categories ) )
				$actual_product_categories = array();
			
			foreach ($actual_product_categories as $actual_product_object) {
				$tax_query_array[] =	array(
						'taxonomy' => 'product_cat',
						'field' => 'id',
						'terms' => intval( $actual_product_object -> term_id ),
					);
				
			}
			
			$args = array(
				'post_type' => 'product_tabpage',
				'tax_query' => $tax_query_array, 
			);
	
			$product_tabpage_postslist_2 = get_posts( $args );
		
		}

		//merge the two arrays (always usable and category dependent tab pages)
		$product_tabpage_postslist = array_unique ( array_merge ( $product_tabpage_postslist_1, $product_tabpage_postslist_2 ), SORT_REGULAR);
			//array_unique works with an array of objects using SORT_REGULAR:

		return apply_filters( 'product_tabpage_postslist', $product_tabpage_postslist );
	
	}
	
	function display_woocommerce_custom_tabs( $tabs ) {
		
		$options = get_option( 'wct_general_settings' );
		
		$codeproduct_array = get_post( null, OBJECT );
		
		//x$tab_content_field_object = get_field_object('common_tab', $codeproduct_array->ID);
		//x$tab_content = $tab_content_field_object['value'];
		$tab_content = get_field('common_tab', $codeproduct_array->ID, false);
		
		//x$tab_custom_title_field_object = get_field_object('common_tab_tab_custom_title', $codeproduct_array->ID);
		//x$tab_custom_title = $tab_custom_title_field_object['value'];
		$tab_custom_title = get_field('common_tab_tab_custom_title', $codeproduct_array->ID, false);

		if ( !empty($tab_content) or 1 != $options['hide_empty_tabs'] ) {
			
			if ($options['common_tabname'] != '') { //if common tabname is set, then this tab has to be displayed
					
				$tabs[ 'common_tab' ] = array(
				'title' 	=> $tab_custom_title == '' ? $options['common_tabname'] : $tab_custom_title,
				'priority' 	=> $options['common_tab_priority'],
				'callback' 	=> array ($this, 'woocommerce_tab_content' ),
				);
	
			}

		}
		return $tabs;
	}

	function woocommerce_tab_content($tab_code = 'common_tab') {

		//$tab_code is iqual field name of the current product post field name that is to be written to this tab page as content
		
		$codeproduct_array = get_post( null, OBJECT );
		
		$tab_content = get_field($tab_code, $codeproduct_array->ID, false);
		$tab_content = apply_filters('the_content', $tab_content ); //process shortcodes
		echo $tab_content;
		
	}
	
	public function wct_activation() {

		//create search page if not exists

	}

	function create_product_tabpage_post_type() {

		global $is_premium;
		$register_post_type_array = 
			array(
				'labels' => array(
					'name' => __( 'Product tabs', 'wct' ) . ($is_premium ? '' : (' ' . __('TEST', 'wct'))),
					'singular_name' => __( 'Product tab', 'wct') . ($is_premium ? '' : (' ' . __('TEST', 'wct'))),
					'add_new'            => __( 'Add New', 'wct' ),
				    'add_new_item'       => __( 'Add New Product Tab Type', 'wct' ),
				    'edit_item'          => __( 'Edit Product Tab Type', 'wct' ),
				    'new_item'           => __( 'New Product Tab Type', 'wct' ),
				    'all_items'          => __( 'All Product Tab Type', 'wct' ),
				    'view_item'          => __( 'View Product Tab Type', 'wct' ),
				    'search_items'       => __( 'Search Product Tab Type', 'wct' ),
				    'not_found'          => __( 'No product tab types found', 'wct' ),
				    'not_found_in_trash' => __( 'No product tab type found in Trash', 'wct' ),
				    'parent_item_colon'  => '',
    					
				),
			'public' => true,
			'has_archive' => false,
			);
		
		register_post_type	( 'product_tabpage', $register_post_type_array );
		
	}	
	
	function taxonomy_for_objet_types() {
		
		//register woocommerce product category to our product tabpage posttype
		register_taxonomy_for_object_type( 'product_cat', 'product_tabpage' );	

	}

	
/**
 *  Register Field Groups
 *
 *  The register_field_group function accepts 1 array which holds the relevant data to register a field group
 *  You may edit the array as you see fit. However, this may result in errors if the array is not compatible with ACF
 * 
 * The base of this function was generated by AFC plugin.
 * 
 * Using ACF in a plugin
 * Including the (free) Advanced Custom Fields plugin inside a free / premium plugin is allowed.
 * You can NOT include any purchased add-ons within the plugin.
 * For your plugin to use any of the premium Add-ons you must ask the customer / user to purchase and include the Add-ons. 
 * 
 * IMPORTANT
 *  For more information, please read:
 *  - http://www.advancedcustomfields.com/terms-conditions/
 *  - http://www.advancedcustomfields.com/resources/getting-started/including-lite-mode-in-a-plugin-theme/
 * 
 */

	function register_tabcontent_wysiwyg_edit_field_group() {

		global $is_premium;
		
		$options = get_option( 'wct_general_settings' );

		/*$args = array(
			'post_type' => 'product_tabpage',
		);
		$product_tabpage_postslist = get_posts( $args );*/
		
		$options = get_option('wct_general_settings');
				
		if	( $is_premium or 1 == $options['enable_multiple_tabs_admin_test'] ) {
		
			$product_tabpage_postslist = $this->get_actual_product_related_tab_page_objects();
	
			if ( !empty($product_tabpage_postslist)) {
	
				
				foreach ($product_tabpage_postslist as $product_tabpage_post) {

					$fields_array[] = array (
									'key' => $product_tabpage_post -> post_name . '_tab_custom_title',
									'label' => $product_tabpage_post -> post_title . ' ' . __('tab custom title (optional)', 'wct') . ' ' . ($is_premium ? '' : __('TEST', 'wct')),
									'name' => $product_tabpage_post -> post_name . '_tab_custom_title',
									'type' => 'text',
									'default_value' => '',
									'placeholder' => '',
									'prepend' => '',
									'append' => '',
									'formatting' => 'html',
									'maxlength' => '',
									);			
				
					$fields_array[] = array (
									'key' => $product_tabpage_post -> post_name,
									'label' => $product_tabpage_post -> post_title . ' ' . __('tab content', 'wct') . ' ' . ($is_premium ? '' : __('TEST', 'wct')),
									'name' => $product_tabpage_post -> post_name,
									'type' => 'wp_wysiwyg',
									'default_value' => '',
									'teeny' => 0,
									'media_buttons' => 1,
									'dfw' => 1,
									);

				}
	
			}

		}
			
		if ($options['common_tabname'] != '') { //if common tabname is set, then admin field of this tab has to be displayed on product admin page

			$fields_array[] = array (
						'key' => 'common_tab_tab_custom_title',
						'label' => $options['common_tabname'] . ' ' . __('tab custom title (optional)', 'wct'),
						'name' => 'common_tab_tab_custom_title',
						'type' => 'text',
						'default_value' => '',
						'placeholder' => '',
						'prepend' => '',
						'append' => '',
						'formatting' => 'html',
						'maxlength' => '',
						);
		
		
			$fields_array[] = array (
						'key' => 'common_tab',
						'label' => $options['common_tabname'] . ' ' . __('tab content', 'wct'),
						'name' => 'common_tab',
						'type' => 'wp_wysiwyg',
						'default_value' => '',
						'teeny' => 0,
						'media_buttons' => 1,
						'dfw' => 1,
						);
		}
	
		if ( !empty($fields_array)) {

			if(function_exists("register_field_group"))
			{
				$register_field_group_array = array (
					'id' => 'acf_product_tabpage-group',
					'title' => __('Product tabpage group', 'wct'),
					'fields' => $fields_array,
					'location' => array (
						array (
							array (
								'param' => 'post_type',
								'operator' => '==',
								'value' => 'product',
								'order_no' => 0,
								'group_no' => 0,
							),
						),
					),
					'options' => array (
						'position' => 'normal', //High (after title) - acf_after_title, Normal (after content) - normal, Side - side
						'layout' => 'no_box',
						'hide_on_screen' => array (
						),
					),
					'menu_order' => 0,
				);
				
				register_field_group ($register_field_group_array);
				
			}
		}
	}	

	function register_product_tab_post_type_priority_field_group() {

		if(function_exists("register_field_group"))
		{
			$register_field_group_array = array (
				'id' => 'acf_product_tab_post_type_priority-group',
				'title' => __('Product tab settings', 'wct'),
				'fields' => array (
					array (
						'key' => 'use_for_all_products',
						'label' => __('Use for all products', 'wct'),
						'name' => 'use_for_all_products',
						'type' => 'true_false',
						'instructions' => __('If this checkbox is on, the tab page will be displayed for all products regardless of product category. The other way is to assign this tabpage type to product categories on the right side. This way this tab will be displayed only on those products, that has the same product categories that this tab. Make sure that "Enable product category dependent product tab pages" checkbox is turn on on WooCommerce Custom Tabs setting page, otherwise the category panel will not be displayed on the sidebar.', 'wct'),
						'message' => '',
						'default_value' => 1,
					),
					array (
						'key' => 'priority',
						'label' => __('Tab page priority', 'wct'),
						'name' => 'priority',
						'type' => 'number',
						'instructions' => 
			__('Determine the order of the tab pages. The higher priority means that the tab will appear backwards. Original WooCommerce tabs priority: Description=10, Additional Information=20, Reviews=30', 'wct'),
						'required' => 1,
						'default_value' => 40,
						'placeholder' => '',
						'prepend' => '',
						'append' => '',
						'min' => 0,
						'max' => '',
						'step' => '',
					),

				),
				'location' => array (
					array (
						array (
							'param' => 'post_type',
							'operator' => '==',
							'value' => 'product_tabpage',
							'order_no' => 0,
							'group_no' => 0,
						),
					),
				),
				'options' => array (
					'position' => 'normal', //acf_after_title
					'layout' => 'no_box',
					'hide_on_screen' => array (
						0 => 'the_content',
						1 => 'excerpt',
						2 => 'custom_fields',
						3 => 'discussion',
						4 => 'comments',
						5 => 'revisions',
						6 => 'format',
						7 => 'featured_image',
						8 => 'categories',
						9 => 'tags',
						10 => 'send-trackbacks',					
					),
				),
				'menu_order' => 0,
			);
			
			register_field_group ($register_field_group_array);
			
		}
	}	

	
	public function plugin_path() {
		if ( $this->plugin_path ) return $this->plugin_path;

		return $this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

	function plugin_url() {
		if ( $this->plugin_url ) return $this->plugin_url;
		return $this->plugin_url = untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	//disable plugin update notice (in PRO)
	function filter_plugin_updates( $value ) {
		if(isset($value->response[ plugin_basename(__FILE__) ])) 
			unset($value->response[ plugin_basename(__FILE__) ]);	    
	    return $value;
	}	

}

//Init WCT class
$GLOBALS['wct'] = new WCT();

}

?>