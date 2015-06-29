<?php

function konzilo_admin_menu() {
  if (!is_multisite()) {
    add_options_page (
      __('Konzilo', 'konzilo'),
      __('Konzilo', 'konzilo'),
      'manage_options',
      'konzilo_auth_settings',
      'konzilo_auth_settings'
    );
  }
}
add_action ( 'admin_menu', 'konzilo_admin_menu', 999 );

function konzilo_settings_form() {
  register_setting('konzilo_auth_settings', 'konzilo_client_id');
  register_setting('konzilo_auth_settings', 'konzilo_client_key');
}
add_action('admin_init', 'konzilo_settings_form');

function konzilo_auth_settings() {
  $state = get_option('konzilo_oauth_state', '');
  if (empty($state)) {
    $state = wp_generate_password(20, false);
    update_option('konzilo_oauth_state', $state);
  }
  $client_id = get_option('konzilo_client_id');
  $client_key = get_option('konzilo_client_key');
  $url = KONZILO_URL;
  $redirect_uri = admin_url('options-general.php?page=konzilo_auth_settings');
  try {
    if (konzilo_get_token($url, $client_id, $client_key,
                                $redirect_uri, $state, true)) {
      $message = _("Authorization complete", 'konzilo');
    }
  }
  catch(Exception $e) {
    $error = $e->getMessage();
  }
  $args = array(
    'client_id' => $client_id,
    'client_key' => $client_key,
    'authorized' => get_option('konzilo_refresh_token', false),
    'error' => !empty($error) ? $error : false,
    'message' => !empty($message) ? $message : false,
  );

  if (isset($_GET['client_id']) && isset($_GET['client_secret']) && empty($_GET['settings-updated'])) {
    $args['client_id'] = $_GET['client_id'];
    $args['client_key'] = $_GET['client_secret'];
    $args['from_konzilo'] = TRUE;
  }
  if (!empty($client_id)) {
    $args['link'] = $url . '/oauth2/authorize?response_type=code&client_id=' .
                  urlencode($client_id) . '&redirect_uri=' . urlencode($redirect_uri) . '&scope=users&state=' . $state;

  }
  $base_dir = plugin_dir_path ( __FILE__ );
  echo konzilo_twig($base_dir)->render(
    'templates/auth_form.html', $args);
}

/*function konzilo_settings_permissions() {
    return 'switch_themes';
}

add_filter('option_page_capability_konzilo_settings', 'konzilox_settings_permissions');
*/