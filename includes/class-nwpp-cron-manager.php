<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'NWPP_Cron_Manager' ) ) {
  class NWPP_Cron_Manager {

    public function __construct( $register = false ) {
      if ( $register ) {
        $this->register();
      }

      require_once NWPP_DIR_PATH . 'includes/class-nwpp-google-analytics.php';
      require_once NWPP_DIR_PATH . 'includes/class-nwpp-db.php';

      $this->ga = new NWPP_Google_Analytics();
      $this->db = new NWPP_DB();
    }

    public function register() {
      add_filter( 'cron_schedules', [$this, 'update_wp_cron_schedule'] );
      add_action( 'nwpp_init_cron', [$this, 'do_init_data_fetch'] );
      add_action( 'nwpp_daily_cron', [$this, 'do_daily_data_fetch'] );

      $is_init_fetch_done = get_option( 'nwpp_init_fetch_done', 'no' );
      if ( $is_init_fetch_done !== 'yes' )  {
        $this->schedule_init_cron();
      }
    }

    public function schedule_init_cron() {
      if ( ! wp_next_scheduled( 'nwpp_init_cron' ) ) {
        wp_schedule_event( time(), 'every_3_minutes', 'nwpp_init_cron' );
      }
    }

    public function schedule_daily_cron() {
      if ( ! wp_next_scheduled( 'nwpp_daily_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'nwpp_daily_cron' );
      }
    }

    public function schedule_rels_processing( $time_offset = 180 ) {
      wp_schedule_single_event( time() + $time_offset, 'nwpp_rels_processing' );
    }

    public function do_daily_data_fetch() {
      $token = 0;
      do {
        list( $stats, $paths, $next_token ) = $this->ga->get_stats( $token, 'yesterday' );
        $this->db->process_relationships( $stats, $paths );
        $token = $next_token;
      } while( ! empty( $next_token ) );
    }

    public function do_init_data_fetch() {
      $token = get_option( 'nwpp_init_fetch_token', 0 );
      list( $stats, $paths, $next_token ) = $this->ga->get_stats( $token );

      $this->db->process_relationships( $stats, $paths );

      if ( empty( $next_token ) ) {
        set_option( 'nwpp_init_fetch_done', 'yes' );
        delete_option( 'nwpp_init_fetch_token' );
        $this->deactivate_init_cron();

        $this->schedule_daily_cron();
      } else {
        update_option( 'nwpp_init_fetch_token', $next_token );
      }
    }



    /**
     * Update WP CRON schedules array. Should be used in 'cron_schedules' hook.
     * @param  array $schedules
     * @return array
     */
    public function update_wp_cron_schedule( $schedules ) {
      $schedules['every_3_minutes'] = [
        'display'  => __( 'Every 3 minutes', 'nwpp' ),
        'interval' => 180,
      ];
      return $schedules;
    }

    public function deactivate_init_cron() {
       wp_clear_scheduled_hook( 'nwpp_init_cron' );
    }

    public function deactivate() {
      $this->deactivate_init_cron();
    }

    public function uninstall() {
      $this->deactivate_init_cron();
      delete_option( 'nwpp_init_fetch_token' );
      delete_option( 'nwpp_init_fetch_done' );
    }

  }
}
