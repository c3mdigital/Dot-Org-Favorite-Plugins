<?php
/*
Plugin Name: Favorite Plugins Widget
Plugin URI: http://plugins.wordpress.org/favorite-plugin-widget
Description: Show your favorite plugins from your WordPress.org profile along with author and star ratings
Version: 1.0
Author: Chris Olbekson
Author URI: http://c3mdigital.com/
License: GPL v2
*/


/*  Copyright 2012  Chris Olbekson  (email : chris@c3mdigital.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Special thanks to Otto for adding favorites to the WordPress.org plugin API.
 * In 3.5 you will also be able to access your favorites directly from the dashboard plugin installer
 */

class C3M_Favorite_Plugins {
	static $instance;
	const VERSION = '1.0';
	const CRON_HOOK = 'c3m_favorite_plugins';

	function __construct() {
		self::$instance = $this;
		add_action( 'widgets_init', 'c3m_register' );
		add_action( 'wp_footer', array( $this, 'css' ) );
	}

	function api( $action, $args ) {
		if ( is_array( $args ) )
			$args = (object) $args;

		$request = wp_remote_post('http://api.wordpress.org/plugins/info/1.0/', array( 'timeout' => 15, 'body' => array('action' => $action, 'request' => serialize($args))) );
		if ( is_wp_error($request) ) {
			$res = new WP_Error('plugins_api_failed', __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="http://wordpress.org/support/">support forums</a>.' ), $request->get_error_message() );
		} else {
			$res = maybe_unserialize( wp_remote_retrieve_body( $request ) );
			if ( ! is_object( $res ) && ! is_array( $res ) )
				$res = new WP_Error('plugins_api_failed', __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="http://wordpress.org/support/">support forums</a>.' ), wp_remote_retrieve_body( $request ) );
		}

		return apply_filters( 'c3m_favorite_results', $res, $action, $args );
	}

	function css() {
		?>
		<style type="text/css">
			div.star-holder {
                position:   relative;
                height:     17px;
                width:      100px;
                background: url(<?php echo admin_url( '/images/stars.png?ver=20120307' ); ?>) repeat-x bottom left;
                }

            div.star-holder .star-rating {
                background: url(<?php echo admin_url( '/images/stars.png?ver=20120307' ); ?>) repeat-x top left;
                height:     17px;
                float:      left;
                }
		</style>
			<?php

	}
}

$c3m_plugins  = new C3M_Favorite_Plugins();


class C3M_My_Favs extends WP_Widget {

	function __construct() {
		$widget_ops = array ( 'classname' => 'my_plugins', 'description' => 'Displays favorite plugins from your WordPress.org profile' );
		$this->WP_Widget( 'my_plugins', 'Favorite Plugins', $widget_ops );

	}

	function widget( $args, $instance ) {
		extract( $args, EXTR_SKIP );
		global $c3m_plugins;

			/** The plugins API returns an object of your favorite plugins.  Lets store it as a transient so we don't kill .org and slow down your pages  */

			if ( false == get_transient( '_c3m_favorite_plugins' ) ) {
				$wp_user = $instance['wp_user'];
				$api_data = $c3m_plugins->api( 'query_plugins', array( 'user' => $wp_user ) );

				set_transient( '_c3m_favorite_plugins', $api_data, 60*60*12 );
			}

		$title = $instance['title'];
		$per_page = $instance['per_page'];
		$stars = (bool)$instance['stars'];
		$authors = (bool)$instance['authors'];

		$api_data = get_transient( '_c3m_favorite_plugins' );

		$api_plugins = $api_data->plugins;

		/**
		 * @var string $before_widget defined by theme
		 * @see register_sidebar()
		 */
		echo $before_widget;

		/**
		 * @var string $before_title defined by theme @see register_sidebar()
		 * @var string $after_title
		 */
		echo $before_title . apply_filters( 'widget_title', $title ) . $after_title;
		echo '<ul class="c3m-favorites">';
		$c = 0;
		foreach( $api_plugins as $plugin ) {
			$c++;
			if ( $c > $per_page )
				continue;

			$name = $plugin->name; ?>
			<li><strong><a target="_blank" href="http://wordpress.org/extend/plugins/<?php echo $plugin->slug ?>/"><?php echo esc_html( $name ); ?></a></strong><br>
			<?php if( $stars ) : ?>
				<div class="star-holder" title="<?php printf( _n( '(based on %s rating)', '(based on %s ratings)', $plugin->num_ratings ), number_format_i18n( $plugin->num_ratings ) ); ?>">
				<div class="star star-rating" style="width: <?php echo esc_attr( str_replace( ',', '.', $plugin->rating ) ); ?>px"></div></div>
			<?php endif; ?>
			<?php if( $authors ) : ?>
				<em><?php _e('By: ') ?></em> <?php echo links_add_target( $plugin->author, '_blank' ). '<br>';
			endif; ?>
			</li><?php
		}
		echo '</ul>';

		/**
		 * @var string $after_widget defined by theme
		 * @see register_sidebar()
		 */
		echo $after_widget;

	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title']  );
		$instance['wp_user'] = strip_tags( strtolower( $new_instance['wp_user'] ) );
		$instance['per_page'] = absint( $new_instance['per_page'] );
		$instance['stars'] = (bool)$new_instance['stars'];
		$instance['authors'] = (bool)$new_instance['authors'];

		delete_transient( '_c3m_favorite_plugins' );

		return $instance;

	}

	function form( $instance ) {

		$title = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';
		$wp_user = isset( $instance['wp_user'] ) ? esc_attr( strtolower( $instance['wp_user']) ) : '';
		$per_page  = isset( $instance['per_page'] ) ? absint( $instance['per_page'] ) : 5;
		$stars = isset( $instance['stars'] ) ? (bool)$instance['stars'] : true;
		$authors = isset( $instance['authors'] ) ? (bool)$instance['authors'] : true;
		?>

		<p><label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
		<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>"/></p>

		<p><label for="<?php echo $this->get_field_id( 'wp_user' ); ?>"><?php _e( 'WordPress.org username:' ); ?></label>
		<input class="widefat" id="<?php echo $this->get_field_id( 'wp_user' ); ?>" name="<?php echo $this->get_field_name( 'wp_user' ); ?>" type="text" value="<?php echo esc_attr( $wp_user ); ?>"/></p>

		<p><label for="<?php echo $this->get_field_name( 'per_page' ); ?>"><?php _e( 'Number of plugins to show:' ); ?></label>
		<input id="<?php echo esc_attr( $this->get_field_id( 'per_page' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'per_page' ) ); ?>" type="text" value="<?php echo  (int)$per_page; ?>" size="3" /></p>

		<p><input class="checkbox" type="checkbox" <?php checked( $stars ); ?> id="<?php echo $this->get_field_id( 'stars' ); ?>" name="<?php echo $this->get_field_name( 'stars' ); ?>" />
		<label for="<?php echo $this->get_field_id( 'stars' ); ?>"><?php _e( 'Display Star Ratings?' ); ?></label></p>

        <p><input class="checkbox" type="checkbox" <?php checked( $authors ); ?> id="<?php echo $this->get_field_id( 'authors' ); ?>" name="<?php echo $this->get_field_name( 'authors' ); ?>"/>
		<label for="<?php echo $this->get_field_id( 'authors' ); ?>"><?php _e( 'Display Plugin Authors?' ); ?></label></p>

	<?php }

 }

function c3m_register() {
	register_widget( 'C3M_My_Favs' );
}