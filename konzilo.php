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

function konzilo_get_updates($post_id) {
  static $updates = array();
  if (empty($updates)) {
    $updates = wp_cache_get('konzilo_updates_' . $post_id);
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
      $profiles = konzilo_get_profiles();
      $konziloSocialNonce = konzilo_create_none_settings( basename( __FILE__ ), 'konzilo_nonce');
      wp_register_script('social', plugins_url( 'dist/social.js', __FILE__ ), array('jquery', 'underscore'));
      wp_localize_script('social', 'SocialSettings', array(
        'profiles' => $profiles,
        'konziloSocialNonce' => $konziloSocialNonce,
      ));
      wp_localize_script('social', 'SocialTranslations', konzilo_box_t());
      wp_enqueue_script('social');
      wp_enqueue_style('social', plugins_url('css/social.css', __FILE__));
      wp_enqueue_style('social-fonts', plugins_url( 'fonts/css/fontello-embedded.css', __FILE__ ));
      add_action('add_meta_boxes', 'konzilo_add_meta_boxes');
      add_action('save_post', 'konzilo_save_meta', 10, 2 );
    } catch(Exception $e) {
      // Notify the user somehow...
    }
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

  $is_new = in_array( $pagenow, array( 'post-new.php' ) );
  $profiles = konzilo_get_profiles();
  $updates = konzilo_get_updates($post->ID);
  $profile_map = konzilo_indexby('id', $profiles);
  $update_map = konzilo_indexby('id', $updates);
  $meta_value = get_post_meta( $post->ID, 'konzilo_post', true );
  if (empty($meta_value)) {
    if ($is_new) {
      $meta_value = get_option('konzilo_defaults', array());
    }
    else {
      $meta_value = array();
    }
  }
  // remove any profiles that doesnt exist anymore.
  // and add the sent parameter for updates that have been sent.
  foreach ($meta_value as $bkey => &$box) {
    if (!empty($box['id'])) {
      if (!empty($update_map[$box['id']])) {
        $box['sent'] = $update_map[$box['id']]->sent;
        if (!empty($update_map[$box['id']]->sent_at)) {
          $box['sent_at'] = $update_map[$box['id']]->sent_at;
        }
      }
      // This update is not present on the server, but it has been there.
      // Let's remove it.
      else {
        unset($meta_value[$bkey]);
      }
    }
    foreach ($box['active'] as $key => $profile) {
      if (empty($profile_map[$profile['profileId']])) {
        unset($box['active'][$key]);
      }
    }
  }
  // Add status to the posts.

  wp_localize_script('social', 'socialdata', array(
    'values' => array_values($meta_value),
  ));
  wp_enqueue_script('social');

  add_meta_box(
    'konzilo-social-post',      // Unique ID
    esc_html__( 'Social media', 'example' ),    // Title
    'konzilo_meta_box',   // Callback function
    'post',         // Admin page (or post type)
    'normal',         // Context
    'high'         // Priority
  );
}

function konzilo_meta_box( $object, $box ) {
  if (!konzilo_has_client()) {
    echo "Konzilo isn't authorized";
    return;
  }
  return;
}

function konzilo_save_meta( $post_id, $post ) {
  /* Verify the nonce before proceeding. */
  if ( !isset( $_POST['konzilo_nonce'] ) || !wp_verify_nonce( $_POST['konzilo_nonce'], basename( __FILE__ ) ) )
    return $post_id;

  /* Get the post type object. */
  $post_type = get_post_type_object( $post->post_type );

  /* Check if the current user has permission to edit the post. */
  if ( !current_user_can( $post_type->cap->edit_post, $post_id ) )
    return $post_id;

  $meta_key = 'konzilo_post';
  $meta_value = get_post_meta( $post_id, $meta_key, true );
  $boxes = konzilo_box_from_post($meta_value);
  $new_meta_value = $boxes;


  /* If a new meta value was added and there was no previous value, add it. */
  if (!empty($new_meta_value)) {
    if ($_POST['post_status'] == 'publish') {
      konzilo_post_updates($post_id, $new_meta_value, $meta_value);
    }
    if (empty($meta_value)) {
      add_post_meta( $post_id, $meta_key, $new_meta_value, true );
    }
    /* If the new meta value does not match the old value, update it. */
    else {
      update_post_meta( $post_id, $meta_key, $new_meta_value );
    }
  }
  /* If there is no new meta value but an old value exists, delete it. */
  elseif ( '' == $new_meta_value && $meta_value )
    delete_post_meta( $post_id, $meta_key, $meta_value );

  // Flush update cache.
  wp_cache_delete('konzilo_updates_' . $post_id);
}

function konzilo_box_from_post($old_data) {
  $boxes = array();
  if (isset($_POST['socialbox_rows'])) {
    foreach($_POST['socialbox_rows'] as $id) {
      $boxes[$id] = array(
        'active' => array(),
        'publishSettings' => array(),
      );
      if (isset($_POST['updateid'][$id])) {
        $boxes[$id]['id'] = $_POST['updateid'][$id];
      }
      if (empty($_POST['socialbox'][$id])) {
        if (!empty($old_data[$id])) {
          $boxes[$id] = $old_data[$id];
        }
      }
      else {
        $profiles = $_POST['socialbox'][$id];
        foreach($profiles as $profile_id => $text) {
          $update_text = array(
            'profileId' => $profile_id,
            'text' => (($text) ? $text : ''),
          );
          if (isset($_POST['socialboxid'][$id][$profile_id])) {
            $update_text['id'] = $_POST['socialboxid'][$id][$profile_id];
          }
          $boxes[$id]['active'][] = $update_text;
        }
        if (isset($_POST['socialPublishSettings'][$id])) {
          date_default_timezone_set(get_option('timezone_string'));
          $boxes[$id]['publishSettings']['type'] = $_POST['socialPublishSettings'][$id];
          if ( isset($_POST['socialPublishDate'][$id])
               && isset($_POST['slots'][$id]['hour'])
               && isset($_POST['slots'][$id]['minute'])) {
            $date = explode('-', $_POST['socialPublishDate'][$id]);
            $value = date('c', mktime($_POST['slots'][$id]['hour'],
                                      $_POST['slots'][$id]['minute'],
                                      0,
                                      $date[1],
                                      $date[2],
                                      $date[0]));
            $boxes[$id]['publishSettings']['value'] = $value;
          }
        }
      }
    }
  }
  return $boxes;
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

function konzilo_post_updates($id, &$social_updates, $old_updates) {
  $post = get_post($id);
  $defaults = array(
    'post_id' => $id,
    'link' => get_permalink($id),
    'updates' => array(),
  );
  if (is_multisite()) {
    $defaults['page'] = get_option('konzilo_page');
    $defaults['organisation'] = get_option('konzilo_organisation');
  }
    $get_values = function ($updates) {
        $updates = array_filter($updates, function ($update) {
            return !empty($update['id']);
        });
        return array_map(function ($update) {
            return $update['id'];
        }, $updates);
    };
    if (!empty($old_updates)) {
        $new_ids = $get_values($social_updates);
        $old_ids = $get_values($old_updates);
        // Remove boxes that shouldnt be there anymore.
        foreach (array_diff($old_ids, $new_ids) as $delete_id) {
            try {
                konzilo_delete_data('updates', $delete_id);
            }
            catch(Exception $e) {
              // We might come across things that are already deleted.
              // it's fine.
            }
        }
    }
    foreach ($social_updates as $key => $update) {
        // Boxes without active profiles are no use saving.
        if (empty($update['active'])) {
            continue;
        }
        $data = array(
            'type' => $update['publishSettings']['type'],
        ) + $defaults;

        if (!empty($update['id'])) {
            $data['id'] = $update['id'];
        }

        if (!empty($update['publishSettings']['value'])) {
            $data['scheduled_at'] = $update['publishSettings']['value'];
        }

        foreach ($update['active'] as $text) {
          if (!empty($text['text'])) {
            $update = array(
              'profile' => $text['profileId'],
              'text' => konzilo_translate_codes($text['text'], $post),
            );
            if (!empty($text['id'])) {
              $update['id'] = $text['id'];
            }
            $data['updates'][] = $update;
          }
        }
        if (!empty($data['updates'])) {
          $queue = get_option('konzilo_queue');
          if (!empty($queue)) {
            $data['queue'] = $queue;
          }
          if (empty($data['sent'])) {
            if (empty($data['id'])) {
              $result = konzilo_post_data(
                'updates',
                array('body' => $data));
            }
            else {
              $result = konzilo_put_data('updates', $data['id'], array(
                'body' => $data));
            }
          }
          $social_updates[$key]['id'] = $result->id;
          foreach ($social_updates[$key]['active'] as $key => &$active) {
            $active['id'] = $result->updates[$key]->id;
            $active['profileId'] = $result->updates[$key]->profile;
          }
        }
    }
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

add_action('admin_init', function () {
  register_setting( 'konzilo_settings', 'konzilo_save_queue');
});


function konzilo_save_queue() {
    if (empty($_POST['social_nonce']) || !wp_verify_nonce($_POST['social_nonce'], 'social_settings')) {
        wp_die(_('Could not verify request'));
        exit();
    }

  $body = array(
    'name' => sanitize_text_field($_POST['name']),
    'slots' => array(),
  );
  if (is_multisite()) {
    $body['organisation'] = get_option('konzilo_organisation');
  }
  $days = array();
  foreach ($_POST['days'] as $day => $value) {
    if ($value || $value == 0) {
      $days[] = $day;
    }
  }
  if (!empty($_POST['slots'])) {
    foreach ($_POST['slots'] as $slot) {
      $body['slots'][] = $slot['hour'] . ':' . $slot['minute'] . ':00';
    }
  }
  $body['days'] = implode(',', $days);
  konzilo_post_data('queues', array('body' => $body));
  wp_redirect( $_SERVER['HTTP_REFERER'] . '&updated=savequeue' );
  exit();
}
add_action('admin_action_konzilo_save_queue', 'konzilo_save_queue');

function konzilo_edit_queue() {
    if (empty($_POST['social_nonce']) || !wp_verify_nonce($_POST['social_nonce'], 'social_settings')) {
        wp_die(_('Could not verify request'));
        exit();
    }
  $body = array(
    'id' => sanitize_text_field($_POST['id']),
    'name' => sanitize_text_field($_POST['name']),
    'slots' => array(),
  );
  if (is_multisite()) {
    $body['organisation'] = get_option('konzilo_organisation');
  }
  $days = array();
  foreach ($_POST['days'] as $day => $value) {
    if ($value || $value == 0) {
      $days[] = $day;
    }
  }
  if (!empty($_POST['slots'])) {
    foreach ($_POST['slots'] as $slot) {
      $body['slots'][] = $slot['hour'] . ':' . $slot['minute'] . ':00';
    }
  }
  $body['days'] = implode(',', $days);
  konzilo_put_data('queues', $body['id'], array('body' => $body));
  update_option('konzilo_queue', intval($body['id']));
  wp_redirect( $_SERVER['HTTP_REFERER'] . '&updated=queue' );
  exit();
}
add_action('admin_action_konzilo_edit_queue', 'konzilo_edit_queue');

function konzilo_save_defaults() {
  $box = konzilo_box_from_post();
  update_option('konzilo_defaults', $box);
  wp_redirect( $_SERVER['HTTP_REFERER'] . '&updated=defaults' );
  exit();
}
add_action('admin_action_social_save_defaults', 'konzilo_save_defaults');

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

function konzilo_before_delete($post_id) {
  $konzilo_id = get_post_meta($post_id, 'konzilo_id', true);
  if (empty($konzilo_id)) {
    return;
  }
  try {
    konzilo_delete_data('updates', $konzilo_id);
  }
  catch(Exception $e) {
    //...
  }
}

add_action('before_delete_post', 'konzilo_before_delete');

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
