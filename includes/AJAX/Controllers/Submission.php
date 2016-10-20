<?php if ( ! defined( 'ABSPATH' ) ) exit;

class NF_AJAX_Controllers_Submission extends NF_Abstracts_Controller
{
    protected $_form_data = array();

    protected $_form_cache = array();

    protected $_preview_data = array();

    protected $_form_id = '';

    public function __construct()
    {
        if( isset( $_POST[ 'nf_resume' ] ) && isset( $_COOKIE[ 'nf_wp_session' ] ) ){
            add_action( 'ninja_forms_loaded', array( $this, 'resume' ) );
            return;
        }

        if( isset( $_POST['formData'] ) ) {
            $this->_form_data = json_decode( $_POST['formData'], TRUE  );

            // php5.2 fallback
            if( ! $this->_form_data ) $this->_form_data = json_decode( stripslashes( $_POST['formData'] ), TRUE  );
        }


        add_action( 'wp_ajax_nf_ajax_submit',   array( $this, 'submit' )  );
        add_action( 'wp_ajax_nopriv_nf_ajax_submit',   array( $this, 'submit' )  );

        add_action( 'wp_ajax_nf_ajax_resume',   array( $this, 'resume' )  );
        add_action( 'wp_ajax_nopriv_nf_ajax_resume',   array( $this, 'resume' )  );
    }

    public function submit()
    {
        check_ajax_referer( 'ninja_forms_display_nonce', 'security' );

        register_shutdown_function( array( $this, 'shutdown' ) );

        $this->form_data_check();

        $this->_form_id = $this->_form_data['id'];

        if( $this->is_preview() ) {

            $this->_form_cache = get_user_option( 'nf_form_preview_' . $this->_form_id );

            if( ! $this->_form_cache ){
                $this->_errors[ 'preview' ] = __( 'Preview does not exist.', 'ninja-forms' );
                $this->_respond();
            }
        } else {
            $this->_form_cache = get_option( 'nf_form_' . $this->_form_id );
        }

        // TODO: Update Conditional Logic to preserve field ID => [ Settings, ID ] structure.
        $this->_form_data = apply_filters( 'ninja_forms_submit_data', $this->_form_data );

        $this->process();
    }

    public function resume()
    {
        $this->_data = Ninja_Forms()->session()->get( 'nf_processing_data' );
        $this->_data[ 'resume' ] = $_POST[ 'nf_resume' ];

        $this->_form_id = $this->_data[ 'form_id' ];

        unset( $this->_data[ 'halt' ] );
        unset( $this->_data[ 'actions' ][ 'redirect' ] );

        $this->process();
    }

    protected function process()
    {
        // Init Field Merge Tags.
        $field_merge_tags = Ninja_Forms()->merge_tags[ 'fields' ];

        // Init Calc Merge Tags.
        $calcs_merge_tags = Ninja_Forms()->merge_tags[ 'calcs' ];

        $this->_data[ 'form_id' ] = $this->_form_id;
        $this->_data[ 'settings' ] = $this->_form_cache[ 'settings' ];
        $this->_data[ 'settings' ][ 'is_preview' ];

        /*
        |--------------------------------------------------------------------------
        | Fields
        |--------------------------------------------------------------------------
        */

        /**
         * The Field Processing Loop.
         *
         * There can only be one!
         * For performance reasons, this should be the only time that the fields array is traversed.
         * Anything needing to loop through fields should integrate here.
         */
        foreach( $this->_form_cache['fields'] as $key => $field ){

            /** Get the field ID */
            /*
             * TODO: Refactor data structures to match.
             * Preview: Field IDs are stored as the associated array key.
             * Publish: Field IDs are stored as an array key=>value pair.
             */
            if( $this->is_preview() ){
                $field[ 'id' ] = $key;
            }

            // Duplicate field ID as single variable for more readable array access.
            $field_id = $field[ 'id' ];

            // Check that the field ID exists in the submitted for data and has a submitted value.
            if( ! isset( $this->_form_data[ 'fields' ][ $field_id ] ) ) continue;
            if( ! isset( $this->_form_data[ 'fields' ][ $field_id ][ 'value' ] ) ) continue;

            $field[ 'settings' ][ 'value' ] = $this->_form_data[ 'fields' ][ $field_id ][ 'value' ];

            // Duplicate the Field ID for access as a setting.
            $field[ 'settings' ][ 'id' ] = $field[ 'id' ];

            /** Validate the Field */
            $this->validate_field( $field[ 'settings' ] );

            /** Process the Field */
            $this->process_field( $field[ 'settings' ] );

            /** Populate Field Merge Tag */
            $field_merge_tags->add_field( $field[ 'settings' ] );

            $this->_data[ 'fields' ][ $field_id ] = $field;
        }

        /*
        |--------------------------------------------------------------------------
        | Calculations
        |--------------------------------------------------------------------------
        */

        if( isset( $this->_form_cache[ 'settings' ][ 'calculations' ] ) ) {

            /**
             * The Calculation Processing Loop
             */
            foreach( $this->_form_cache[ 'settings' ][ 'calculations' ] as $calc ){
                $eq = apply_filters( 'ninja_forms_calc_setting', $calc[ 'eq' ] );
                $calcs_merge_tags->set_merge_tags( $calc[ 'name' ], $eq );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Actions
        |--------------------------------------------------------------------------
        */

        // Sort Actions by Timing Order, then by Priority Order.
        usort( $this->_form_cache[ 'actions' ], array( $this, 'sort_form_actions' ) );

        /*
         * Filter Actions so that they can be pragmatically disabled by add-ons.
         *
         * ninja_forms_submission_actions
         * ninja_forms_submission_actions_preview
         */
        $this->_form_cache[ 'actions' ] = apply_filters( 'ninja_forms_submission_actions', $this->_form_cache[ 'actions' ], $this->_form_cache );
        if( $this->is_preview() ) {
            $this->_form_cache['actions'] = apply_filters('ninja_forms_submission_actions_preview', $this->_form_cache['actions'], $this->_form_cache);
        }

        // Initialize the process actions log.
        if( ! isset( $this->_form_cache[ 'processed_actions' ] ) ) $this->_form_cache[ 'processed_actions' ] = array();

        /**
         * The Action Processing Loop
         */
        foreach( $this->_form_cache[ 'actions' ] as $key => $action ){

            /** Get the action ID */
            /*
             * TODO: Refactor data structures to match.
             * Preview: Action IDs are stored as the associated array key.
             * Publish: Action IDs are stored as an array key=>value pair.
             */
            if( $this->is_preview() ){
                $action[ 'id' ] = $key;
            }

            // Duplicate the Action ID for access as a setting.
            $action[ 'settings' ][ 'id' ] = $action[ 'id' ];

            // Duplicate action ID as single variable for more readable array access.
            $action_id = $action[ 'id' ];

            // If an action has already run (ie resume submission), do not re-process.
            if( in_array( $action[ 'id' ], $this->_data[ 'processed_actions' ] ) ) continue;

            $action[ 'settings' ] = apply_filters( 'ninja_forms_run_action_settings', $action[ 'settings' ], $this->_form_id, $action[ 'id' ], $this->_form_data['settings'] );
            if( $this->is_preview() ){
                $action[ 'settings' ] = apply_filters( 'ninja_forms_run_action_settings_preview', $action[ 'settings' ], $this->_form_id, $action[ 'id' ], $this->_form_data['settings'] );
            }

            if( ! $action[ 'settings' ][ 'active' ] ) continue;

            if( ! apply_filters( 'ninja_forms_run_action_type_' . $action[ 'settings' ][ 'type' ], TRUE ) ) continue;

            $type = $action[ 'settings' ][ 'type' ];

            $action_class = Ninja_Forms()->actions[ $type ];

            if( ! method_exists( $action_class, 'process' ) ) return;

            if( $data = $action_class->process($action[ 'settings' ], $this->_form_id, $this->_data ) ){
                $this->_data = $data;
            }

//            $this->_data[ 'actions' ][ $type ][] = $action;

            $this->maybe_halt( $action[ 'id' ] );
        }

        do_action( 'ninja_forms_after_submission', $this->_data );

        $this->_respond();
    }

    protected function validate_field( $field_settings )
    {
        $field_settings = apply_filters( 'ninja_forms_pre_validate_field_settings', $field_settings );

        $field_class = Ninja_Forms()->fields[ $field_settings['type'] ];

        if( ! method_exists( $field_class, 'validate' ) ) return;

        if( $errors = $field_class->validate( $field_settings, $this->_form_data ) ){
            $field_id = $field_settings[ 'id' ];
            $this->_errors[ 'fields' ][ $field_id ] = $errors;
        }
    }

    protected function process_field( $field_settings )
    {
        $field_class = Ninja_Forms()->fields[ $field_settings['type'] ];

        if( ! method_exists( $field_class, 'process' ) ) return;

        if( $data = $field_class->process( $field_settings, $this->_form_data ) ){
            $this->_form_data = $data;
        }
    }

    protected function maybe_halt( $action_id )
    {
        if( isset( $this->_data[ 'errors' ] ) && $this->_data[ 'errors' ] ){
            $this->_respond();
        }

        if( isset( $this->_data[ 'halt' ] ) && $this->_data[ 'halt' ] ){

            Ninja_Forms()->session()->set( 'nf_processing_data', $this->_data );

            $this->_respond();
        }

        array_push( $this->_data[ 'processed_actions' ], $action_id );
    }

    protected function sort_form_actions( $a, $b )
    {
        if( is_object( $a ) ) {
            if( ! isset( Ninja_Forms()->actions[ $a->get_setting( 'type' ) ] ) ) return -1;
            $a = Ninja_Forms()->actions[ $a->get_setting( 'type' ) ];
        } else {
            if( ! isset( Ninja_Forms()->actions[ $a[ 'settings' ][ 'type' ] ] ) ) return -1;
            $a = Ninja_Forms()->actions[ $a[ 'settings' ][ 'type' ] ];
        }

        if( is_object( $b ) ) {
            if( ! isset( Ninja_Forms()->actions[ $b->get_setting( 'type' ) ] ) ) return 1;
            $b = Ninja_Forms()->actions[ $b->get_setting( 'type' ) ];
        } else {
            if( ! isset( Ninja_Forms()->actions[ $b[ 'settings' ][ 'type' ] ] ) ) return 1;
            $b = Ninja_Forms()->actions[ $b[ 'settings' ][ 'type' ] ];
        }

        if ( $a->get_timing() == $b->get_timing() ) {
            if ( $a->get_priority() == $b->get_priority() ) {
                return 0;
            }
            return ( $a->get_priority() < $b->get_priority() ) ? -1 : 1;
        }

        return ( $a->get_timing() < $b->get_timing() ) ? -1 : 1;
    }

    public function shutdown()
    {
        $error = error_get_last();
        if( $error !== NULL && in_array( $error[ 'type' ], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ) ) ) {
            $this->_errors[ 'form' ][ 'last' ] = __( 'The server encountered an error during processing.', 'ninja-forms' );
            $this->_errors[ 'last' ] = $error;
            $this->_respond();
        }
    }

    protected function form_data_check()
    {
        if( $this->_form_data ) return;

        if( function_exists( 'json_last_error' ) // Function not supported in php5.2
            && function_exists( 'json_last_error_msg' )// Function not supported in php5.2
            && json_last_error() ){
            $this->_errors[] = json_last_error_msg();
        } else {
            $this->_errors[] = __( 'An unexpected error occurred.', 'ninja-forms' );
        }

        $this->_respond();
    }

    protected function is_preview()
    {
        if( ! isset( $this->_form_data[ 'settings' ][ 'is_preview' ] ) ) return false;
        return $this->_form_data[ 'settings' ][ 'is_preview' ];
    }
}