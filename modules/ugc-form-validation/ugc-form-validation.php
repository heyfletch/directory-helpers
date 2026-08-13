<?php
/**
 * UGC Form Validation Module
 *
 * Server-side minimum-length validation for the Get Listed (UGC) form.
 *
 * @package Directory_Helpers
 * @subpackage UGC_Form_Validation
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_UGC_Form_Validation {

    /**
     * Fluent Forms form ID for the Get Listed (UGC) form.
     */
    const FORM_ID = 7;

    /**
     * Minimum word counts per required UGC field.
     */
    private $min_words = array(
        'ugc_philosophy'   => 40,
        'ugc_tough_case'   => 40,
        'ugc_expectations' => 30,
    );

    private $labels = array(
        'ugc_philosophy'   => 'Your Training Philosophy',
        'ugc_tough_case'   => 'A Tough Case You Solved',
        'ugc_expectations' => 'What New Clients Should Expect',
    );

    public function __construct() {
        add_filter( 'fluentform/validation_errors', array( $this, 'validate_ugc_fields' ), 10, 4 );
    }

    /**
     * Enforce minimum word counts on UGC fields.
     *
     * @param array  $errors   Existing validation errors.
     * @param array  $formData Submitted form data.
     * @param object $form     The form object.
     * @param array  $fields   Form fields.
     * @return array
     */
    public function validate_ugc_fields( $errors, $formData, $form, $fields ) {
        if ( (int) $form->id !== self::FORM_ID ) {
            return $errors;
        }

        foreach ( $this->min_words as $field => $min ) {
            $value = isset( $formData[ $field ] ) ? trim( wp_strip_all_tags( $formData[ $field ] ) ) : '';
            $words = str_word_count( $value );
            if ( $words < $min ) {
                $errors[ $field ] = array(
                    sprintf(
                        '"%s" needs a bit more detail - at least %d words (you wrote %d). This is what makes your profile unique, so tell us more!',
                        $this->labels[ $field ],
                        $min,
                        $words
                    ),
                );
            }
        }

        return $errors;
    }
}
