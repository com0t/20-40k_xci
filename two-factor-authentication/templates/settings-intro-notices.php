<p class="simba_tfa_personal_settings_notice simba_tfa_intro_notice">
<?php

echo __('These are your personal settings.', 'two-factor-authentication').' '.__('Nothing you change here will have any effect on other users.', 'two-factor-authentication');

if (is_multisite()) {
	if (is_super_admin()) {
		// Since WP 4.9
		$main_site_id = function_exists('get_main_site_id') ? get_main_site_id() : 1;
		$switched = switch_to_blog($main_site_id);
		echo ' <a href="'.admin_url('options-general.php?page=two-factor-auth').'">'.__('The site-wide administration options are here.', 'two-factor-authentication').'</a>';
		if ($switched) restore_current_blog();
	}
} elseif (current_user_can($simba_tfa->get_management_capability())) { 
	echo ' <a href="'.admin_url('options-general.php?page=two-factor-auth').'">'.__('The site-wide administration options are here.', 'two-factor-authentication').'</a>';
}

?>
</p>

<p class="simba_tfa_verify_tfa_notice simba_tfa_intro_notice"><strong>

	<?php echo apply_filters('simbatfa_message_you_should_verify', __('If you activate two-factor authentication, then verify that your two-factor application and this page show the same One-Time Password (within a minute of each other) before you log out.', 'two-factor-authentication')); ?></strong>

	<?php if (current_user_can($simba_tfa->get_management_capability())) { ?>
		<a href="https://wordpress.org/plugins/two-factor-authentication/faq/"><?php _e('You should also bookmark the FAQs, which explain how to de-activate the plugin even if you cannot log in.', 'two-factor-authentication');?></a>
	<?php } ?>
</p>
