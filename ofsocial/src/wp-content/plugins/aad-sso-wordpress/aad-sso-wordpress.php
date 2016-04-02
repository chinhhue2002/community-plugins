<?php

/*
Plugin Name: Single Sign-on with Azure Active Directory
Plugin URI: http://github.com/psignoret/aad-sso-wordpress
Description: Allows you to use your organizations Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Azure Active Directory Graph to get group membership and other details.
Author: Philippe Signoret
Version: 0.6a
Author URI: http://psignoret.com/
Text Domain: aad-sso-wordpress
Domain Path: /languages/
*/

$openfire = new Java("org.jivesoftware.openfire.plugin.ofsocial.PHP2Java");
$openfire->of_logInfo("aad-sso-wordpress 1 " . plugin_dir_url( __FILE__ ) . " " . plugin_dir_path( __FILE__ ));

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'AADSSO', 'aad-sso-wordpress' );
define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Proxy to be used for calls, should be useful for tracing with Fiddler
// BUGBUG: Doesn't actually work, at least not with WP running on WAMP stack
//define( 'WP_PROXY_HOST', '127.0.0.1' );
//define( 'WP_PROXY_PORT', '8888' );

require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/SettingsPage.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/GraphHelper.php';

$openfire->of_logInfo("aad-sso-wordpress 2 " . $__FILE__);

// TODO: Auto-load the ( the exceptions at least )
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Authentication/JWT.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Exceptions/BeforeValidException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Exceptions/ExpiredException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/Exceptions/SignatureInvalidException.php';

$openfire->of_logInfo("aad-sso-wordpress 3 " . $__FILE__);

class AADSSO {

	static $instance = FALSE;

	private $settings = null;

	public function __construct( $settings ) {
		$this->settings = $settings;

		// Setup the admin settings page
		$this->setup_admin_settings();

		// Some debugging locations
		//add_action( 'admin_notices', array( $this, 'print_debug' ) );
		//add_action( 'login_footer', array( $this, 'print_debug' ) );

		// Add a link to the Settings page in the list of plugins
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'add_settings_link' )
		);

		// Register activation and deactivation hooks
		register_activation_hook( __FILE__, array( 'AADSSO', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'AADSSO', 'deactivate' ) );

		// If plugin is not configured, we shouldn't proceed.
		if ( ! $this->plugin_is_configured() ) {
			add_action( 'all_admin_notices', array( $this, 'print_plugin_not_configured' ) );
			return;
		}

		// Add the hook that starts the SESSION
		add_action( 'init', array( $this, 'register_session' ) );

		// The authenticate filter
		add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 3 );

		// Add the <style> element to the login page
		add_action( 'login_enqueue_scripts', array( $this, 'print_login_css' ) );

		// Add the link to the organization's sign-in page
		add_action( 'login_form', array( $this, 'print_login_link' ) ) ;

		// Clear session variables when logging out
		add_action( 'wp_logout', array( $this, 'clear_session' ) );

		// If configured, bypass the login form and redirect straight to AAD
		add_action( 'login_init', array( $this, 'save_redirect_and_maybe_bypass_login' ) );

		// Redirect user back to original location
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );

		// Register the textdomain for localization after all plugins are loaded
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Run on activation, checks for stored settings, and if none are found, sets defaults.
	 */
	public static function activate() {
		$stored_settings = get_option( 'aadsso_settings', null );
		if ( null === $stored_settings ) {
			update_option( 'aadsso_settings', AADSSO_Settings::get_defaults() );
		}
	}

	/**
	 * Run on deactivation, currently does nothing.
	 */
	public static function deactivate() { }

	/**
	 * Load the textdomain for localization.
	 */
	public function load_textdomain()
	{
		load_plugin_textdomain(
			AADSSO,
			false, // deprecated
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Determine if required plugin settings are stored.
	 *
	 * @return bool Whether plugin is configured
	 */
	public function plugin_is_configured() {
		return
			   ! empty( $this->settings->client_id )
			&& ! empty( $this->settings->client_secret )
			&& ! empty( $this->settings->redirect_uri )
		;
	}

	/**
	 * Gets the (only) instance of the plugin. Initializes an instance if it hasn't yet.
	 *
	 * @return \AADSSO The (only) instance of the class.
	 */
	public static function get_instance( $settings ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $settings );
		}
		return self::$instance;
	}

	/**
	 * Based on settings and current page, bypasses the login form and forwards straight to AAD.
	 */
	public function save_redirect_and_maybe_bypass_login() {

		$bypass = apply_filters(
			'aad_auto_forward_login',
			$this->settings->enable_auto_forward_to_aad
		);

		/*
		 * If the user is attempting to log out AND the auto-forward to AAD
		 * login is set then we need to ensure we do not auto-forward the user and get
		 * them stuck in an infinite logout loop.
		 */
		if( $this->wants_to_login() ) {

			// Save the redirect_to query param ( if present ) to session
			if ( isset( $_GET['redirect_to'] ) ) {
				$_SESSION['aadsso_redirect_to'] = $_GET['redirect_to'];
			}

			if ( $bypass && ! isset( $_GET['code'] ) ) {
				wp_redirect( $this->get_login_url() );
				die();
			}
		}
	}

	/**
	 * Restores the session variable that stored the original 'redirect_to' so that after
	 * authenticating with AAD, the user is returned to the right place.
	 *
	 * @param string $redirect_to
	 * @param string $requested_redirect_to
	 * @param WP_User|WP_Error $user
	 *
	 * @return string
	 */
	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_a( $user, 'WP_User' ) && isset( $_SESSION['aadsso_redirect_to'] ) ) {
			$redirect_to = $_SESSION['aadsso_redirect_to'];
		}

		return $redirect_to;
	}

	/**
	* Checks to determine if the user wants to login on wp-login.
	*
	* This function mostly exists to cover the exceptions to login
	* that may exist as other parameters to $_GET[action] as $_GET[action]
	* does not have to exist. By default WordPress assumes login if an action
	* is not set, however this may not be true, as in the case of logout
	* where $_GET[loggedout] is instead set
	*
	* @return boolean Whether or not the user is trying to log in to wp-login.
	*/
	private function wants_to_login() {
		$wants_to_login = false;
		// Cover default WordPress behavior
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
		// And now the exceptions
		$action = isset( $_GET['loggedout'] ) ? 'loggedout' : $action;
		if( 'login' == $action ) {
			$wants_to_login = true;
		}
		return $wants_to_login;
	}

	/**
	 * Authenticates the user with Azure AD and WordPress.
	 *
	 * This method, invoked as an 'authenticate' filter, implements the OpenID Connect
	 * Authorization Code Flow grant to sign the user in to Azure AD (if they aren't already),
	 * obtain an ID Token to identify the current user, and obtain an Access Token to access
	 * the Azure AD Graph API.
	 *
	 * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
	 * @param string $username The username provided during form-based signing. Not used.
	 * @param string $password The password provided during form-based signing. Not used.
	 *
	 * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.
	 */
	function authenticate( $user, $username, $password ) {

		// Don't re-authenticate if already authenticated
		if ( is_a( $user, 'WP_User' ) ) { return $user; }

		/* If 'code' is present, this is the Authorization Response from Azure AD, and 'code' has
		 * the Authorization Code, which will be exchanged for an ID Token and an Access Token.
		 */
		if ( isset( $_GET['code'] ) ) {

			$antiforgery_id = $_SESSION['aadsso_antiforgery-id'];
			$state_is_missing = ! isset( $_GET['state'] );
			$state_doesnt_match = $_GET['state'] != $antiforgery_id;

			if ( $state_is_missing || $state_doesnt_match ) {
				return new WP_Error(
					'antiforgery_id_mismatch',
					sprintf( __( 'ANTIFORGERY_ID mismatch. Expecting %s', AADSSO ), $antiforgery_id )
				);
			}

			// Looks like we got a valid authorization code, let's try to get an access token with it
			$token = AADSSO_AuthorizationHelper::get_access_token( $_GET['code'], $this->settings );

			// Happy path
			if ( isset( $token->access_token ) ) {

				try {
					$jwt = AADSSO_AuthorizationHelper::validate_id_token(
						$token->id_token,
						$this->settings,
						$antiforgery_id
					);
				} catch ( Exception $e ) {
					return new WP_Error(
						'invalid_id_token',
						sprintf( __( 'ERROR: Invalid id_token. %s', AADSSO ), $e->getMessage() )
					);
				}

				// Invoke any configured matching and auto-provisioning strategy and get the user.
				$user = $this->get_wp_user_from_aad_user( $jwt );

				if ( is_a( $user, 'WP_User' ) ) {

					// At this point, we have an authorization code, an access token and the user
					// exists in WordPress (either because it already existed, or we created it
					// on-the-fly). All that's left is to set the roles based on group membership.
					if ( true === $this->settings->enable_aad_group_to_wp_role ) {
						$user = $this->update_wp_user_roles( $user, $jwt->upn, $jwt->tid );
					}
				}

			} elseif ( isset( $token->error ) ) {

				// Unable to get an access token ( although we did get an authorization code )
				return new WP_Error(
					$token->error,
					sprintf(
						__( 'ERROR: Could not get an access token to Azure Active Directory. %s', AADSSO ),
						$token->error_description
					)
				);
			} else {

				// None of the above, I have no idea what happened.
				return new WP_Error( 'unknown', __( 'ERROR: An unknown error occured.', AADSSO ) );
			}

		} elseif ( isset( $_GET['error'] ) ) {

			// The attempt to get an authorization code failed.
			return new WP_Error(
				$_GET['error'],
				sprintf(
					__( 'ERROR: Access denied to Azure Active Directory. %s', AADSSO ),
					$_GET['error_description']
				)
			);
		}

		return $user;
	}

	function get_wp_user_from_aad_user( $jwt ) {

		// Try to find an existing user in WP where the UPN of the current AAD user is
		// (depending on config) the 'login' or 'email' field
		$user = get_user_by( $this->settings->field_to_match_to_upn, $jwt->upn );

		if ( ! is_a( $user, 'WP_User' ) ) {

			// Since the user was authenticated with AAD, but not found in WordPress,
			// need to decide whether to create a new user in WP on-the-fly, or to stop here.
			if( true === $this->settings->enable_auto_provisioning ) {

				// Setup the minimum required user data
				// TODO: Is null better than a random password?
				// TODO: Look for otherMail, or proxyAddresses before UPN for email
				$userdata = array(
					'user_email' => $jwt->upn,
					'user_login' => $jwt->upn,
					'first_name' => $jwt->given_name,
					'last_name'	=> $jwt->family_name,
					'user_pass'	=> null
				);

				$new_user_id = wp_insert_user( $userdata );

				$user = new WP_User( $new_user_id );
			} else {

				// The user was authenticated, but not found in WP and auto-provisioning is disabled
				return new WP_Error(
					'user_not_registered',
					sprintf(
						__( 'ERROR: The authenticated user %s is not a registered user in this blog.', AADSSO ),
						$jwt->upn
					)
				);
			}
		}

		return $user;
	}

	/**
		* Sets a WordPress user's role based on their AAD group memberships
		*
		* @param WP_User $user
		* @param string $aad_user_id The AAD object id of the user
		* @param string $aad_tenant_id The AAD directory tenant ID
		*
		* @return WP_User|WP_Error Return the WP_User with updated rols, or WP_Error if failed.
		*/
	function update_wp_user_roles( $user, $aad_user_id, $aad_tenant_id ) {

		// Pass the settings to GraphHelper
		AADSSO_GraphHelper::$settings = $this->settings;
		AADSSO_GraphHelper::$tenant_id = $aad_tenant_id;

		// Of the AAD groups defined in the settings, get only those where the user is a member
		$group_ids = array_keys( $this->settings->aad_group_to_wp_role_map );
		$group_memberships = AADSSO_GraphHelper::user_check_member_groups( $aad_user_id, $group_ids );

		// Determine which WordPress role the AAD group corresponds to.
		// TODO: Check for error in the group membership response
		$role_to_set = $this->settings->default_wp_role;
		if ( ! empty( $group_memberships->value ) ) {
			foreach ( $this->settings->aad_group_to_wp_role_map as $aad_group => $wp_role ) {
				if ( in_array( $aad_group, $group_memberships->value ) ) {
					$role_to_set = $wp_role;
					break;
				}
			}
		}

		if ( null != $role_to_set || "" != $role_to_set ) {
			// Set the role on the WordPress user
			$user->set_role( $role_to_set );
		} else {
			return new WP_Error(
				'user_not_member_of_required_group',
				sprintf(
					__( 'ERROR: AAD user %s is not a member of any group granting a role.', AADSSO ),
					$aad_user_id
				)
			);
		}

		return $user;
	}

	/**
	 * Adds a link to the settings page.
	 *
	 * @param array $links The existing list of links
	 *
	 * @return array The new list of links to display
	 */
	function add_settings_link( $links ) {
		$link_to_settings =
			'<a href="' . admin_url( 'options-general.php?page=aadsso_settings' ) . '">Settings</a>';
		array_push( $links, $link_to_settings );
		return $links;
	}

	/**
	 * Generates the URL used to initiate a sign-in with Azure AD.
	 *
	 * @return string The authorization URL used to initiate a sign-in to Azure AD.
	 */
	function get_login_url() {
		$antiforgery_id = com_create_guid();
		$_SESSION['aadsso_antiforgery-id'] = $antiforgery_id;
		return AADSSO_AuthorizationHelper::get_authorization_url( $this->settings, $antiforgery_id );
	}

	/**
	 * Generates the URL for logging out of Azure AD. (Does not log out of WordPress.)
	 */
	function get_logout_url() {

		// logout_redirect_uri is not a required setting, use default value if none is set
		$logout_redirect_uri = $this->settings->logout_redirect_uri;
		if ( empty( $logout_redirect_uri ) ) {
			$logout_redirect_uri = AADSSO_Settings::get_defaults('logout_redirect_uri');
		}

		return $this->settings->end_session_endpoint
			. '?'
			. http_build_query(
				array( 'post_logout_redirect_uri' => $logout_redirect_uri )
			);
	}

	/**
	 * Starts a new session.
	 */
	function register_session() {
		if ( ! session_id() ) {
			session_start();
		}
	}

	/**
	 * Clears the current the session (e.g. as part of logout).
	 */
	function clear_session() {
		session_destroy();
	}

	/*** Settings ***/

	/**
	 * Add filters and actions for admin settings.
	 */
	public function setup_admin_settings() {
		if ( is_admin() ) {
			$azure_active_directory_settings = new AADSSO_Settings_Page();
		}
	}


	/*** View ***/

	/**
	 * Renders the error message shown if this plugin is not correctly configured.
	 */
	function print_plugin_not_configured() {
		echo '<div id="message" class="error"><p>'
		. __( 'Single Sign-on with Azure Active Directory required settings are not defined. '
		      . 'Update them under Settings > Azure AD.', AADSSO )
		      .'</p></div>';
	}

	/**
	 * Renders some debugging data.
	 */
	function print_debug() {
		echo '<p>SESSION</p><pre>' . var_export( $_SESSION, TRUE ) . '</pre>';
		echo '<p>GET</pre><pre>' . var_export( $_GET, TRUE ) . '</pre>';
		echo '<p>Database settings</p><pre>' .var_export( get_option( 'aadsso_settings' ), true ) . '</pre>';
		echo '<p>Plugin settings</p><pre>' . var_export( $this->settings, true ) . '</pre>';
	}

	/**
	 * Renders the CSS used by the HTML injected into the login page.
	 */
	function print_login_css() {
		wp_enqueue_style( AADSSO, AADSSO_PLUGIN_URL . '/login.css' );
	}

	/**
	 * Renders the link used to initiate the login to Azure AD.
	 */
	function print_login_link() {
		$html = '<p class="aadsso-login-form-text">';
		$html .= '<a href="%s">';
		$html .= sprintf( __( 'Sign in with your %s account', AADSSO ),
		                  htmlentities( $this->settings->org_display_name ) );
		$html .= '</a><br /><a class="dim" href="%s">'
		         . __( 'Sign out', AADSSO ) . '</a></p>';
		printf(
			$html,
			$this->get_login_url(),
			$this->get_logout_url()
		);
	}
}

$openfire->of_logInfo("aad-sso-wordpress 4 " . $__FILE__);

// Load settings JSON contents from DB and initialize the plugin
$aadsso_settings_instance = AADSSO_Settings::init();

$openfire->of_logInfo("aad-sso-wordpress 5 " . $__FILE__);
$aadsso = AADSSO::get_instance( $aadsso_settings_instance );


$openfire->of_logInfo("aad-sso-wordpress 9 " . $__FILE__);
/*** Utility functions ***/

if ( ! function_exists( 'com_create_guid' ) ) {
	/**
	 * Generates a globally unique identifier ( Guid ).
	 *
	 * @return string A new random globally unique identifier.
	 */
	function com_create_guid() {
		mt_srand( ( double )microtime() * 10000 );
		$charid = strtoupper( md5( uniqid( rand(), true ) ) );
		$hyphen = chr( 45 ); // "-"
		$uuid = chr( 123 ) // "{"
			.substr( $charid, 0, 8 ) . $hyphen
			.substr( $charid, 8, 4 ) . $hyphen
			.substr( $charid, 12, 4 ) . $hyphen
			.substr( $charid, 16, 4 ) . $hyphen
			.substr( $charid, 20, 12 )
			.chr( 125 ); // "}"
		return $uuid;
	}
}