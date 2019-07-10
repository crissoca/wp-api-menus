<?php
/**
 * WP REST API Menu routes
 *
 * @package WP_API_Menus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'WP_REST_Menus' ) ) :


	/**
	 * WP REST Menus class.
	 *
	 * WP API Menus support for WP API v2.
	 *
	 * @package WP_API_Menus
	 * @since 1.2.0
	 */
	class WP_REST_Menus {


		/**
		 * Get Blog ID.
		 *
		 * @return string
		 * @since 1.2.2
		 */
		public static function get_blog_id() {
			return get_current_blog_id();
		}


		/**
		 * Get WP API Menus namespace.
		 *
		 * @return string
		 * @since 1.2.1
		 */
		public static function get_plugin_namespace() {
			return 'wp-api-menus/v2';
		}

		/**
		 * Get Cache group.
		 *
		 * @return string
		 * @since 1.2.1
		 */
		public static function get_cache_group() {
			return self::get_plugin_namespace() . '-' . self::get_blog_id();
		}


		/**
		 * Register menu routes for WP API v2.
		 *
		 * @since  1.2.0
		 */
		public function register_routes() {

			register_rest_route( self::get_plugin_namespace(), '/menus', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [
					$this,
					'get_menus'
				],
			] );

			register_rest_route( self::get_plugin_namespace(), '/menus/(?P<id>\d+)', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [
					$this,
					'get_menu'
				],
				'args'     => [
					'context' => [
						'default' => 'view',
					],
				],
			] );

			register_rest_route( self::get_plugin_namespace(), '/menu-locations', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [
					$this,
					'get_menu_locations'
				],
			] );

			register_rest_route( self::get_plugin_namespace(), '/menu-locations/(?P<location>[a-zA-Z0-9_-]+)', [
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [
					$this,
					'get_menu_location'
				],
			] );
		}


		/**
		 * Get menus.
		 *
		 * @return array All registered menus
		 * @since  1.2.0
		 */
		public static function get_menus() {

			if ( false === ( $rest_menus = wp_cache_get( 'get_menus', self::get_cache_group() ) ) ) :

				$rest_url = trailingslashit( get_rest_url() . self::get_plugin_namespace() . '/menus/' );
				$wp_menus = wp_get_nav_menus();

				$i          = 0;
				$rest_menus = [];
				foreach ( $wp_menus as $wp_menu ) :

					$menu = (array) $wp_menu;

					$rest_menus[ $i ]                = $menu;
					$rest_menus[ $i ]['ID']          = $menu['term_id'];
					$rest_menus[ $i ]['name']        = $menu['name'];
					$rest_menus[ $i ]['slug']        = $menu['slug'];
					$rest_menus[ $i ]['description'] = $menu['description'];
					$rest_menus[ $i ]['count']       = $menu['count'];

					$rest_menus[ $i ]['meta']['links']['collection'] = $rest_url;
					$rest_menus[ $i ]['meta']['links']['self']       = $rest_url . $menu['term_id'];

					$i ++;
				endforeach;

				wp_cache_set( 'get_menus', $rest_menus, self::get_cache_group(), DAY_IN_SECONDS );

			endif;

			return apply_filters( 'rest_menus_format_menus', $rest_menus );
		}


		/**
		 * Get a menu.
		 *
		 * @param  $request
		 *
		 * @return array Menu data
		 * @since  1.2.0
		 */
		public function get_menu( $request ) {

			$id = (int) $request['id'];

			if ( false === ( $rest_menu = wp_cache_get( 'get_menu_' . $id, self::get_cache_group() ) ) ) :

				$rest_url       = get_rest_url() . self::get_plugin_namespace() . '/menus/';
				$wp_menu_object = $id ? wp_get_nav_menu_object( $id ) : [];
				$wp_menu_items  = $id ? wp_get_nav_menu_items( $id ) : [];

				$rest_menu = [];

				if ( $wp_menu_object ) :

					$menu                     = (array) $wp_menu_object;
					$rest_menu['ID']          = abs( $menu['term_id'] );
					$rest_menu['name']        = $menu['name'];
					$rest_menu['slug']        = $menu['slug'];
					$rest_menu['description'] = $menu['description'];
					$rest_menu['count']       = abs( $menu['count'] );

					$rest_menu_items = [];
					foreach ( $wp_menu_items as $item_object ) {
						$rest_menu_items[] = $this->format_menu_item( $item_object );
					}

					$rest_menu_items = $this->nested_menu_items( $rest_menu_items, 0 );

					$rest_menu['items']                       = $rest_menu_items;
					$rest_menu['meta']['links']['collection'] = $rest_url;
					$rest_menu['meta']['links']['self']       = $rest_url . $id;

				endif;

				wp_cache_set( 'get_menu_' . $id, $rest_menu, self::get_cache_group(), DAY_IN_SECONDS );

			endif;

			return apply_filters( 'rest_menus_format_menu', $rest_menu );
		}


		/**
		 * Handle nested menu items.
		 *
		 * Given a flat array of menu items, split them into parent/child items
		 * and recurse over them to return children nested in their parent.
		 *
		 * @param  $menu_items
		 * @param  $parent
		 *
		 * @return array
		 * @since  1.2.0
		 */
		private function nested_menu_items( &$menu_items, $parent = null ) {

			$parents  = [];
			$children = [];

			// Separate menu_items into parents & children.
			array_map( function ( $i ) use ( $parent, &$children, &$parents ) {
				if ( $i['id'] != $parent && $i['parent'] == $parent ) {
					$parents[] = $i;
				} else {
					$children[] = $i;
				}
			}, $menu_items );

			foreach ( $parents as &$parent ) {

				if ( $this->has_children( $children, $parent['id'] ) ) {
					$parent['children'] = $this->nested_menu_items( $children, $parent['id'] );
				}
			}

			return $parents;
		}


		/**
		 * Check if a collection of menu items contains an item that is the parent id of 'id'.
		 *
		 * @param array $items
		 * @param int   $id
		 *
		 * @return array
		 * @since  1.2.0
		 */
		private function has_children( $items, $id ) {
			return array_filter( $items, function ( $i ) use ( $id ) {
				return $i['parent'] == $id;
			} );
		}


		/**
		 * Get menu locations.
		 *
		 * @param  $request
		 *
		 * @return array All registered menus locations
		 * @since 1.2.0
		 */
		public static function get_menu_locations( $request ) {

			if ( false === ( $rest_menus = wp_cache_get( 'menu_locations', self::get_cache_group() ) ) ) :

				$locations        = get_nav_menu_locations();
				$registered_menus = get_registered_nav_menus();
				$rest_url         = get_rest_url() . self::get_plugin_namespace() . '/menu-locations/';
				$rest_menus       = [];

				if ( $locations && $registered_menus ) :

					foreach ( $registered_menus as $slug => $label ) :

						// Sanity check
						if ( ! isset( $locations[ $slug ] ) ) {
							continue;
						}

						$rest_menus[ $slug ]['ID']                          = $locations[ $slug ];
						$rest_menus[ $slug ]['label']                       = $label;
						$rest_menus[ $slug ]['meta']['links']['collection'] = $rest_url;
						$rest_menus[ $slug ]['meta']['links']['self']       = $rest_url . $slug;

					endforeach;

				endif;

				wp_cache_set( 'menu_locations', $rest_menus, self::get_cache_group(), DAY_IN_SECONDS );

			endif;

			return $rest_menus;
		}


		/**
		 * Get menu for location.
		 *
		 * @param  $request
		 *
		 * @return array The menu for the corresponding location
		 * @since 1.2.0
		 */
		public function get_menu_location( $request ) {

			$params   = $request->get_params();
			$location = $params['location'];

			if ( false === ( $rev_menu = wp_cache_get( 'menu_location_' . $location, self::get_cache_group() ) ) ) :

				$locations = get_nav_menu_locations();

				if ( ! isset( $locations[ $location ] ) ) {
					return [];
				}

				$wp_menu    = wp_get_nav_menu_object( $locations[ $location ] );
				$menu_items = wp_get_nav_menu_items( $wp_menu->term_id );

				/**
				 * wp_get_nav_menu_items() outputs a list that's already sequenced correctly.
				 * So the easiest thing to do is to reverse the list and then build our tree
				 * from the ground up
				 */
				$rev_items = array_reverse( $menu_items );
				$rev_menu  = [];
				$cache     = [];

				foreach ( $rev_items as $item ) :

					$formatted = [
						'ID'          => abs( $item->ID ),
						'order'       => (int) $item->menu_order,
						'parent'      => abs( $item->menu_item_parent ),
						'title'       => $item->title,
						'url'         => $item->url,
						'attr'        => $item->attr_title,
						'target'      => $item->target,
						'classes'     => implode( ' ', $item->classes ),
						'xfn'         => $item->xfn,
						'description' => $item->description,
						'object_id'   => abs( $item->object_id ),
						'object'      => $item->object,
						'type'        => $item->type,
						'type_label'  => $item->type_label,
						'children'    => [],
					];

					if ( array_key_exists( $item->ID, $cache ) ) {
						$formatted['children'] = array_reverse( $cache[ $item->ID ] );
					}

					$formatted = apply_filters( 'rest_menus_format_menu_item', $formatted );

					if ( $item->menu_item_parent != 0 ) {

						if ( array_key_exists( $item->menu_item_parent, $cache ) ) {
							array_push( $cache[ $item->menu_item_parent ], $formatted );
						} else {
							$cache[ $item->menu_item_parent ] = [ $formatted ];
						}

					} else {

						array_push( $rev_menu, $formatted );
					}

				endforeach;

				wp_cache_set( 'menu_location_' . $location, $rev_menu, self::get_cache_group(), DAY_IN_SECONDS );

			endif;

			return array_reverse( $rev_menu );
		}


		/**
		 * Returns all child nav_menu_items under a specific parent.
		 *
		 * @param int   $parent_id The parent nav_menu_item ID
		 * @param array $nav_menu_items Navigation menu items
		 * @param bool  $depth Gives all children or direct children only
		 *
		 * @return array    returns filtered array of nav_menu_items
		 * @since   1.2.0
		 */
		public function get_nav_menu_item_children( $parent_id, $nav_menu_items, $depth = true ) {

			$nav_menu_item_list = [];

			foreach ( (array) $nav_menu_items as $nav_menu_item ) :

				if ( $nav_menu_item->menu_item_parent == $parent_id ) :

					$nav_menu_item_list[] = $this->format_menu_item( $nav_menu_item, true, $nav_menu_items );

					if ( $depth ) {
						if ( $children = $this->get_nav_menu_item_children( $nav_menu_item->ID, $nav_menu_items ) ) {
							$nav_menu_item_list = array_merge( $nav_menu_item_list, $children );
						}
					}

				endif;

			endforeach;

			return $nav_menu_item_list;
		}


		/**
		 * Format a menu item for REST API consumption.
		 *
		 * @param object|array $menu_item The menu item
		 * @param bool         $children Get menu item children (default false)
		 * @param array        $menu The menu the item belongs to (used when $children is set to true)
		 *
		 * @return array    a formatted menu item for REST
		 * @since  1.2.0
		 */
		public function format_menu_item( $menu_item, $children = false, $menu = [] ) {

			$item = (array) $menu_item;

			$menu_item = [
				'id'          => abs( $item['ID'] ),
				'order'       => (int) $item['menu_order'],
				'parent'      => abs( $item['menu_item_parent'] ),
				'title'       => $item['title'],
				'url'         => $item['url'],
				'attr'        => $item['attr_title'],
				'target'      => $item['target'],
				'classes'     => implode( ' ', $item['classes'] ),
				'xfn'         => $item['xfn'],
				'description' => $item['description'],
				'object_id'   => abs( $item['object_id'] ),
				'object'      => $item['object'],
				'object_slug' => get_post( $item['object_id'] )->post_name,
				'type'        => $item['type'],
				'type_label'  => $item['type_label'],
			];

			if ( $children === true && ! empty( $menu ) ) {
				$menu_item['children'] = $this->get_nav_menu_item_children( $item['ID'], $menu );
			}

			return apply_filters( 'rest_menus_format_menu_item', $menu_item );
		}


	}


endif;
