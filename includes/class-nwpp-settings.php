<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'NWPP_Settings' ) ) {
  /**
   * Class responsible for managing plugin's settings.
   */
  class NWPP_Settings {
    /**
     * Settings page slug.
     * @var string
     */
    public $slug = 'nwpp';

    /**
     * Name of the option group used in plugin's settings.
     * @var string
     */
    private $settings_group = 'nwpp-options';

    /**
     * Path to Google Analytics private key.
     * @var string
     */
    public $config_file_path = NWPP_DIR_PATH . 'uploads/client-secrets.json';

    /**
     * Field names/ids of the all setting fields.
     * @var array
     */
    private $field_names = [
      'enabled'           => 'nwpp-enabled',
      'tracking_id'       => 'nwpp-tracking-id-setting',
      'application_name'  => 'nwpp-application-name-setting',
      'view_id'           => 'nwpp-view-id-setting',
      'client_file'       => 'nwpp-client-file',
      'do_imgs_cache'     => 'nwpp-do-imgs-cache',
      'max_cache_size'    => 'nwpp-max-cache-size',
    ];

    /**
     * Register settings action hooks.
     */
    public function register() {
      add_action( 'admin_enqueue_scripts', 'wp_enqueue_media' );
      add_action( 'admin_menu', [$this, 'add_settings_page'] );
      add_action( 'admin_init', [$this, 'register_ga_settings'], 10 );
      add_action( 'admin_init', [$this, 'process_ga_config_file'] );
    }

    /**
     * Validate and maybe save uploaded GA private key.
     */
    public function process_ga_config_file() {
      if ( ! isset( $_FILES[ $this->field_names['client_file'] ] ) ) {
        return;
      }

      $file_data = $_FILES[ $this->field_names['client_file'] ];
      if ( !isset($file_data['tmp_name']) || empty($file_data['tmp_name']) ) {
        return;
      }

      if ( $file_data['error'] ) {
        $message = __( 'Error while uploading the Google\'s private key.', 'nwpp' );
        return $this->raise_error( $message );
      }

      try {
        $file = fopen( $file_data['tmp_name'], 'r' );
        $config_raw = fread( $file, $file_data['size'] );
        fclose( $file );
      } catch ( Exception $e ) {
        $message = __( 'Error while trying to read Google\'s private key.', 'nwpp' );
        return $this->raise_error( $message );
      }

      try {
        $config = json_decode( $config_raw, true );
      } catch ( Exception $e ) {
        $message = __( 'Google\'s private key should be valid JSON file.', 'nwpp' );
        return $this->raise_error( $message );
      }

      try {
        if ( ! isset( $config['type'] ) && $config['type'] !== 'service_account' ) {
          $message = __( 'Google\'s private key should be of "service_account" type.', 'nwpp' );
          return $this->raise_error( $message );
        }

        $config_approved_keys = [
          'type', 'project_id', 'private_key_id', 'private_key', 'client_email',
          'client_id', 'auth_uri', 'token_uri', 'auth_provider_x509_cert_url',
          'client_x509_cert_url'
        ];
        $config_keys = array_keys( $config );
        $config_valid_keys = array_intersect($config_keys, $config_approved_keys);

        if ( $config_approved_keys !== $config_valid_keys ) {
          $message = __( 'Google\'s private key contains invalid keys.', 'nwpp' );
          return $this->raise_error( $message );
        }
      } catch ( Exception $e ) {
        $message = __( 'Failed to process Google\'s private key.', 'nwpp' );
        return $this->raise_error( $message );
      }

      try {
        $file = fopen( $this->config_file_path, 'w' );
        fwrite( $file, $config_raw );
        chmod( $this->config_file_path, 0600 );
        fclose( $file );
      } catch ( Exception $e ) {
        $message = __( 'Failed to save Google\'s private key.', 'nwpp' );
        return $this->raise_error( $message );
      }

    }

    /**
     * Register settings error with the given message which will be displayed
     * on the WP's admin interface.
     * @param string $message
     */
    public function raise_error( $message ) {
      add_settings_error( $this->slug, 'nwpp-ga-settings', $message, 'error' );
    }

    /**
     * Register Google Analytics settings fields.
     */
    public function register_ga_settings() {
      $section_name = 'nwpp-ga-settings';
      $is_config_uploaded = file_exists( $this->config_file_path );
      $config_desc = ! $is_config_uploaded ? 'Valid file already uploaded.' :
        '
          <ol>
            <li>Open the <a target="_blank" href="https://console.developers.google.com/iam-admin/serviceaccounts">Service accounts page</a>.</li>
            <li>Select or create a new project.</li>
            <li>Click <b>Create service account</b>.</li>
            <li>Follow steps, type a name for the service account, select viewer role, create a new JSON private key, and then click Done.</li>
            <li>Upload here downloaded JSON file.</li>
            <li>Copy email address from the selected or newly created project, it should be formated as a <i>NAME@PROJECT_ID.iam.gserviceaccount.com</i>.</li>
            <li>Use that email to <a target="_blank" href="https://support.google.com/analytics/answer/1009702">add a new user</a> to the Google analytics view,
            only <a target="_blank" href="https://support.google.com/analytics/answer/2884495">Read & Analyze</a> permissions are needed.</li>
          </ol>
        ';

      $fields = [
        [
          'type'       => 'checkbox',
          'class'      => 'nwpp-checkbox',
          'id'         => $this->field_names['enabled'],
          'title'      => __( 'Enable cache', 'nwpp' ),
          'value'      => 1,
          'checked'    => $this->is_enabled(),
          'callback'   => [$this, 'do_ga_setting'],
        ],
        [
          'type'       => 'file',
          'id'         => $this->field_names['client_file'],
          'title'      => __( 'Google\'s private key', 'nwpp' ),
          'value'      => 1,
          'callback'   => [$this, 'do_ga_setting'],
          'register'   => false,
          'desc'       => $config_desc,
        ],
        [
          'id'          => $this->field_names['view_id'],
          'title'       => __( 'View ID', 'nwpp' ),
          'value'       => $this->get_view_id(),
          'callback'    => [$this, 'do_ga_setting'],
          'desc'        => __( 'From Analytics Account -> Properties & Apps -> Views.', 'nwpp' ),
        ],
        [
          'type'        => 'checkbox',
          'class'       => 'nwpp-checkbox',
          'id'          => $this->field_names['do_imgs_cache'],
          'title'       => __( 'Cache Images', 'nwpp' ),
          'value'       => 1,
          'checked'     => $this->do_imgs_cache(),
          'callback'    => [$this, 'do_ga_setting'],
          'desc'        => __( 'Should the images from the target pages be cached.', 'nwpp' ),
        ],
        [
          'type'        => 'number',
          'id'          => $this->field_names['max_cache_size'],
          'title'       => __( 'Max Cache Size (MB)', 'nwpp' ),
          'value'       => $this->get_max_cache_size(),
          'callback'    => [$this, 'do_ga_setting'],
          'min'         => 1,
          'max'         => 50,
        ],
      ];

      $this->register_settings(
        $section_name,
        __( 'Next Web Page Cache Settings', 'nwpp' ),
        'do_ga_settings_section',
        $fields
      );
    }

    /**
     * Register section and settings fields.
     * @param string $section_name
     * @param string $section_title
     * @param string $section_function Function name, should be part of class.
     * @param array  $fields
     */
    private function register_settings( $section_name, $section_title, $section_function, $fields ) {
      add_settings_section(
        $section_name,
        $section_title,
        [$this, $section_function],
        $this->settings_group
      );

      foreach ( $fields as $field ) {
        $args = [
          'type'        => isset( $field['type'] ) ? $field['type'] : 'text',
          'class'       => isset( $field['class'] ) ? $field['class'] : '',
          'value'       => $field['value'],
          'desc'        => isset( $field['desc'] ) ? $field['desc'] : '',
          'label_for'   => $field['id'],
          'placeholder' => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
          'key_exists'  => isset( $field['key_exists'] ) ? $field['key_exists'] : '',
          'checked'     => isset( $field['checked'] ) ? (bool) $field['checked'] : false
        ];

        if ( isset( $field['min'] ) ) {
          $args['min'] = intval( $field['min'] );
        }

        if ( isset( $field['max'] ) ) {
          $args['max'] = intval( $field['max'] );
        }

        add_settings_field(
          $field['id'],
          $field['title'],
          $field['callback'],
          $this->settings_group,
          $section_name,
          $args
        );

        if ( ! isset($field['register']) || $field['register'] ) {
          register_setting( $this->settings_group, $field['id'] );
        }
      }
    }

    /**
     * Display HTML section description for the Google Analytics section.
     * @param array $args Section settings data.
     */
    public function do_ga_settings_section( $args ) {
      ?>
      <p id="<?php echo esc_attr( $args['id'] ); ?>">
        <?php esc_html_e( 'Options for the Google Analytics account.', 'nwpp' ); ?>
      </p>
      <?php
    }

    /**
     * Echo key setting field HTML.
     * @param array $args Setting field's data.
     */
    public function do_ga_setting( $args ) {
      ?>
      <input type="<?php echo $args['type']; ?>"
        id="<?php echo $args['label_for']; ?>"
        name="<?php echo $args['label_for']; ?>"
        value="<?php echo $args['value']; ?>"
        class="<?php echo ! empty($args['class']) ? $args['class'] : 'large-text'; ?>"
        placeholder="<?php if ( ! empty($args['placeholder']) ) echo $args['placeholder']; ?>"
        <?php if ( isset( $args['checked'] ) && $args['checked'] ): ?> checked <?php endif; ?>
        <?php if ( isset( $args['min'] ) ): ?> min="<?php echo $args['min']; ?>" <?php endif; ?>
        <?php if ( isset( $args['max'] ) ): ?> max="<?php echo $args['max']; ?>" <?php endif; ?>
      >
      <?php if ( ! empty( $args['desc'] ) ): ?>
        <p class="description"><?php echo $args['desc']; ?></p>
      <?php endif; ?>

      <?php
    }

    /**
     * Register plugin's settins page.
     */
    public function add_settings_page() {
      add_submenu_page(
        'options-general.php',
        __( 'Neuralab Next Web Page Cache', 'nwpp' ),
        __( 'Next Web Page Cache', 'nwpp' ),
        'manage_options',
        $this->slug,
        [$this, 'do_settings_page']
      );
    }

    /**
     * Output HTML for the settings page.
     */
    public function do_settings_page() {
      if ( ! current_user_can('manage_options') ) {
        return;
      }
      ?>
      <div class="wrap">
        <h1><?php esc_html_e( get_admin_page_title() ); ?></h1>
        <form method="post" action="options.php" enctype="multipart/form-data">
          <?php settings_fields( $this->settings_group ); ?>
          <?php do_settings_sections( $this->settings_group ); ?>
          <?php submit_button(); ?>
        </form>
      </div>
      <?php
    }

    /**
     * Return true if tracking is enabled. Defaults to false.
     * @return bool
     */
    public function is_enabled() {
      return (bool) get_option( $this->field_names['enabled'], false );
    }

    /**
     * Return Google Analytics tracking (property) ID.
     * @return string
     */
    public function get_tracking_id() {
      return get_option( $this->field_names['tracking_id'], '' );
    }

    /**
     * Return application name or empty string if none.
     * @return string
     */
    public function get_application_name() {
      return get_option( $this->field_names['application_name'], '' );
    }

    /**
     * Return max allowed cache size.
     * @return int
     */
    public function get_max_cache_size() {
      $max_cache_size = get_option( $this->field_names['max_cache_size'], 1 );
      try {
        $max_cache_size = intval($max_cache_size);
      } catch ( Exception $e ) {
        $max_cache_size = 1;
      }
      return $max_cache_size;
    }

    /**
     * Return true if images should be cached or false otherwise.
     * @return bool
     */
    public function do_imgs_cache() {
      $do_imgs_cache = get_option( $this->field_names['do_imgs_cache'], false );
      return filter_var( $do_imgs_cache, FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * Return View ID for Google Analytics or empty string if none found.
     * @return string
     */
    public function get_view_id() {
      $view_id = get_option( $this->field_names['view_id'], false );
      if ( ! $view_id ) {
        return '';
      }

      if ( substr( $view_id, 0, 3 ) === 'ga:' ) {
        return $view_id;
      }
      return 'ga:' . $view_id;
    }

  }
}
