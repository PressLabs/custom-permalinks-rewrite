<?php
/*
Plugin Name: Custom Permalinks Rewrite
Plugin URI:  https://www.presslabs.com
Description: The plugin allows for a different set of rewrite rules to be enforced starting with a configurable date.
Version:     1.1
Author:      Presslabs
Author URI:  https://www.presslabs.com
License:     GPL2
*/


class PL_Custom_Permalinks_Rewrite {

	/**
	 * Function which retrieves the singleton class instance.
	 */
	public static function get_instance() {
		static $instance = null;
		if ( $instance === null ) {
			$instance = new static();
		}

		return $instance;
	}


	private function __clone() {}
	private function __wakeup() {}


	/**
	 * The constructor function.
	 */
	protected function __construct() {
		// Register action which imposes permalink rewrites.
		add_action( 'init', array( $this, 'add_post_permalink_rewrite_rule' ) );
		// Register filter which alters the return of get_permalink().
		add_filter( 'post_link', array( $this, 'rewrite_post_permalink' ), 1, 3 );
		// Register the action which creates the configuration options.
		add_action( 'admin_init', array( $this, 'register_setting_in_permalinks_screen' ) );
		// Register the action which saves the configuration options to the DB.
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		// Register the action which displays an admin notice in case start date is left empty.
		add_action( 'admin_notices', array( $this, 'maybe_display_admin_notice' ) );
	}


	/**
	 * Add custom rewrite rules handling for posts from before rewrite start date.
	 */
	public function add_post_permalink_rewrite_rule() {
		global $wp_rewrite;
		$wp_rewrite->add_permastruct( 'post', '/%post%/', false );
		// Enforce a rewrite rule which avoids 301s on older posts.
		add_rewrite_rule( '^[0-9]{4}/[0-9]{2}/[0-9]{2}/([^/]+)/(.+)?/?', 'index.php?attachment=$matches[2]', 'top' );
		add_rewrite_rule( '^[0-9]{4}/[0-9]{2}/[0-9]{2}/(.+)/?', 'index.php?name=$matches[1]', 'top' );
	}


	/**
	 * Rewrite permalinks of posts from before rewrite start date.
	 *
	 * @param string $post_link
	 * @param object $post
	 * @param bool $leavename
	 */
	public function rewrite_post_permalink( $post_link, $post, $leavename ) {
		global $wp_rewrite;
		$custom_rewrite_settings = get_option( 'plcpr_settings' );

		/* Enforce old permalink structure if custom rewrite rules are
		 * enabled and if post creation date is before rewrite start date. */
		if ( ( $custom_rewrite_settings['rewrite_enabled'] == 'yes' ) &&
			( $custom_rewrite_settings['rewrite_start_date'] != '' ) &&
			( $post->post_date_gmt != '0000-00-00 00:00:00' ) &&
			( strtotime( $post->post_date_gmt ) < $custom_rewrite_settings['rewrite_start_date'] ) ) {

			// Proceed only if current post is published.
			$post_not_published = isset( $post->post_status ) && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ) );
			if ( $post_not_published and ! $leavename ) {
				return $post_link;
			}

			$year = date( 'Y', strtotime( $post->post_date_gmt ) );
			$month = date( 'm', strtotime( $post->post_date_gmt ) );
			$day = date( 'd', strtotime( $post->post_date_gmt ) );

			if ( $leavename ) {
				$permalink = home_url( "/$year/$month/$day/%postname%/"  );
			} else {
				$permalink = $wp_rewrite->get_extra_permastruct( 'post' );
				// Add the post title to the post link.
				$permalink = str_replace( '%post%', $year . '/' . $month . '/' . $day . '/' . $post->post_name, $permalink );
				// Create the permalink.
				$permalink = home_url( user_trailingslashit( $permalink ) );
			}
			return $permalink;
		} else {
			return $post_link;
		}
	}


	/**
	 * Register the settings section in the Permalinks screen.
	 */
	public function register_setting_in_permalinks_screen() {
		add_settings_section( 'plcpr_settings', 'Custom Permalinks Rewrite Settings', array( $this, 'generate_settings_section' ), 'permalink' );
	}


	/**
	 * Generate the HTML for the settings section in the Permalinks screen.
	 */
	public function generate_settings_section() {
		$plcpr_settings = get_option(
			'plcpr_settings',
			array(
				'rewrite_enabled'    => 'no',
				'rewrite_start_date' => '',
			)
		);
		$enabled_checked = ( $plcpr_settings['rewrite_enabled'] == 'yes' ) ? 'checked' : ''; ?>
        <table id="custom-permalinks-rewrite-settings" class="form-table">
            <tbody>
                <tr>
                    <th><label>Enable Custom Rewrite</label></th>
                    <td>
                        <input type="hidden" name="plcpr_settings[rewrite_enabled]" value="no" />
                        <input type="checkbox" name="plcpr_settings[rewrite_enabled]" value="yes" <?php echo $enabled_checked; ?> />
                    </td>
                </tr>
                <tr>
                    <th><label>Rewrite Start Date</label></th>
                    <td><input type="date" name="plcpr_settings[rewrite_start_date]" value="<?php echo date( 'Y-m-d', $plcpr_settings['rewrite_start_date'] ); ?>" /></td>
                </tr>
            </tbody>
        </table>
	<?php
	}

	/**
	 * Sanitize the plugin settings.
	 */
	public function maybe_save_settings() {
		if ( isset( $_POST['submit'] ) && isset( $_POST['plcpr_settings'] ) ) {
			if ( empty( $_POST['plcpr_settings']['rewrite_start_date'] ) ) {
				$start_date = '';
			} else {
				$start_date = strtotime( $_POST['plcpr_settings']['rewrite_start_date'] );
			}
			update_option(
				'plcpr_settings',
				array(
					'rewrite_enabled'    => $_POST['plcpr_settings']['rewrite_enabled'],
					'rewrite_start_date' => $start_date,
				)
			);
		}
	}


	/**
	 * Display an admin notice on options save in case the
	 * custom rewrite functionality is enabled, but the
	 * start date field was left empty.
	 */
	function maybe_display_admin_notice() {
		$plcpr_settings = get_option(
			'plcpr_settings',
			array(
				'rewrite_enabled'    => 'no',
				'rewrite_start_date' => '',
			)
		);
		if ( ( $plcpr_settings['rewrite_enabled'] == 'yes' ) &&
			( $plcpr_settings['rewrite_start_date'] == '' ) ):
			// Add link to permalinks view if not on the permalinks screen.
			$current_screen = get_current_screen();
			if ( $current_screen->id != 'options-permalink' ) {
				$link_to_permalinks_screen = ' <a href="' . admin_url( 'options-permalink.php' ) . '#custom-permalinks-rewrite-settings"> Edit the custom permalinks rewrite settings.</a>';
			} ?>
            <div class="error">
                <p>Please specify the "Rewrite Start Date" in order to enable the custom rewrite rules.<?php echo $link_to_permalinks_screen; ?></p>
            </div>
		<?php
		endif;
	}
}


/**
 * Initialize the plugin functionality.
 */
PL_Custom_Permalinks_Rewrite::get_instance();
