<?php
$account_id = get_option( 'trustpulse_script_id', null );
$enabled    = get_option( 'trustpulse_script_enabled', null );
$url        = trustpulse_dir_uri();
?>
<div id="wrap" class="trustpulse-wrap">
	<h1 class="tp-heading"><?php esc_html_e( 'Boost Your Sales and Conversions with Social Proof Notifications', 'trustpulse-api' ); ?></h1>
	<?php if ( $account_id ) : ?>
		<div class="tp-admin-box">
			<h3><?php esc_html_e( 'Your account is connected!', 'trustpulse-api' ); ?></h3>
			<p><?php printf( esc_html__( '%1$s is connected to your WordPress site. We\'ll automatically monitor your forms for campaign submissions, and display your %1$s notifications.', 'trustpulse-api' ), 'TrustPulse' ); ?></p>
			<div class="tp-content-row">
				<a href="<?php echo TRUSTPULSE_APP_URL; ?>" class="tp-content-row__item tp-button tp-button--green" target="_blank"><?php esc_html_e( 'View My Campaigns', 'trustpulse-api' ); ?></a>
				<form action="<?php echo admin_url() . 'options-general.php'; ?>">
					<input type="hidden" name="page" value="<?php echo TRUSTPULSE_ADMIN_PAGE_NAME; ?>">
					<input type="hidden" name="action" value="remove_account_id">
					<?php wp_nonce_field( 'remove_account_id', 'remove_account_id' ); ?>
					<input class="tp-content-row__item  tp-button tp-button--blue" type="submit" value="<?php esc_attr_e( 'Disconnect Account', 'trustpulse-api' ); ?>">
				</form>
			</div>
		</div>
		<div class="tp-admin-box tp-admin-box--video">
			<div id="tpVideo">
				<iframe width="560" height="315" id="tpVideoIframe" src="https://www.youtube.com/embed/iRvZxNiujpg" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
			</div>
			<div id="tpVideoPreview">
			<h3><?php esc_html_e( 'Start converting visitors into customers and subscribers!', 'trustpulse-api' ); ?></h3>
			<p><?php printf( esc_html__( 'Watch a quick overview of what you can do with %s.', 'trustpulse-api' ), 'TrustPulse' ); ?></p>
			<img class="tp-video-button" src="<?php echo $url . 'assets/images/video-cta-button.png'; ?>" alt="<?php esc_attr_e( 'Click to Play', 'trustpulse-api' ); ?>">
				<img class="tp-video-background" src="<?php echo $url . 'assets/images/video-background.png'; ?>" alt="<?php esc_attr_e( 'Click to Play', 'trustpulse-api' ); ?>">
			</div>
		</div>
		<?php require __DIR__ . '/partials/trustpulse-works-on.php'; ?>

	<?php else : ?>
		<div class="tp-admin-box">
			<p>
                <?php printf( esc_html__( '%s helps you leverage the true power of social proof to instantly increase trust, conversions and sales by up to 15%%', 'trustpulse-api' ), 'TrustPulse' ); ?>
			</p>
			<div class="tp-content-row">
				<a href="<?php echo TRUSTPULSE_APP_URL; ?>checkout/1/monthly" class="tp-content-row__item tp-button tp-button--green"><?php esc_html_e( 'Get Started For Free', 'trustpulse-api' ); ?></a>
				<div class="tp-content-row__item"> <?php esc_html_e( 'or', 'trustpulse-api' ); ?> </div>
				<form action="<?php echo TRUSTPULSE_APP_URL . 'auth/plugin'; ?>">
					<?php wp_nonce_field( 'add_account_id', 'nonce' ); ?>
					<input type="hidden" name="redirect_url" value="<?php echo urlencode( admin_url() . 'admin.php?page=' . TRUSTPULSE_ADMIN_PAGE_NAME ); ?>">
					<input class="tp-content-row__item tp-button tp-button--blue" type="submit" value="<?php esc_attr_e( 'Connect Your Existing Account', 'trustpulse-api' ); ?>">
				</form>
			</div>
		</div>
		<h2 class="tp-heading"><?php printf( esc_html__( 'Top 4 Reasons Why People Love %s', 'trustpulse-api' ), 'TrustPulse' ); ?></h2>
		<p class="tp-subheading"><?php printf( esc_html__( 'Here\'s why smart business owners love %s, and you will too!', 'trustpulse-api' ), 'TrustPulse' ); ?></p>
		<div class="tp-features">
			<div class="tp-feature tp-feature--text-right">
				<div class="tp-feature__image">
					<img src="<?php echo $url . 'assets/images/features-event.svg'; ?>" alt="<?php esc_attr_e( 'Real-Time Event Tracking', 'trustpulse-api' ); ?>">
				</div>
				<div class="tp-feature__text">
					<h3><?php esc_html_e( 'Real-Time Event Tracking', 'trustpulse-api' ); ?></h3>
					<p><?php esc_html_e( 'Show a live stream of any action on your website, including purchases, demo registrations, email newsletter signups, and more.', 'trustpulse-api' ); ?></p>
				</div>
			</div>
			<div class="tp-feature tp-feature--text-left">
				<div class="tp-feature__image">
					<img src="<?php echo $url . 'assets/images/features-fire.svg'; ?>" alt="<?php esc_attr_e( 'On Fire Notifications', 'trustpulse-api' ); ?>">
				</div>
				<div class="tp-feature__text">
					<h3><?php esc_html_e( '"On Fire" Notifications', 'trustpulse-api' ); ?></h3>
					<p><?php esc_html_e( 'Show how many people are taking action in a given period. Great for leveraging FOMO (Fear of Missing Out) on landing pages and checkouts.', 'trustpulse-api' ); ?></p>
				</div>
			</div>
			<div class="tp-feature tp-feature--text-right">
				<div class="tp-feature__image">
					<img src="<?php echo $url . 'assets/images/home-smart-targeting.svg'; ?>" alt="<?php esc_attr_e( 'Real-Time Event Tracking', 'trustpulse-api' ); ?>">
				</div>
				<div class="tp-feature__text">
					<h3><?php esc_html_e( 'Smart Targeting', 'trustpulse-api' ); ?></h3>
					<p><?php esc_html_e( 'Show your social proof notifications to the right people at the right time to boost conversions by using advanced targeting rules and timing controls.', 'trustpulse-api' ); ?></p>
				</div>
			</div>
			<div class="tp-feature tp-feature--text-left">
				<div class="tp-feature__image">
					<img src="<?php echo $url . 'assets/images/home-flexible.svg'; ?>" alt="<?php esc_attr_e( 'Smart Targeting', 'trustpulse-api' ); ?>">
				</div>
				<div class="tp-feature__text">
					<h3><?php esc_html_e( 'Flexible Design Options', 'trustpulse-api' ); ?></h3>
					<p><?php esc_html_e( 'Create attractive notifications designed to convert. Customize the message, colors, images, and more to match the look and feel of your website.', 'trustpulse-api' ); ?></p>
				</div>
			</div>
		</div>
		<!-- <div class="tp-admin-box-group">
					<div class="tp-admin-box">
						Box 1
					</div>
					<div class="tp-admin-box">
						Box 2
					</div>
					<div class="tp-admin-box">
						Box 3
					</div>
				</div> -->
		<?php require __DIR__ . '/partials/trustpulse-works-on.php'; ?>

		<div class="tp-admin-box">
			<p>
                <?php printf( esc_html__( 'Join other smart business owners who use %s to convert visitors into subscribers and customers.', 'trustpulse-api' ), 'TrustPulse' ); ?>
			</p>
			<div class="tp-content-row">
				<a href="<?php echo TRUSTPULSE_APP_URL; ?>checkout/1/monthly" class="tp-content-row__item tp-button tp-button--green"><?php esc_html_e( 'Get Started For Free', 'trustpulse-api' ); ?></a>
				<div class="tp-content-row__item"> <?php esc_html_e( 'or', 'trustpulse-api' ); ?> </div>
				<form action="<?php echo TRUSTPULSE_APP_URL . 'auth/plugin'; ?>">
					<?php wp_nonce_field( 'add_account_id', 'nonce' ); ?>
					<input type="hidden" name="redirect_url" value="<?php echo urlencode( admin_url() . 'admin.php?page=' . TRUSTPULSE_ADMIN_PAGE_NAME ); ?>">
					<input class="tp-content-row__item tp-button tp-button--blue" type="submit" value="<?php esc_attr_e( 'Connect Your Existing Account', 'trustpulse-api' ); ?>">
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>
