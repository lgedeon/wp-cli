<?php

class Menu_Command extends WP_CLI_Command {

    /**
     * Handle menu import cli command and call import_json() to import menu content from a json file.
	 *
	 * Still soo much to do:
	 * - (maybe) support pasting a json object on the commandline instead of file name
	 * - (maybe) incorporate into main cli import and export commands
	 * - add wp-admin ui to call functions without setting up wp-cli
	 * - support mode and missing parameters
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a valid json file for importing.
	 *
	 * json object should be in the form:
	 * [
	 *   {
	 *     location : "theme location menu should be assigned to (optional)"
	 *     name : "Menu Name"
	 *     items :
	 *     [
	 *       {
	 *         slug : "required-for-nested-menu--always-recommended"
	 *         parent : "parent-menu-item-slug--parent-must-be-defined-before-children"
	 *         title : "Not always required but highly recommended"
	 *         page : "only-if-menu-points-to-page"
	 *         taxonomy : "only_if_pointing_to_term"
	 *         term : "the Term"
	 *         url : "http://domain.com/fully/qualified/" OR "/relative/"
	 *       },
	 *       { ... additional menu items ... }
	 *     ]
	 *   },
	 *   { ... additional menus ... }
	 * ]
	 *
	 * <mode>
	 * : update = matching menus and menu items overwritten. skip = matching items skipped, missing items skipped. append = matching skipped, new items added
	 *
     * <missing>
     * : Method for handling missing objects pointed to by menu. Can be 'create', 'skip', 'default'.
	 *
	 * <default>
	 * : page to point to if matching slug isn't found. If default slug doesn't exist either menu items will be skipped.
     *
     * @synopsis <file> [--mode=<mode>] [--missing=<missing>] [--default=<default>]
     */

    public function import ( $args, $assoc_args ) {
        list( $file ) = $args;

        if ( ! file_exists( $file ) )
            WP_CLI::error( "File to import doesn't exist." );

        $defaults = array(
            'missing' => 'skip',
            'default' => null,
        );
        $assoc_args = wp_parse_args( $assoc_args, $defaults );

		$ret = $this->import_json( $file, $assoc_args['missing'], $assoc_args['default'] );

		if ( is_wp_error( $ret ) ) {
			WP_CLI::error( $ret->get_error_message() );
		} else {
			WP_CLI::line();
			WP_CLI::success( "Import complete." );
		}
	}

	/**
	 * Import menu content from a json file.
	 *
	 * @param string $file Name of json file to import. (might allow just passing the json string here later)
	 * @param string $missing
	 * @param string $default
	 */
	public function import_json( $file, $mode = 'append', $missing = 'skip', $default = null ) {
		$string      = file_get_contents( $file );
		$json_object = json_decode( $string );

		// $json object may contain a single menu definition object or array of menu objects
		if ( ! is_array( $json_object ) ) {
			$json_object = array( $json_object );
		}

		$locations = get_nav_menu_locations();

		foreach ( $json_object as $menu ) :
			if ( isset( $menu->location ) && isset( $locations[ $menu->location ] ) ) :
				$menu_id = $locations[ $menu->location ];
			elseif ( isset( $menu->name ) ) :
				// If we can't find a menu by this name, create one.
				if ( $menu_lookup = wp_get_nav_menu_object( $menu->name ) ) :
					$menu_id = $menu_lookup->ID;
				else :
					$menu_id = wp_create_nav_menu( $menu->name );
				endif;
			else : // if no location or name is supplied, we have nowhere to put an additional info in this object.
				continue;
			endif;

			$new_menu = array();

			if ( isset ( $menu->items ) && is_array( $menu->items ) ) : foreach ( $menu->items as $item ) :

				// merge in existing items here

				// Build $item_array from supplied data
				$item_array = array(
					'menu-item-title' => ( isset( $item->title ) ? $item->title : false ),
					'menu-item-status' => 'publish'

				);

				if ( isset( $item->page ) && $page = get_page_by_path( $item->page ) ) { // @todo support lookup by title
					$item_array['menu-item-object']    = 'page';
					$item_array['menu-item-type']      = 'post_type';
					$item_array['menu-item-object-id'] = $page->ID;
					$item_array['menu-item-title']     = ( $item_array['menu-item-title'] ) ?: $page->post_title;
				} elseif ( isset ( $item->taxonomy ) && isset( $item->term ) ) {

				} elseif ( isset( $item->url ) ) {
					$item_array['menu-item-url']   = ( 'http' == substr( $item->url, 0, 4 ) ) ? esc_url( $item->url ) : home_url( $item->url );
					$item_array['menu-item-title'] = ( $item_array['menu-item-title'] ) ?: $item->url;
				} else {
					continue;
				}

				$slug  = isset( $item->slug ) ? $item->slug : sanitize_title_with_dashes( $item_array['menu-item-title'] );
				$new_menu[$slug] = array();

				if ( isset( $item->parent ) ) {
					$new_menu[$slug]['parent']         = $item->parent;
					$item_array['menu-item-parent-id'] = $new_menu[ $item->parent ]['id'];
				}

				$new_menu[$slug]['id'] = wp_update_nav_menu_item($menu_id, 0, $item_array );

			endforeach; endif;



		endforeach;
	}
}

WP_CLI::add_command( 'menu', new Menu_Command );