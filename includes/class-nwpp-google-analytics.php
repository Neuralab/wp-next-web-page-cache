<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'NWPP_Google_Analytics' ) ) {
  /**
   * Class responsible for managing connection and data sync from Google Analytics.
   *
   * GA setup: https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php
   */
  class NWPP_Google_Analytics {

    /**
     * Application name of the used Google Client.
     * @var string
     */
    private $application_name = 'Neuralab Next Web Page Cache';

    /**
     * ID of the used Google Analytics view.
     * @var string
     */
    private $view_id;

    /**
     * Location of the service key file.
     * @var string
     */
    private $config_file_path;

    /**
     * RegEx string used to filter out page path.
     * @var string
     */
    private $page_path_re = '/\/[^?]*/is';

    /**
     * Define class attributes.
     */
    public function __construct() {
      require_once NWPP_DIR_PATH . 'vendor/autoload.php';
      require_once NWPP_DIR_PATH . 'includes/class-nwpp-settings.php';

      $settings = new NWPP_Settings();

      $this->view_id          = $settings->get_view_id();
      $this->config_file_path = $settings->config_file_path;
    }

    /**
     * Remove parameters from the given page path and return cleaned string or
     * false in case of error or no match.
     * @param  string $page_path
     * @return string|boolean
     */
    private function clean_page_path( $page_path ) {
      $is_match = preg_match($this->page_path_re, $page_path, $matches);
      if ( $is_match ) {
        $page_path_cleaned = trim( $matches[0] );
        return rtrim( $page_path_cleaned, '/' ) . '/';
      }
      return false;
    }

    /**
     * Fetch and process page paths from Google Analytics and return an array
     * of page stats, pages and next page token
     * @param  string $page_token Defaults to 0.
     * @param  string $start_date Defaults to '3650daysAgo'.
     * @return array              Contains $stats, $pages, $page_token.
     */
    public function get_stats( $page_token = 0, $start_date = '3650daysAgo' ) {
      $pages   = [];
      $stats   = [];
      $ga      = $this->get_analytics_interface();
      $reports = $this->get_raw_data( $ga, $page_token, $start_date );

      $next_page_token = $reports[0]->nextPageToken;
      foreach ( $reports[0]->getData()->getRows() as $row ) {
        $dims    = $row->getDimensions(); // date, previousPagePath, pagePath
        $metrics = $row->getMetrics(); // pageviews

        try {
          if ( $dims[1] === '(entrance)' || $dims[2] === '(entrance)' ) {
            continue;
          }

          $prev_page = $this->clean_page_path( $dims[1] );
          $next_page = $this->clean_page_path( $dims[2] );
          if ( !$prev_page || !$next_page || $prev_page === $next_page ) {
            continue;
          }

          if ( ! in_array( $prev_page, $pages ) ) {
            $pages[] = $prev_page;
          }

          if ( ! in_array( $next_page, $pages ) ) {
            $pages[] = $next_page;
          }

          if ( ! isset( $stats[$prev_page] ) ) {
            $stats[$prev_page] = [];
          }

          if ( ! isset( $stats[$prev_page][$next_page] ) ) {
            $stats[$prev_page][$next_page] = 0;
          }

          $stats[$prev_page][$next_page] += intval( $metrics[0]['values'][0] );
        } catch ( Exception $e ) {
          continue;
        }

      }

      return [$stats, $pages, $next_page_token];
    }

    /**
     * Queries the Analytics Reporting API V4.
     * @param  int    $page_token Defaults to 0.
     * @param  string $start_date
     * @return Google_Service_AnalyticsReporting_GetReportsResponse
     */
    private function get_raw_data( $ga, $page_token = 0, $start_date ) {
      $dateRange = new Google_Service_AnalyticsReporting_DateRange();
      $dateRange->setStartDate( $start_date );
      $dateRange->setEndDate( 'today' );

      $pageviews = new Google_Service_AnalyticsReporting_Metric();
      $pageviews->setExpression( 'ga:pageviews' );
      $pageviews->setAlias( 'pageviews' );

      $request = new Google_Service_AnalyticsReporting_ReportRequest();
      $request->setViewId( $this->view_id );
      $request->setDateRanges( $dateRange );
      $request->setMetrics( [$pageviews] );

      $date_dim = new Google_Service_AnalyticsReporting_Dimension();
      $date_dim->setName( 'ga:date' );

      $prev_page_path_dim = new Google_Service_AnalyticsReporting_Dimension();
      $prev_page_path_dim->setName( 'ga:previousPagePath' );

      $page_path_dim = new Google_Service_AnalyticsReporting_Dimension();
      $page_path_dim->setName( 'ga:pagePath' );

      $request->setDimensions( [$date_dim, $prev_page_path_dim, $page_path_dim] );

      if ( $page_token > 0 ) {
        $request->setPageToken( $page_token );
      }

      $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
      $body->setReportRequests( [$request] );
      return $ga->reports->batchGet( $body );
    }

    /**
     * Initializes an Analytics Reporting API V4 service object.
     * @return Google_Service_AnalyticsReporting
     */
    private function get_analytics_interface() {
      $client = new Google_Client();
      $client->setApplicationName( $this->application_name );
      $client->setAuthConfig( $this->config_file_path );
      $client->setScopes( [
        'https://www.googleapis.com/auth/analytics.readonly'
      ] );

      return new Google_Service_AnalyticsReporting( $client );
    }

  }
}
