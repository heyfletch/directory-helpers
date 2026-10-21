<?php
/**
 * Owner Requests: the Fluent Forms form "Ask trainers for help".
 *
 * Installed or updated by `wp dh-requests install-form`, which stores the form
 * id in the module settings. Turnstile is a field of the form; the honeypot is
 * Fluent Forms' site-wide setting (honeypotStatus = yes). The form sends no
 * Fluent Forms notification email and keeps no entry: the module copies the
 * request into its own table (with the retention schedule) and Fluent Forms
 * deletes its copy after the submit.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function dh_or_form_definition() {
    $required = function ( $message ) {
        return array(
            'required' => array(
                'value'          => true,
                'message'        => $message,
                'global'         => false,
                'global_message' => 'This field is required',
            ),
        );
    };
    $optional = array(
        'required' => array( 'value' => false, 'message' => 'This field is required', 'global' => false, 'global_message' => 'This field is required' ),
    );

    $options = function ( array $pairs ) {
        $out = array();
        foreach ( $pairs as $value => $label ) {
            $out[] = array( 'label' => $label, 'value' => $value, 'calc_value' => '', 'image' => '' );
        }
        return $out;
    };

    $text_field = function ( $index, $name, $label, $key, $type, $rules, $extra = array(), $attrs = array() ) {
        return array(
            'index'          => $index,
            'element'        => 'input_text',
            'attributes'     => array_merge( array( 'type' => $type, 'name' => $name, 'value' => '', 'id' => '', 'class' => '', 'placeholder' => '' ), $attrs ),
            'settings'       => array_merge( array(
                'container_class'    => 'dh-rb-f dh-rb-f--' . $name,
                'label'              => $label,
                'help_message'       => '',
                'admin_field_label'  => $label,
                'prefix_label'       => '',
                'suffix_label'       => '',
                'visible'            => true,
                'validation_rules'   => $rules,
                'conditional_logics' => array(),
            ), $extra ),
            'editor_options' => array( 'title' => 'Simple Text', 'icon_class' => 'ff-edit-text', 'template' => 'inputText' ),
            'uniqElKey'      => 'el_dhor_' . $key,
        );
    };

    $hidden = function ( $index, $name ) {
        return array(
            'index'          => $index,
            'element'        => 'input_hidden',
            'attributes'     => array( 'type' => 'hidden', 'name' => $name, 'value' => '' ),
            'settings'       => array( 'admin_field_label' => $name ),
            'editor_options' => array( 'title' => 'Hidden Field', 'icon_class' => 'ff-edit-hidden-field', 'template' => 'inputHidden' ),
            'uniqElKey'      => 'el_dhor_' . $name,
        );
    };

    $fields = array();

    $fields[] = array(
        'index'          => 1,
        'element'        => 'textarea',
        'attributes'     => array(
            'name'        => 'problem',
            'value'       => '',
            'id'          => '',
            'class'       => '',
            'placeholder' => 'Example: My dog is 1 year old, pulls on the leash, and barks at other dogs.',
            'rows'        => 4,
            'cols'        => 2,
            'maxlength'   => (int) DH_OR_Settings::get( 'text_max' ),
        ),
        'settings'       => array(
            'container_class'    => 'dh-rb-f dh-rb-f--wide dh-rb-f--problem',
            'label'              => 'What is going on with your dog?',
            'admin_field_label'  => 'Problem',
            'label_placement'    => '',
            'help_message'       => 'Links and phone numbers are removed from this box.',
            'validation_rules'   => $required( 'Please tell us what is going on.' ),
            'conditional_logics' => array(),
        ),
        'editor_options' => array( 'title' => 'Text Area', 'icon_class' => 'ff-edit-textarea', 'template' => 'inputTextarea' ),
        'uniqElKey'      => 'el_dhor_problem',
    );

    $fields[] = array(
        'index'          => 2,
        'element'        => 'input_checkbox',
        'attributes'     => array( 'type' => 'checkbox', 'name' => 'problem_chips', 'value' => array() ),
        'settings'       => array(
            'container_class'    => 'dh-rb-f dh-rb-f--wide dh-rb-f--chips',
            'label'              => 'What do you need help with? Pick any that fit.',
            'help_message'       => '',
            'admin_field_label'  => 'Help with',
            'advanced_options'   => $options( array(
                'puppy' => 'Puppy training', 'basic' => 'Basic obedience', 'potty' => 'Potty training',
                'leash' => 'Leash pulling', 'recall' => 'Recall', 'jumping' => 'Jumping on people',
                'barking' => 'Barking', 'separation' => 'Separation anxiety', 'fearfulness' => 'Fearfulness',
                'biting' => 'Biting or nipping', 'reactivity' => 'Reactivity or aggression',
                'guarding' => 'Resource guarding', 'stealing' => 'Stealing food',
            ) ),
            'randomize_options'  => 'no',
            'layout_class'       => 'ff_list_buttons',
            'validation_rules'   => $optional,
            'conditional_logics' => array(),
        ),
        'editor_options' => array( 'title' => 'Check Box', 'icon_class' => 'ff-edit-checkbox-1', 'template' => 'inputCheckable' ),
        'uniqElKey'      => 'el_dhor_chips',
    );

    $select = function ( $index, $name, $label, $pairs, $placeholder ) use ( $optional ) {
        return array(
            'index'          => $index,
            'element'        => 'select',
            'attributes'     => array( 'name' => $name, 'value' => '', 'id' => '', 'class' => '' ),
            'settings'       => array(
                'dynamic_default_value' => '',
                'label'                 => $label,
                'admin_field_label'     => $label,
                'help_message'          => '',
                'container_class'       => 'dh-rb-f dh-rb-f--' . $name,
                'label_placement'       => '',
                'placeholder'           => $placeholder,
                'advanced_options'      => array_map( function ( $v, $l ) {
                    return array( 'label' => $l, 'value' => $v, 'calc_value' => '' );
                }, array_keys( $pairs ), array_values( $pairs ) ),
                'calc_value_status'     => false,
                'enable_image_input'    => false,
                'values_visible'        => false,
                'enable_option_groups'  => 'no',
                'enable_select_2'       => 'no',
                'validation_rules'      => $optional,
                'conditional_logics'    => array(),
                'randomize_options'     => 'no',
            ),
            'editor_options' => array( 'title' => 'Dropdown', 'icon_class' => 'ff-edit-dropdown', 'element' => 'select', 'template' => 'select' ),
            'uniqElKey'      => 'el_dhor_' . $name,
        );
    };

    $fields[] = $select( 3, 'dog_age', "Your dog's age", array(
        'puppy'      => 'Puppy (under 1 year)',
        'adolescent' => 'Adolescent (1 to 2 years)',
        'adult'      => 'Adult (2 to 8 years)',
        'senior'     => 'Senior (8 years or more)',
        'unsure'     => 'Not sure',
    ), 'Choose an age' );

    $fields[] = $select( 4, 'format', 'What kind of training do you want?', array(
        'any'   => 'Any kind',
        'home'  => 'In-home training',
        'group' => 'A group class',
        'board' => 'Board and train',
        'video' => 'Video calls',
    ), 'Choose one' );

    $fields[] = $text_field( 5, 'zip', 'Your ZIP code', 'zip', 'text', $required( 'Please enter your ZIP code.' ), array(), array( 'inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'maxlength' => 10 ) );
    $fields[] = $text_field( 6, 'first_name', 'Your first name', 'first_name', 'text', $required( 'Please enter your first name.' ), array(), array( 'autocomplete' => 'given-name', 'maxlength' => 40 ) );

    $fields[] = array(
        'index'          => 7,
        'element'        => 'input_email',
        'attributes'     => array( 'type' => 'email', 'name' => 'email', 'value' => '', 'id' => '', 'class' => '', 'placeholder' => '', 'autocomplete' => 'email' ),
        'settings'       => array(
            'container_class'    => 'dh-rb-f dh-rb-f--email',
            'label'              => 'Your email',
            'help_message'       => '',
            'admin_field_label'  => 'Email',
            'prefix_label'       => '',
            'suffix_label'       => '',
            'validation_rules'   => array_merge(
                $required( 'Please enter your email.' ),
                array( 'email' => array( 'value' => true, 'message' => 'This field must contain a valid email', 'global_message' => 'This field must contain a valid email', 'global' => true ) )
            ),
            'conditional_logics' => array(),
            'is_unique'          => 'no',
        ),
        'editor_options' => array( 'title' => 'Email', 'icon_class' => 'ff-edit-email', 'template' => 'inputText' ),
        'uniqElKey'      => 'el_dhor_email',
    );

    $fields[] = $text_field( 8, 'phone', 'Your phone (optional)', 'phone', 'tel', $optional, array(), array( 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 30 ) );

    foreach ( array( 'source_post_id', 'source_type', 'viewed_profile_id', 'utm_source', 'utm_medium', 'utm_campaign' ) as $i => $name ) {
        $fields[] = $hidden( 9 + $i, $name );
    }

    $fields[] = array(
        'index'          => 20,
        'element'        => 'terms_and_condition',
        'attributes'     => array( 'type' => 'checkbox', 'name' => 'consent', 'value' => false, 'class' => '' ),
        'settings'       => array(
            'tnc_html'           => esc_html( DH_OR_Gates::CONSENT_TEXT )
                . ' <a target="_blank" rel="noopener" href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">Privacy Policy</a>.',
            'has_checkbox'       => true,
            'admin_field_label'  => 'Consent',
            'container_class'    => 'dh-rb-f dh-rb-f--wide dh-rb-f--consent gd2-termsfield',
            'validation_rules'   => $required( 'Please tick this box so we can send your request.' ),
            'conditional_logics' => array(),
        ),
        'editor_options' => array( 'title' => 'Terms & Conditions', 'icon_class' => 'ff-edit-terms-condition', 'template' => 'termsCheckbox' ),
        'uniqElKey'      => 'el_dhor_consent',
    );

    $fields[] = array(
        'index'          => 21,
        'element'        => 'turnstile',
        'attributes'     => array( 'name' => 'cf-turnstile-response' ),
        'settings'       => array( 'label' => '', 'label_placement' => '', 'validation_rules' => array() ),
        'editor_options' => array( 'title' => 'Turnstile', 'icon_class' => 'ff-edit-recaptha', 'why_disabled_modal' => 'turnstile', 'template' => 'turnstile' ),
        'uniqElKey'      => 'el_dhor_turnstile',
    );

    $submit = array(
        'uniqElKey'      => 'el_dhor_submit',
        'element'        => 'button',
        'attributes'     => array( 'type' => 'submit', 'class' => '' ),
        'settings'       => array(
            'align'            => 'left',
            'button_style'     => 'default',
            'container_class'  => 'dh-rb-submit',
            'help_message'     => '',
            'background_color' => '#0572bd',
            'button_size'      => 'md',
            'color'            => '#ffffff',
            'button_ui'        => array( 'type' => 'default', 'text' => 'Send my request', 'img_url' => '' ),
            'normal_styles'    => array( 'backgroundColor' => '#0572bd', 'borderColor' => '#0572bd', 'color' => '#ffffff', 'borderRadius' => '', 'minWidth' => '' ),
            'hover_styles'     => array( 'backgroundColor' => '#0686db', 'borderColor' => '#0686db', 'color' => '#ffffff', 'borderRadius' => '', 'minWidth' => '' ),
            'current_state'    => 'normal_styles',
        ),
        'editor_options' => array( 'title' => 'Submit Button' ),
    );

    $settings = array(
        'confirmation' => array(
            'redirectTo'            => 'samePage',
            'messageToShow'         => '<p>Thanks. We got your request.</p>',
            'customPage'            => null,
            'samePageFormBehavior'  => 'hide_form',
            'customUrl'             => null,
        ),
        'restrictions' => array(
            'limitNumberOfEntries' => array( 'enabled' => false, 'numberOfEntries' => null, 'period' => 'total', 'limitReachedMsg' => 'Maximum number of entries exceeded.' ),
            'scheduleForm'         => array( 'enabled' => false, 'start' => null, 'end' => null, 'selectedDays' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ), 'pendingMsg' => 'Form submission is not started yet.', 'expiredMsg' => 'Form submission is now closed.' ),
            'requireLogin'         => array( 'enabled' => false, 'requireLoginMsg' => 'You must be logged in to submit the form.' ),
            'denyEmptySubmission'  => array( 'enabled' => false, 'message' => 'Sorry, you cannot submit an empty form.' ),
        ),
        'layout' => array( 'labelPlacement' => 'top', 'helpMessagePlacement' => 'under_input', 'errorMessagePlacement' => 'inline', 'cssClassName' => 'dh-rb-form', 'asteriskPlacement' => 'asterisk-right' ),
        // Fluent Forms keeps no entry: the module's own table holds the request under the retention schedule.
        'delete_entry_on_submission' => 'yes',
        'appendSurveyResult' => array( 'enabled' => false, 'showLabel' => false, 'showCount' => false ),
    );

    return array(
        'title'    => 'Ask trainers for help',
        'fields'   => array( 'fields' => $fields, 'submitButton' => $submit ),
        'settings' => $settings,
    );
}
