<?php
/*!
* WordPress Social Login
*
* https://miled.github.io/wordpress-social-login/ | https://github.com/miled/wordpress-social-login
*   (c) 2011-2020 Mohamed Mrassi and contributors | https://wordpress.org/plugins/wordpress-social-login/
*/

/**
* New Users Gateway: Accounts linking + Profile Completion
*
* When enabled, Bouncer will popup this screen for unrecognised user, where they will be given the choice to either associate
* any existing account in your website with the provider ID they have connected with or to create a new user account.
*/

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

// --------------------------------------------------------------------

function wsl_process_login_new_users_gateway( $provider, $redirect_to, $hybridauth_user_profile )
{
	// HOOKABLE:
	do_action( "wsl_process_login_new_users_gateway_start", $provider, $redirect_to, $hybridauth_user_profile );

	$assets_base_url = WORDPRESS_SOCIAL_LOGIN_PLUGIN_URL . 'assets/img/16x16/';

	// remove wsl widget
	remove_action( 'register_form', 'wsl_render_auth_widget_in_wp_register_form' );

	$hybridauth_user_email          = sanitize_email( $hybridauth_user_profile->email );
    $hybridauth_user_email_verified = sanitize_email( $hybridauth_user_profile->emailVerified );
	$hybridauth_user_login          = sanitize_user( $hybridauth_user_profile->displayName, true );
	$hybridauth_user_avatar         = $hybridauth_user_profile->photoURL;

	if ( empty( $hybridauth_user_avatar ) )
	{
		$hybridauth_user_avatar  = 'https://secure.gravatar.com/avatar/' . md5( $hybridauth_user_email ) . '?size=145';
	}

	$hybridauth_user_website     = $hybridauth_user_profile->webSiteURL;
	$hybridauth_user_link        = $hybridauth_user_profile->profileURL;

	$hybridauth_user_login       = trim( str_replace( array( ' ', '.' ), '_', $hybridauth_user_login ) );
	$hybridauth_user_login       = trim( str_replace( '__', '_', $hybridauth_user_login ) );

	$requested_user_email        = isset( $_REQUEST["user_email"] ) ? trim( $_REQUEST["user_email"] ) : $hybridauth_user_email;
	$requested_user_login        = isset( $_REQUEST["user_login"] ) ? trim( $_REQUEST["user_login"] ) : $hybridauth_user_login;

	$requested_user_email        = apply_filters( 'wsl_new_users_gateway_alter_requested_email', $requested_user_email );
	$requested_user_login        = apply_filters( 'wsl_new_users_gateway_alter_requested_login', $requested_user_login );

	$user_id    = 0;
	$shall_pass = false;

	$bouncer_account_linking    = false;
	$account_linking_errors     = array();

	$bouncer_profile_completion = false;
	$profile_completion_errors  = array();

	if ( ! validate_username( $requested_user_login ) )
	{
		$profile_completion_errors[] = __( '<strong>Error</strong>: This username is invalid because it uses illegal characters. Please enter a valid username.', 'wordpress-social-login' );
	}

	$registration_enabled = get_option( 'wsl_settings_bouncer_registration_enabled' );
	$linking_enabled      = get_option( 'wsl_settings_bouncer_accounts_linking_enabled' );
	$require_email        = get_option( 'wsl_settings_bouncer_profile_completion_require_email' );
	$change_username      = get_option( 'wsl_settings_bouncer_profile_completion_change_username' );
	$extra_fields         = get_option( 'wsl_settings_bouncer_profile_completion_hook_extra_fields' );

	// Better UX when possible without UI prompts to user
	if( ! isset( $_REQUEST["bouncer_profile_completion"] ) && ! isset( $_REQUEST["bouncer_profile_completion"] ) )
	{
		// when linking is enabled, email is verified by IDp
		// then try to do account linking WITHOUT asking the user to link to WP account
		// if verified email exists to a WP user
		if( $linking_enabled == 1 && ! empty( $hybridauth_user_email_verified ) )
		{
			// check if the verified email exist in wp_users
			$user_id = (int) wsl_wp_email_exists( $hybridauth_user_email_verified );

			if( $user_id )
			{
				$shall_pass = true;
			}
		}

		//  if account_linking is disabled, try to create a new user
		if( ! $shall_pass && $linking_enabled == 2 )
		{
			// Bouncer::Profile Completion enabled?
			// > if not enabled or email is verified by IDp
			//   we just let the user pass
			if( ( $require_email == 2 || ( ! empty( $hybridauth_user_email_verified ) ) )
				&& $change_username == 2 && $extra_fields == 2 )
			{
				$shall_pass = true;
			}

		}
	}

	if( isset( $_REQUEST["bouncer_account_linking"] ) )
	{
		if( $linking_enabled == 2 )
		{
			return wsl_process_login_render_notice_page( __( "Not tonight.", 'wordpress-social-login' ) );
		}

		$bouncer_account_linking = true;

		$username = isset( $_REQUEST["user_login"]    ) ? trim( $_REQUEST["user_login"]    ) : '';
		$password = isset( $_REQUEST["user_password"] ) ? trim( $_REQUEST["user_password"] ) : '';

		# http://codex.wordpress.org/Function_Reference/wp_authenticate
		$user = wp_authenticate( $username, $password );

		// WP_Error object?
		if( is_wp_error( $user ) )
		{
			// we give no useful hint.
			$account_linking_errors[] =
								sprintf(
										__(
												'<strong>ERROR</strong>: Invalid username or incorrect password. <a href="%s">Lost your password</a>?',
												'wordpress-social-login'
										),
										wp_lostpassword_url( home_url() )
								);
		}

		elseif( is_a( $user, 'WP_User') )
		{
			$user_id = $user->ID;

			$shall_pass = true;
		}
	}

	elseif( isset( $_REQUEST["bouncer_profile_completion"] ) )
	{
		// Bouncer::Profile Completion enabled?
		// > if not enabled we just let the user pass
		if( $require_email == 2 && $change_username == 2 && $extra_fields == 2 )
		{
			$shall_pass = true;
		}

		// otherwise we request email &or username &or extra fields
		else
		{
			$bouncer_profile_completion = true;

			/**
			* Code based on wpmu_validate_user_signup()
			*
			* Ref: http://codex.wordpress.org/Function_Reference/wpmu_validate_user_signup
			*/

			# {{{ validate usermail
			if( $require_email == 1 )
			{
				if ( empty( $requested_user_email ) )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: Please type your e-mail address.', 'wordpress-social-login' );
				}

				if ( ! is_email( $requested_user_email ) )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: Please enter a valid email address.', 'wordpress-social-login' );
				}

				if ( wsl_wp_email_exists( $requested_user_email ) )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: Sorry, that email address is already used!', 'wordpress-social-login' );
				}
			}
			# }}} validate usermail

			# {{{ validate username (called login in wsl)
			if( $change_username == 1 )
			{
				$illegal_names = array(  'www', 'web', 'root', 'admin', 'main', 'invite', 'administrator' );

				$illegal_names = apply_filters( 'wsl_new_users_gateway_alter_illegal_names', $illegal_names );

				if ( in_array( $requested_user_login, $illegal_names ) == true )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: That username is not allowed.', 'wordpress-social-login' );
				}

				if ( strlen( $requested_user_login ) < 4 )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: Username must be at least 4 characters.', 'wordpress-social-login' );
				}

				if ( preg_match( '/^[0-9]*$/', $requested_user_login ) )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: Sorry, usernames must have letters too!', 'wordpress-social-login' );
				}

				if ( username_exists( $requested_user_login) )
				{
					$profile_completion_errors[] = __( '<strong>ERROR</strong>: Sorry, that username already exists!', 'wordpress-social-login' );
				}
			}
			# }}} validate username

			# ... well, that was a lot of sorries.

			# {{{ extra fields
			if( $extra_fields == 1 )
			{
				$errors = new WP_Error();

				$errors = apply_filters( 'registration_errors', $errors, $requested_user_login, $requested_user_email );

				if( $errors = $errors->get_error_messages() )
				{
					foreach ( $errors as $error )
					{
						$profile_completion_errors[] = $error;
					}
				}
			}
			# }}} extra fields

			$profile_completion_errors = apply_filters( 'wsl_new_users_gateway_alter_profile_completion_errors', $profile_completion_errors );

			// all check?
			if( ! $profile_completion_errors )
			{
				$shall_pass = true;
			}
		}
	}

	if( $shall_pass == false )
	{
		$provider_name = wsl_get_provider_name_by_id( $provider );
?>
<!DOCTYPE html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
		<title><?php echo get_bloginfo('name'); ?></title>
		<style type="text/css">
			body {
				background: #f3f6f8;
				color: #324155;
				font-family: -apple-system,BlinkMacSystemFont,"Segoe UI","Roboto","Oxygen-Sans","Ubuntu","Cantarell","Helvetica Neue",sans-serif;
				font-size: 16px;
				line-height: 1.6;
			}
			hr {
				border-color: #eeeeee;
				border-style: none none solid;
				border-width: 0 0 1px;
				margin: 2px 0 0;
			}
			h4 {
				font-size: 14px;
				margin-bottom: 10px;
			}
			#login {
				max-width: 620px;
				min-width: 340px;
				margin: auto;
				padding: 114px 0 0;
			}
			#login-panel {
				background: none repeat scroll 0 0 #fff;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.13);
				margin: 2em auto;
				box-sizing: border-box;
				display: inline-block;
				padding: 70px 0 15px;
				position: relative;
				text-align: center;
				width: auto;
			}
			#avatar {
				margin-left: -76px;
				top: -80px;
				left: 50%;
				padding: 4px;
				position: absolute;
			}
			#avatar img {
				background: none repeat scroll 0 0 #fff;
				border: 3px solid #f1f1f1;
				border-radius: 75px !important;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.13);
				height: 145px;
				width: 145px;
			}
			#welcome {
				height: 55px;
				margin: 15px 20px 35px;
			}
			#idp-icon {
				position: absolute;
				margin-top: 2px;
				margin-left: -19px;
			}
			#login-form{
				margin: 0;
				padding: 0;
			}
			.button-primary {
				background-color: #21759b;
				background-image: linear-gradient(to bottom, #2a95c5, #21759b);
				border-color: #21759b #21759b #1e6a8d;
				border-radius: 3px;
				border-style: solid;
				border-width: 1px;
				box-shadow: 0 1px 0 rgba(120, 200, 230, 0.5) inset;
				box-sizing: border-box;
				color: #fff;
				cursor: pointer;
				display: inline-block;
				float: none;
				font-size: 12px;
				height: 36px;
				line-height: 23px;
				margin: 0;
				padding: 0 10px 1px;
				text-decoration: none;
				text-shadow: 0 1px 0 rgba(0, 0, 0, 0.1);
				white-space: nowrap;
			}
			.button-primary.focus, .button-primary:hover{
				background:#1e8cbe;
				border-color:#0074a2;
				-webkit-box-shadow:inset 0 1px 0 rgba(120,200,230,.6);
				box-shadow:inset 0 1px 0 rgba(120,200,230,.6);
				color:#fff;
			}
			input[type="text"],
			input[type="password"] {
				border: 1px solid #e5e5e5;
				box-shadow: 1px 1px 2px rgba(200, 200, 200, 0.2) inset;
				color: #555;
				font-size: 17px;
				height: 30px;
				line-height: 1;
				margin-bottom: 16px;
				margin-right: 6px;
				margin-top: 2px;
				outline: 0 none;
				padding: 3px;
				width: 100%;
			}
			input[type="text"]:focus,
			input[type="password"]:focus {
				border-color:#5b9dd9;
				-webkit-box-shadow:0 0 2px rgba(30,140,190,.8);
				box-shadow:0 0 2px rgba(30,140,190,.8)
			}
			input[type="submit"]{
				float:right;
			}
			label{
				color:#777;
				font-size:14px;
				cursor:pointer;
				vertical-align:middle;
				text-align: left;
			}
			table {
				width:530px;
				margin-left:auto;
				margin-right:auto;
			}
			#mapping-options {
				width:555px;
			}
			#mapping-authenticate {
				display:none;
			}
			#mapping-complete-info {
				display:none;
			}
			#mapping-authenticate, #mapping-complete-info {
				width: 93%;
                padding-left: 15px;
                padding-right: 20px;
			}
			.error {
				display:none;
				background-color: #fff;
				border-left: 4px solid #dd3d36;
				box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1);
                margin: 0 22px;
                margin-top: 16px;
                padding: 6px 12px;
				text-align:left;
			}
			.back-to-options {
				float: left;
				margin: 7px 0px;
			}
			.back-to-home {
				font-size: 14px;
				margin-top: -22px;
			}
			a {
				color: #00aadc;
				text-decoration: none;
			}
			.back-to-home a {
				color: #005082;
				text-decoration: none;
			}
			<?php
				if( $linking_enabled == 2 )
				{
					?>
					#welcome, #mapping-options, #errors-account-linking, #mapping-complete-info {display: none;}
					#errors-profile-completion, #mapping-complete-info {display: block;}
					<?php
				}
				elseif( $bouncer_account_linking )
				{
					?>
					#welcome, #mapping-options, #errors-profile-completion, #mapping-complete-info {display: none;}
					#errors-account-linking, #mapping-authenticate {display: block;}
					<?php
				}
				elseif( $bouncer_profile_completion )
				{
					?>
					#welcome, #mapping-options, #errors-account-linking, #mapping-complete-info {display: none;}
					#errors-profile-completion, #mapping-complete-info {display: block;}
					<?php
				}
			?>
		</style>
		<script>
			// good old time
			function toggle_el( el, display )
			{
				if( el = document.getElementById( el ) )
				{
					el.style.display = display;
				}
			}

			function toggleWidth( el, width )
			{
				if( el = document.getElementById( el ) )
				{
					el.style.width = width;
				}
			}

			function display_mapping_options()
			{
				toggleWidth( 'login', '616px' );

				toggle_el( 'welcome'        , 'block' );
				toggle_el( 'mapping-options', 'block' );

				toggle_el( 'errors-profile-completion', 'none' );
				toggle_el( 'mapping-authenticate'     , 'none' );

				toggle_el( 'errors-account-linking', 'none' );
				toggle_el( 'mapping-complete-info' , 'none' );
			}

			function display_mapping_authenticate()
			{
                toggleWidth( 'login', 'auto' );

				toggle_el( 'welcome'        , 'none' );
				toggle_el( 'mapping-options', 'none' );

				toggle_el( 'errors-account-linking', 'none' );
				toggle_el( 'mapping-authenticate'  , 'block' );

				toggle_el( 'errors-profile-completion', 'none' );
				toggle_el( 'mapping-complete-info'    ,'none' );
			}

			function display_mapping_complete_info()
			{
                toggleWidth( 'login', 'auto' );

				toggle_el( 'welcome'        , 'none' );
				toggle_el( 'mapping-options', 'none' );

				toggle_el( 'errors-account-linking', 'none' );
				toggle_el( 'mapping-authenticate'  , 'none' );

				toggle_el( 'errors-profile-completion', 'none' );
				toggle_el( 'mapping-complete-info'    , 'block' );
			}
		</script>
	</head>
	<body>
		<div id="login">
			<div id="login-panel">
				<div id="avatar">
					<img src="<?php echo $hybridauth_user_avatar; ?>">
				</div>

				<div id="welcome">
					<img id="idp-icon" src="<?php echo $assets_base_url . strtolower($provider); ?>.png" >
					<b><?php printf( __( "Hi %s", 'wordpress-social-login' ), htmlentities( $hybridauth_user_profile->displayName ) ); ?></b>
					<p><?php printf( __( "You're now signed in with your %s account but you are still one step away of getting into our website", 'wordpress-social-login' ), $provider_name ); ?>.</p>

					<hr />
				</div>

				<table id="mapping-options" style="padding-top: 12px;" border="0">
					<tr>
						<?php if( $linking_enabled == 1 ): ?>
							<td valign="top"  width="50%" style="text-align:center;">
								<h4><?php _e( "Already have an account", 'wordpress-social-login' ); ?>?</h4>
								<p style="font-size: 12px;"><?php printf( __( "Link your existing account on our website to your %s ID.", 'wordpress-social-login' ), $provider_name ); ?></p>
							</td>
						<?php endif; ?>

						<?php if( $registration_enabled == 1 ): ?>
						<td valign="top"  width="50%" style="text-align:center;">
							<h4><?php _e( "New to our website", 'wordpress-social-login' ); ?>?</h4>
							<p style="font-size: 12px;"><?php printf( __( "Create a new account and it will be associated with your %s ID.", 'wordpress-social-login' ), $provider_name ); ?></p>
						</td>
						<?php endif; ?>
					</tr>

					<tr>
						<?php if( $linking_enabled == 1 ): ?>
							<td valign="top"  width="50%" style="text-align:center;">
								<input type="button" value="<?php _e( "Link my account", 'wordpress-social-login' ); ?>" class="button-primary" onclick="display_mapping_authenticate();" >
							</td>
						<?php endif; ?>

						<?php if( $registration_enabled == 1 ): ?>
						<td valign="top"  width="50%" style="text-align:center;">
							<?php if( ( $require_email != 1 || ! empty( $hybridauth_user_email_verified ) ) && $change_username != 1 && $extra_fields != 1 ): ?>
								<input type="button" value="<?php _e( "Create a new account", 'wordpress-social-login' ); ?>" class="button-primary" onclick="document.getElementById('info-form').submit();" >
							<?php else : ?>
								<input type="button" value="<?php _e( "Create a new account", 'wordpress-social-login' ); ?>" class="button-primary" onclick="display_mapping_complete_info();" >
							<?php endif; ?>
						</td>
						<?php endif; ?>
					</tr>
				</table>

				<?php
					if( ! empty($account_linking_errors) )
					{
						echo '<div id="errors-account-linking" class="error">';

						foreach( $account_linking_errors as $error )
						{
							?><p style="padding: 2px; margin: 0px;"><?php echo $error; ?></p><?php
						}

						echo '</div>';
					}

					if( $profile_completion_errors )
					{
						echo '<div id="errors-profile-completion" class="error">';

						foreach( $profile_completion_errors as $error )
						{
							?><p style="padding: 2px; margin: 0px;"><?php echo $error; ?></p><?php
						}

						echo '</div>';
					}
				?>

				<?php if( $linking_enabled == 1 ): ?>

					<form method="post" action="<?php echo site_url( 'wp-login.php', 'login_post' ); ?>" id="link-form">
						<table id="mapping-authenticate" border="0">
							<tr>
								<td valign="top" style="text-align:center;">
									<h4><?php _e( "Already have an account", 'wordpress-social-login' ); ?>?</h4>

									<p><?php printf( __( "Please enter your username and password of your existing account on our website. Once verified, it will linked to your %s ID", 'wordpress-social-login' ), $provider_name ) ; ?>.</p>
								</td>
							</tr>
							<tr>
								<td valign="bottom" style="text-align:left;">
									<label>
										<?php _e( "Username", 'wordpress-social-login' ); ?>
										<br />
										<input type="text" name="user_login" class="input" value=""  size="25" placeholder="" />
									</label>

									<label>
										<?php _e( "Password", 'wordpress-social-login' ); ?>
										<br />
										<input type="password" name="user_password" class="input" value="" size="25" placeholder="" />
									</label>

									<input type="submit" value="<?php _e( "Continue", 'wordpress-social-login' ); ?>" class="button-primary" >

									<a href="javascript:void(0);" onclick="display_mapping_options();" class="back-to-options"><?php _e( "Cancel", 'wordpress-social-login' ); ?></a>
								</td>
							</tr>
						</table>

						<input type="hidden" id="redirect_to" name="redirect_to" value="<?php echo $redirect_to ?>">
						<input type="hidden" id="provider" name="provider" value="<?php echo $provider ?>">
						<input type="hidden" id="action" name="action" value="wordpress_social_account_linking">
						<input type="hidden" id="bouncer_account_linking" name="bouncer_account_linking" value="1">
					</form>

				<?php endif; ?>

				<?php if( $registration_enabled == 1 ): ?>

					<form method="post" action="<?php echo site_url( 'wp-login.php', 'login_post' ); ?>" id="info-form">
						<table id="mapping-complete-info" border="0">
							<tr>
								<td valign="top" style="text-align:center;">
									<?php if( $linking_enabled == 1 ): ?>
										<h4><?php _e( "New to our website", 'wordpress-social-login' ); ?>?</h4>
									<?php endif; ?>

									<p><?php printf( __( "Please fill in your information in the form below. Once completed, you will be able to automatically sign into our website through your %s ID", 'wordpress-social-login' ), $provider_name ); ?>.</p>
								</td>
							</tr>
							<tr>
								<td valign="bottom" style="text-align:left;">
									<?php if( $change_username == 1 ): ?>
										<label>
											<?php _e( "Username", 'wordpress-social-login' ); ?>
											<br />
											<input type="text" name="user_login" class="input" value="<?php echo $requested_user_login; ?>" size="25" placeholder="" />
										</label>
									<?php endif; ?>

									<?php if( $require_email == 1 ): ?>
										<label>
											<?php _e( "E-mail", 'wordpress-social-login' ); ?>
											<br />
											<input type="text" name="user_email" class="input" value="<?php echo $requested_user_email; ?>" size="25" placeholder="" />
										</label>
									<?php endif; ?>

									<?php
										/**
										* Fires following the 'E-mail' field in the user registration form.
										*
										* hopefully, this won't become a pain in future
										*
										* Ref: http://codex.wordpress.org/Plugin_API/Action_Reference/register_form
										*/
										if( $extra_fields == 1 )
										{
											do_action( 'register_form' );
										}
									?>

									<input type="submit" value="<?php _e( "Continue", 'wordpress-social-login' ); ?>" class="button-primary" >

									<?php if( $linking_enabled == 1 ): ?>
										<a href="javascript:void(0);" onclick="display_mapping_options();" class="back-to-options"><?php _e( "Cancel", 'wordpress-social-login' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<input type="hidden" id="redirect_to" name="redirect_to" value="<?php echo $redirect_to ?>">
						<input type="hidden" id="provider" name="provider" value="<?php echo $provider ?>">
						<input type="hidden" id="action" name="action" value="wordpress_social_account_linking">
						<input type="hidden" id="bouncer_profile_completion" name="bouncer_profile_completion" value="1">
					</form>

				<?php endif; ?>
            </div>

			<p class="back-to-home">
				<a href="<?php echo home_url(); ?>">&#8592; <?php printf( __( "Back to %s", 'wordpress-social-login' ), get_bloginfo('name') ); ?></a>
			</p>
		</div>

		<?php
			// Development mode on?
			if( get_option( 'wsl_settings_development_mode_enabled' ) )
			{
				wsl_display_dev_mode_debugging_area();
			}

			// HOOKABLE:
			do_action( "wsl_process_login_new_users_gateway_closing_body", $provider, $redirect_to, $hybridauth_user_profile );
		?>
	</body>
</html>
<?php
		die();
	}

	return array( $shall_pass, $user_id, $requested_user_login, $requested_user_email );
}

// --------------------------------------------------------------------