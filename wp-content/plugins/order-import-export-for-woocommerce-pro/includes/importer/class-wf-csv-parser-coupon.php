<?php
/**
 * WooCommerce CSV Importer class for managing parsing of CSV files.
 */
class WF_CSV_Parser_Coupon {

    var $row;
    var $post_type;
    var $reserved_fields;		// Fields we map/handle (not custom fields)
    var $post_defaults;			// Default post data
    var $postmeta_defaults;		// default post meta
    var $postmeta_allowed;		// post meta validation

    /**
     * Constructor
     */
    public function __construct( $post_type = 'shop_coupon' ) {
        $this->post_type         = $post_type;
        $this->reserved_fields   = include( 'data-coupon/data-wf-reserved-fields.php' );
        $this->post_defaults     = include( 'data-coupon/data-wf-post-defaults.php' );
        $this->postmeta_defaults = include( 'data-coupon/data-wf-postmeta-defaults.php' );
        $this->postmeta_allowed  = include( 'data-coupon/data-wf-postmeta-allowed.php' );
    }

    /**
     * Format data from the csv file
     * @param  string $data
     * @param  string $enc
     * @return string
     */
    public function format_data_from_csv( $data, $enc ) {
        return ( $enc == 'UTF-8' ) ? $data : utf8_encode( $data );
    }

    /**
     * Parse the data
     * @param  string  $file      [description]
     * @param  string  $delimiter [description]
     * @param  array  $mapping   [description]
     * @param  integer $start_pos [description]
     * @param  integer  $end_pos   [description]
     * @return array
     */
    public function parse_data( $file, $delimiter, $mapping, $start_pos = 0, $end_pos = null, $eval_field ) {
        // Set locale
        $enc = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );
        if ( $enc )
                setlocale( LC_ALL, 'en_US.' . $enc );
        @ini_set( 'auto_detect_line_endings', true );

        $parsed_data = array();
        $raw_headers = array();

        // Put all CSV data into an associative array
        if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
            $header   = fgetcsv( $handle, 0, $delimiter );
            if ( $start_pos != 0 )
                fseek( $handle, $start_pos );

            while ( ( $postmeta = fgetcsv( $handle, 0, $delimiter ) ) !== FALSE ) {
            $row = array();

            foreach ( $header as $key => $heading ) {
                                $s_heading = $heading;

                // Check if this heading is being mapped to a different field
                if ( isset( $mapping[$s_heading] ) ) {
                    if ( $mapping[$s_heading] == 'import_as_meta' ) {
                        $s_heading = 'meta:' . $s_heading;
                    } elseif ( $mapping[$s_heading] == 'import_as_images' ) {
                        $s_heading = 'images';
                    } else {
                        $s_heading = esc_attr( $mapping[$s_heading] );
                    }
                }
                foreach ($mapping as $mkey => $mvalue) {
                    if(trim($mvalue) === trim($heading)){
                        $s_heading =  $mkey;
                    }
                }

                if ( $s_heading == '' )
                        continue;

                // Add the heading to the parsed data
                $row[$s_heading] = ( isset( $postmeta[$key] ) ) ? $this->format_data_from_csv( $postmeta[$key], $enc ) : '';

                if(!empty($eval_field[$s_heading]))
                $row[$s_heading] = $this->evaluate_field($row[$s_heading], $eval_field[$s_heading]);

                // Raw Headers stores the actual column name in the CSV
                $raw_headers[ $s_heading ] = $heading;
            }
            $parsed_data[] = $row;
            unset( $postmeta, $row );
            $position = ftell( $handle );
            if ( $end_pos && $position >= $end_pos )
                break;
            }
            fclose( $handle );
        }
        return array( $parsed_data, $raw_headers, $position );
    }

    private function evaluate_field($value, $evaluation_field){
        $processed_value = $value;
        if(!empty($evaluation_field)){
            $operator = substr($evaluation_field, 0, 1);
            if(in_array($operator, array('=', '+', '-', '*', '/', '&'))){
                $eval_val = substr($evaluation_field, 1);
                switch($operator){
                    case '=':
                        $processed_value = trim($eval_val); 
                        break;
                    case '+':
                        $processed_value = $this->hf_currency_formatter($value) + $eval_val; 
                        break;
                    case '-': 
                        $processed_value = $value - $eval_val; 
                        break;
                    case '*': 
                        $processed_value = $value * $eval_val; 
                        break;
                    case '/': 
                        $processed_value = $value / $eval_val; 
                        break;
                    case '&': 
                        if (strpos($eval_val, '[VAL]') !== false) {
                                $processed_value = str_replace('[VAL]',$value,$eval_val);								 
                        }else{
                                $processed_value = $value . $eval_val;
                        }
                        break;	
                    case '@': 
                        if (!(bool) strtotime($value)) {
                            $value = str_replace("/", "-", $value);
                            $eval_val = str_replace("/", "-", $eval_val);
                        }
                        if (version_compare(PHP_VERSION, '5.6.0', '>=')) {
                            $date = DateTime::createFromFormat($eval_val, $value);
                            $processed_value = $date->format('Y-m-d H:i:s');
                        } else {
                            $processed_value = date("d-m-Y H:i:s", strtotime($value));
                        }
                        break;                
                }
            }	
        }
        return $processed_value;	
    }

    /**
     * Parse Coupons
     * @param  array  $item
     * @return array
     */
    public function parse_coupon( $item) {
        global $WF_CSV_Coupon_Import, $wpdb;
        $this->row++;

        $postmeta = $coupon = array();
        // Merging
        $merging = ( ! empty( $_POST['merge'] ) && $_POST['merge'] ) ? true : false;
        $this->post_defaults['post_type'] = 'shop_coupon';

        $post_id = ( ! empty( $item['id'] ) ) ? $item['id'] : 0;
        $post_id = ( ! empty( $item['post_id'] ) ) ? $item['post_id'] : $post_id;
        if ( $merging ) {
            $coupon['merging'] = true;
            $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', sprintf( __('> Row %s - preparing for merge.', 'wf_order_import_export'), $this->row ) );
            if ( ! $post_id  ) {
                $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', __( '> > Cannot merge without id or sku. Importing instead.', 'wf_order_import_export') );
                $merging = false;
            } else {
                if ( ! $post_id ) {
                    $post_db_type = $this->post_defaults['post_type'];
                    $post_pass_type = '"'.$post_db_type.'"';
                    $db_query = $wpdb->prepare("
                                        SELECT $wpdb->posts.ID
                                    FROM $wpdb->posts
                                    LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id)
                                    WHERE $wpdb->posts.post_type = $post_pass_type
                                    AND $wpdb->posts.post_status IN ( 'publish', 'private', 'draft', 'pending', 'future' )
                                    AND $wpdb->posts.ID = '%d'
                                 ", $item['ID']);
                    $found_product_id = $wpdb->get_var($db_query);
                    if ( ! $found_product_id ) {
                        $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', sprintf(__( '> > Skipped. Cannot find coupon with sku %s. Importing instead.', 'wf_order_import_export'), $item['sku']) );
                        $merging = false;
                    } else {
                        $post_id = $found_product_id;
                        $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', sprintf(__( '> > Found coupon with ID %s.', 'wf_order_import_export'), $post_id) );
                    }
                }
                $coupon['merging'] = true;
            }
        }

        if ( ! $merging ) {
            $coupon['merging'] = false;
            $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', sprintf( __('> Row %s - preparing for import.', 'wf_order_import_export'), $this->row ) );
            if ( isset($item['post_parent']) && $item['post_parent']=== '' &&  $item['post_title']=== '') {
                $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', __( '> > Skipped. No post_title set for new coupon.', 'wf_order_import_export') );
                return new WP_Error( 'parse-error', __( 'No post_title set for new coupon.', 'wf_order_import_export' ) );
            }
            if ( isset($item['post_parent']) && $item['post_parent']!== '' && $item['post_parent']!== null &&  $item['parent_sku'] === '' ) {
                $WF_CSV_Coupon_Import->hf_coupon_log_data_change( 'coupon-csv-import', __( '> > Skipped. No parent set for new variation product.', 'wf_order_import_export') );
                return new WP_Error( 'parse-error', __( 'No parent set for new variation product.', 'wf_order_import_export' ) );
            }
        }

        $coupon['post_id'] = $post_id;
        foreach ( $this->post_defaults as $column => $default ) {
            if ( isset( $item[ $column ] ) ) $coupon[ $column ] = $item[ $column ];
        }

        // Get custom fields
        foreach ( $this->postmeta_defaults as $column => $default ) {
            if ( isset( $item[$column] ) )
                $postmeta[$column] = (string) $item[$column];
            elseif ( isset( $item[$column] ) )
                $postmeta[$column] = (string) $item[$column];
            // Check custom fields are valid
            if ( isset( $postmeta[$column] ) && isset( $this->postmeta_allowed[$column] ) && ! in_array( $postmeta[$column], $this->postmeta_allowed[$column] ) ) {
                $postmeta[$column] = $this->postmeta_defaults[$column];
            }
        }

        // Get any custom meta fields
        foreach ( $item as $key => $value ) {
            if ( ! $value ) {
                continue;
            }
            if ( strstr( $key, 'meta:' ) ) {
                // Get meta key name
                $meta_key = trim( str_replace( 'meta:', '', $key ) );
                // Add to postmeta array
                $postmeta[$meta_key] = $value;
            }
        }                

        if ( ! $merging ) {
            // Merge post meta with defaults
            $coupon  = wp_parse_args( $coupon, $this->post_defaults );
            $postmeta = wp_parse_args( $postmeta, $this->postmeta_defaults );
        }
        if ( ! empty( $coupon['post_status'] ) ) {
            if ( ! in_array( $coupon['post_status'], array( 'publish', 'private', 'draft', 'pending', 'future', 'inherit', 'trash' ) ) ) {
                $coupon['post_status'] = 'publish';
            }
            if ( ! in_array( $coupon['post_status'], array( 'private', 'publish' ) ) ) {
                $coupon['post_status'] = 'publish';
            }
        }
        foreach ( $postmeta as $key => $value ) {
            $coupon['postmeta'][] = array( 'key' 	=> esc_attr($key), 'value' => $value );
        }

        $coupon['post_title'] = ( ! empty( $item['post_title'] ) ) ? $item['post_title'] : '';
        unset( $item, $postmeta);
        return $coupon;
    }
    
    function hf_currency_formatter($price){
        $decimal_seperator = wc_get_price_decimal_separator();
        return preg_replace("[^0-9\\'.$decimal_seperator.']", "", $price);
    }
}