<?php
/**
 * WordPress Beta Tester
 *
 * @package WordPress_Beta_Tester
 * @author Andy Fragen, original author Peter Westwood.
 * @license GPLv2+
 * @copyright 2009-2016 Peter Westwood (email : peter.westwood@ftwr.co.uk)
 */

/**
 * Class WPBT_Beta_RC
 * Author: Paul V. Biron/Sparrow Hawk Computing
 * Author Email: paul@sparrowhawkcomputing.com
 * Author URL: https://sparrowhawkcomputing.com
 */

defined( 'ABSPATH' ) || die;

/**
 * Class to modify the response to the Core Update API to include the next beta/RC
 * package if it is available.
 *
 * This feature is experimental as the API does not offer beta/RC packages directly.
 *
 * @since 2.2.0
 */
class WPBT_Beta_RC {
	/**
	 * Regular expression for extracting the components of `$wp_version` string.
	 *
	 * The subpatterns are as follows:
	 *
	 * 1. The first is the WP version number (e.g., 5.2).
	 * 2. The second is the minor version number (e.g., .3).
	 *    This subpattern is optional because WP uses versions numbers like
	 *    5.3 instead of 5.3.0.
	 * 3. The third is whether the release is an alpha, beta or RC.
	 * 4. The forth is the number of the beta or RC release (e.g., 1st beta, 2nd RC, etc).
	 *    If site is running a "bleeding edge" version, this will also contain the
	 *    commit number, e.g., after RC3, will be something like 3-1234).
	 *
	 * @since 2.2.0
	 *
	 * @var string
	 */
	const VERSION_REGEX = '^(\d+\.\d+(\.\d+)?)-(alpha|beta|RC)(\d+|\d*-\d+)?$';

	/**
	 * Sprintf pattern for beta/RC download URLs.
	 *
	 * The variables in the pattern (e.g., %1$s) are as follows:
	 *
	 * 1. the version number (e.g., 5.3)
	 * 2. the package type (e.g., beta or RC)
	 * 3. the release number (e.g., 2 for RC2).
	 *
	 * @since 2.2.0
	 *
	 * @var string
	 */
	const DOWNLOAD_URL_PATTERN = 'https://wordpress.org/wordpress-%1$s-%2$s%3$d.zip';

	/**
	 * Used to store the URL(s) for the next beta/RC download packages.
	 *
	 * @since 2.2.0
	 *
	 * @var array
	 */
	protected $next_package_urls = array();

	/**
	 * Whether we found the next beta/RC package.
	 *
	 * @since 2.2.0
	 *
	 * @var bool|string Will be boolean false if the next beta/RC package was not found,
	 *                  or the version of the package (as a string) otherwise.
	 */
	protected $found = false;

	/**
	 * Constructor.
	 *
	 * Adds the {@link https://developer.wordpress.org/reference/hooks/http_response/ http_response}
	 * hook callback.
	 *
	 * Also generates the URLs for the next beta/RC download packages.  We do that here so that
	 * we don't have to do it inside the `http_response` callback, which would slow down handling
	 * of Core Update API requests.
	 *
	 * @since 2.2.0
	 */
	public function __construct() {
		$this->load_hooks();
		$this->get_next_packages();
	}

	/**
	 * Load hooks for Beta/RC.
	 *
	 * @return void
	 */
	protected function load_hooks() {
		add_filter( 'http_response', array( $this, 'update_to_beta_or_rc_releases' ), 10, 3 );
		// set priority to 11 so that we fire after the function core hooks into this filter.
		add_filter( 'update_footer', array( $this, 'update_footer' ), 11 );
	}

	/**
	 * Get next available package URLs.
	 *
	 * @return array Keys are the packages (e.g., RC3), values are the download URLs.
	 */
	protected function get_next_packages() {
		$wp_version = get_bloginfo( 'version' );
		// Exit early if not currently on a development branch.
		if ( ! preg_match( '/alpha|beta|RC/', $wp_version ) ) {
			return array( __( 'next release version', 'wordpress-beta-tester' ) => false );
		}

		// extract the parts of the version the site is running.
		$matches = array();
		preg_match( '@' . self::VERSION_REGEX . '@', $wp_version, $matches );

		// see the DocBlock of self::$version_regex for what those parts are.
		$version = $matches[1];

		$package_type = $matches[3];
		$next         = isset( $matches[4] ) ? absint( $matches[4] ) + 1 : null;

		$this->next_package_urls[ $version ] = "https://wordpress.org/wordpress-{$version}.zip";

		// construct the URLs for the next beta/RC release.
		switch ( $package_type ) {
			case 'alpha':
				// when running alpha, we check for both the first beta and the first RC.
				// we check for RC's because some releases never have betas (e.g., 5.0.2,
				// 5.0.3, 5.1.1, 5.2.2, 5.2.3).
				// check the RC1 package first.
				$this->next_package_urls[ "{$version}-RC1" ]   = sprintf( self::DOWNLOAD_URL_PATTERN, $version, 'RC', 1 );
				$this->next_package_urls[ "{$version}-beta1" ] = sprintf( self::DOWNLOAD_URL_PATTERN, $version, 'beta', 1 );

				break;
			case 'beta':
				// when running a beta, we check for both the next beta and the first RC.
				// check the RC1 package first.
				$this->next_package_urls[ "{$version}-RC1" ]         = sprintf( self::DOWNLOAD_URL_PATTERN, $version, 'RC', 1 );
				$this->next_package_urls[ "{$version}-beta{$next}" ] = sprintf( self::DOWNLOAD_URL_PATTERN, $version, 'beta', $next );

				break;
			case 'RC':
				// when running an RC, we just check for the next RC.
				// @todo has it ever happened that once RC1 was release that another beta was released?
				// I don't think so, but if that is a possibility then we'll need to account
				// for that possibility?
				$this->next_package_urls[ "{$version}-RC{$next}" ] = sprintf( self::DOWNLOAD_URL_PATTERN, $version, 'RC', $next );

				break;
		}

		return $this->next_package_urls;
	}

	/**
	 * Modify the repsonse from WP Core Update API requests to only show the
	 * next Beta/RC (and the latest stable release) package.
	 *
	 * @since 2.2.0
	 *
	 * @param array  $response HTTP response.
	 * @param array  $parsed_args HTTP request arguments.
	 * @param string $url The request URL.
	 * @return array
	 *
	 * @filter http_response
	 */
	public function update_to_beta_or_rc_releases( $response, $parsed_args, $url ) {
		if ( is_wp_error( $response ) ||
				! preg_match( '@^https?://api.wordpress.org/core/version-check/@', $url ) ||
				200 !== wp_remote_retrieve_response_code( $response ) ) {
			// not a successful core update API request.
			// nothing to do, so bail.
			return $response;
		}

		$options = get_site_option(
			'wp_beta_tester',
			array(
				'stream' => 'point',
				'revert' => true,
			)
		);
		if ( 0 !== strpos( $options['stream'], 'beta-rc' ) ) {
			return $response;
		}

		// get the response body as an array.
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// loop through the next beta/RC download URLs and see if a package exists
		// at any of them.
		$this->found = false;
		foreach ( $this->next_package_urls as $version => $next_package_url ) {
			if ( ! $this->next_package_exists( $next_package_url ) ) {
				continue;
			}

			// the next beta/RC package was found.
			// Modify the development and autoupdate offers to use the URL
			// of that next beta/RC release.
			// @todo are there any cases where we'd need to modify other offers?
			// I don't know the core update API well enough to know.
			foreach ( $body['offers'] as &$offer ) {
				switch ( $offer['response'] ) {
					case 'development':
					case 'autoupdate':
						$offer['download'] = $next_package_url;
						$offer['current']  = $offer['version'] = $version;

						foreach ( $offer['packages'] as $package => &$package_url ) {
							$package_url = 'full' === $package ? $next_package_url : false;
						}

						$this->found = $version;

						break;
				}
			}

			break;
		}

		if ( ! $this->found ) {
			// the next beta/RC release package was not found.
			// remove the development and autoupdate offers.
			$body['offers'] = array_diff_key(
				$body['offers'],
				wp_list_filter( $body['offers'], array( 'response' => 'development' ) ),
				wp_list_filter( $body['offers'], array( 'response' => 'autoupdate' ) )
			);
		}

		// re-json encode the body.
		$response['body'] = wp_json_encode( $body );

		return $response;
	}

	/**
	 * Check whether the next beta/RC package exists.
	 *
	 * Note: having this check enscapsulated in a method is for 2 reasons:
	 *
	 * 1. to avoid weird variable name for the `$respsonse` to this question,
	 *    since the update_to_beta_or_rc_releases() is passed the a variable named
	 *    `$response`.
	 * 2. The `wp_remote_head()` calls we do will *often* result in 404s (and
	 *    that is perfectly OK).  However, if the
	 *    {@link https://wordpress.org/plugins/query-monitor/ Query Minitor} plugin
	 *    is active, it will report those 404s are errors (by turning it's Admin Bar node
	 *    bright red) and that could be very disconcerting to even the type of user
	 *    who is likely the want the functionality of this plugin.  I have opened an
	 *    {@link https://github.com/johnbillion/query-monitor/issues/474 issue} to
	 *    add a new hook that would allow us to tell QM to ignore those 404s.  Having
	 *    this check in its own method will make it easier to add that hook when/if
	 *    it is supported by QM.
	 *
	 * @since 2.2.0
	 *
	 * @param string $url URL of a beta/RC release package.
	 * @return bool       True if the package at `$url` exists, false otherwise.
	 */
	private function next_package_exists( $url ) {
		// note: adding this filter will be a no-op until a version of QM that supports
		// https://github.com/johnbillion/query-monitor/issues/474 is released.
		add_filter( 'qm/collect/silent_http_error_statuses', array( $this, 'qm_silence_404s' ), 10, 2 );

		$response = wp_remote_head( $url );

		remove_filter( 'qm/collect/silent_http_error_statuses', array( $this, 'qm_silence_404s' ) );

		return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Ensure core still displays "You are using a development verison..." in the admin
	 * footer, even if we've removed the `development` update response because the next
	 * beta/RC package is not available.
	 *
	 * @since 2.2.0
	 *
	 * @param string $content The content that will be printed.
	 * @return string
	 *
	 * @filter update_footer
	 */
	public function update_footer( $content = '' ) {
		if ( $this->found ) {
			// we found the next beta/RC package, so no need to "fake" the
			// footer message.
			// nothing to do, so bail.
			return $content;
		}

		add_filter( 'pre_site_transient_update_core', array( $this, 'add_minimal_development_response' ), 10, 2 );

		$content = core_update_footer();

		remove_filter( 'pre_site_transient_update_core', array( $this, 'add_minimal_development_response' ) );

		return $content;
	}

	/**
	 * Add a minimal development response as the preferred update.
	 *
	 * @since 2.2.0
	 *
	 * @param mixed  $pre_site_transient The default value to return if the site
	 *                                   transient does not exist. Any value other
	 *                                   than false will short-circuit the retrieval
	 *                                   of the transient, and return the returned value.
	 * @param string $transient          Transient name.
	 * @return object
	 *
	 * @filter pre_site_transient_update_core
	 */
	public function add_minimal_development_response( $pre_site_transient, $transient ) {
		$from_api = new stdClass();
		$update   = new stdClass();
		// a "minimal" response is one with the `response`, `current` and
		// `locale` properties.
		$update->response = 'development';
		$update->current  = get_bloginfo( 'version' );
		$update->locale   = get_locale();

		$from_api->updates = array(
			$update,
		);

		return $from_api;
	}

	/**
	 * Silence Query Monitor red banner for 404s.
	 *
	 * @since 2.2.0
	 *
	 * @param array $silenced QM HTTP codes to be silenced.
	 * @param array $http     QM "HTTP" request object.
	 * @return int[]          Array of HTTP Status Codes to be silenced.
	 */
	public function qm_silence_404s( $silenced, $http ) {
		$silenced[] = 404;

		return $silenced;
	}

	/**
	 * Get version of the next beta/RC package found.
	 *
	 * @since 2.2.0
	 *
	 * @return bool|string Will be boolean false if the next beta/RC package was not found,
	 *                  or the version of the package (as a string) otherwise.
	 */
	public function get_found_version() {
		return $this->found;
	}

	/**
	 * Get the versions of the beta/RC packages we will look for.
	 *
	 * @since 2.2.0
	 *
	 * @return string[]
	 */
	public function next_package_versions() {
		return array_keys( $this->next_package_urls );
	}
}
