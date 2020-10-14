<?php
/*!
* WordPress Social Login
*
* https://miled.github.io/wordpress-social-login/ | https://github.com/miled/wordpress-social-login
*   (c) 2011-2020 Mohamed Mrassi and contributors | https://wordpress.org/plugins/wordpress-social-login/
*/

/**
* Components Manager
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// --------------------------------------------------------------------

function wsl_component_components_gallery()
{
	return; // ya men 3ach

	// HOOKABLE:
	do_action( "wsl_component_components_gallery_start" );

	$response = wp_remote_get( 'http://miled.github.io/wordpress-social-login/components-' . wsl_get_version() . '.json', array( 'timeout' => 15, 'sslverify' => false ) );

	if ( ! is_wp_error( $response ) )
	{
		$response = wp_remote_retrieve_body( $response );

		$components = json_decode ( $response );

		if( $components )
		{
?>
<br />

<h2><?php __e( "Other Components available", 'wordpress-social-login' ) ?></h2>

<p><?php __e( "These components and add-ons can extend the functionality of WordPress Social Login", 'wordpress-social-login' ) ?>.</p>

<?php
	foreach( $components as $item )
	{
		$item = (array) $item;
		?>
			<div class="wsl_component_div">
				<h3 style="margin:0px;"><?php __e( $item['name'], 'wordpress-social-login' ) ?></h3>

				<div class="wsl_component_about_div">
					<p>
						<?php __e( $item['description'], 'wordpress-social-login' ) ?>
						<br />
						<?php echo sprintf( __( '<em>By <a href="%s">%s</a></em>' , 'wordpress-social-login' ), $item['developer_link'], $item['developer_name'] ); ?>
					</p>
				</div>

				<a class="button button-secondary" href="<?php echo $item['download_link']; ?>" target="_blank"><?php __e( "Get this Component", 'wordpress-social-login' ) ?></a>
			</div>
		<?php
	}
?>

<div class="wsl_component_div">
	<h3 style="margin:0px;"><?php __e( "Build yours", 'wordpress-social-login' ) ?></h3>

	<div class="wsl_component_about_div">
		<p><?php __e( "Want to build your own custom <b>WordPress Social Login</b> component? It's pretty easy. Just refer to the online developer documentation.", 'wordpress-social-login' ) ?></p>
	</div>

	<a class="button button-primary"   href="http://miled.github.io/wordpress-social-login/documentation.html" target="_blank"><?php __e( "WSL Developer API", 'wordpress-social-login' ) ?></a>
	<a class="button button-secondary" href="http://miled.github.io/wordpress-social-login/submit-component.html" target="_blank"><?php __e( "Submit your WSL Component", 'wordpress-social-login' ) ?></a>
</div>

<?php
		}
	}

	// HOOKABLE:
	do_action( "wsl_component_components_gallery_end" );
}

// --------------------------------------------------------------------
