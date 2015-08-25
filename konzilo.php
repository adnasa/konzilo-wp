<?php
/*
 * Plugin name: Konzilo
 * Plugin URI: http://wordpress.org/extend/plugins/konzilo/
 * Description: Konzilo integration with wordpress.
 * Author: Fabian Sörqvist
 * Author URI: http://kntnt.com/
 * Version: 0.1
 */

// we look for Composer files first in the plugins dir
// then in the wp-content dir (site install)
// and finally in the current themes directories
if (   file_exists( $composer_autoload = __DIR__ . '/vendor/autoload.php' ) /* check in self */
       || file_exists( $composer_autoload = WP_CONTENT_DIR.'/vendor/autoload.php') /* check in wp-content */
       || file_exists( $composer_autoload = get_stylesheet_directory().'/vendor/autoload.php') /* check in child theme */
       || file_exists( $composer_autoload = get_template_directory().'/vendor/autoload.php') /* check in parent theme */
) {
  require_once $composer_autoload;
}

// Default konzilo location.
if (!defined('KONZILO_URL')) {
  define('KONZILO_URL', 'http://localhost:8000');
}

$base_dir = plugin_dir_path ( __FILE__ );
require_once ($base_dir . '/includes/twig.inc');
require_once ($base_dir . '/konzilo.pages.php');
require_once($base_dir . '/includes/submitbox.inc');

/**
 * Get the konzilo URL, either a global URL for multisite,
 * or the default url.
 */
function konzilo_get_url() {
  return is_multisite() ? get_site_option('konzilo_url') : KONZILO_URL;
}

/**
 * Add the konzilo text domain.
 */
function konzilo_add_textdomain() {
  load_plugin_textdomain ( 'konzilo' );
}
add_action ( 'init', 'konzilo_add_textdomain', 11 );

/**
 * Verify that we have a valid client.
 */
function konzilo_has_client() {
  $get = is_multisite() ? 'get_site_option' : 'get_option';
  $client_id = $get('konzilo_client_id', '');
  $client_key = $get('konzilo_client_key', '');
  $token = $get('konzilo_access_token', '');
  return $client_id && $client_key && $token;
}

/**
 * Get authentication headers for a request against konzilo.
 */
function konzilo_get_auth_headers() {
  $get = is_multisite() ? 'get_site_option' : 'get_option';
  $url = $get('konzilo_url');
  $expires = $get('konzilo_token_expires');
  if ($expires < time()) {
    konzilo_refresh_token();
  }
  $token = $get('konzilo_access_token', '');
  return array(
    'Authorization' => 'Bearer ' . $token,
    'Content-Type' => 'application/json'
  );
}

/**
 * Get a token for a particular client.
 */
function konzilo_get_token($url, $client_id, $client_secret, $redirect_uri, $state, $local = false) {
  if (!isset($_GET['code']) || !isset($_GET['state'])) {
    return false;
  }
  if ($state != $_GET['state']) {
    throw new Exception('Invalid state');
  }
  $oauth_url = $url . '/oauth2/token/';
  $args = array(
    'body' => array(
      'grant_type' => 'authorization_code',
      'code' => $_GET['code'],
      'redirect_uri' => $redirect_uri,
    ),
    'headers' => array(
      'Authorization' => 'Basic ' .
      base64_encode($client_id . ':' . $client_secret)
    ),
  );
  $result = wp_remote_post($oauth_url, $args);
  if ($result['response']['code'] > 399) {
    throw new Exception("Authorization failed");
  }
  $codes = json_decode($result['body']);
  $update = $local ? 'update_option' : 'update_site_option';

  $update('konzilo_refresh_token', $codes->refresh_token);
  $update('konzilo_access_token', $codes->access_token);
  $update('konzilo_token_expires', time() + $codes->expires_in);
  return true;
}


/**
 * Get a refresh token. If the current token is expired,
 * get a new one.
 */
function konzilo_refresh_token() {
  $get = is_multisite() ? 'get_site_option' : 'get_option';
  $set = is_multisite() ? 'update_site_option' : 'update_site_option';
  $url = konzilo_get_url();
  $client_id = $get('konzilo_client_id');
  $client_key = $get('konzilo_client_key');
  $args = array(
    'body' => array(
      'grant_type' => 'refresh_token',
      'refresh_token' => $get('konzilo_refresh_token'),
      'client_id' => $client_id,
      'client_secret' => $client_key
    ),
  );
  $result = wp_remote_post($url . '/oauth2/token/', $args);
  if (is_object($result)) {

    throw new Exception($result->get_error_message());
  }
  if (is_object($result) || $result['response']['code'] > 399) {
    throw new Exception($result['response']['code']);
  }
  $codes = json_decode($result['body']);
  $set('konzilo_refresh_token', $codes->refresh_token);
  $set('konzilo_access_token', $codes->access_token);
  $set('konzilo_token_expires', time() + $codes->expires_in);
  return true;
}

/**
 * Get data from konzilo. This is basically a wrapper around
 * wp_remote_get() that adds appropriate headers.
 */
function konzilo_get_data($resource, $args = array(), $id = NULL, $params = array()) {
  $args['headers'] = konzilo_get_auth_headers();
  $url = konzilo_get_url();

  $uri = $url . '/api/' . $resource;
  if (!empty($id)) {
      $uri .= '/' . $id;
  }
  // Allow other plugins to hook in.
  $new_params = apply_filters('konzilo_request_params', $resource, $params);
  if (is_array($new_params)) {
    $params = $new_params;
  }
  if (!empty($params)) {
    $uri .= '?';
    $parts = array();
    foreach ($params as $key => $value) {
      $parts[] = "$key=$value";
    }
    $uri .= implode('&', $parts);
  }
  $result = wp_remote_get($uri, $args);
  if ($result['response']['code'] >= 400) {
    throw new Exception($result['response']['code']);
  }
  return json_decode($result['body']);
}

/**
 * Post data against konzilo.
 */
function konzilo_post_data($resource, $args = array(), $id = null) {
  $args['headers'] = konzilo_get_auth_headers();
  $args['body'] = json_encode($args['body']);
  $url = konzilo_get_url();

  $uri = $url . '/api/' . $resource;
  if (!empty($id)) {
      $uri .= '/' . $id;
  }
  $result = wp_remote_post($uri, $args);
  if ($result['response']['code'] >= 400) {
    print $result['body'];
    throw new Exception($result['response']['code']);
  }
  return json_decode($result['body']);
}

function konzilo_put_data($resource, $id, $args = array()) {
  $args['method'] = 'PUT';
  return konzilo_post_data($resource, $args, $id);
}

function konzilo_delete_data($resource, $id, $args = array()) {
  $args['method'] = 'DELETE';
  return konzilo_get_data($resource, $args, $id);
}

function konzilo_get_profiles() {
  static $profiles = null;
  if (empty($profiles)) {
    $profiles = wp_cache_get('konzilo_profiles');
    if (empty($profiles)) {
      $profiles = konzilo_get_data('profiles');
      wp_cache_set('konzilo_profiles', $profiles);
    }
  }
  return $profiles;
}

function konzilo_get_update($id) {
  return konzilo_get_data('updates', array(), $id);
}

function konzilo_get_updates($post_id, $cache = true) {
  static $updates = array();
  if (empty($updates) && $cache) {
    if ($cache) {
      $updates = wp_cache_get('konzilo_updates_' . $post_id);
    }
    if (empty($updates)) {
      $args = array('post_id' => $post_id);
      if (is_multisite()) {
        $args['page'] = get_option('konzilo_page');
      }
      else {
        $args['application'] = true;
      }
      $updates = konzilo_get_data('updates', array(), null, $args);
      wp_cache_set('konzilo_updates_' . $post_id, $updates);
    }
  }
  return $updates;
}

add_action('load-post.php', 'konzilo_meta_box_setup');
add_action('load-post-new.php', 'konzilo_meta_box_setup');

function konzilo_meta_box_setup() {
  if (konzilo_has_client()) {
    try {
      wp_enqueue_style('social', plugins_url('css/social.css', __FILE__));
      add_action('add_meta_boxes', 'konzilo_add_meta_boxes');
      add_action('save_post', 'konzilo_save_update', 10, 2 );
    }
    catch(Exception $e) {
      // Notify the user somehow...
    }
  }
}

function konzilo_save_update($post_id, $post ) {
  $post_type = get_post_type_object( $post->post_type );
  /* Check if the current user has permission to edit the post.*/
  if ( !current_user_can( $post_type->cap->edit_post, $post_id ) || !isset($_POST['konzilo_type']))
    return $post_id;
  $konzilo_id = get_post_meta( $post->ID, 'konzilo_id', true );
  if (!empty($konzilo_id)) {
    $update = konzilo_get_data('updates', array(), $konzilo_id);
  }
  if (empty($update)) {
    $update = new stdClass;
  }
  $update->text = $_POST['post_title'];
  $update->post_id = $post->ID;
  $update->type = $_POST['konzilo_type'];
  $update->queue = $_POST['konzilo_queue'];
  $update->status = $_POST['post_status'];
  $update->link = get_permalink($post_id);
  $update->updates = array();

  if (!empty($_POST['konzilo_text'])) {
    $update->text = $_POST['konzilo_text'];
  }
  $update_map = array();
  $update_offset_map = array();
  $schedule_map = array();
  foreach ($update->updates as $text) {
    if ($text->offset == 0) {
      $update_map[$text->profile] = $text;
    }
    else {
      $update_map[$text->profile . '_' . $text->offset] = $text;
    }
  }
  $old_updates = $update->updates;
  $update->updates = array();

  if (!empty($_POST['schedule'])) {
    foreach ($_POST['schedule'] as $profile => $times) {
      foreach ($times as $time) {
        $update_text = array(
          'profile' => $profile,
          'text' => $update->text,
          'offset' => $time,
        );
        if (!empty($update_map[$profile . '_' . $time])) {
          $update_text->id = $update_map[$profile . '_' . $time]->id;
        }
        $update->updates[] = $update_text;
      }
    }
  }
  if (!empty($_POST['konzilo_channels'])) {
    foreach ($_POST['konzilo_channels'] as $key => $val) {
      $new_update = (object)array(
        'profile' => $key,
        'text' => $update->text,
      );
      if (isset($update_map[$val])) {
        $new_update->id = $update_map[$val]->id;
      }
      $update->updates[] = $new_update;
    }
  }

  if (!empty($update->id)) {
    $result = konzilo_put_data('updates', $update->id, array(
      'body' => $update));
  }
  else {
    $result = konzilo_post_data('updates', array(
      'body' => $update));
    update_post_meta($post_id, 'konzilo_id', $result->id);
  }
}

function konzilo_create_none_settings($action, $name) {
  $name = esc_attr( $name );
  $setting = new stdClass();
  $setting->nonce = new stdClass();
  $setting->nonce->type = 'hidden';
  $setting->nonce->id = $name;
  $setting->nonce->name = $name;
  $setting->nonce->value = wp_create_nonce( $action );

  $setting->referer = new stdClass();
  $setting->referer->type = 'hidden';
  $setting->referer->name = '_wp_http_referer';
  $setting->referer->value = esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ) );

  return $setting;
}

function konzilo_indexby($key, $arr) {
  $indexed = array();
  foreach ($arr as $item) {
    $indexed[$item->{$key}] = $item;
 }
  return $indexed;
}


function konzilo_add_meta_boxes() {
  global $post;
  global $pagenow;

  // Add status to the posts.
  //wp_localize_script('social', 'socialdata', array(
  //  'values' => array_values($meta_value),
  //));
  //wp_enqueue_script('social');
  add_meta_box(
    'konzilo-social-post',      // Unique ID
    esc_html__( 'Konzilo', 'konzilo' ),    // Title
    'konzilo_meta_box',   // Callback function
    'post',         // Admin page (or post type)
    'normal',         // Context
    'high'         // Priority
  );
}

class KonziloChannelExtension extends Twig_Extension {
  public function getName() {
    return 'konzilo_channel';
  }

  public function getFunctions() {
    return array(
      new Twig_SimpleFunction('channel_checked', function ($update, $profile) {
        $update = (object)$update;
        foreach ($update->updates as $text) {
          if ($text->profile == $profile && $text->offset == 0) {
            return 'checked';
          }
        }
      })
    );
  }
}

function konzilo_meta_box( $object, $box ) {
  if (!konzilo_has_client()) {
    echo "Konzilo isn't authorized";
    return;
  }

  $typeMap = array(
    'facebook' => 'dashicons dashicons-facebook',
    'twitter' => 'dashicons dashicons-twitter',
  );

  $base_dir = plugin_dir_path ( __FILE__ );
  $twig = konzilo_twig($base_dir, true);
  if (!$twig->hasExtension('konzilo_channel')) {
    $twig->addExtension(new KonziloChannelExtension());
  }
  $update = konzilo_get_post_update($object->ID);
  $profiles = konzilo_get_data('profiles');

  if (empty($update)) {
    $update = (object)array(
      'text' => '',
      'updates' => array()
    );
  }
  $channels = array();

  $updateSchedule = array();
  foreach ($update->updates as $text) {
    if ($text->offset && $text->offset > 0) {
      $updateSchedule[$text->profile] = !empty($updateSchedule[$text->profile]) ? $updateSchedule[$text->profile] : array();
      $updateSchedule[$text->profile][$text->offset] = $text;
    }
  }

  $header = array_map(function ($profile) use ($typeMap) {
    return array(
      'username' => $profile->formatted_username,
      'service' => $profile->service
    );
  }, $profiles);

  $hourCheckboxes = function ($hour, $label) use ($profiles, $updateSchedule) {
    return array(
      'time' => $label,
      'values' => array_map(function ($profile) use ($hour, $updateSchedule) {
          return array(
            'value' => $hour * 3600,
            'id' => $profile->id,
            'checked' => !empty($updateSchedule[$profile->id][$hour * 3600]) ?
            'checked' : ''
          );
        }, $profiles)
    );
  };

  $scheme = array(
    2 => __('+ 2 hours', 'konzilo'),
    4 => __('+ 4 hours', 'konzilo'),
    8 => __('+ 8 hours', 'konzilo'),
    16 => __('+ 16 hours', 'konzilo'),
    32 => __('+ 32 hours', 'konzilo'),
    64 => __('+ 64 hours', 'konzilo'),
    128 => __('+ 128 hours', 'konzilo')
  );


  $schedule = array();

  foreach ($scheme as $hour => $label) {
    $schedule[] = $hourCheckboxes($hour, $label);
  }

  echo $twig->render('templates/social_form.html', array(
    'update' => $update,
    'channels' => $profiles,
    'header' => $header,
    'schedule' => $schedule
  ));

}


function konzilo_get_post_update($post_id) {
  $konzilo_id = get_post_meta($post_id, 'konzilo_id', true);
  if (!empty($konzilo_id)) {
    try {
      $post = konzilo_get_data('updates', array(), $konzilo_id);
      return $post;
    } catch(Exception $e) {
      // Whateva.
    }
  }
  return null;
}

function konzilo_translate_codes($subject, $post) {
  $codes = array(
    '[sitename]' => function () {
      return get_bloginfo('name');
    },
    '[title]' => function ($post) {
      return sanitize_text_field($post->post_title);
    },
    '[author]' => function ($post) {
      return get_user_by('id', $post->post_author)->dispay_name;
    },
    '[date]' => function ($post) {
      return $post->post_date;
    },
    '[excerpt]' => function ($post) {
      setup_postdata( $post );
      return get_the_excerpt();
    },
    '[pageurl]' => function ($post) {
      return get_permalink($post->id);
    },
  );

  foreach ($codes as $code => $fn) {
      $subject = str_replace($code, $fn($post), $subject);
  }
  return $subject;
}

function konzilo_publish($id) {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        $social_updates = get_post_meta( $id, 'konzilo_post', true);
        konzilo_post_updates($id, $social_updates);
    }
}
add_action( 'publish_post', 'konzilo_publish');


add_action('admin_menu', function () {
  add_options_page (
      __('Social publishing', 'konzilo'),
      __('Social publishing', 'konzilo'),
    'switch_themes',
    'konzilo_settings',
    'konzilo_settings'
  );
  add_menu_page(__('Curate content', 'konzilo'), 'Curate content', 'publish_posts', 'curate_content', 'konzilo_curate');
});

function konzilo_curate() {
  wp_register_script('iframefix', plugins_url( 'dist/iframefix.js', __FILE__ ),
                     array('jquery', 'underscore'));
  wp_enqueue_script('iframefix');
  print '<iframe class="curate" src="https://konzilo.kntnt.it/curate?iframe=1" style="width: 95%; margin-top: 20px; box-shadow: 2px 2px 5px rgba(0,0,0,0.26);"></iframe>';
}

function konzilo_get_queues() {
  return konzilo_get_data('queues');
}

function konzilo_settings() {
  try {
    $queues = konzilo_get_queues();
  }
  catch(Exception $e) {
    echo 'Could not establish connection, check your settings';
    return;
  }
  $queue = get_option('konzilo_queue');
  $defaults = get_option('konzilo_defaults', array());
  if (konzilo_has_client()) {
    wp_register_script('social', plugins_url( 'dist/social.js', __FILE__ ),
                       array('jquery', 'underscore'));
    foreach ($queues as $q) {
        $q->days = array_map(function ($val) {
            return intval($val);
        }, explode(',', $q->days));
    }
    wp_localize_script('social', 'SocialSettings', array(
      'active' => array(),
      'queues' => $queues,
      'queue' => intval($queue),
      'admin_url' => admin_url('admin.php'),
      'nonce' => wp_create_nonce('social_settings'),
      'defaults' => $defaults,
      'profiles' => konzilo_get_profiles(),
      'konziloSocialNonce' => konzilo_create_none_settings( basename( __FILE__ ), 'konzilo_nonce'),
    ));
    wp_localize_script('social', 'SocialTranslations',
                       konzilo_queue_t() + konzilo_box_t());
    wp_enqueue_script('social');
    wp_enqueue_style('social', plugins_url('css/social.css', __FILE__));
    wp_enqueue_style('social', plugins_url( 'fonts/css/fontello-embedded.css', __FILE__ ));

  }
  echo '<div class="wrap"><div id="konzilo-social-queue"></div></div>';

}

class KonziloTwigExtension extends Twig_Extension {
  public function getName() {
    return 'konzilo_form';
  }

  public function getFunctions() {
    return array(
      new Twig_SimpleFunction('checked', function ($values, $type) {
        $values = (object)$values;

        if ((is_array($type) && in_array($values->type, $type)) ||
             $values->type == $type) {
          return 'checked';
        }
      })
    );
  }
}

function konzilo_submit_actions() {
  global $post;
  try {
    $queues = konzilo_get_queues();
    $konzilo_id = get_post_meta($post->ID, 'konzilo_id', true);
    $update = konzilo_get_post_update($post->ID);

    if (empty($update)) {
      $update = array(
        'type' => 'queue_last',
        'queue' => $queues[0]->id
      );
      $konzilo_status = __('Last in') . ' ' . $queues[0]->name;
    }
    else {
      switch ($update->type) {
      case 'now':
        $konzilo_status = __('Publish now', 'konzilo');
        break;

      case 'stored':
        $konzilo_status = __('Parked', 'konzilo');
        break;

      default:
        $queue_map = array();
        foreach ($queues as $queue) {
          $queue_map[$queue->id] = $queue;
        }
        $konzilo_status = __('In') . ' ' . $queue_map[$update->queue]->name;
      }
    }

    $args = array(
      'queues' => $queues,
      'post' => $update,
      'konzilo_status' => $konzilo_status
    );
    $base_dir = plugin_dir_path ( __FILE__ );
    $twig = konzilo_twig($base_dir);
    $twig->addExtension(new KonziloTwigExtension());
    echo $twig->render(
      'templates/publish_form.html', $args);

  }
  catch (Exception $e) {
    // Still not sure how to display these errors.
  }
}
add_action('post_submitbox_misc_actions', 'konzilo_submit_actions');

function konzilo_before_delete($post_id) {
  $konzilo_id = get_post_meta($post_id, 'konzilo_id', true);
  if (empty($konzilo_id)) {
    return;
  }
  try {
    konzilo_delete_data('updates', $konzilo_id);
  }
  catch(Exception $e) {
    //...ŋŋ
  }
}
add_action('before_delete_post', 'konzilo_before_delete');


function konzilo_transition($new_status, $old_status, $post) {
  if ($new_status == $old_status) {
    return;
  }
  $konzilo_id = get_post_meta($post->ID, 'konzilo_id', true);
  if (empty($konzilo_id)) {
    return;
  }
  try {
    $data = konzilo_get_update($konzilo_id);
    $data->status = $new_status;
    if ($new_status == 'published') {
      $data->type = 'now';
    }
    konzilo_put_data('updates', $konzilo_id, array('body' => $data));
  }
  catch(Exception $e) {
    //...
  }
}
add_action('transition_post_status', 'konzilo_transition', 10, 3);


function konzilo_post_status() {
  $args = array(
    'label'                     => _x( 'done', 'Status General Name', 'konzilo' ),
    'label_count'               => _n_noop( 'done (%s)',  'done (%s)', 'konzilo' ),
    'public'                    => false,
    'exclude_from_search'       => true,
  );
  register_post_status( 'done', $args );

}
add_action( 'init', 'konzilo_post_status', 0 );

function konzilo_queue_t() {
  return array(
    'queue' => __('Queue', 'konzilo'),
    'days' => __('Days', 'konzilo'),
    'monday' => __('Monday', 'konzilo'),
    'tuesday' => __('Tuesday', 'konzilo'),
    'wednesday' => __('Wednesday', 'konzilo'),
    'thursday' => __('Thursday', 'konzilo'),
    'friday' => __('Friday', 'konzilo'),
    'saturday' => __('Saturday', 'konzilo'),
    'sunday' => __('Sunday', 'konzilo'),
    'postingTimes' => __('Posting times', 'konzilo'),
    'remove' => __('Remove', 'konzilo'),
    'addPostingTime' => __('Add posting time', 'konzilo'),
    'saveQueue' => __('Save queue', 'konzilo'),
    'createNewQueue' => __('Create new queue', 'konzilo'),
    'createQueue' => __('Create queue', 'konzilo'),
    'name' => __('Name', 'konzilo'),
    'defaults' => __('Default settings for social media', 'konzilo'),
    'saveDefaults' => __('Save defaults', 'konzilo'),
    'queueSettings' => __('Queue settings', 'konzilo'),
    'allDays' => __('All days', 'konzilo')
  );
}

function konzilo_box_t() {
  return array(
    'addProfile' => __('Add profile', 'konzilo'),
    'useShortCodes' => __('You can use the following shortcodes:', 'konzilo'),
    'publishNow' => __('Publish now', 'konzilo'),
    'firstInQueue' => __('First in queue', 'konzilo'),
    'lastInQueue' => __('Last in queue', 'konzilo'),
    'scheduledAt' => __('Scheduled at:', 'konzilo'),
    'addAnotherUpdate' => __('Add another update', 'konzilo'),
    'sentAt' => __('Sent at:', 'konzilo')
  );
}

function konzilo_publishing_t() {
  return array(
    'lastin' => __('Last in', 'konzilo'),
    'firstin'=>  __('First in', 'konzilo'),
    'parked' => __('Parked', 'konzilo'),
    'publishnow' => __('Publish now', 'konzilo')
  );
}
