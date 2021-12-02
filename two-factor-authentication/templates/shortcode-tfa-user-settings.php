<?php

if (!defined('ABSPATH')) die('No direct access.');

if (!$is_activated_for_user) {
	_e('Two factor authentication is not available for your user.', 'two-factor-authentication');
} else {
	
	?>
	
	<div class="wrap" style="padding-bottom:10px">
	
		<?php $simba_tfa->include_template('settings-intro-notices.php'); ?>
		
		<?php $tfa_frontend->settings_enable_or_disable_output(); ?>
		
		<?php $simba_tfa->get_totp_controller()->current_codes_box(); ?>
		
		<?php $simba_tfa->get_totp_controller()->advanced_settings_box(array($tfa_frontend, 'save_settings_button')); ?>
	
	</div>
	
	<?php $tfa_frontend->save_settings_javascript_output(); ?>
	
	<?php
}
