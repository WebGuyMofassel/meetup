<?php

/**
 * Ajax handler
 *
 * @package meetup
 * @author Tareq Hasan>
 */
class WeDevs_Meetup_Ajax {

    /**
     * Hook to suitable actions and filters
     *
     * @return void
     */
    function __construct() {
        add_action( 'wp_ajax_nopriv_meetup_fb_register', array($this, 'facebook_register') );

        add_action( 'wp_ajax_meetup_user_join', array($this, 'user_booking') );
        add_action( 'wp_ajax_nopriv_meetup_user_join', array($this, 'guest_site_registration') );

        add_action( 'wp_ajax_meetup_booking_cancel', array($this, 'cancel_booking') );
    }

    /**
     * Guess a suitable username for registration based on email address
     *
     * @param string $email email address
     * @return string username
     */
    function guess_username( $email ) {
        // username from email address
        $username = sanitize_user( substr( $email, 0, strpos( $email, '@' ) ) );

        if ( ! username_exists( $username ) ) {
            return $username;
        }

        // try to add some random number in username
        // and may be we got our username
        $username .= rand( 1, 199 );
        if ( !username_exists( $username ) ) {
            return $username;
        }
    }

    /**
     * Register a user
     *
     * @param  string $email
     * @param  string $first_name
     * @param  string $last_name
     * @return boolean
     */
    function register_user( $email, $first_name, $last_name ) {
        $username  = $this->guess_username( $email );
        $user_pass = wp_generate_password( 12, false );

        $errors = new WP_Error();
        do_action( 'register_post', $username, $email, $errors );

        $user_id = wp_create_user( $username, $user_pass, $email );

        if ( $user_id && !is_wp_error( $user_id ) ) {
            // we can turn on/off notification email via this filter
            // default is `true`
            $send_notification = apply_filters( 'meetup_new_user_notificaion', true, $user_id, $username, $email, $user_pass );

            if ( $send_notification ) {
                wp_new_user_notification( $user_id, $user_pass );
            }

            // update display name to full name
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => $first_name . ' ' . $last_name,
                'first_name'   => $first_name,
                'last_name'    => $last_name
            ) );

            do_action( 'meetup_user_registered', $user_id, $username, $email, $user_pass );

            // lets auto login the user
            wp_set_auth_cookie( $user_id, true );

            return $user_id;
        }

        return false;
    }

    /**
     * Check booking limit permission
     *
     * @param  int $meetup_id
     * @param  int $seat
     */
    function check_booking_limit( $meetup_id, $seat ) {
        $book_limit = (int) get_post_meta( $meetup_id, 'book_limit', true );

        if ( $seat > $book_limit ) {
            wp_send_json_error( array(
                'type'    => 'error',
                'message' => __( 'Please enter a valid seat number', 'meetup' )
            ) );
        }
    }

    /**
     * Do the booking process
     *
     * @param  int $user_id
     * @param  int $meetup_id
     * @param  int $seat
     * @return void
     */
    function do_booking( $user_id, $meetup_id, $seat ) {

        // do the booking process
        $booking = meetup_book_seat( $user_id, $meetup_id, $seat );

        if ( is_wp_error( $booking ) ) {
            wp_send_json_error( array(
                'type'    => 'registered',
                'message' => $booking->get_error_message()
            ) );
        }

        // seems like we made a booking
        wp_send_json_success( array(
            'type'    => 'registered',
            'message' => __( 'You have successfully booked the seat!', 'meetup' )
        ) );
    }

    /**
     * Perform guest registration and booking
     *
     * @return void
     */
    function guest_site_registration() {
        check_ajax_referer( 'meetup-site-join-form' );

        // do some cleanup
        $posted     = array_map( 'strip_tags', $_POST );
        $posted     = array_map( 'trim', $posted );

        $first_name = $posted['meetup_fname'];
        $last_name  = $posted['meetup_lname'];
        $email      = $posted['meetup_email'];
        $phone      = $posted['meetup_phone'];
        $seat       = (int) $posted['meetup-fb-join-seat'];
        $meetup_id  = (int) $posted['meetup_id'];

        if ( empty( $phone ) || empty( $first_name ) || empty( $last_name ) ) {
            wp_send_json_error( array(
                'type'    => 'error',
                'message' => __( 'Please complete the required fields', 'meetup' )
            ) );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array(
                'type'    => 'error',
                'message' => __( 'Please enter a valid email address', 'meetup' )
            ) );
        }

        if ( email_exists( $email ) ) {
            wp_send_json_error( array(
                'type'    => 'login',
                'message' => __( 'You are already registered to our site, please login', 'meetup' ),
                'url'     => wp_login_url( get_permalink( $meetup_id ) )
            ) );
        }

        // may be trying to book more than permitted?
        $this->check_booking_limit( $meetup_id, $seat );

        // register the user
        if ( $user_id = $this->register_user( $email, $first_name, $last_name ) ) {

            $this->update_user_meta( $user_id );
            $this->do_booking( $user_id, $meetup_id, $seat );

        } else {
            wp_send_json_error( array(
                'type'    => 'error',
                'message' => $user_id->get_error_message()
            ) );
        }

        exit;
    }

    /**
     * Perform booking for a logged in user
     *
     * @return void
     */
    function user_booking() {
        check_ajax_referer( 'meetup-site-join-form' );

        // do some cleanup
        $posted     = array_map( 'strip_tags', $_POST );
        $posted     = array_map( 'trim', $posted );
        $user_id    = get_current_user_id();

        $first_name = $posted['meetup_fname'];
        $last_name  = $posted['meetup_lname'];
        $phone      = $posted['meetup_phone'];
        $seat       = (int) $posted['meetup-fb-join-seat'];
        $meetup_id  = (int) $posted['meetup_id'];

        if ( empty( $phone ) || empty( $first_name ) || empty( $last_name ) ) {
            wp_send_json_error( array(
                'type'    => 'error',
                'message' => __( 'Please complete the required fields', 'meetup' )
            ) );
        }

        // update user meta info
        $this->update_user_meta( $user_id );

        // may be trying to book more than permitted?
        $this->check_booking_limit( $meetup_id, $seat );

        $this->do_booking( $user_id, $meetup_id, $seat );
    }

    /**
     * Cancel a meetup booking
     *
     * @return void
     */
    function cancel_booking() {
        check_ajax_referer( 'meetup-nonce' );

        $user_id    = get_current_user_id();
        $meetup_id  = (int) $_POST['meetup_id'];
        $booking_id = (int) $_POST['booking_id'];

        meetup_cancel_seat( $user_id, $meetup_id, $booking_id );
        wp_send_json_success( __( 'Your booking has been cancelled!', 'meetup' ) );
        exit;
    }

    /**
     * When users register via facebook button
     *
     * @return void
     */
    function facebook_register() {
        $posted = $_POST;

        $email      = $posted['email'];
        $first_name = $posted['first_name'];
        $last_name  = $posted['last_name'];

        $user = get_user_by( 'email', $email );

        if ( $user ) {
            // an existing user found
            // lets auto login the user
            wp_set_auth_cookie( $user->ID, true );

        } else {

            //register the user
            // register the user
            if ( $user_id = $this->register_user( $email, $first_name, $last_name ) ) {

                // setup some metadata
                update_user_meta( $user_id, '_fb_id', $posted['id'] );
                update_user_meta( $user_id, '_fb_link', $posted['link'] );

            } else {
                wp_send_json_error( array(
                    'type'    => 'error',
                    'message' => $user_id->get_error_message()
                ) );
            }
        }

        exit;
    }

    /**
     * Update user meta information
     *
     * @param  int $user_id
     * @return void
     */
    function update_user_meta( $user_id ) {
        $posted   = $_POST;
        $fname    = isset( $posted['meetup_fname'] ) ? strip_tags( $posted['meetup_fname'] ) : '';
        $lname    = isset( $posted['meetup_lname'] ) ? strip_tags( $posted['meetup_lname'] ) : '';
        $phone    = isset( $posted['meetup_phone'] ) ? $posted['meetup_phone'] : '';
        $site_url = isset( $posted['meetup_site_url'] ) ? $posted['meetup_site_url'] : '';
        $twitter  = isset( $posted['meetup_twitter'] ) ? $posted['meetup_twitter'] : '';
        $career   = isset( $posted['meetup_career'] ) ? $posted['meetup_career'] : '';

        wp_update_user( array(
            'ID'           => $user_id,
            'first_name'   => $fname,
            'last_name'    => $lname,
            'display_name' => $fname . ' ' . $lname,
            'user_url'     => $site_url
        ) );

        update_user_meta( $user_id, 'phone', $phone );
        update_user_meta( $user_id, 'twitter', $twitter );
        update_user_meta( $user_id, 'career', $career );

        do_action( 'meetup_update_user_meta', $user_id, $posted );
    }
}

new WeDevs_Meetup_Ajax();