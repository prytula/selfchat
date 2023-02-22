<?php
/*
Plugin Name: Selfchat
Requires Plugins: bp-better-messages, wptelegram
Plugin URI:
Description: Messaging system for WordPress
Version: 0.0.2
Author: Max L. & Alex P.
License: -
Text Domain: selfchat
Domain Path: /languages
*/
defined('ABSPATH') || exit;

use WPTelegram\BotAPI\API as BotApi;

if (!class_exists('Selfchat')) {
  add_action('plugins_loaded', 'Selfchat_Init', 20);

  class Selfchat {
    public $version = '0.0.2';
    public $db_version = '1.0.0';
    // public $realtime;
    public $path;
    public $url;
    public $settings;
    public $defaults;
    // public $options;
    // public $functions;
    // public $shortcodes;
    // public $api;
    // public $mentions;
    // public $urls;
    // public $files;
    // public $chats;
    // public $email;
    // public $tab;
    // public $hooks;
    // public $groups;
    // public $customize;
    // public $user_config;
    // public $mobile_app = false;
    public $script_variables;

    public static function instance() {
      static $instance = null;
      if (is_null($instance)) {
        $instance = new self();
      }

      return $instance;
    }

    /**
     * Forbid cloning
     */
    public function __clone() {
    }
    /**
     * Forbid deserializing
     */
    public function __wakeup() {
    }

    private function __construct() {
      $this->path = plugin_dir_path(__FILE__);
      $this->url = plugin_dir_url(__FILE__);

      $this->load_textDomain();
      $this->setup_default_settings();
      add_filter('bp_better_messages_overwrite_email', array($this, 'add_wptelegram_hook'), 10, 4);
      add_action('login_init', array($this, 'redirect_logged_in_user_to_conversation'));
      $this->setup_scripts_and_styles();
      // $this->setup_ajax();
      add_action('rest_api_init', array($this, 'rest_api_init'));
      // remove_action('wp_head', array(Better_Messages()->customize, 'header_output'));
      // $this->setup_db();
      add_filter('body_class', array($this, 'change_user_theme'));
    }


    private function setup_scripts_and_styles() {
      add_action('wp_enqueue_scripts', array($this, 'load_scripts'));
    }

    // private function setup_ajax() {
    //   add_action('wp_ajax_getTelegramOptionValue', array($this, 'get_telegram_option_value'));
    //   add_action('wp_ajax_nopriv_getTelegramOptionValue', array($this, 'get_telegram_option_value'));

    //   add_action('wp_ajax_updateTelegramOptionValue', array($this, 'update_telegram_option_value'));
    //   add_action('wp_ajax_nopriv_updateTelegramOptionValue', array($this, 'update_telegram_option_value'));
    // }

    public function change_user_theme($classes) {
      $user_id = get_current_user_id();
      $use_dark_theme = get_user_meta($user_id, 'user_use_dark_theme', true);
      switch ($use_dark_theme) {
        case 'yes':
          if (in_array('bm-messages-light', $classes)) {
            unset($classes[array_search('bm-messages-light', $classes)]);
            $classes[] = array('bm-messages-dark');
          }
          break;
        case 'no':
          if (in_array('bm-messages-dark', $classes)) {
            unset($classes[array_search('bm-messages-dark', $classes)]);
            $classes[] = array('bm-messages-light');
          }
          break;
      }
      return $classes;
    }

    public function load_scripts() {
      if (!is_user_logged_in()) {
        return false;
      }
      $this->enqueue_css();
      $this->enqueue_js();
      return true;
    }

    public function enqueue_css() {
      wp_register_style(
        'selfchat',
        plugins_url('assets/css/selfchat.min.css', __FILE__),
        array('better-messages'),
        $this->version
      );
      wp_enqueue_style('selfchat');
    }

    public function enqueue_js() {
      wp_register_script(
        'selfchat',
        plugins_url('assets/js/selfchat.min.js', __FILE__),
        array('wp-i18n', 'better-messages'),
        $this->version,
        false
      );

      $script_variables = $this->get_script_variables();
      wp_set_script_translations('selfchat', 'selfchat', plugin_dir_path(__FILE__) . 'languages/');
      wp_localize_script('selfchat', 'Selfchat', $script_variables);
      wp_enqueue_script('selfchat');
    }

    private function setup_default_settings() {
      $this->defaults = array(
        'messagesRetention_days' => 365,
        'enableTelegramNotification' => '1',
        'enableThemeSwitch' => '1',
      );
      $args = get_option('selfchat-settings', array());
      $this->settings = wp_parse_args($args, $this->defaults);
    }

    private function load_textDomain() {
      load_plugin_textdomain('selfchat', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    public function redirect_logged_in_user_to_conversation() {
      if (
        is_user_logged_in()
        && !str_contains(basename($_SERVER['REQUEST_URI']), 'wp-login.php?action=logout')
        && isset($_REQUEST['redirect_to'])
      ) {
        $redirect_to = $_REQUEST['redirect_to'];
        if (str_contains($redirect_to, 'conversation')) {
          wp_safe_redirect($redirect_to);
          exit;
        }
      }
    }

    public function notifications_telegram_sender() {
      global $wpdb;

      set_time_limit(0);

      // $this->install_template_if_missing();

      /**
       * Update users without activity
       */
      $user_without_last_activity = $wpdb->get_col("
        SELECT {$wpdb->users}.ID
        FROM {$wpdb->users}
        WHERE ID NOT IN (
            SELECT {$wpdb->usermeta}.user_id
            FROM {$wpdb->usermeta}
            WHERE {$wpdb->usermeta}.meta_key = 'bpbm_last_activity'
            GROUP BY user_id
        )");

      if (count($user_without_last_activity) > 0) {
        foreach ($user_without_last_activity as $user_id) {
          $last_activity = get_user_meta($user_id, 'last_activity', true);

          if (!empty($last_activity)) {
            update_user_meta($user_id, 'bpbm_last_activity', $last_activity);
          } else {
            update_user_meta($user_id, 'bpbm_last_activity', gmdate('Y-m-d H:i:s', 0));
          }
        }
      }

      $minutes = Better_Messages()->settings['notificationsOfflineDelay'];
      $time = gmdate('Y-m-d H:i:s', (strtotime(bp_core_current_time()) - (60 * $minutes)));

      $sql = "SELECT
          usermeta.meta_value AS last_visit,
          usermeta.user_id as user_id,
          " . bm_get_table('recipients') . ".thread_id,
          " . bm_get_table('recipients') . ".unread_count,
          " . bm_get_table('messages') . ".id AS last_id
        FROM " . bm_get_table('recipients') . "
          INNER JOIN {$wpdb->usermeta} as usermeta
            ON " . bm_get_table('recipients') . ".user_id = usermeta.user_id
          INNER JOIN " . bm_get_table('messages') . "
            ON " . bm_get_table('messages') . ".thread_id = " . bm_get_table('recipients') . ".thread_id
              AND " . bm_get_table('messages') . ".id = (
                  SELECT MAX(m2.id)
                  FROM " . bm_get_table('messages') . " m2 
                  WHERE m2.thread_id = " . bm_get_table('recipients') . ".thread_id
              )
        WHERE usermeta.meta_key = 'bpbm_last_activity'
        AND STR_TO_DATE(usermeta.meta_value, '%Y-%m-%d %H:%i:%s') < " . $wpdb->prepare('%s', $time) . "
        AND " . bm_get_table('recipients') . ".unread_count > 0
        AND " . bm_get_table('recipients') . ".is_deleted = 0
        GROUP BY usermeta.user_id,
        " . bm_get_table('recipients') . ".thread_id";

      $unread_threads = $wpdb->get_results($sql);

      $last_notified = array();

      foreach (array_unique(wp_list_pluck($unread_threads, 'user_id')) as $user_id) {
        $meta = get_user_meta($user_id, 'bp-better-messages-last-notified', true);
        $last_notified[$user_id] = (!empty($meta)) ? $meta : array();
      }

      $gmt_offset = get_option('gmt_offset') * 3600;

      foreach ($unread_threads as $thread) {
        $user_id = $thread->user_id;
        $thread_id = $thread->thread_id;

        $chat_id = null;

        $muted_threads = Better_Messages()->functions->get_user_muted_threads($user_id);
        if (isset($muted_threads[$thread_id])) {
          continue;
        }

        $type = Better_Messages()->functions->get_thread_type($thread_id);

        if ($type === 'group') {
          if (Better_Messages()->settings['enableGroupsEmails'] !== '1') {
            $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'group_id');

            if (!empty($group_id)) {
              $last_notified[$user_id][$thread_id] = $thread->last_id;
              continue;
            }
          }

          if (Better_Messages()->settings['PSenableGroupsEmails'] !== '1') {
            $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'peepso_group_id');

            if (!empty($group_id)) {
              $last_notified[$user_id][$thread_id] = $thread->last_id;
              continue;
            }
          }

          if (Better_Messages()->settings['UMenableGroupsEmails'] !== '1') {
            $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'um_group_id');

            if (!empty($group_id)) {
              $last_notified[$user_id][$thread_id] = $thread->last_id;
              continue;
            }
          }
        }

        if ($type === 'chat-room') {
          $chat_id = Better_Messages()->functions->get_thread_meta($thread_id, 'chat_id');

          if (!empty($chat_id)) {
            $is_excluded_from_threads_list = Better_Messages()->functions->get_thread_meta($thread_id, 'exclude_from_threads_list');
            if ($is_excluded_from_threads_list === '1') {
              $last_notified[$user_id][$thread_id] = $thread->last_id;
              continue;
            }

            $notifications_enabled = Better_Messages()->functions->get_thread_meta($thread_id, 'enable_notifications');
            if ($notifications_enabled !== '1') {
              $last_notified[$user_id][$thread_id] = $thread->last_id;
              continue;
            }
          }
        }

        if (get_user_meta($user_id, 'notification_telegram_messages_new_message', true) == 'no') {
          $last_notified[$user_id][$thread_id] = $thread->last_id;
          continue;
        }


        $ud = get_userdata($user_id);

        if (!isset($last_notified[$user_id][$thread_id]) || ($thread->last_id > $last_notified[$user_id][$thread_id])) {

          $user_last = (isset($last_notified[$user_id][$thread_id])) ? $last_notified[$user_id][$thread_id] : 0;

          $query = $wpdb->prepare("
                    SELECT
                      `messages`.id,
                      `messages`.message,
                      `messages`.sender_id,
                      `threads`.subject,
                      `messages`.date_sent
                    FROM " . bm_get_table('messages') . " as messages
                    LEFT JOIN " . bm_get_table('threads') . " as threads ON
                        threads.id = messages.thread_id
                    LEFT JOIN " . bm_get_table('meta') . " messagesmeta ON
                    ( messagesmeta.`bm_message_id` = `messages`.`id` AND messagesmeta.meta_key = 'bpbm_call_accepted' )
                    WHERE `messages`.thread_id = %d
                    AND `messages`.id > %d 
                    AND `messages`.sender_id != %d 
                    AND `messages`.sender_id != 0 
                    AND ( messagesmeta.meta_id IS NULL )
                    ORDER BY id DESC
                    LIMIT 0, %d
                ", $thread->thread_id, $user_last, $user_id, $thread->unread_count);

          $messages = array_reverse($wpdb->get_results($query));

          if (empty($messages)) {
            continue;
          }

          foreach ($messages as $index => $message) {
            if ($message->message) {
              $is_sticker = strpos($message->message, '<span class="bpbm-sticker">') !== false;
              if ($is_sticker) {
                $message->message = __('Sticker', 'bp-better-messages');
              }

              $is_gif = strpos($message->message, '<span class="bpbm-gif">') !== false;
              if ($is_gif) {
                $message->message = __('GIF', 'bp-better-messages');
              }
            }
          }

          if (empty($messages)) {
            continue;
          }

          $last_id = 0;
          foreach ($messages as $message) {
            $last_id = $message->sender_id;
          }
          $last_notified[$user_id][$thread_id] = $thread->last_id;
          update_user_meta($user_id, 'bp-better-messages-last-notified', $last_notified[$user_id]);
        }
      }
    }
    public function add_wptelegram_hook($overwritten, $user_id, $thread_id, $messages) {
      $bot_token = WPTG()?->options()?->get('bot_token');
      $tg_api = new BotApi($bot_token);
      $chat_id = get_userdata($user_id)?->{WPTELEGRAM_USER_ID_META_KEY};

      if (!empty($bot_token) && !empty($tg_api) && !empty($chat_id)) {
        $thread_url = esc_url(
          Better_Messages()->functions->add_hash_arg(
            'conversation/' . $thread_id,
            [],
            Better_Messages()->functions->get_link($user_id)
          )
        );
        if (!empty($thread_url)) {
          $thread_url = esc_url(wp_login_url(home_url($thread_url)));
          $message =
            '<i><a href="' . $thread_url . '">' .
            sprintf(
              esc_html__('Continue to chat with %s on website:', 'selfchat'),
              '<b>' .
                wp_strip_all_tags(stripslashes(get_userdata(reset($messages)->sender_id)?->display_name)) .
                '</b>'
            ) .
            '</a></i>';
          error_log(print_r($messages, true));
          foreach ($messages as $_message) {
            $sender = get_userdata($_message->sender_id);
            if (!is_object($sender)) {
              continue;
            }

            $timestamp = strtotime($_message->date_sent) + get_option('gmt_offset') * 3600;
            $time_format = get_option('time_format');

            if (gmdate('Ymd') != gmdate('Ymd', $timestamp)) {
              $time_format .= ' ' . get_option('date_format');
            }

            $allowed_tags = array(
              'b' => array(),
              'i' => array(),
              'u' => array(),
              's' => array(),
              'strong' => array(),
              'em' => array(),
              'ins' => array(),
              'del' => array(),
              'strike' => array(),
              'a' => array(
                'href' => true
              )
            );
            $message .= "\n" . wp_kses(str_replace(
              array('<br>', '<!-- BM-ONLY-FILES -->'),
              array("\n", '<b>' . esc_html__('Attached file(s)', 'selfchat') . '</b>'),
              stripslashes($_message->message)
            ), $allowed_tags);
            $message .= "\n" . wp_strip_all_tags(stripslashes(date_i18n($time_format, $timestamp))) . "\n";
          }
          error_log(print_r($message, true));
          $params = array('chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'HTML');
          $api_res = $tg_api->sendMessage($params);
        }
      }
      return $overwritten;
    }

    public function get_script_variables() {
      $script_variables = array(
        'hash' => md5(serialize($this->settings) . $this->db_version),
        'user_id' => get_current_user_id(),
        // 'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => esc_url_raw(get_rest_url(null, '/selfchat/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        // 'siteRefresh'        => ( isset( $this->settings['site_interval'] ) ? intval( $this->settings['site_interval'] ) * 1000 : 10000 ),
        // 'threadRefresh'      => ( isset( $this->settings['thread_interval'] ) ? intval( $this->settings['thread_interval'] ) * 1000 : 3000 ),
        // 'assets'             => plugin_dir_url( __FILE__ ) . 'assets/',
        'telegramNotification' => ($this->settings['enableTelegramNotification'] == '1' ? '0' : '1')
      );
      $this->script_variables = $script_variables;
      return $this->script_variables;
    }
    public function rest_api_init() {
      register_rest_route('selfchat/v1', '/userSettings', array(
        'methods' => 'GET',
        'callback' => array($this, 'user_settings'),
        'permission_callback' => array(Better_Messages_Rest_Api(), 'is_user_authorized')
      ));

      register_rest_route('selfchat/v1', '/userSettings/save', array(
        'methods' => 'POST',
        'callback' => array($this, 'user_settings_save'),
        'permission_callback' => array(Better_Messages_Rest_Api(), 'is_user_authorized')
      ));
    }

    public function user_settings(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $settings = [];
      $notifications_options = [];

      if (Selfchat()->settings['enableTelegramNotification'] === '1') {
        $notifications_options[] = [
          'id' => 'telegram_notifications',
          'label' => _x('Enable notifications via Telegram', 'User settings', 'selfchat'),
          'value' => 'yes',
          'checked' => (get_user_meta($user_id, 'notification_telegram_new_message', true) !== 'no'),
          'desc' => _x('When enabled, you will receive notifications about new messages via Telegram when you are offline.', 'User settings', 'selfchat')
        ];
      }
      if (Selfchat()->settings['enableThemeSwitch'] === '1') {
        $value = get_user_meta($user_id, 'user_use_dark_theme', true);
        $notifications_options[] = [
          'id' => 'dark_theme',
          'label' => _x('Enable Dark theme', 'User settings', 'selfchat'),
          'value' => ($value === 'auto') ? 'auto' : (($value === 'yes') ? 'yes' : 'no'),
          'checked' => ($value === 'yes'),
          'desc' => ''
        ];
      }

      if (count($notifications_options) > 0) {
        $notifications = [
          'title' => _x('Notifications', 'User settings', 'bp-better-messages'),
          'options' => $notifications_options
        ];
      }

      $settings[] = $notifications;

      return $settings;
    }
    public function user_settings_save(WP_REST_Request $request) {
      $user_id = get_current_user_id();

      $option = sanitize_text_field($request->get_param('option'));
      $value = sanitize_text_field($request->get_param('value'));

      $message = array('message' => _x('Saved successfully', 'User settings', 'bp-better-messages'));

      switch ($option) {
        case 'telegram_notifications':
          $new_value = ($value === 'false') ? 'no' : 'yes';
          update_user_meta($user_id, 'notification_telegram_new_message', $new_value);
          break;
        case 'dark_theme':
          $new_value = ($value === 'auto') ? 'auto' : (($value === 'yes') ? 'yes' : 'no');
          $message['options']['set_theme'] = $new_value === 'auto' ? get_theme_mod('bm-theme', 'light') : (($new_value === 'yes') ? 'dark' : 'light') ;
          update_user_meta($user_id, 'user_use_dark_theme', $new_value);
          break;
      }

      return $message;
    }
  }
  function Selfchat() {
    return Selfchat::instance();
  }
  function Selfchat_Init() {
    $error = false;
    if (version_compare(PHP_VERSION, '7.4') < 0) {
      add_action('admin_notices', 'selfchat_incompatible_PHP');
      $error = true;
    }
    if (!class_exists('Better_Messages') || !defined('WPTELEGRAM_LOADED')) {
      add_action('admin_notices', 'selfchat_dependencies_error');
      $error = true;
    }
    if (!$error) {
      Selfchat();
    }
  }
  function selfchat_dependencies_error() { ?>
    <div class="error notice">
      <p>
        <?php
        $required_plugins = array(
          'Better Messages' => 'bp-better-messages/bp-better-messages.php',
          'WP Telegram' => 'wptelegram/wptelegram.php',
        );
        $missing_plugin_names = array_keys(array_filter(
          $required_plugins,
          function ($main_plugin_file_path) {
            return !in_array($main_plugin_file_path, apply_filters('active_plugins', get_option('active_plugins')));
          },
          ARRAY_FILTER_USE_BOTH
        ));

        printf(
          wp_kses(
            __(
              '<b>%s:</b> The <b>%s</b> plugin cannot execute because the following required plugins are not active: <b>%s</b>. Please activate these plugins.',
              'selfchat'
            ),
            array(
              'b' => array()
            )
          ),
          esc_html__('Error', 'selfchat'),
          esc_html__('Selfchat', 'selfchat'),
          esc_html(implode(', ', $missing_plugin_names))
        ); ?>
      </p>
    </div>
  <?php
  }
  function selfchat_incompatible_PHP() { ?>
    <div class="error notice">
      <p>
        <?php
        printf(
          wp_kses(
            __(
              '<b>%s:</b> The <b>%s</b> plugin require at least <b>PHP 7.4</b>, currently running <b>PHP %s</b>. Please upgrade your website PHP version.',
              'selfchat'
            ),
            array(
              'b' => array()
            )
          ),
          esc_html__('Error', 'selfchat'),
          esc_html__('Selfchat', 'selfchat'),
          esc_html(PHP_VERSION)
        ); ?>
      </p>
    </div>
<?php
  }
}
