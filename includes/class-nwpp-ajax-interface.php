<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}


if ( ! class_exists( 'NWPP_Ajax_Interface' ) ) {
  /**
   * Class responsible for exposing ajax endpoints for fetching recommendations.
   */
  class NWPP_Ajax_Interface {

    /**
     * Define class attributes.
     */
    public function __construct( $register = false ) {
      require_once NWPP_DIR_PATH . 'includes/class-nwpp-db.php';
      require_once NWPP_DIR_PATH . 'includes/class-nwpp-settings.php';

      $this->db       = new NWPP_DB();
      $this->settings = new NWPP_Settings();
    }

    /**
     * Define all the needed hooks.
     */
    public function register() {
      add_action( 'wp_enqueue_scripts', [$this, 'register_client_script'] );
      add_action( 'wp_ajax_nwpp_get_predictions', [$this, 'get_predictions'] );
      add_action( 'wp_ajax_nopriv_nwpp_get_predictions', [$this, 'get_predictions'] );
    }

    /**
     * Reduce entry object to $id2path array.
     */
    public function reduce_db_entries( $id2path, $entry ) {
      $id2path[ $entry->id ] = $entry->path;
      return $id2path;
    }

    /**
     * Echo predictions for the given pathnames via $_POST. Should be used as a
     * ajax endpoint.
     */
    public function get_predictions() {
      if ( ! isset( $_POST['current_pathname'] ) || empty( $_POST['current_pathname'] ) ) {
        wp_send_json([]);
      }

      $current   = $this->esc_pathname( $_POST['current_pathname'] );
      $pathnames = $this->get_pathnames_post_variable();

      $all_pathnames   = $pathnames;
      $all_pathnames[] = $current;
      $entries         = $this->db->get_entries( $all_pathnames );

      $id2path = array_reduce( $entries, [$this, 'reduce_db_entries'] );

      if ( ! array_key_exists( $current, $entries ) ) {
        wp_send_json([]);
      }

      try {
        $rels = unserialize( $entries[$current]->relationships );
      } catch ( Exception $e ) {
        wp_send_json([]);
      }

      $path2weight = [];
      foreach ( $rels as $id => $rel ) {
        if ( array_key_exists( $id, $id2path ) ) {
          $path2weight[ $id2path[$id] ] = $rels[$id];
        }
      }

      arsort( $path2weight );
      $sorted_pathnames    = array_keys( $path2weight );
      $not_found_pathnames = array_diff( $pathnames, $sorted_pathnames );

      $sorted_not_found_pathnames = [];
      foreach ( $not_found_pathnames as $pathname ) {
        if ( array_key_exists( $pathname, $entries ) ) {
          $sorted_not_found_pathnames[$pathname] = $entries[$pathname]->incoming_links_count;
        }
      }

      arsort( $sorted_not_found_pathnames );
      $sorted_not_found_pathnames = array_keys( $sorted_not_found_pathnames );

      $recommended = array_merge( $sorted_pathnames, $sorted_not_found_pathnames );
      wp_send_json( $recommended );
    }

    /**
     * Return an array of pathnames from the $_POST array or empty array if none.
     * @return array
     */
    private function get_pathnames_post_variable() {
      if ( ! isset( $_POST['pathnames'] ) || empty( $_POST['pathnames'] ) ) {
        return [];
      }

      $pathnames = [];
      foreach ( $_POST['pathnames'] as $pathname ) {
        $pathnames[] = $this->esc_pathname( $pathname );
      }

      return $pathnames;
    }

    /**
     * Escape pathname for further usage and matching.
     */
    private function esc_pathname( $value ) {
      if ( ! $this->starts_with( $value, '/' ) ) {
        $value = '/' . $value;
      }
      if ( ! $this->ends_with( $value, '/' ) ) {
        $value .= '/';
      }
      return esc_sql( $value );
    }

    /**
     * Register plugin's client JS script.
     */
    public function register_client_script() {
      wp_enqueue_script( 'nwpp-client-script',
        NWPP_DIR_URL . 'assets/nwpp-prefetcher.min.js', ['jquery'], false, true );

      $home_url_raw = home_url( '/' );
      list($is_match, $matches) = $this->is_url($home_url_raw);

      if ($is_match) {
        $home_url = $matches[count($matches) - 1];
      } else {
        $home_url = $home_url_raw;
      }

      $data = [
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'homeUrl'       => $home_url,
        'doCacheImages' => $this->settings->do_imgs_cache(),
        'maxCacheSize'  => $this->settings->get_max_cache_size() * 1000000,
      ];

      wp_localize_script( 'nwpp-client-script', 'nwpp', $data );
    }

    /**
     * Return true if the given string is formated as $url and return parts of
     * that URL (re match).
     * @param  string  $url
     * @return array
     */
    private function is_url($url) {
      $url = filter_var($url, FILTER_SANITIZE_URL);
      if (filter_var($url, FILTER_VALIDATE_URL)) {
        $re = '/((http|https):\/\/){0,1}(w{3}\.){0,1}([-a-zA-Z0-9@:%_.\+~#=]{2,256}[\.{1,}[a-z0-9]{2,12}]*)\?{0,}/';
        $is_match = preg_match($re, $url, $matches);
        return [(bool)$is_match, $matches];
      }
      return [false, []];
    }

    /**
     * Return true if $haystack string starts with $needle or false otherwise.
     */
    private function starts_with( $haystack, $needle ) {
      return substr_compare( $haystack, $needle, 0, strlen($needle) ) === 0;
    }

    /**
     * Return true if $haystack string ends with $needle or false otherwise.
     */
    private function ends_with( $haystack, $needle ) {
      return substr_compare( $haystack, $needle, -strlen($needle) ) === 0;
    }

  }
}
