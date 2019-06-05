<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'NWPP_DB' ) ) {
  /**
   * Responsible for managing connection to the DB, creating needed tables,
   * fetching and inserting data.
   */
  class NWPP_DB {

    /**
     * Create needed DB table. Return true if created successfully or already
     * exists.
     * @return bool
     */
    public function install() {
      global $wpdb;
      $query = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}nwpp_pages` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `path` TEXT NOT NULL,
        `relationships` TEXT NULL,
        `raw_relationships` TEXT NULL,
        `incoming_links_count` INT NOT NULL DEFAULT 0,
        `created_on` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`))";

      return $wpdb->query( $query );
    }

    /**
     * Update paths in the DB with the given, update relationships and incoming
     * links count for each path.
     * @param array $stats
     * @param array $paths
     */
    public function process_relationships( $stats, $paths ) {
      $this->insert_paths( $paths, true );

      $path2id = [];
      $all_paths = $this->get_all_paths();
      foreach ( $all_paths as $path ) {
        $path2id[ $path['path'] ] = $path['id'];
      }

      $all_raw_rels = [];
      foreach ( $stats as $root_path => $stat ) {
        $root_path_id = $path2id[$root_path];
        $all_raw_rels[$root_path_id] = [];
        foreach ( $stat as $child_path => $views_count ) {
          $all_raw_rels[$root_path_id][ $path2id[$child_path] ] = $views_count;
        }
      }

      $this->update_relationships( $all_raw_rels );
      $this->update_incoming_links_count( $all_raw_rels );
    }

    /**
     * Extract counts for incoming path ids, merge them to existing,
     * and save to DB.
     * @param  array $rels
     */
    private function update_incoming_links_count( $rels ) {
      global $wpdb;

      $new_counts = [];
      foreach ($rels as $rel) {
        foreach ($rel as $id => $count) {
          if ( ! array_key_exists($id, $new_counts) ) {
            $new_counts[$id] = 0;
          }
          $new_counts[$id] += $count;
        }
      }

      $query = "SELECT id, incoming_links_count FROM `{$wpdb->prefix}nwpp_pages`;";
      $existing_counts = $wpdb->get_results( $query, ARRAY_N );
      foreach ($existing_counts as $existing_count) {
        if (array_key_exists($existing_count[0], $new_counts)) {
          $new_counts[$existing_count[0]] += $existing_count[1];
        }
      }

      $values = '';
      foreach ($new_counts as $id => $count) {
        if ( empty($id) ) {
          continue;
        }
        if (! empty($values)) {
          $values .= ', ';
        }
        $values .= '(' . $id . ',' . $count . ')';
      }

      $query = "INSERT INTO `{$wpdb->prefix}nwpp_pages` (id, incoming_links_count) VALUES " . $values . " ON DUPLICATE KEY UPDATE incoming_links_count=VALUES(incoming_links_count);";
      $wpdb->query($query);
    }

    /**
     * Update relationships (raw and normalized) in DB.
     * @param  array $rels
     * @return void
     */
    private function update_relationships( $rels ) {
      global $wpdb;

      $query = "SELECT id, raw_relationships FROM `{$wpdb->prefix}nwpp_pages`;";
      $existing_rels = $wpdb->get_results( $query, OBJECT_K );

      $insert_values = [];
      foreach ( $rels as $path_id => $rel ) {
        try {
          $existing_rel = unserialize( $existing_rels[$path_id]->raw_relationships );
        } catch ( Exception $e ) {
          $existing_rel = [];
        }

        if ( ! empty($existing_rel) ) {
          foreach ( $existing_rel as $ex_id => $ex_count ) {
            if ( array_key_exists($ex_id, $rel) ) {
              $rel[$ex_id] += $ex_count;
            } else {
              $rel[$ex_id] = $ex_count;
            }
          }
        }

        $norm_rel = $this->normalize( $rel );
        $insert_values[] = $wpdb->prepare( '(%d, %s, %s)', $path_id,
          serialize( $rel ), serialize( $norm_rel ) );
      }

      if ( ! empty( $insert_values ) ) {
        $insert_values = join( ',', $insert_values );
        $query = "INSERT INTO `{$wpdb->prefix}nwpp_pages`
          (id, raw_relationships, relationships) VALUES {$insert_values}
          ON DUPLICATE KEY
          UPDATE raw_relationships=VALUES(raw_relationships),
          relationships=VALUES(relationships);";
        return $wpdb->query( $query );
      }
      return false;
    }

    /**
     * Divide each set value by the sum of all values and return updated array.
     * @param  array $set
     * @return array
     */
    private function normalize($set) {
      $total = array_sum( $set );
      foreach ($set as $key => $value) {
        try {
          $set[$key] = $value / $total;
        } catch ( Exception $e ) {
          $set[$key] = 0;
        }
      }
      return $set;
    }

    /**
     * To be used in reduce() function.
     */
    private function reduce_array_to_0th_dim( $container, $array ) {
      $container[] = $array[0];
      return $container;
    }

    /**
     * Escape value to be used with VALUES clause in SQL queries.
     */
    private function esc_sql_value( $value ) {
      $escaped_value = esc_sql( $value );
      return "('{$escaped_value}')";
    }

    /**
     * Insert given paths to the DB and return true if successful.
     * @param  array   $paths
     * @param  boolean $return_all_paths
     * @return boolean
     */
    private function insert_paths( $paths, $return_all_paths = false ) {
      global $wpdb;

      $select_query  = "SELECT path FROM `{$wpdb->prefix}nwpp_pages`";
      $current_paths = $wpdb->get_results( $select_query, ARRAY_N );
      if ( ! empty( $current_paths ) ) {
        $current_paths = array_reduce( $current_paths, [$this, 'reduce_array_to_0th_dim'] );
      }

      $new_paths = array_diff($paths, $current_paths);
      if ( ! empty( $new_paths ) ) {
        try {
          $values = array_map( [$this, 'esc_sql_value'], $new_paths );
          $values = join( ',', $values );
          $query = "INSERT INTO `{$wpdb->prefix}nwpp_pages` (path) VALUES {$values}";
          $wpdb->query( $query );
        } catch ( Exception $e ) {
          error_log($e);
          return false;
        }
      }
      return true;
    }

    /**
     * Get all paths from the DB.
     * @return array
     */
    private function get_all_paths() {
      global $wpdb;
      $query = "SELECT id, path FROM `{$wpdb->prefix}nwpp_pages`";
      return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Return an associative array of objects where key is pathname and object
     * containts properties such as id, relationships and links count for
     * the corresponding paths.
     * @param  array $paths Should contain set of pathnames (string).
     * @return array
     */
    public function get_entries( $paths ) {
      global $wpdb;

      $count = count( $paths );
      if ( ! $count ) {
        return [];
      }

      $placeholders = array_fill( 0, $count, '%s' );
      $format       = implode( ', ', $placeholders );

      $query = "SELECT path, id, relationships, incoming_links_count
        FROM `{$wpdb->prefix}nwpp_pages` WHERE path IN ($format)";
      return $wpdb->get_results( $wpdb->prepare( $query, $paths ), OBJECT_K );
    }

    /**
     * Uninstall created table.
     * @return bool
     */
    public function uninstall() {
      global $wpdb;
      return $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}nwpp_pages`" );
    }

  }
}
